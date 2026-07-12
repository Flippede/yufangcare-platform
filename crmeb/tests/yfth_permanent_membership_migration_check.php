<?php

use think\App;
use think\facade\Config;
use think\facade\Db;
use Phinx\Db\Adapter\AdapterFactory;

require dirname(__DIR__) . '/vendor/autoload.php';
if ((string)getenv('YFTH_PERMANENT_MEMBERSHIP_MIGRATION_EXECUTE') !== '1') { echo "[NOTE] migration_check_skipped_set_execute=1\n"; exit(0); }

$app = new class() extends App {
    public function loadEnv(string $envName = ''): void {
        parent::loadEnv($envName);
        foreach (['HOSTNAME'=>'hostname','HOSTPORT'=>'hostport','USERNAME'=>'username','PASSWORD'=>'password','DATABASE'=>'database','PREFIX'=>'prefix','CHARSET'=>'charset'] as $env => $key) {
            $value = getenv('YFTH_REAL_FLOW_DB_' . $env); if ($value !== false) $this->env->set('database.' . $key, $value);
        }
        if ((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY') === '1') $this->env->set('database.password', '');
        $this->env->set('cache.driver', 'file');
    }
};
$app->initialize();
require_once dirname(__DIR__) . '/database/migrations/20260715100000_create_yfth_permanent_membership_tables.php';

$failures=[];$passes=[];$assert=function(bool $ok,string $label)use(&$failures,&$passes){$ok?$passes[]=$label:$failures[]=$label;};
try {
    $version=(string)(Db::query('SELECT VERSION() version')[0]['version']??'');
    $database=(string)Db::query('SELECT DATABASE() db')[0]['db'];
    $assert((string)getenv('YFTH_REAL_FLOW_ISOLATED_DB')==='1','isolated_guard');
    $assert(strpos($version,'8.0.46')===0 && stripos($version,'mariadb')===false,'mysql_8_0_46:' . $version);
    $assert((bool)preg_match('/(test|sandbox|validation)/i',$database),'isolated_database_name:' . $database);
    $default=(string)Config::get('database.default');
    $config=Config::get('database.connections.' . $default);
    $raw=AdapterFactory::instance()->getAdapter('mysql',['adapter'=>$config['type'],'host'=>$config['hostname'],'name'=>$config['database'],'user'=>$config['username'],'pass'=>$config['password'],'port'=>$config['hostport'],'charset'=>$config['charset'],'table_prefix'=>$config['prefix'],'default_migration_table'=>$config['prefix'].Config::get('database.migration_table','migrations')]);
    $migration=new CreateYfthPermanentMembershipTables(20260715100000);
    $migration->setAdapter(AdapterFactory::instance()->getWrapper('prefix',$raw));
    $migration->up(); $migration->up();
    $tables=['yfth_permanent_membership_enrollment','yfth_permanent_membership','yfth_permanent_membership_event','yfth_business_dynamic_code','yfth_membership_reward_candidate'];
    foreach($tables as $table)$assert(Db::query("SHOW TABLES LIKE '" . $config['prefix'] . $table . "'")!==[],'table_created:' . $table);
    $assert((int)Db::name('system_menus')->whereIn('unique_auth',['yfth-permanent-membership-index','yfth-permanent-membership-enrollment-read','yfth-permanent-membership-member-read','yfth-permanent-membership-enrollment-create','yfth-permanent-membership-enrollment-bind','yfth-permanent-membership-payment-confirm','yfth-permanent-membership-confirmation-code'])->count()===7,'seven_permissions_unique');
    $migration->down();
    foreach($tables as $table)$assert(Db::query("SHOW TABLES LIKE '" . $config['prefix'] . $table . "'")===[],'table_rolled_back:' . $table);
    $assert((int)Db::name('system_menus')->whereLike('unique_auth','yfth-permanent-membership%')->count()===0,'permissions_rolled_back');
    $migration->up(); $migration->up();
    foreach($tables as $table)$assert(Db::query("SHOW TABLES LIKE '" . $config['prefix'] . $table . "'")!==[],'table_rerun:' . $table);
} catch(Throwable $e){$failures[]='migration_exception:'.$e->getMessage().':'.$e->getLine();}
if($failures){foreach($failures as $failure)fwrite(STDERR,"[FAIL] {$failure}\n");exit(1);}foreach($passes as $pass)echo"[PASS] {$pass}\n";echo"[OK] Stage 2 migration lifecycle verified.\n";
