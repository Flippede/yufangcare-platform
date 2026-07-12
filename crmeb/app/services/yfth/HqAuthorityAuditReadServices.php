<?php

namespace app\services\yfth;

use crmeb\exceptions\AdminException;

class HqAuthorityAuditReadServices
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

    public function attributionEvents(int $id, array $adminInfo): array
    {
        $this->adminContext->assertHeadquarterScope($adminInfo);
        if (!$this->read->attributionById($id)) {
            throw new AdminException('authority_attribution_not_found');
        }
        return ['list' => array_map([$this->dto, 'attributionEvent'], $this->read->attributionEvents($id))];
    }

    public function referralEvents(int $id, array $adminInfo): array
    {
        $this->adminContext->assertHeadquarterScope($adminInfo);
        if (!$this->read->referralById($id)) {
            throw new AdminException('authority_referral_not_found');
        }
        return ['list' => array_map([$this->dto, 'referralEvent'], $this->read->referralEvents($id))];
    }
}
