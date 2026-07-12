<?php

$root = dirname(__DIR__);
$failures = [];
$passes = [];
$assert = function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes[] = $label;
    } else {
        $failures[] = $label;
    }
};
$read = function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        throw new RuntimeException('missing_file:' . $path);
    }
    return (string)file_get_contents($full);
};

try {
    $migration = str_replace("\r\n", "\n", $read('database/migrations/20260713100000_create_yfth_hq_authority_foundation_tables.php'));
    $attribution = $read('app/services/yfth/HqCustomerAttributionServices.php');
    $referral = $read('app/services/yfth/HqActiveReferralServices.php');
    $runner = $read('app/services/yfth/HqAuthorityOperationRunner.php');
    $canonicalizer = $read('app/services/yfth/HqAuthoritySourceCanonicalizer.php');
    $qualification = $read('app/services/yfth/FailClosedReferralQualificationPolicy.php');

    foreach ([
        'yfth_hq_customer_attribution_current',
        'yfth_hq_customer_attribution_event',
        'yfth_hq_active_referral_current',
        'yfth_hq_active_referral_event',
    ] as $table) {
        $assert(strpos($migration, "'{$table}'") !== false, 'migration_contains_' . $table);
    }
    foreach ([
        'uniq_yfth_hq_attr_current_uid', 'idx_yfth_hq_attr_store_status_uid',
        'uniq_yfth_hq_attr_event_version', 'uniq_yfth_hq_attr_event_source',
        'uniq_yfth_hq_ref_current_active_uid', 'uniq_yfth_hq_ref_current_source',
        'uniq_yfth_hq_ref_event_version', 'uniq_yfth_hq_ref_event_source',
    ] as $index) {
        $assert(strpos($migration, $index) !== false, 'migration_contains_' . $index);
    }
    $currentMethodStart = strpos($migration, 'private function createAttributionCurrent');
    $currentMethodEnd = strpos($migration, 'private function createAttributionEvent');
    $currentMethod = substr($migration, $currentMethodStart, $currentMethodEnd - $currentMethodStart);
    $assert(strpos($currentMethod, 'source_unique_key') === false, 'attribution_current_has_no_source_unique_key');
    $assert(strpos($migration, 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL') !== false, 'source_key_uses_ascii_bin_char64');
    $assert(strpos($migration, 'migrationRecordExists') !== false && strpos($migration, 'forward_repair_required') !== false, 'migration_record_incomplete_blocks_for_forward_repair');
    $assert(strpos($migration, 'assertTableColumns') !== false && strpos($migration, 'assertNoDuplicates') !== false, 'partial_schema_signature_and_unique_conflict_checks_exist');
    $assert(strpos($migration, 'assertIndexSignature') !== false && strpos($migration, 'expectedIndexes') !== false, 'migration_validates_expected_index_signatures');
    $assert(strpos($migration, 'NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE') !== false, 'index_signature_checks_uniqueness_columns_order_and_type');
    $assert(strpos($migration, "'yfth_hq_active_referral_event',\n            'yfth_hq_customer_attribution_event',\n            'yfth_hq_active_referral_current',\n            'yfth_hq_customer_attribution_current'") !== false, 'migration_down_order_is_frozen');

    foreach ([
        'hq_attribution_event', 'hq_active_referral_relation', 'hq_active_referral_event',
    ] as $domain) {
        $assert(strpos($canonicalizer, "'{$domain}'") !== false, 'canonical_domain_' . $domain);
    }
    $assert(strpos($canonicalizer, "hash('sha256'") !== false, 'canonicalizer_uses_sha256');
    $assert(strpos($canonicalizer, 'authority_source_type_not_allowed') !== false, 'unknown_source_type_fails_closed');
    $assert(strpos($qualification, 'permanent_membership_authority_unavailable') !== false, 'production_qualification_fails_closed');
    foreach (['member_5980', 'member_yfth', 'yfth_customer_relation', 'referral_candidate'] as $forbidden) {
        $assert(strpos($qualification, $forbidden) === false, 'qualification_does_not_query_' . $forbidden);
    }

    $assert(substr_count($runner, '->begin(') === 1, 'operation_runner_calls_begin_once');
    $assert(strpos($runner, 'tryReacquire') === false, 'operation_runner_never_reacquires_during_retry');
    $assert(strpos($runner, '$attempt >= 3') !== false, 'operation_runner_limits_transaction_attempts_to_three');
    $assert(strpos($runner, 'ThinkPdoException') !== false && strpos($runner, 'Driver Error Code') !== false, 'operation_runner_requires_structured_database_exception');
    $assert(strpos($runner, 'isRetryableDatabaseCode') !== false && strpos($runner, '[1205, 1213]') !== false, 'operation_runner_retries_database_lock_codes_only');
    $assert(strpos($runner, "strpos(\$message, 'deadlock')") === false && strpos($runner, "strpos(\$message, '1205')") === false, 'operation_runner_has_no_arbitrary_message_retry');
    $assert(strpos($runner, 'idempotency->complete') !== false && strpos($runner, 'Db::transaction') !== false, 'idempotency_completion_is_in_business_transaction');

    foreach (['assignFirst', 'markHistoricalUnassigned', 'pause', 'resume', 'close'] as $method) {
        $assert(strpos($attribution, 'function ' . $method . '(') !== false, 'attribution_method_' . $method);
    }
    $assert(strpos($attribution, 'initial_placeholder') !== false && strpos($attribution, 'store_terminated_no_successor') !== false, 'pristine_and_historical_unassigned_are_distinct');
    $assert(strpos($attribution, 'attribution_not_pristine') !== false && strpos($attribution, 'attribution_store_conflict') !== false, 'attribution_rebind_guards_exist');
    $validator = $read('app/services/yfth/HqAuthorityConsistencyValidator.php');
    $assert(strpos($attribution, 'HqAuthorityConsistencyValidator') !== false
        && strpos($referral, 'HqAuthorityConsistencyValidator') !== false,
        'stage1a_writers_share_authority_consistency_validator');
    $assert(strpos($validator, '$expectedVersion = $offset + 1') !== false
        && strpos($validator, 'attribution_latest_event_inconsistent') !== false
        && strpos($validator, 'referral_latest_event_inconsistent') !== false,
        'current_event_versions_and_final_content_are_consistent');
    $assert(strpos($attribution, 'lockCurrents') !== false && strpos($attribution, 'sort($uids, SORT_NUMERIC)') !== false, 'attribution_uid_locks_are_numeric_ascending');
    $assignStart = strpos($attribution, 'public function assignFirst');
    $assignRunner = strpos($attribution, '$this->runner->run', $assignStart);
    $assignCanonical = strpos($attribution, "canonicalizer->attributionEvent('attribution_created'", $assignStart);
    $assert($assignCanonical !== false && $assignCanonical < $assignRunner, 'attribution_source_is_canonicalized_before_runner_and_shortcut');

    foreach (['create', 'pause', 'resume', 'close', 'invalidate'] as $method) {
        $assert(strpos($referral, 'function ' . $method . '(') !== false, 'referral_method_' . $method);
    }
    $assert(strpos($referral, 'referral_self_or_invalid_relation') !== false, 'self_referral_rejected');
    $assert(strpos($referral, 'referral_direct_reverse_relation_forbidden') !== false, 'direct_reverse_referral_rejected');
    $assert(strpos($referral, 'active_referred_uid') !== false && strpos($referral, "'relation_version' => 1") !== false, 'referral_active_slot_and_version_one_exist');
    $assert(strpos($referral, 'canonicalizer->referralRelation') !== false && strpos($referral, 'canonicalizer->referralEvent') !== false, 'referral_relation_and_event_keys_are_separate');
    $assert(strpos($referral, "'source_unique_key' => \$relationSourceKey") !== false, 'referral_current_stores_creation_source_key');
    $transitionStart = strpos($referral, 'private function transition');
    $transitionEnd = strpos($referral, 'private function appendEvent', $transitionStart);
    $transitionText = substr($referral, $transitionStart, $transitionEnd - $transitionStart);
    $assert(strpos($transitionText, "'source_unique_key' =>") === false, 'referral_transitions_never_replace_current_source_key');
    $assert(strpos($referral, 'qualification->assertQualified') !== false, 'referral_create_and_resume_require_qualification');
    $createStart = strpos($referral, 'public function create');
    $createRunner = strpos($referral, '$this->runner->run', $createStart);
    $createCanonical = strpos($referral, 'canonicalizer->referralRelation', $createStart);
    $assert($createCanonical !== false && $createCanonical < $createRunner, 'referral_source_is_canonicalized_before_runner_and_shortcut');
    $activeShortcut = strpos($referral, 'return $this->result($active, $active, false);', $createStart);
    $activeQualification = strrpos(substr($referral, $createStart, $activeShortcut - $createStart), 'qualification->assertQualified');
    $assert($activeShortcut !== false && $activeQualification !== false, 'existing_referral_shortcut_requires_qualification');

    foreach ([
        'YfthHqCustomerAttributionCurrent', 'YfthHqCustomerAttributionEvent',
        'YfthHqActiveReferralCurrent', 'YfthHqActiveReferralEvent',
    ] as $class) {
        $assert(is_file($root . '/app/model/yfth/' . $class . '.php'), 'model_exists_' . $class);
        $assert(is_file($root . '/app/dao/yfth/' . $class . 'Dao.php'), 'dao_exists_' . $class);
    }
} catch (Throwable $e) {
    $failures[] = 'contract_check_exception:' . $e->getMessage();
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}
foreach ($passes as $pass) {
    echo "[PASS] {$pass}\n";
}
echo "[OK] YFTH headquarters authority foundation contract verified.\n";
