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
            'activated' => ['refunding', 'refunded', 'closed'],
            'refunding' => ['activated', 'refunded', 'refund_failed', 'closed'],
            'refund_failed' => ['activated', 'refunding'],
            'refunded' => [],
            'closed' => [],
        ],
        'instance' => [
            'active' => ['refunding', 'refunded', 'closed', 'expired'],
            'refunding' => ['active', 'refunded', 'refund_failed', 'closed'],
            'refund_failed' => ['active', 'refunding'],
            'expired' => ['closed'],
            'refunded' => [],
            'closed' => [],
        ],
        'plan' => [
            'active' => ['refunding', 'refunded', 'closed', 'expired'],
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
