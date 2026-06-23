<?php
namespace app\services\dist;

use app\model\dist\DistConfig;
use app\model\dist\DistLevel;
use app\model\dist\DistCommissionLog;
use app\model\user\User as UserModel;

class CommissionServices
{
    protected function cfg(string $key, $default=null){
        return DistConfig::where('key',$key)->value('value') ?? $default;
    }
    protected function parsePath(?string $path): array {
        if (!$path) return [];
        $arr = array_filter(array_map('intval', explode('/', $path)));
        return array_reverse($arr); // 近->远
    }
    protected function mapLevels(): array {
        $list = DistLevel::where('status',1)->select()->toArray();
        $map = [];
        foreach ($list as $lv) $map[(int)$lv['id']] = $lv;
        return $map;
    }
    protected function calcBaseAmount(array $order): float {
        $mode = $this->cfg('base_amount','display'); // display|settle|gross
        $total = 0.0;
        foreach (($order['cartList'] ?? []) as $it){
            $qty = (int)($it['cart_num'] ?? $it['num'] ?? 1);
            $display = (float)($it['price'] ?? 0);
            $settle  = isset($it['_settle_price']) ? (float)$it['_settle_price'] : $display;
            $cost    = (float)($it['cost'] ?? 0);
            $base = $display;
            if ($mode==='settle') $base = $settle;
            if ($mode==='gross')  $base = max($display-$cost,0);
            $total += $base * $qty;
        }
        if ($total <= 0 && isset($order['pay_price'])) $total = (float)$order['pay_price'];
        return round($total,2);
    }
    protected function freezeLog(int $orderId, string $orderNo, int $buyerUid, int $ownerUid, ?int $ownerLevelId,
                                 float $base, float $rate, float $money, string $reason, array $snapshot=[]): void {
        if ($money == 0.0) return;
        DistCommissionLog::create([
            'order_id'=>$orderId,'order_no'=>$orderNo,'buyer_uid'=>$buyerUid,'owner_uid'=>$ownerUid,
            'owner_level_id'=>$ownerLevelId,'base_amount'=>$base,'rate'=>$rate,'commission'=>$money,
            'stage'=>'freeze','reason'=>$reason,'snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** 下单成功后：写入冻结佣金 */
    public function createFreezeLogs(array $order, array $buyer): void
    {
        $maxLayer = (int)$this->cfg('max_layer', 3);
        $base     = $this->calcBaseAmount($order);
        if ($base <= 0) return;

        $pathUids = $this->parsePath($buyer['dist_path'] ?? '');
        if (!$pathUids) return;

        $levelsMap = $this->mapLevels();
        $getLevelByUid = function(int $uid) use ($levelsMap){
            $u = UserModel::where('uid',$uid)->field(['uid','dist_level_id'])->find();
            $lid = $u ? (int)$u['dist_level_id'] : 0;
            return $lid>0 && isset($levelsMap[$lid]) ? $levelsMap[$lid] : null;
        };

        $orderId = (int)($order['order_id'] ?? $order['id'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? $order['order_id'] ?? '');
        $buyerUid= (int)($buyer['uid'] ?? 0);

        // L1 直推（见单奖）
        $l1Uid = $pathUids[0] ?? 0;
        if ($l1Uid && $maxLayer >= 1){
            $l1Level = $getLevelByUid($l1Uid);
            $rate = $l1Level ? (float)$l1Level['direct_rate'] : 0.0;
            $this->freezeLog($orderId,$orderNo,$buyerUid,$l1Uid,$l1Level['id']??null,$base,$rate,round($base*$rate,2),'direct',[
                'layer'=>1,'buyer_level'=>$buyer['dist_level_id']??null,'owner_level'=>$l1Level['id']??null
            ]);
        }

        // L2/L3 … 同级 & 越级补差
        $prevHighRate = (float)($l1Uid ? ($getLevelByUid($l1Uid)['direct_rate'] ?? 0.0) : 0.0);
        for ($i=1; $i<$maxLayer && isset($pathUids[$i]); $i++){
            $uid = $pathUids[$i];
            $level = $getLevelByUid($uid);
            if (!$level) continue;

            $target = (float)$level['direct_rate'];
            if ($target > $prevHighRate){
                $diffRate = round($target - $prevHighRate, 4);
                if ($diffRate > 0){
                    $this->freezeLog($orderId,$orderNo,$buyerUid,$uid,$level['id']??null,$base,$diffRate,round($base*$diffRate,2),'cross',[
                        'layer'=>$i+1,'target'=>$target,'taken_before'=>$prevHighRate
                    ]);
                    $prevHighRate = $target;
                }
            } else {
                if (abs($target - $prevHighRate) < 1e-6){
                    $peer = (float)$level['peer_rate'];
                    if ($peer > 0){
                        $this->freezeLog($orderId,$orderNo,$buyerUid,$uid,$level['id']??null,$base,$peer,round($base*$peer,2),'peer',[
                            'layer'=>$i+1,'peer_rate'=>$peer
                        ]);
                    }
                }
            }
        }
        // 如需团队/管理奖，可再扫一遍路径按 team_rate 发放（等你确认口径后补）
    }

    /** 订单完成后：冻结 → 可用 */
    public function thawByOrder(int $orderId): void
    {
        DistCommissionLog::where('order_id',$orderId)
            ->where('stage','freeze')
            ->update(['stage'=>'available','updated_at'=>date('Y-m-d H:i:s')]);
    }

    /** 退款/关单：撤销 */
    public function revokeByOrder(int $orderId): void
    {
        DistCommissionLog::where('order_id',$orderId)
            ->whereIn('stage',['freeze','available'])
            ->update(['stage'=>'revoked','updated_at'=>date('Y-m-d H:i:s')]);
    }
}
