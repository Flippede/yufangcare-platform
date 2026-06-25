<?php

namespace crmeb\command;

use app\services\yfth\PackageActivationRecoveryServices;
use app\services\yfth\PackagePurchaseServices;
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
            ->addArgument('action', Argument::REQUIRED, 'recover-activation|scan-orphan-orders')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'batch limit', 50)
            ->addOption('close', null, Option::VALUE_NONE, 'close payable orphan package intent orders')
            ->setDescription('YFTH package maintenance commands');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = (string)$input->getArgument('action');
        $limit = (int)$input->getOption('limit');
        if ($action === 'recover-activation') {
            /** @var PackageActivationRecoveryServices $services */
            $services = app()->make(PackageActivationRecoveryServices::class);
            $result = $services->recoverPaidUnactivated($limit, 0, 'console');
            $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($action === 'scan-orphan-orders') {
            /** @var PackagePurchaseServices $services */
            $services = app()->make(PackagePurchaseServices::class);
            $result = $services->scanUnboundPackageIntentOrders($limit, (bool)$input->getOption('close'), 0);
            $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE));
            return;
        }
        if (!in_array($action, ['recover-activation', 'scan-orphan-orders'], true)) {
            $output->error('unsupported_action');
            return;
        }
    }
}
