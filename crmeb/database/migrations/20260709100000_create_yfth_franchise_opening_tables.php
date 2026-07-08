<?php

use think\migration\Migrator;

class CreateYfthFranchiseOpeningTables extends Migrator
{
    private $menuKeys = [
        'yfth-franchise-opening-index',
        'yfth-franchise-opening-contract-list',
        'yfth-franchise-opening-contract-detail',
        'yfth-franchise-opening-contract-create',
        'yfth-franchise-opening-contract-confirm',
        'yfth-franchise-opening-payment-list',
        'yfth-franchise-opening-payment-confirm',
        'yfth-franchise-opening-payment-reject',
        'yfth-franchise-opening-profile-detail',
        'yfth-franchise-opening-profile-save',
        'yfth-franchise-opening-profile-bind-store',
        'yfth-franchise-opening-task-list',
        'yfth-franchise-opening-task-review',
        'yfth-franchise-opening-acceptance-list',
        'yfth-franchise-opening-acceptance-detail',
        'yfth-franchise-opening-acceptance-review',
        'yfth-franchise-opening-identity-grant',
    ];

    public function up()
    {
        $this->createContractTable();
        $this->createPaymentProofTable();
        $this->createStoreProfileTable();
        $this->createPreparationTaskTable();
        $this->createPreparationTaskRecordTable();
        $this->createAcceptanceTable();
        $this->createAcceptanceItemTable();
        $this->createIdentityGrantTable();
        $this->seedMenus();
    }

    public function down()
    {
        $quoted = array_map(function ($key) {
            return $this->quote($key);
        }, $this->menuKeys);
        $this->execute('DELETE FROM `' . $this->prefixed('system_menus') . '` WHERE `unique_auth` IN (' . implode(',', $quoted) . ')');

        foreach ([
            'yfth_franchise_identity_grant',
            'yfth_store_opening_acceptance_item',
            'yfth_store_opening_acceptance',
            'yfth_franchise_preparation_task_record',
            'yfth_franchise_preparation_task',
            'yfth_franchise_store_profile',
            'yfth_franchise_payment_proof',
            'yfth_franchise_contract',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop();
            }
        }
    }

    private function createContractTable(): void
    {
        if ($this->hasTable('yfth_franchise_contract')) {
            return;
        }
        $this->table('yfth_franchise_contract')
            ->setEngine('InnoDB')
            ->setComment('YFTH offline franchise contract records')
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'franchise application id'])
            ->addColumn('applicant_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB user uid'])
            ->addColumn('contract_no', 'string', ['limit' => 64, 'default' => '', 'comment' => 'contract number'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'draft', 'comment' => 'draft/pending_user_confirm/user_confirmed/hq_confirmed/signed'])
            ->addColumn('amount_snapshot', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'offline contract amount'])
            ->addColumn('attachment_ids', 'text', ['null' => true, 'comment' => 'contract attachment ids'])
            ->addColumn('signed_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'signed time'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'admin operator id'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['contract_no'], ['unique' => true, 'name' => 'uniq_yfth_franchise_contract_no'])
            ->addIndex(['application_id'], ['unique' => true, 'name' => 'uniq_yfth_franchise_contract_app'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_yfth_franchise_contract_status_time'])
            ->create();
    }

    private function createPaymentProofTable(): void
    {
        if ($this->hasTable('yfth_franchise_payment_proof')) {
            return;
        }
        $this->table('yfth_franchise_payment_proof')
            ->setEngine('InnoDB')
            ->setComment('YFTH offline franchise payment proof')
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'franchise application id'])
            ->addColumn('contract_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'franchise contract id'])
            ->addColumn('amount_snapshot', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00', 'comment' => 'offline paid amount'])
            ->addColumn('attachment_ids', 'text', ['null' => true, 'comment' => 'proof attachment ids'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending_upload', 'comment' => 'pending_upload/uploaded/rejected/finance_confirmed'])
            ->addColumn('finance_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'finance admin id'])
            ->addColumn('finance_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'finance confirm time'])
            ->addColumn('reject_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reject reason'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['application_id'], ['unique' => true, 'name' => 'uniq_yfth_payment_proof_app'])
            ->addIndex(['contract_id'], ['name' => 'idx_yfth_payment_proof_contract'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_yfth_payment_proof_status_time'])
            ->create();
    }

    private function createStoreProfileTable(): void
    {
        if ($this->hasTable('yfth_franchise_store_profile')) {
            return;
        }
        $this->table('yfth_franchise_store_profile')
            ->setEngine('InnoDB')
            ->setComment('YFTH franchise store preparation profile')
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'franchise application id'])
            ->addColumn('contract_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'franchise contract id'])
            ->addColumn('intended_store_type', 'string', ['limit' => 32, 'default' => '', 'comment' => 'intended store type'])
            ->addColumn('store_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'prepared store name'])
            ->addColumn('province', 'string', ['limit' => 64, 'default' => '', 'comment' => 'province'])
            ->addColumn('city', 'string', ['limit' => 64, 'default' => '', 'comment' => 'city'])
            ->addColumn('district', 'string', ['limit' => 64, 'default' => '', 'comment' => 'district'])
            ->addColumn('address', 'string', ['limit' => 255, 'default' => '', 'comment' => 'address'])
            ->addColumn('business_subject_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'business subject id'])
            ->addColumn('system_store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'bound CRMEB system_store id'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'draft', 'comment' => 'draft/submitted/verified/bound'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['application_id'], ['unique' => true, 'name' => 'uniq_yfth_store_profile_app'])
            ->addIndex(['system_store_id', 'status'], ['name' => 'idx_yfth_store_profile_system_store'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_yfth_store_profile_status_time'])
            ->create();
    }

    private function createPreparationTaskTable(): void
    {
        if ($this->hasTable('yfth_franchise_preparation_task')) {
            return;
        }
        $this->table('yfth_franchise_preparation_task')
            ->setEngine('InnoDB')
            ->setComment('YFTH franchise preparation tasks')
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'franchise application id'])
            ->addColumn('store_profile_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store profile id'])
            ->addColumn('task_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'task code'])
            ->addColumn('task_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'task name'])
            ->addColumn('required_flag', 'boolean', ['default' => 1, 'comment' => 'required flag'])
            ->addColumn('owner_type', 'string', ['limit' => 32, 'default' => 'applicant', 'comment' => 'applicant/headquarters'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending', 'comment' => 'pending/in_progress/submitted/approved/rejected'])
            ->addColumn('purchase_order_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'optional supply purchase order id'])
            ->addColumn('verified_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'review admin id'])
            ->addColumn('verified_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'review time'])
            ->addColumn('reject_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reject reason'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['application_id', 'task_code'], ['unique' => true, 'name' => 'uniq_yfth_preparation_app_task'])
            ->addIndex(['store_profile_id', 'status'], ['name' => 'idx_yfth_preparation_profile_status'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_yfth_preparation_status_time'])
            ->addIndex(['purchase_order_id'], ['name' => 'idx_yfth_preparation_purchase'])
            ->create();
    }

    private function createPreparationTaskRecordTable(): void
    {
        if ($this->hasTable('yfth_franchise_preparation_task_record')) {
            return;
        }
        $this->table('yfth_franchise_preparation_task_record')
            ->setEngine('InnoDB')
            ->setComment('YFTH franchise preparation task records')
            ->addColumn('task_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'task id'])
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'franchise application id'])
            ->addColumn('operator_type', 'string', ['limit' => 32, 'default' => '', 'comment' => 'applicant/headquarters'])
            ->addColumn('operator_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'operator id'])
            ->addColumn('action', 'string', ['limit' => 64, 'default' => '', 'comment' => 'action'])
            ->addColumn('content', 'text', ['null' => true, 'comment' => 'record content'])
            ->addColumn('attachment_ids', 'text', ['null' => true, 'comment' => 'evidence attachment ids'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addIndex(['task_id', 'create_time'], ['name' => 'idx_yfth_task_record_task_time'])
            ->addIndex(['application_id', 'create_time'], ['name' => 'idx_yfth_task_record_app_time'])
            ->addIndex(['operator_uid'], ['name' => 'idx_yfth_task_record_operator'])
            ->create();
    }

    private function createAcceptanceTable(): void
    {
        if ($this->hasTable('yfth_store_opening_acceptance')) {
            return;
        }
        $this->table('yfth_store_opening_acceptance')
            ->setEngine('InnoDB')
            ->setComment('YFTH store opening acceptance')
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'franchise application id'])
            ->addColumn('contract_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'contract id'])
            ->addColumn('store_profile_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'store profile id'])
            ->addColumn('system_store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'bound CRMEB system_store id'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'draft', 'comment' => 'draft/submitted/reviewing/passed/rejected/recheck_required'])
            ->addColumn('reviewer_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'review admin id'])
            ->addColumn('review_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'review time'])
            ->addColumn('reject_reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reject reason'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['application_id'], ['unique' => true, 'name' => 'uniq_yfth_acceptance_app'])
            ->addIndex(['system_store_id', 'status'], ['name' => 'idx_yfth_acceptance_store_status'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_yfth_acceptance_status_time'])
            ->create();
    }

    private function createAcceptanceItemTable(): void
    {
        if ($this->hasTable('yfth_store_opening_acceptance_item')) {
            return;
        }
        $this->table('yfth_store_opening_acceptance_item')
            ->setEngine('InnoDB')
            ->setComment('YFTH store opening acceptance items')
            ->addColumn('acceptance_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'acceptance id'])
            ->addColumn('item_code', 'string', ['limit' => 64, 'default' => '', 'comment' => 'item code'])
            ->addColumn('item_name', 'string', ['limit' => 128, 'default' => '', 'comment' => 'item name'])
            ->addColumn('result', 'string', ['limit' => 32, 'default' => 'pending', 'comment' => 'pending/pass/fail'])
            ->addColumn('evidence_attachment_ids', 'text', ['null' => true, 'comment' => 'evidence attachment ids'])
            ->addColumn('reviewer_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'review admin id'])
            ->addColumn('review_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'review time'])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => 'remark'])
            ->addIndex(['acceptance_id', 'item_code'], ['unique' => true, 'name' => 'uniq_yfth_acceptance_item_code'])
            ->create();
    }

    private function createIdentityGrantTable(): void
    {
        if ($this->hasTable('yfth_franchise_identity_grant')) {
            return;
        }
        $this->table('yfth_franchise_identity_grant')
            ->setEngine('InnoDB')
            ->setComment('YFTH franchise identity grant records')
            ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'franchise application id'])
            ->addColumn('acceptance_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'acceptance id'])
            ->addColumn('target_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'target CRMEB user uid'])
            ->addColumn('store_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'CRMEB system_store id'])
            ->addColumn('role_code', 'string', ['limit' => 32, 'default' => '', 'comment' => 'franchisee/store_manager'])
            ->addColumn('store_role_id', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'yfth_user_store_role id'])
            ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending', 'comment' => 'pending/active/revoked'])
            ->addColumn('grant_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'grant admin id'])
            ->addColumn('grant_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'grant time'])
            ->addColumn('revoke_uid', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'revoke admin id'])
            ->addColumn('revoke_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'revoke time'])
            ->addColumn('reason', 'string', ['limit' => 255, 'default' => '', 'comment' => 'reason'])
            ->addColumn('active_key', 'string', ['limit' => 191, 'null' => true, 'default' => null, 'comment' => 'active grant key'])
            ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'created at'])
            ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0, 'comment' => 'updated at'])
            ->addIndex(['application_id', 'role_code'], ['name' => 'idx_yfth_identity_grant_app_role'])
            ->addIndex(['target_uid', 'store_id', 'role_code'], ['name' => 'idx_yfth_identity_grant_target_store'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uniq_yfth_identity_grant_active'])
            ->create();
    }

    private function seedMenus(): void
    {
        $rootId = $this->ensureRoot();
        $pageId = $this->upsertMenu([
            'pid' => $rootId,
            'icon' => 'md-checkbox',
            'menu_name' => 'Franchise Opening',
            'module' => 'admin',
            'controller' => 'v1.yfth.FranchiseOpening',
            'action' => 'index',
            'api_url' => 'yfth/franchise_opening/contract',
            'methods' => 'GET',
            'params' => '',
            'sort' => 8,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth/franchise-opening',
            'path' => (string)$rootId,
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => 'yfth-franchise-opening-index',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);

        foreach ([
            $this->apiRow($pageId, 'Opening contract list', 'yfth/franchise_opening/contract', 'GET', 'yfth-franchise-opening-contract-list'),
            $this->apiRow($pageId, 'Opening contract detail', 'yfth/franchise_opening/contract/<id>', 'GET', 'yfth-franchise-opening-contract-detail'),
            $this->apiRow($pageId, 'Opening contract create', 'yfth/franchise_opening/contract/create', 'POST', 'yfth-franchise-opening-contract-create'),
            $this->apiRow($pageId, 'Opening contract confirm', 'yfth/franchise_opening/contract/<id>/confirm', 'POST', 'yfth-franchise-opening-contract-confirm'),
            $this->apiRow($pageId, 'Opening payment list', 'yfth/franchise_opening/payment', 'GET', 'yfth-franchise-opening-payment-list'),
            $this->apiRow($pageId, 'Opening payment confirm', 'yfth/franchise_opening/payment/<id>/confirm', 'POST', 'yfth-franchise-opening-payment-confirm'),
            $this->apiRow($pageId, 'Opening payment reject', 'yfth/franchise_opening/payment/<id>/reject', 'POST', 'yfth-franchise-opening-payment-reject'),
            $this->apiRow($pageId, 'Opening profile detail', 'yfth/franchise_opening/profile/<application_id>', 'GET', 'yfth-franchise-opening-profile-detail'),
            $this->apiRow($pageId, 'Opening profile save', 'yfth/franchise_opening/profile/save', 'POST', 'yfth-franchise-opening-profile-save'),
            $this->apiRow($pageId, 'Opening profile bind store', 'yfth/franchise_opening/profile/<id>/bind_store', 'POST', 'yfth-franchise-opening-profile-bind-store'),
            $this->apiRow($pageId, 'Opening task list', 'yfth/franchise_opening/task', 'GET', 'yfth-franchise-opening-task-list'),
            $this->apiRow($pageId, 'Opening task review', 'yfth/franchise_opening/task/<id>/review', 'POST', 'yfth-franchise-opening-task-review'),
            $this->apiRow($pageId, 'Opening acceptance list', 'yfth/franchise_opening/acceptance', 'GET', 'yfth-franchise-opening-acceptance-list'),
            $this->apiRow($pageId, 'Opening acceptance detail', 'yfth/franchise_opening/acceptance/<id>', 'GET', 'yfth-franchise-opening-acceptance-detail'),
            $this->apiRow($pageId, 'Opening acceptance review', 'yfth/franchise_opening/acceptance/<id>/review', 'POST', 'yfth-franchise-opening-acceptance-review'),
            $this->apiRow($pageId, 'Opening identity grant', 'yfth/franchise_opening/identity_grant', 'POST', 'yfth-franchise-opening-identity-grant'),
        ] as $row) {
            $this->upsertMenu($row);
        }
    }

    private function ensureRoot(): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $root = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote('yfth-foundation') . ' LIMIT 1');
        if ($root) {
            return (int)$root['id'];
        }
        return $this->upsertMenu([
            'pid' => 0,
            'icon' => 'md-git-network',
            'menu_name' => 'YFTH',
            'module' => 'admin',
            'controller' => '',
            'action' => '',
            'api_url' => '',
            'methods' => 'GET',
            'params' => '',
            'sort' => 32,
            'is_show' => 1,
            'is_show_path' => 1,
            'access' => 1,
            'menu_path' => '/yfth',
            'path' => '/yfth',
            'auth_type' => 1,
            'header' => 'yfth',
            'is_header' => 1,
            'unique_auth' => 'yfth-foundation',
            'is_del' => 0,
            'mark' => 'yfth',
        ]);
    }

    private function apiRow(int $pid, string $name, string $url, string $method, string $auth): array
    {
        return [
            'pid' => $pid,
            'icon' => '',
            'menu_name' => $name,
            'module' => 'admin',
            'controller' => 'v1.yfth.FranchiseOpening',
            'action' => '',
            'api_url' => $url,
            'methods' => $method,
            'params' => '',
            'sort' => 0,
            'is_show' => 0,
            'is_show_path' => 0,
            'access' => 1,
            'menu_path' => '',
            'path' => (string)$pid,
            'auth_type' => 2,
            'header' => 'yfth',
            'is_header' => 0,
            'unique_auth' => $auth,
            'is_del' => 0,
            'mark' => 'yfth',
        ];
    }

    private function upsertMenu(array $row): int
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        $existing = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        if ($existing) {
            $sets = [];
            foreach ($row as $field => $value) {
                if ($field === 'unique_auth') {
                    continue;
                }
                $sets[] = '`' . $field . '` = ' . $this->quote($value);
            }
            $this->execute('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ' . (int)$existing['id']);
            return (int)$existing['id'];
        }

        $fields = array_map(function ($field) {
            return '`' . $field . '`';
        }, array_keys($row));
        $values = array_map(function ($value) {
            return $this->quote($value);
        }, array_values($row));
        $this->execute('INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')');
        $created = $this->getAdapter()->fetchRow('SELECT `id` FROM ' . $table . ' WHERE `unique_auth` = ' . $this->quote($row['unique_auth']) . ' LIMIT 1');
        return (int)$created['id'];
    }

    private function quote($value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
