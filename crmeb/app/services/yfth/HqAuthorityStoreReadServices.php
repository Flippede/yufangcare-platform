<?php

namespace app\services\yfth;

use app\Request;
use crmeb\exceptions\ApiException;

class HqAuthorityStoreReadServices
{
    private const ROLES = ['store_manager'];
    private $read;
    private $dto;
    private $context;

    public function __construct(
        HqAuthorityReadServices $read,
        HqAuthorityDtoServices $dto,
        CurrentBusinessContextServices $context
    ) {
        $this->read = $read;
        $this->dto = $dto;
        $this->context = $context;
    }

    public function index(Request $request, array $filters): array
    {
        $context = $this->trustedContext($request);
        $result = $this->read->attributionPage($filters, (int)$context['store_id'], ['active', 'paused']);
        $uids = array_column($result['list'], 'uid');
        $users = $this->read->userMap($uids);
        $result['list'] = array_map(function ($row) use ($users) {
            if (!$this->read->isAttributionConsistent($row)) {
                throw new ApiException('authority_data_requires_headquarters_review');
            }
            $uid = (int)$row['uid'];
            return $this->dto->storeAttribution(
                $row,
                $users[$uid] ?? [],
                $this->read->hasActiveReferral($uid, (int)$row['store_id'])
            );
        }, $result['list']);
        return $result;
    }

    public function detail(Request $request, int $id): array
    {
        $context = $this->trustedContext($request);
        $row = $this->read->attributionById($id);
        if (!$row || (int)$row['store_id'] !== (int)$context['store_id']
            || !in_array((string)$row['status'], ['active', 'paused'], true)) {
            throw new ApiException('authority_store_attribution_not_found');
        }
        if (!$this->read->isAttributionConsistent($row)) {
            throw new ApiException('authority_data_requires_headquarters_review');
        }
        $uid = (int)$row['uid'];
        $users = $this->read->userMap([$uid]);
        return ['attribution' => $this->dto->storeAttribution(
            $row,
            $users[$uid] ?? [],
            $this->read->hasActiveReferral($uid, (int)$context['store_id'])
        )];
    }

    private function trustedContext(Request $request): array
    {
        $context = $this->context->fromRequest($request);
        if (!in_array((string)($context['role_code'] ?? ''), self::ROLES, true)
            || (int)($context['store_id'] ?? 0) <= 0) {
            throw new ApiException('authority_store_read_role_forbidden');
        }
        return $context;
    }
}
