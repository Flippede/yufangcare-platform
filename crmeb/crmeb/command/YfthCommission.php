<?php

namespace crmeb\command;

use app\services\yfth\AutomaticCommissionServices;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class YfthCommission extends Command
{
    protected function configure()
    {
        $this->setName('yfth:commission-settle')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'due accrual batch limit', 100)
            ->setDescription('Settle due YFTH automatic commission accruals');
    }

    protected function execute(Input $input, Output $output)
    {
        $result = app()->make(AutomaticCommissionServices::class)->processDue((int)$input->getOption('limit'));
        $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
