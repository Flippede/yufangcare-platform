<?php

use Phinx\Migration\AbstractMigration;

final class CreateYlzFranchisePortalProfiles extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('yfth_franchise_application_profile')) {
            $this->table('yfth_franchise_application_profile')
                ->setEngine('InnoDB')
                ->setComment('Yang Lang Zhong franchise portal structured application profile')
                ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('form_version', 'string', ['limit' => 32, 'default' => 'ylz-v1'])
                ->addColumn('marital_status', 'string', ['limit' => 24, 'default' => ''])
                ->addColumn('household_income', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('education', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('knows_related_brands', 'string', ['limit' => 8, 'default' => ''])
                ->addColumn('store_experience', 'string', ['limit' => 8, 'default' => ''])
                ->addColumn('health_store_experience', 'string', ['limit' => 8, 'default' => ''])
                ->addColumn('career_status', 'string', ['limit' => 48, 'default' => ''])
                ->addColumn('source_channel', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('store_type', 'string', ['limit' => 24, 'default' => ''])
                ->addColumn('budget_range', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('opening_plan', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('site_status', 'string', ['limit' => 32, 'default' => ''])
                ->addColumn('privacy_agreed_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('submit_snapshot', 'text', ['null' => true])
                ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('update_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['application_id'], ['unique' => true, 'name' => 'uniq_yfth_franchise_profile_application'])
                ->create();
        }

        if (!$this->hasTable('yfth_franchise_application_attachment')) {
            $this->table('yfth_franchise_application_attachment')
                ->setEngine('InnoDB')
                ->setComment('Private attachment metadata for franchise applications')
                ->addColumn('application_id', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('uploader_uid', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('category', 'string', ['limit' => 32, 'default' => 'other'])
                ->addColumn('storage_disk', 'string', ['limit' => 32, 'default' => 'private_oss'])
                ->addColumn('object_key', 'string', ['limit' => 255, 'default' => ''])
                ->addColumn('original_name', 'string', ['limit' => 160, 'default' => ''])
                ->addColumn('mime_type', 'string', ['limit' => 80, 'default' => ''])
                ->addColumn('file_size', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('status', 'string', ['limit' => 24, 'default' => 'active'])
                ->addColumn('create_time', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('delete_time', 'integer', ['signed' => false, 'default' => 0])
                ->addIndex(['application_id', 'category', 'status'], ['name' => 'idx_yfth_franchise_attachment_application'])
                ->addIndex(['object_key'], ['unique' => true, 'name' => 'uniq_yfth_franchise_attachment_object'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('yfth_franchise_application_attachment')) {
            $this->table('yfth_franchise_application_attachment')->drop();
        }
        if ($this->hasTable('yfth_franchise_application_profile')) {
            $this->table('yfth_franchise_application_profile')->drop();
        }
    }
}
