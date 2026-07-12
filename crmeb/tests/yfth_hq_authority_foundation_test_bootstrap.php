<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use app\dao\system\store\SystemStoreDao;
use app\dao\user\UserDao;
use app\dao\yfth\YfthHqActiveReferralCurrentDao;
use app\dao\yfth\YfthHqActiveReferralEventDao;
use app\dao\yfth\YfthHqCustomerAttributionCurrentDao;
use app\dao\yfth\YfthHqCustomerAttributionEventDao;
use app\services\yfth\AuditEventServices;
use app\services\yfth\HqActiveReferralServices;
use app\services\yfth\HqAuthorityMutation;
use app\services\yfth\HqAuthorityOperationRunner;
use app\services\yfth\HqAuthoritySource;
use app\services\yfth\HqAuthoritySourceCanonicalizer;
use app\services\yfth\HqCustomerAttributionServices;
use app\services\yfth\IdempotencyRecordServices;
use app\services\yfth\ReferralQualificationPolicy;
use think\App;

class YfthHqAuthorityTestQualificationPolicy implements ReferralQualificationPolicy
{
    public $assertionCount = 0;

    public function assertQualified(int $referrerUid, int $storeId): void
    {
        $this->assertionCount++;
        if ($referrerUid <= 0 || $storeId <= 0) {
            throw new RuntimeException('test_referral_qualification_invalid');
        }
    }
}

class YfthHqAuthorityTestIdempotencyServices extends IdempotencyRecordServices
{
    public $beginCount = 0;
    public $completeCount = 0;
    public $failCount = 0;

    public function __construct()
    {
    }

    public function begin(string $domain, string $action, string $key, array $payload = [], string $objectId = '', int $ttl = 86400): array
    {
        $this->beginCount++;
        return ['acquired' => true, 'status' => 'processing', 'record' => ['id' => 1]];
    }

    public function complete(int $id, array $summary = []): void
    {
        $this->completeCount++;
    }

    public function fail(int $id, string $reason): void
    {
        $this->failCount++;
    }
}

function hqAuthorityBootTestApp(): App
{
    $app = new class() extends App {
        public function loadEnv(string $envName = ''): void
        {
            parent::loadEnv($envName);
            foreach ([
                'YFTH_REAL_FLOW_DB_HOSTNAME' => 'database.hostname',
                'YFTH_REAL_FLOW_DB_HOSTPORT' => 'database.hostport',
                'YFTH_REAL_FLOW_DB_USERNAME' => 'database.username',
                'YFTH_REAL_FLOW_DB_PASSWORD' => 'database.password',
                'YFTH_REAL_FLOW_DB_DATABASE' => 'database.database',
                'YFTH_REAL_FLOW_DB_PREFIX' => 'database.prefix',
                'YFTH_REAL_FLOW_DB_CHARSET' => 'database.charset',
            ] as $envKey => $configKey) {
                $value = getenv($envKey);
                if ($value !== false) {
                    $this->env->set($configKey, $value);
                }
            }
            if ((string)getenv('YFTH_REAL_FLOW_DB_PASSWORD_EMPTY') === '1') {
                $this->env->set('database.password', '');
            }
            $this->env->set('cache.driver', 'file');
        }
    };
    $app->initialize();
    return $app;
}

function hqAuthorityTestServices(bool $qualified = true, array $allowedSourceTypes = null): array
{
    $allowedSourceTypes = $allowedSourceTypes === null ? [
        'test_attribution', 'test_referral', 'test_transition', 'test_concurrency', 'test_retry',
    ] : $allowedSourceTypes;
    $canonicalizer = new HqAuthoritySourceCanonicalizer($allowedSourceTypes);
    $runner = app()->make(HqAuthorityOperationRunner::class);
    $attribution = new HqCustomerAttributionServices(
        app()->make(YfthHqCustomerAttributionCurrentDao::class),
        app()->make(YfthHqCustomerAttributionEventDao::class),
        app()->make(UserDao::class),
        app()->make(SystemStoreDao::class),
        $canonicalizer,
        $runner,
        app()->make(AuditEventServices::class)
    );
    $policy = $qualified ? new YfthHqAuthorityTestQualificationPolicy() : null;
    $referral = new HqActiveReferralServices(
        app()->make(YfthHqActiveReferralCurrentDao::class),
        app()->make(YfthHqActiveReferralEventDao::class),
        $attribution,
        $canonicalizer,
        $runner,
        app()->make(AuditEventServices::class),
        $policy
    );
    return compact('canonicalizer', 'runner', 'attribution', 'referral') + ['qualification_policy' => $policy];
}

function hqAuthorityTestMutation(string $sourceType, int $sourceId, string $idempotencyKey, string $reason = 'isolated_test'): HqAuthorityMutation
{
    return new HqAuthorityMutation(
        HqAuthoritySource::fromTrusted($sourceType, $sourceId),
        max(1, (int)(getenv('YFTH_HQ_TEST_OPERATOR_UID') ?: 1)),
        'test_operator',
        $reason,
        'test-request-' . $idempotencyKey,
        $idempotencyKey
    );
}
