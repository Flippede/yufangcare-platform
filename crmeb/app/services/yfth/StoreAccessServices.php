<?php

namespace app\services\yfth;

use app\services\system\store\SystemStoreServices;
use crmeb\exceptions\ApiException;

class StoreAccessServices extends YfthFoundationBaseServices
{
    public function assertStoreActive(int $storeId): array
    {
        if ($storeId <= 0) {
            throw new ApiException('store_id is required for store scoped roles');
        }

        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $store = $storeServices->get($storeId);
        if (!$store) {
            throw new ApiException('store_not_found');
        }

        $row = is_array($store) ? $store : $store->toArray();
        if ((int)($row['is_del'] ?? 0) !== 0 || (int)($row['is_show'] ?? 0) !== 1) {
            throw new ApiException('store_not_active');
        }

        return $this->formatStore($row);
    }

    public function formatStore(array $row): array
    {
        return [
            'store_id' => (int)($row['id'] ?? 0),
            'store_name' => (string)($row['name'] ?? ''),
            'store_status' => ((int)($row['is_del'] ?? 0) === 0 && (int)($row['is_show'] ?? 0) === 1) ? 'active' : 'inactive',
            'is_show' => (int)($row['is_show'] ?? 0),
            'is_del' => (int)($row['is_del'] ?? 0),
        ];
    }
}
