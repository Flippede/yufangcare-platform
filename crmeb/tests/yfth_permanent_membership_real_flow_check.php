<?php

use app\services\system\admin\SystemAdminServices;
use app\services\user\UserAuthServices;
use think\App;
use think\facade\Config;
use think\facade\Db;
use crmeb\services\CacheService;

require dirname(__DIR__) . '/vendor/autoload.php';
if ((string)getenv('YFTH_PERMANENT_MEMBERSHIP_REAL_FLOW_EXECUTE') !== '1') { echo "[NOTE] real_flow_skipped_set_execute=1\n"; exit(0); }

$app = new class() extends App {
    public function loadEnv(string $envName = ''): void {
        parent::loadEnv($envName);
        foreach (['HOSTNAME'=>'hostname','HOSTPORT'=>'hostport','USERNAME'=>'username','PASSWORD'=>'password','DATABASE'=>'database','PREFIX'=>'prefix','CHARSET'=>'charset'] as $env => $key) {
            $value=getenv('YFTH_REAL_FLOW_DB_'.$env); if($value!==false)$this->env->set('database.'.$key,$value);
        }
        if((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY')==='1')$this->env->set('database.password','');
        $this->env->set('cache.driver','file');
    }
};
$app->initialize();
$failures=[];$passes=[];$notes=[];$server=[];
$assert=function(bool $ok,string $label)use(&$failures,&$passes){$ok?$passes[]=$label:$failures[]=$label;};
try {
    $version=(string)(Db::query('SELECT VERSION() version')[0]['version']??'');
    $database=(string)Db::query('SELECT DATABASE() db')[0]['db'];
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB')==='1','isolated_database_guard');
    $assert(strpos($version,'8.0.46')===0 && stripos($version,'mariadb')===false,'mysql_community_8_0_46:'.$version);
    $assert((bool)preg_match('/(test|sandbox|validation)/i',$database),'isolated_database_name:'.$database);
    $run='PM'.date('His').strtoupper(substr(bin2hex(random_bytes(3)),0,6));
    $fixture=pmSeed($run);
    CacheService::clearAll();
    $server=pmStartServer($notes);
    $base=$server['base_url'];
    $tokens=[];foreach($fixture['users'] as $key=>$uid)$tokens[$key]=pmUserToken($uid);
    $adminToken=pmAdminToken($fixture['admin']['id'],$fixture['admin']['pwd']);
    $noAuthToken=pmAdminToken($fixture['admin_no_auth']['id'],$fixture['admin_no_auth']['pwd']);
    $ctx='?role_code=store_manager&store_id='.$fixture['stores']['A'];

    pmExpectFail(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership'.$ctx,$tokens['staff'],['idempotency_key'=>$run.'staff']), 'store_staff_create_rejected',$assert);
    pmExpectFail(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership'.$ctx,$tokens['customer'],['idempotency_key'=>$run.'customer']), 'ordinary_customer_store_create_rejected',$assert);
    pmExpectFail(pmRequest('GET',$base.'/adminapi/yfth/permanent_membership/enrollment',$noAuthToken), 'admin_without_permission_rejected',$assert);

    $first=pmExpectOk(pmRequest('POST',$base.'/api/yfth/permanent_membership/identity_code',$tokens['customer']), 'identity_code_first_ok',$assert);
    $second=pmExpectOk(pmRequest('POST',$base.'/api/yfth/permanent_membership/identity_code',$tokens['customer']), 'identity_code_refresh_ok',$assert);
    $assert($first['data']['token']!==$second['data']['token'],'identity_code_refresh_changes_plaintext');
    $assert((int)Db::name('yfth_business_dynamic_code')->where('target_uid',$fixture['users']['customer'])->where('scene','customer_identity')->where('status','replaced')->count()===1,'identity_code_refresh_invalidates_old');
    $assert((int)Db::name('yfth_business_dynamic_code')->where('token_hash',$second['data']['token'])->count()===0,'identity_plaintext_not_stored');

    $enrollment=pmExpectOk(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership'.$ctx,$tokens['manager'],['idempotency_key'=>$run.'create']), 'store_create_enrollment_ok',$assert)['data'];
    pmExpectFail(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership/'.$enrollment['id'].'/bind'.$ctx,$tokens['manager'],['identity_token'=>$first['data']['token'],'idempotency_key'=>$run.'bindold']), 'replaced_identity_code_rejected',$assert);
    $bound=pmExpectOk(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership/'.$enrollment['id'].'/bind'.$ctx,$tokens['manager'],['identity_token'=>$second['data']['token'],'idempotency_key'=>$run.'bind']), 'same_store_identity_bind_ok',$assert)['data'];
    $assert((int)$bound['target_uid']===$fixture['users']['customer'],'identity_code_resolves_authenticated_uid');
    pmExpectFail(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership/'.$enrollment['id'].'/confirmation_code'.$ctx,$tokens['manager']), 'unpaid_confirmation_code_rejected',$assert);
    pmExpectOk(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership/'.$enrollment['id'].'/payment'.$ctx,$tokens['manager'],['idempotency_key'=>$run.'pay']), 'offline_payment_confirm_ok',$assert);
    $confirmation=pmExpectOk(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership/'.$enrollment['id'].'/confirmation_code'.$ctx,$tokens['manager']), 'membership_confirmation_code_ok',$assert)['data'];
    $activated=pmExpectOk(pmRequest('POST',$base.'/api/yfth/permanent_membership/confirm',$tokens['customer'],['confirmation_token'=>$confirmation['token'],'idempotency_key'=>$run.'activate']), 'customer_membership_confirm_ok',$assert)['data'];
    $assert(($activated['membership']['permanent']??false)===true && (int)$activated['membership']['store_id']===$fixture['stores']['A'],'permanent_membership_created_for_store');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid',$fixture['users']['customer'])->count()===1,'membership_unique_per_uid');
    $assert((int)Db::name('yfth_permanent_membership_event')->where('uid',$fixture['users']['customer'])->count()===1,'membership_event_written_once');
    $assert((int)Db::name('yfth_membership_reward_candidate')->where('target_uid',$fixture['users']['customer'])->count()===1,'amount_free_reward_candidate_written_once');
    $relation=Db::name('yfth_hq_active_referral_current')->where('referred_uid',$fixture['users']['customer'])->find();
    $assert($relation['status']==='closed' && $relation['close_reason']==='membership_activated' && $relation['active_referred_uid']===null,'active_referral_closed_by_membership');
    $attribution=Db::name('yfth_hq_customer_attribution_current')->where('uid',$fixture['users']['customer'])->find();
    $assert($attribution['status']==='active' && (int)$attribution['store_id']===$fixture['stores']['A'],'same_store_attribution_preserved');
    $repeat=pmExpectOk(pmRequest('POST',$base.'/api/yfth/permanent_membership/confirm',$tokens['customer'],['confirmation_token'=>$confirmation['token'],'idempotency_key'=>$run.'activate-repeat']), 'used_confirmation_code_replays_success',$assert);
    $assert((int)Db::name('yfth_permanent_membership')->where('uid',$fixture['users']['customer'])->count()===1 && (int)Db::name('yfth_membership_reward_candidate')->where('target_uid',$fixture['users']['customer'])->count()===1,'repeat_confirmation_has_no_duplicate_writes');
    $me=pmExpectOk(pmRequest('GET',$base.'/api/yfth/permanent_membership/me',$tokens['customer']), 'customer_membership_read_ok',$assert);
    $assert(($me['data']['has_referral_qualification']??false)===true,'active_member_has_referral_qualification');
    $assert(!array_key_exists('source_type',$me['data']['membership']??[]) && !array_key_exists('reward',$me['data']), 'customer_dto_hides_internal_source_and_reward');

    $cross=pmPrepareEnrollment($base,$ctx,$tokens['conflict'],$tokens['manager'],$run.'cross',$assert);
    $before=pmBusinessCounts($fixture['users']['conflict']);
    pmExpectFail(pmRequest('POST',$base.'/api/yfth/permanent_membership/confirm',$tokens['conflict'],['confirmation_token'=>$cross['token'],'idempotency_key'=>$run.'crossactivate']), 'cross_store_attribution_conflict_rejected',$assert);
    $after=pmBusinessCounts($fixture['users']['conflict']);
    $assert($before===$after,'cross_store_conflict_has_no_partial_membership_event_candidate_or_code_use');

    $concurrent=pmPrepareEnrollment($base,$ctx,$tokens['concurrent'],$tokens['manager'],$run.'concurrent',$assert);
    $url=$base.'/api/yfth/permanent_membership/confirm';
    $results=pmConcurrentConfirm($url,$tokens['concurrent'],$concurrent['token'],$run,$notes);
    $assert(count($results)===2 && (int)($results[0]['status']??0)===200 && (int)($results[1]['status']??0)===200,'two_process_confirmation_both_return_success');
    $assert((int)Db::name('yfth_permanent_membership')->where('uid',$fixture['users']['concurrent'])->count()===1,'concurrent_membership_written_once');
    $assert((int)Db::name('yfth_permanent_membership_event')->where('uid',$fixture['users']['concurrent'])->count()===1,'concurrent_event_written_once');
    $assert((int)Db::name('yfth_membership_reward_candidate')->where('target_uid',$fixture['users']['concurrent'])->count()===1,'concurrent_candidate_written_once');
    $conAttr=Db::name('yfth_hq_customer_attribution_current')->where('uid',$fixture['users']['concurrent'])->find();
    $assert($conAttr['status']==='active' && (int)$conAttr['store_id']===$fixture['stores']['A'],'no_attribution_customer_gets_permanent_store_attribution');

    pmExpectOk(pmRequest('GET',$base.'/api/yfth/store_workbench/permanent_membership'.$ctx,$tokens['manager']), 'store_enrollment_list_ok',$assert);
    pmExpectFail(pmRequest('GET',$base.'/api/yfth/store_workbench/permanent_membership?role_code=store_manager&store_id='.$fixture['stores']['B'],$tokens['manager']), 'store_cross_scope_rejected',$assert);
    pmExpectOk(pmRequest('GET',$base.'/adminapi/yfth/permanent_membership/enrollment',$adminToken), 'headquarter_enrollment_list_ok',$assert);
    pmExpectOk(pmRequest('GET',$base.'/adminapi/yfth/permanent_membership/member',$adminToken), 'headquarter_member_list_ok',$assert);
    $hq=pmExpectOk(pmRequest('POST',$base.'/adminapi/yfth/permanent_membership/enrollment',$adminToken,['store_id'=>$fixture['stores']['A'],'idempotency_key'=>$run.'hqcreate']), 'headquarter_create_for_explicit_store_ok',$assert);
    $assert((int)$hq['data']['store_id']===$fixture['stores']['A'],'headquarter_create_uses_explicit_active_store');
} catch(Throwable $e){$failures[]='real_flow_exception:'.$e->getMessage().':'.$e->getFile().':'.$e->getLine();}
finally { if($server)pmStopServer($server,$notes); }
foreach($notes as $note)echo"[NOTE] {$note}\n";
if($failures){foreach($failures as $failure)fwrite(STDERR,"[FAIL] {$failure}\n");exit(1);}foreach($passes as $pass)echo"[PASS] {$pass}\n";echo"[OK] Stage 2 permanent membership real HTTP and concurrency flow verified.\n";

function pmSeed(string $run): array
{
    $users=[];foreach(['customer','conflict','concurrent','referrer','manager','staff'] as $key)$users[$key]=pmCreateUser($run,$key);
    $stores=['A'=>pmCreateStore($run,'A'),'B'=>pmCreateStore($run,'B')];
    pmGrantRole($users['manager'],$stores['A'],'store_manager',$run);pmGrantRole($users['staff'],$stores['A'],'store_staff',$run);
    $customerAttr=pmAttribution($users['customer'],$stores['A'],$run.'customer');
    pmAttribution($users['referrer'],$stores['A'],$run.'referrer');
    pmAttribution($users['conflict'],$stores['B'],$run.'conflict');
    pmReferral($users['referrer'],$users['customer'],$stores['A'],$customerAttr,$run);
    $menuIds=Db::name('system_menus')->whereLike('unique_auth','yfth-permanent-membership-%')->column('id');
    $role=(int)Db::name('system_role')->insertGetId(['role_name'=>'PM '.$run,'rules'=>implode(',',$menuIds),'level'=>1,'status'=>1]);
    $none=(int)Db::name('system_role')->insertGetId(['role_name'=>'PM none '.$run,'rules'=>'','level'=>1,'status'=>1]);
    $admin=pmCreateAdmin($run,'admin',$role);$adminNo=pmCreateAdmin($run,'none',$none);
    foreach([$admin,$adminNo] as $row)Db::name('yfth_admin_store_scope')->insert(['admin_id'=>$row['id'],'store_id'=>0,'role_code'=>'headquarter_operator','permission_scope'=>'','status'=>'active','start_time'=>0,'end_time'=>0,'created_uid'=>$row['id'],'updated_uid'=>$row['id'],'disabled_uid'=>0,'disabled_time'=>0,'close_reason'=>'','active_key'=>$row['id'].':0:headquarter_operator','add_time'=>time(),'update_time'=>time()]);
    return compact('run','users','stores','admin')+['admin_no_auth'=>$adminNo];
}
function pmCreateUser(string $run,string $key):int{return(int)Db::name('user')->insertGetId(['account'=>substr(strtolower($run.$key),0,32),'pwd'=>md5($run.$key),'real_name'=>'PM '.$key,'nickname'=>'PM '.$key,'avatar'=>'','phone'=>'139'.str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT),'add_time'=>time(),'last_time'=>time(),'status'=>1,'user_type'=>'h5','login_type'=>'h5','uniqid'=>md5($run.$key.random_int(1,999999)),'is_del'=>0]);}
function pmCreateStore(string $run,string $key):int{return(int)Db::name('system_store')->insertGetId(['name'=>'PM Store '.$key.' '.$run,'introduction'=>'Stage 2 validation','phone'=>'13800000000','address'=>'上海市测试区','detailed_address'=>'Validation Road','image'=>'','oblong_image'=>'','latitude'=>'31.2304','longitude'=>'121.4737','valid_time'=>'','day_time'=>'09:00-21:00','add_time'=>time(),'is_show'=>1,'is_del'=>0]);}
function pmGrantRole(int $uid,int $store,string $role,string $run):void{Db::name('yfth_user_store_role')->insert(['uid'=>$uid,'store_id'=>$store,'role_code'=>$role,'permission_scope'=>'','status'=>'active','start_time'=>time()-60,'end_time'=>time()+3600,'creator_uid'=>0,'active_key'=>$uid.':'.$store.':'.$role,'add_time'=>time(),'update_time'=>time()]);}
function pmAttribution(int $uid,int $store,string $key):int{$now=time();$id=(int)Db::name('yfth_hq_customer_attribution_current')->insertGetId(['uid'=>$uid,'store_id'=>$store,'status'=>'active','status_reason_code'=>'','authority_version'=>1,'source_type'=>'runtime_validation','source_id'=>$key,'bound_at'=>$now-100,'paused_at'=>0,'closed_at'=>0,'close_reason'=>'','add_time'=>$now-100,'update_time'=>$now]);Db::name('yfth_hq_customer_attribution_event')->insert(['event_no'=>'HAE'.strtoupper(substr(hash('sha256',$key),0,24)),'attribution_current_id'=>$id,'uid'=>$uid,'authority_version'=>1,'event_type'=>'attribution_created','before_store_id'=>0,'after_store_id'=>$store,'before_status'=>'unassigned','after_status'=>'active','before_status_reason_code'=>'initial_placeholder','after_status_reason_code'=>'','source_type'=>'runtime_validation','source_id'=>$key,'source_unique_key'=>hash('sha256',$key.':attr'),'operator_uid'=>1,'operator_role_code'=>'runtime_validation','reason'=>'validation','request_id'=>$key,'add_time'=>$now-100]);return$id;}
function pmReferral(int $referrer,int $referred,int $store,int $attr,string $key):void{$now=time();$no='HRR'.strtoupper(substr(hash('sha256',$key),0,24));$id=(int)Db::name('yfth_hq_active_referral_current')->insertGetId(['relation_no'=>$no,'referrer_uid'=>$referrer,'referred_uid'=>$referred,'store_id'=>$store,'attribution_current_id'=>$attr,'status'=>'active','active_referred_uid'=>$referred,'source_type'=>'runtime_validation','source_id'=>$key,'source_unique_key'=>hash('sha256',$key.':relation'),'started_at'=>$now-100,'paused_at'=>0,'closed_at'=>0,'close_reason'=>'','relation_version'=>1,'request_id'=>$key,'add_time'=>$now-100,'update_time'=>$now]);Db::name('yfth_hq_active_referral_event')->insert(['event_no'=>'HRE'.strtoupper(substr(hash('sha256',$key.'event'),0,24)),'referral_current_id'=>$id,'relation_no'=>$no,'relation_version'=>1,'referrer_uid'=>$referrer,'referred_uid'=>$referred,'store_id'=>$store,'event_type'=>'relation_created','before_status'=>'','after_status'=>'active','source_type'=>'runtime_validation','source_id'=>$key,'source_unique_key'=>hash('sha256',$key.':event'),'operator_uid'=>1,'operator_role_code'=>'runtime_validation','reason'=>'validation','request_id'=>$key,'add_time'=>$now-100]);}
function pmCreateAdmin(string $run,string $key,int $role):array{$pwd=password_hash('pm-'.$run.'-'.$key,PASSWORD_BCRYPT);$id=(int)Db::name('system_admin')->insertGetId(['account'=>substr(strtolower('pm_'.$key.'_'.$run),0,32),'head_pic'=>'','pwd'=>$pwd,'real_name'=>'PM '.$key,'roles'=>(string)$role,'last_ip'=>'127.0.0.1','last_time'=>0,'add_time'=>time(),'login_count'=>0,'level'=>1,'status'=>1,'division_id'=>0,'is_del'=>0]);return compact('id','pwd');}
function pmUserToken(int $uid):string{return(string)app()->make(UserAuthServices::class)->createToken($uid,'api')['token'];}
function pmAdminToken(int $id,string $pwd):string{return(string)app()->make(SystemAdminServices::class)->createToken($id,'admin',$pwd)['token'];}
function pmRequest(string $method,string $url,string $token,array $data=[]):array{$headers=['Content-Type: application/x-www-form-urlencoded'];if($token!==''){$headers[]='Authorization: Bearer '.$token;$headers[]='Authori-zation: Bearer '.$token;}$context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?http_build_query($data):'','ignore_errors'=>true,'timeout'=>30]]);$body=@file_get_contents($url,false,$context);$code=0;if(isset($http_response_header[0])&&preg_match('/\s(\d{3})\s/',$http_response_header[0],$m))$code=(int)$m[1];$json=is_string($body)?json_decode($body,true):null;return['http_code'=>$code,'body'=>(string)$body,'json'=>is_array($json)?$json:[]];}
function pmExpectOk(array $response,string $label,callable $assert):array{$ok=$response['http_code']>=200&&$response['http_code']<300&&(int)($response['json']['status']??0)===200;$assert($ok,$label);if(!$ok)throw new RuntimeException($label.':'.substr($response['body'],0,500));return$response['json'];}
function pmExpectFail(array $response,string $label,callable $assert):void{$ok=!($response['http_code']>=200&&$response['http_code']<300&&(int)($response['json']['status']??0)===200);$assert($ok,$label);if(!$ok)throw new RuntimeException($label.':unexpected_success');}
function pmPrepareEnrollment(string $base,string $ctx,string $customerToken,string $managerToken,string $key,callable $assert):array{$identity=pmExpectOk(pmRequest('POST',$base.'/api/yfth/permanent_membership/identity_code',$customerToken),$key.'_identity',$assert)['data'];$enroll=pmExpectOk(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership'.$ctx,$managerToken,['idempotency_key'=>$key.'_create']),$key.'_create',$assert)['data'];pmExpectOk(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership/'.$enroll['id'].'/bind'.$ctx,$managerToken,['identity_token'=>$identity['token'],'idempotency_key'=>$key.'_bind']),$key.'_bind',$assert);pmExpectOk(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership/'.$enroll['id'].'/payment'.$ctx,$managerToken,['idempotency_key'=>$key.'_pay']),$key.'_pay',$assert);return pmExpectOk(pmRequest('POST',$base.'/api/yfth/store_workbench/permanent_membership/'.$enroll['id'].'/confirmation_code'.$ctx,$managerToken),$key.'_code',$assert)['data'];}
function pmBusinessCounts(int $uid):array{return['member'=>(int)Db::name('yfth_permanent_membership')->where('uid',$uid)->count(),'event'=>(int)Db::name('yfth_permanent_membership_event')->where('uid',$uid)->count(),'candidate'=>(int)Db::name('yfth_membership_reward_candidate')->where('target_uid',$uid)->count(),'used_code'=>(int)Db::name('yfth_business_dynamic_code')->where('target_uid',$uid)->where('scene','membership_confirmation')->where('status','used')->count()];}
function pmConcurrentConfirm(string $url,string $token,string $confirmation,string $run,array &$notes):array{$php=trim((string)getenv('YFTH_PERMANENT_MEMBERSHIP_PHP'))?:PHP_BINARY;$ini=trim((string)getenv('YFTH_PERMANENT_MEMBERSHIP_PHP_INI'));$worker=__DIR__.'/yfth_permanent_membership_http_worker.php';$processes=[];foreach([1,2]as$i){$command=[$php];if($ini!==''){$command[]='-c';$command[]=$ini;}$command=array_merge($command,[$worker,$url,$token,$confirmation,$run.'_concurrent_'.$i]);$pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);$processes[]=compact('process','pipes');} $results=[];foreach($processes as$item){$out=stream_get_contents($item['pipes'][1]);$err=stream_get_contents($item['pipes'][2]);foreach($item['pipes']as$pipe)fclose($pipe);$exit=proc_close($item['process']);$notes[]='concurrency_worker_exit:'.$exit.':'.$err;$results[]=json_decode(trim($out),true)?:[];}return$results;}
function pmStartServer(array &$notes):array{$root=dirname(__DIR__);$lock=$root.'/public/install.lock';$created=false;if(!is_file($lock)){file_put_contents($lock,'stage2_validation');$created=true;}$router=sys_get_temp_dir().'/yfth_pm_router_'.getmypid().'.php';$autoload=$root.'/vendor/autoload.php';$code=<<<'PHP'
<?php
namespace think;
require __AUTOLOAD__;
$_SERVER['DOCUMENT_ROOT']=__ROOT__.DIRECTORY_SEPARATOR.'public';$_SERVER['SCRIPT_FILENAME']=$_SERVER['DOCUMENT_ROOT'].DIRECTORY_SEPARATOR.'index.php';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['PHP_SELF']='/index.php';$_SERVER['PATH_INFO']=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$app=new class(__ROOT__) extends App { public function loadEnv(string $envName=''):void { parent::loadEnv($envName); $this->env->set('cache.driver','file'); } };
$http=$app->http;$response=$http->run();$response->send();$http->end($response);
PHP;
file_put_contents($router,str_replace(['__AUTOLOAD__','__ROOT__'],[var_export($autoload,true),var_export($root,true)],$code));$php=trim((string)getenv('YFTH_PERMANENT_MEMBERSHIP_PHP'))?:PHP_BINARY;$ini=trim((string)getenv('YFTH_PERMANENT_MEMBERSHIP_PHP_INI'));$command=[$php];if($ini!==''){$command[]='-c';$command[]=$ini;}$command=array_merge($command,['-S','127.0.0.1:18152','-t',sys_get_temp_dir(),$router]);$stdout=sys_get_temp_dir().'/yfth_pm_http.out.log';$stderr=sys_get_temp_dir().'/yfth_pm_http.err.log';$process=proc_open($command,[0=>['pipe','r'],1=>['file',$stdout,'a'],2=>['file',$stderr,'a']],$pipes,sys_get_temp_dir(),$_ENV);if(!is_resource($process))throw new RuntimeException('http_server_start_failed');for($i=0;$i<40;$i++){$s=@fsockopen('127.0.0.1',18152,$errno,$error,.25);if(is_resource($s)){fclose($s);$notes[]='http_server_started:18152';return compact('process','router','lock','created')+['base_url'=>'http://127.0.0.1:18152'];}usleep(250000);}throw new RuntimeException('http_server_not_ready');}
function pmStopServer(array $server,array &$notes):void{if(isset($server['process'])&&is_resource($server['process'])){proc_terminate($server['process']);proc_close($server['process']);$notes[]='http_server_stopped';}if(!empty($server['created']))@unlink($server['lock']);@unlink($server['router']);}
