<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

class HqAuthorityUserReadServices
{
    private $read;
    private $dto;

    public function __construct(HqAuthorityReadServices $read, HqAuthorityDtoServices $dto)
    {
        $this->read = $read;
        $this->dto = $dto;
    }

    public function me(int $uid): array
    {
        $row = $this->read->attributionByUid($uid);
        if (!$row) {
            return $this->dto->userAttribution([], [], false);
        }
        if (!$this->read->isAttributionConsistent($row)) {
            throw new ApiException('authority_data_requires_headquarters_review');
        }
        $stores = $this->read->storeMap([(int)$row['store_id']]);
        $store = $stores[(int)$row['store_id']] ?? [];
        if (in_array((string)$row['status'], ['active', 'paused'], true) && !$store) {
            throw new ApiException('authority_data_requires_headquarters_review');
        }
        return $this->dto->userAttribution(
            $row,
            $store,
            $this->read->hasActiveReferral($uid, (int)$row['store_id'])
        );
    }
}
