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
        $rewardEvent = [];
        $commission = [];
        if ((int)$lockContext['relation_id'] > 0) {
            // The package order is always a YFTH source. Marking it again here
            // is harmless and closes the recovery-path gap before any payment
            // listener can consult CRMEB's legacy brokerage services.
            app()->make(YfthCommissionOrderSourceServices::class)->mark((int)($purchase['order_id'] ?? 0), 'package');
            // The active referral is frozen into the durable activation event and
            // its automatic accrual before the relationship is closed.  That
            // ordering prevents a membership retry from observing neither a
            // reward nor an active referral.
            $relation = [
                'id' => (int)$lockContext['relation_id'],
                'referrer_uid' => (int)$lockContext['referrer_uid'],
                'referred_uid' => $uid,
                'store_id' => $storeId,
            ];
            $amountCent = $this->moneyToCents($snapshot['order_pay_price'] ?? '0.00');
            $payload = [
                'relation' => $relation,
                'instance_id' => $instanceId,
                'order_id' => (int)($purchase['order_id'] ?? 0),
                'purchase_id' => (int)($purchase['id'] ?? 0),
                'amount_cent' => $amountCent,
            ];
            $rewardEvent = app()->make(UnifiedRewardOrchestratorServices::class)->enqueue(
                'package_activated',
                'package_instance',
                (string)$instanceId,
                $payload
            );
            $commission = app()->make(AutomaticCommissionServices::class)->consumePackageActivation($payload);
            $closed = $this->referral->closeForMembershipWithLockedCurrentsInTransaction($uid, $storeId, $mutation, $lockContext, $lockedCurrents);
            $relation = (array)$closed['before'];
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
            'reward_event_created' => (bool)($rewardEvent['created'] ?? false),
            'reward_event_id' => (int)($rewardEvent['event']['id'] ?? 0),
            'commission_accrual_id' => (int)($commission['accrual']['id'] ?? 0),
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
