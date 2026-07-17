<?php

use think\facade\Env;

return [
    'acceptance_fixture_enabled' => Env::get('yfth.acceptance_fixture_enabled', false),
    'acceptance_account_file' => Env::get(
        'yfth.acceptance_account_file',
        root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'yfth-acceptance-test-accounts.txt'
    ),
    'user_account_closure_enabled' => Env::get('yfth.user_account_closure_enabled', true),
];
