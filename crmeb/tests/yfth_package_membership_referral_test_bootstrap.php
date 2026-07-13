<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use think\App;

function packageMembershipReferralBootTestApp(): App
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
