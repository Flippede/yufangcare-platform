<?php

namespace app\services\yfth;

use crmeb\exceptions\AdminException;

class HqAuthorityAdminReadServices
{
    private $read;
    private $dto;
    private $adminContext;

    public function __construct(
        HqAuthorityReadServices $read,
        HqAuthorityDtoServices $dto,
        AdminStoreContextServices $adminContext
    ) {
        $this->read = $read;
        $this->dto = $dto;
        $this->adminContext = $adminContext;
    }

    public function attributionList(array $filters, array $adminInfo): array
    {
        $this->adminContext->assertHeadquarterScope($adminInfo);
        $result = $this->read->attributionPage($filters);
        $users = $this->read->userMap(array_column($result['list'], 'uid'));
        $stores = $this->read->storeMap(array_column($result['list'], 'store_id'));
        $result['list'] = array_map(function ($row) use ($users, $stores) {
            $uid = (int)$row['uid'];
            $storeId = (int)$row['store_id'];
            return $this->dto->adminAttribution(
                $row,
                $users[$uid] ?? [],
                $stores[$storeId] ?? [],
                $this->read->hasActiveReferral($uid, $storeId),
                $this->read->isAttributionConsistent($row)
            );
        }, $result['list']);
        return $result;
    }

    public function attributionDetail(int $id, array $adminInfo): array
    {
        $this->adminContext->assertHeadquarterScope($adminInfo);
        $row = $this->read->attributionById($id);
        if (!$row) {
            throw new AdminException('authority_attribution_not_found');
        }
        $uid = (int)$row['uid'];
        $storeId = (int)$row['store_id'];
        $users = $this->read->userMap([$uid]);
        $stores = $this->read->storeMap([$storeId]);
        return ['attribution' => $this->dto->adminAttribution(
            $row,
            $users[$uid] ?? [],
            $stores[$storeId] ?? [],
            $this->read->hasActiveReferral($uid, $storeId),
            $this->read->isAttributionConsistent($row)
        )];
    }

    public function referralList(array $filters, array $adminInfo): array
    {
        $this->adminContext->assertHeadquarterScope($adminInfo);
        $result = $this->read->referralPage($filters);
        $uids = array_merge(array_column($result['list'], 'referrer_uid'), array_column($result['list'], 'referred_uid'));
        $users = $this->read->userMap($uids);
        $stores = $this->read->storeMap(array_column($result['list'], 'store_id'));
        $result['list'] = array_map(function ($row) use ($users, $stores) {
            return $this->dto->adminReferral(
                $row,
                $users[(int)$row['referrer_uid']] ?? [],
                $users[(int)$row['referred_uid']] ?? [],
                $stores[(int)$row['store_id']] ?? [],
                $this->read->isReferralConsistent($row)
            );
        }, $result['list']);
        return $result;
    }

    public function referralDetail(int $id, array $adminInfo): array
    {
        $this->adminContext->assertHeadquarterScope($adminInfo);
        $row = $this->read->referralById($id);
        if (!$row) {
            throw new AdminException('authority_referral_not_found');
        }
        $users = $this->read->userMap([(int)$row['referrer_uid'], (int)$row['referred_uid']]);
        $stores = $this->read->storeMap([(int)$row['store_id']]);
        return ['referral' => $this->dto->adminReferral(
            $row,
            $users[(int)$row['referrer_uid']] ?? [],
            $users[(int)$row['referred_uid']] ?? [],
            $stores[(int)$row['store_id']] ?? [],
            $this->read->isReferralConsistent($row)
        )];
    }
}
