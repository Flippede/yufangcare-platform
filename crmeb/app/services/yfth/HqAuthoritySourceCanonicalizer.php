<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

class HqAuthoritySourceCanonicalizer
{
    public const ATTRIBUTION_EVENT_DOMAIN = 'hq_attribution_event';
    public const REFERRAL_RELATION_DOMAIN = 'hq_active_referral_relation';
    public const REFERRAL_EVENT_DOMAIN = 'hq_active_referral_event';

    private $allowedSourceTypes;

    public function __construct(array $allowedSourceTypes = [
        'package_membership_referral_invite',
        'package_membership_activation',
        'historical_package_activation',
    ])
    {
        $this->allowedSourceTypes = array_values(array_unique(array_map('strval', $allowedSourceTypes)));
    }

    public function attributionEvent(string $eventType, HqAuthoritySource $source): string
    {
        return $this->digest(self::ATTRIBUTION_EVENT_DOMAIN, $eventType, $source);
    }

    public function referralRelation(HqAuthoritySource $source): string
    {
        return $this->digest(self::REFERRAL_RELATION_DOMAIN, 'relation_created', $source);
    }

    public function referralEvent(string $eventType, HqAuthoritySource $source): string
    {
        return $this->digest(self::REFERRAL_EVENT_DOMAIN, $eventType, $source);
    }

    private function digest(string $domain, string $eventType, HqAuthoritySource $source): string
    {
        if (!in_array($source->type(), $this->allowedSourceTypes, true)) {
            throw new ApiException('authority_source_type_not_allowed');
        }
        $eventType = trim($eventType);
        if ($eventType === '' || !preg_match('/^[a-z][a-z0-9_]{1,47}$/', $eventType)) {
            throw new ApiException('authority_event_type_invalid');
        }
        return hash('sha256', $domain . '|' . $eventType . '|' . $source->type() . '|' . $source->id());
    }
}
