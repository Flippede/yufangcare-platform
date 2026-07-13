# YFTH Headquarters Mall Stage 3 Mall Consumption Reward Architecture

## 1. Status And Scope

Stage 3 connects real CRMEB headquarters-mall ordinary-order payment and full-refund events to the existing Stage 2 direct-referral reward candidate domain. Development is complete on `codex/yfth-hq-mall-stage3-mall-consumption-reward-v1` from stable baseline `423a1d3ac03d0c4771ac4350334e95c3c2509b3e` and awaits independent architecture review. It is not merged or deployed.

The candidate is a pending allocation observation inside B1's business economics. It is not money received by C1 and does not create a headquarters duplicate cost, wallet entry, commission, settlement, withdrawal or payout.

## 2. Reused Authorities

- CRMEB `store_order` remains the only order and payment/refund authority.
- CRMEB payment-success and refund-success events remain the trigger points; their core transaction and state machines are not rewritten.
- Stage 1A attribution current/event tables remain the C1/C2 permanent B1 authority.
- Stage 1A active-referral current/event tables remain the one-level referral authority.
- Stage 2 permanent membership remains C2 membership authority.
- Stage 2 versioned direct-referral rules provide `mall_consumption_ratio_bps`.
- Stage 2 `yfth_direct_referral_reward_candidate` and its source unique guard store the pending candidate.
- Existing audit and trusted user/store/headquarters read surfaces are reused.

CRMEB `store_order.store_id` is not treated as B1 attribution. In the unified headquarters mall it may describe order/pickup metadata; B1 is derived only from consistent Stage 1A authority for both C1 and C2.

## 3. Payment Event Flow

`MallConsumptionRewardPayListener` receives the real CRMEB payment-success event and calls `DirectReferralRewardServices::recordMallOrderPaid()`. It catches and logs extension failure so an unavailable ratio, ineligible relation or extension exception cannot turn a successful CRMEB payment into a failed payment.

The service accepts only an order that is all of the following:

- paid;
- top-level (`pid = 0`);
- non-package;
- not refunded;
- not user-deleted or system-deleted;
- not cancelled;
- in a valid non-negative order status;
- positive actual paid amount.

It first resolves an existing source-key candidate for idempotent event replay. Otherwise it pre-reads the active referral and active rule without writing, then enters one transaction and uses the Stage 2 lock order:

1. lock C2 attribution as the shared serialization gate;
2. lock and revalidate the exact referral current/event consistency;
3. lock C1 attribution;
4. require both attributions active and assigned to the referral B1;
5. require C2 still not to have effective permanent membership;
6. lock and revalidate the active versioned reward rule;
7. allocate the next C1 sequence and insert the pending candidate.

Missing rules and ordinary ineligible orders are safe skips. Inconsistent authority fails closed. No path creates attribution/referral placeholders or mutates authority current/event state.

## 4. Candidate Snapshot And Idempotency

Candidate type is `mall_consumption`. Its immutable business snapshot contains:

- C1 referrer UID;
- C2 referred UID;
- B1 store ID;
- CRMEB order ID and source unique key;
- actual paid amount in integer cents;
- configured ratio in basis points;
- calculated amount in integer cents;
- exact rule-version ID;
- status `pending`.

The existing unique source key prevents duplicate candidates for one order. Sequence allocation remains protected by the existing referrer/sequence unique constraint. No hardcoded ratio exists in the listener or service.

## 5. Full Refund Boundary

`MallConsumptionRewardCustomEventListener` consumes only CRMEB `admin_order_refund_success`. It resolves the real main order and cancels a matching ordinary-mall candidate only when CRMEB records a full refund (`refund_status = 2` and refund amount covers actual paid amount).

Only `pending` candidates transition to `cancelled`; repeated notifications return the existing cancelled record without duplicate audit. Partial refund leaves the pending candidate unchanged because proportional reversal is outside V1. Refund never changes permanent membership and never restores a closed referral.

## 6. Read And Page Surfaces

- C1 uses the existing authenticated own-candidate API and minimum uni-app page.
- B1 `franchisee` and `store_manager` use the existing trusted-store candidate API and workbench page.
- Headquarters uses the existing explicitly authorized candidate API and Admin page.

All three pages label the result as pending confirmation and not paid. The store is derived from trusted server context, and cross-store access remains denied. Role-specific DTO allowlists remain in force; raw models, other-user private data, tokens and internal idempotency/audit payloads are not exposed.

## 7. Schema And Compatibility

Stage 3 adds no table, column, index, migration, permission, Controller or route. It reuses Stage 2 schema and existing read APIs. The Stage 1A source guard explicitly allowlists only the dedicated Stage 3 listeners and event registration while retaining its general entry-point prohibition.

The implementation does not write CRMEB balance, points, brokerage, distribution, sales, SKU stock or YFTH inventory. It does not modify package activation, membership permanence, referral closure or package 15/25/60 candidate calculations.

## 8. Deferred Work And Gate

Deferred: candidate confirmation/finalization, settlement, payout, withdrawal, accounting reconciliation, partial-refund reversal, referral restoration, store takeover, city partner and multi-level referral.

The next gate is an independent read-only architecture review. Before it passes, the branch must not be merged into `main` and no production migration or deployment is authorized.
