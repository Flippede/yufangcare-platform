<?php

namespace crmeb\command;

use app\services\yfth\PackageActivationRecoveryServices;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

class YfthPackage extends Command
{
    protected function configure()
    {
        $this->setName('yfth:package')
            ->addArgument('action', Argument::REQUIRED, 'recover-activation')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'batch limit', 50)
            ->setDescription('YFTH package maintenance commands');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = (string)$input->getArgument('action');
        if ($action !== 'recover-activation') {
            $output->error('unsupported_action');
            return;
        }
        $limit = (int)$input->getOption('limit');
        /** @var PackageActivationRecoveryServices $services */
        $services = app()->make(PackageActivationRecoveryServices::class);
        $result = $services->recoverPaidUnactivated($limit, 0, 'console');
        $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}
