<?php

namespace app\services\yfth;

use crmeb\exceptions\ApiException;

class PackageBenefitStateMachine
{
    private $transitions = [
        'purchase' => [
            'created' => ['wait_pay', 'closed', 'refunding'],
            'wait_pay' => ['paid', 'activated', 'closed', 'refunding', 'refunded'],
            'paid' => ['activated', 'refunding', 'refunded'],
            'activated' => ['refunding', 'refunded', 'closed', 'closed_after_partial_refund'],
            'refunding' => ['activated', 'refunded', 'refund_failed', 'closed', 'closed_after_partial_refund'],
            'refund_failed' => ['activated', 'refunding'],
            'closed_after_partial_refund' => [],
            'refunded' => [],
            'closed' => [],
        ],
        'instance' => [
            'active' => ['refunding', 'refunded', 'closed', 'expired', 'frozen', 'suspended'],
            'refunding' => ['active', 'refunded', 'refund_failed', 'closed'],
            'refund_failed' => ['active', 'refunding'],
            'frozen' => ['active', 'refunding', 'refunded', 'closed', 'expired'],
            'suspended' => ['active', 'refunding', 'refunded', 'closed', 'expired'],
            'expired' => ['closed'],
            'refunded' => [],
            'closed' => [],
        ],
        'plan' => [
            'active' => ['paused', 'refunding', 'refunded', 'closed', 'expired'],
            'paused' => ['active', 'refunding', 'refunded', 'closed', 'expired'],
            'refunding' => ['active', 'refunded', 'refund_failed', 'closed'],
            'refund_failed' => ['active', 'refunding'],
            'expired' => ['closed'],
            'refunded' => [],
            'closed' => [],
        ],
        'period' => [
            'unopened' => ['available', 'closed', 'refunded'],
            'available' => ['expired', 'closed', 'refunded'],
            'expired' => ['closed', 'refunded'],
            'closed' => [],
            'refunded' => [],
        ],
        'item' => [
            'unopened' => ['available', 'closed', 'refunded'],
            'available' => ['used', 'expired', 'closed', 'refunded'],
            'used' => ['closed', 'refunded'],
            'expired' => ['closed', 'refunded'],
            'closed' => [],
            'refunded' => [],
        ],
    ];

    public function assertTransition(string $machine, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        if (!isset($this->transitions[$machine][$from]) || !in_array($to, $this->transitions[$machine][$from], true)) {
            throw new ApiException('invalid_' . $machine . '_status_transition:' . $from . '_to_' . $to);
        }
    }

    public function all(): array
    {
        return $this->transitions;
    }
}
