<?php

use think\migration\Migrator;

class ProductizeYfthHqAdminMenus extends Migrator
{
    private $upNames = [
        'yfth-foundation' => ['menu_name' => '御方通和', 'sort' => 90],
        'yfth-foundation-index' => ['menu_name' => '业务基础域', 'sort' => 30],
        'yfth-package-benefit-index' => ['menu_name' => '套餐与权益', 'sort' => 20],
        'yfth-service-appointment-index' => ['menu_name' => '服务预约与核销', 'sort' => 10],
    ];

    private $downNames = [
        'yfth-foundation' => ['menu_name' => 'YFTH', 'sort' => 32],
        'yfth-foundation-index' => ['menu_name' => 'Foundation', 'sort' => 10],
        'yfth-package-benefit-index' => ['menu_name' => 'Package Benefits', 'sort' => 20],
        'yfth-service-appointment-index' => ['menu_name' => 'Service Appointment', 'sort' => 30],
    ];

    public function up()
    {
        $this->renameMenus($this->upNames);
    }

    public function down()
    {
        $this->renameMenus($this->downNames);
    }

    private function renameMenus(array $names): void
    {
        $table = '`' . $this->prefixed('system_menus') . '`';
        foreach ($names as $auth => $data) {
            $sets = [
                '`menu_name` = ' . $this->quote($data['menu_name']),
                '`sort` = ' . (int)$data['sort'],
            ];
            $this->execute(
                'UPDATE ' . $table .
                ' SET ' . implode(', ', $sets) .
                ' WHERE `unique_auth` = ' . $this->quote($auth)
            );
        }
    }

    private function quote($value): string
    {
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    private function prefixed(string $table): string
    {
        $adapter = $this->getAdapter();
        $prefix = method_exists($adapter, 'getOption') ? (string)$adapter->getOption('table_prefix') : '';
        return $prefix . $table;
    }
}
