<?php

namespace crmeb\command;

use app\services\yfth\PackageMembershipServices;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class YfthStoreRoleMembershipBackfill extends Command
{
    protected function configure()
    {
        $this->setName('yfth:store-role-membership-backfill')
            ->addOption('execute', null, Option::VALUE_NONE, 'apply the backfill; omit for a read-only report')
            ->addOption('operator-uid', null, Option::VALUE_OPTIONAL, 'headquarters operator uid', 0)
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'maximum users to inspect', 1000)
            ->setDescription('Backfill permanent membership for active store managers and staff');
    }

    protected function execute(Input $input, Output $output)
    {
        $execute = (bool)$input->getOption('execute');
        $operatorUid = (int)$input->getOption('operator-uid');
        $limit = max(1, min(10000, (int)$input->getOption('limit')));
        if ($execute && $operatorUid <= 0) {
            $output->writeln(json_encode([
                'success' => false,
                'reason' => 'operator_uid_required',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return 1;
        }

        $rows = Db::name('yfth_user_store_role')
            ->whereIn('role_code', ['store_manager', 'store_staff'])
            ->where('status', 'active')
            ->order('uid asc,id asc')
            ->limit($limit)
            ->select()
            ->toArray();
        $byUid = [];
        foreach ($rows as $row) {
            $byUid[(int)$row['uid']][] = $row;
        }

        $result = [
            'success' => true,
            'execute' => $execute,
            'inspected_users' => count($byUid),
            'eligible_users' => 0,
            'already_active' => 0,
            'backfilled' => 0,
            'conflicts' => 0,
            'failed' => 0,
        ];
        /** @var PackageMembershipServices $memberships */
        $memberships = app()->make(PackageMembershipServices::class);
        foreach ($byUid as $uid => $roles) {
            $storeIds = array_values(array_unique(array_map(function ($role) {
                return (int)$role['store_id'];
            }, $roles)));
            if (count($storeIds) !== 1) {
                $result['conflicts']++;
                continue;
            }
            $effective = $memberships->effectiveMembershipAuthority((int)$uid);
            if (!empty($effective['is_member'])) {
                $result['already_active']++;
                continue;
            }
            $result['eligible_users']++;
            if (!$execute) {
                continue;
            }
            $role = $roles[0];
            try {
                Db::transaction(function () use ($memberships, $uid, $role, $operatorUid) {
                    $memberships->grantForStoreRoleInTransaction(
                        (int)$uid,
                        (int)$role['store_id'],
                        (int)$role['id'],
                        $operatorUid,
                        'store-role-membership-backfill:' . (int)$role['id']
                    );
                });
                $result['backfilled']++;
            } catch (\Throwable $e) {
                $result['failed']++;
            }
        }
        $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $result['failed'] === 0 ? 0 : 1;
    }
}
