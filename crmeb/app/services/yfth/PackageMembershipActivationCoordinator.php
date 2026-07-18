<?php

namespace app\services\yfth;

class PackageMembershipActivationCoordinator
{
    private $membership;
    private $attribution;
    private $referral;

    public function __construct(
        PackageMembershipServices $membership,
        HqCustomerAttributionServices $attribution,
        HqActiveReferralServices $referral
    ) {
        $this->membership = $membership;
        $this->attribution = $attribution;
        $this->referral = $referral;
    }

    public function activateInTransaction(array $purchase, array $snapshot, int $instanceId): array
    {
        $decision = app()->make(PackageMembershipGrantPolicy::class)->forSnapshot($snapshot);
        if (!$decision['grants_permanent_membership']) {
            return ['granted' => false, 'reason' => 'package_rule_does_not_grant_membership'];
        }

        $uid = (int)$purchase['uid'];
        $storeId = (int)$purchase['store_id'];
        app()->make(StoreAccessServices::class)->assertStoreActive($storeId);
        $lockContext = $this->referral->membershipLockContext($uid);
        $lockedCurrents = (array)$lockContext['locked_currents'];
        $source = HqAuthoritySource::fromTrusted('package_membership_activation', $instanceId);
        $requestId = 'package_membership_activation:' . $instanceId;
        $mutation = new HqAuthorityMutation($source, $uid, 'customer', 'package_membership_activated', $requestId, $requestId);
        $attribution = $this->attribution->assignFirstWithLockedCurrentsInTransaction($uid, $storeId, $mutation, $lockedCurrents);

        $relation = [];
        $candidate = [];
        if ((int)$lockContext['relation_id'] > 0) {
            $closed = $this->referral->closeForMembershipWithLockedCurrentsInTransaction($uid, $storeId, $mutation, $lockContext, $lockedCurrents);
            $relation = (array)$closed['before'];
            $amountCent = $this->moneyToCents($snapshot['order_pay_price'] ?? '0.00');
            $candidate = app()->make(UnifiedRewardOrchestratorServices::class)->enqueue(
                'package_activated',
                'package_instance',
                (string)$instanceId,
                ['relation' => $relation, 'instance_id' => $instanceId, 'amount_cent' => $amountCent]
            );
        }

        $membership = $this->membership->grantFromPackageInTransaction(
            $purchase,
            $snapshot,
            $instanceId,
            'package_membership_activation',
            $requestId
        );

        return [
            'granted' => true,
            'membership_created' => (bool)$membership['created'],
            'membership_id' => (int)$membership['member']['id'],
            'attribution_changed' => (bool)$attribution['changed'],
            'relation_closed' => !empty($relation),
            'reward_event_created' => (bool)($candidate['created'] ?? false),
            'reward_event_id' => (int)($candidate['event']['id'] ?? 0),
        ];
    }

    private function moneyToCents($value): int
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new \crmeb\exceptions\ApiException('money_snapshot_invalid');
        }
        return (int)$matches[1] * 100 + (int)str_pad($matches[2] ?? '', 2, '0');
    }
}
