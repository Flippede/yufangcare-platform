# YFTH Headquarters Mall Stage 4 Reward Settlement Ledger V1

## Scope

Stage 4 adds the smallest accountable handling flow for existing direct-referral reward candidates. It accepts both immutable `package_activation` 15/25/60 candidates and Stage 3 `mall_consumption` candidates. It does not calculate or alter any amount, ratio, rule version, source order, package, C1, C2, or B1 snapshot.

The branch is `codex/yfth-hq-mall-stage4-reward-settlement-ledger-v1`, based on stable main `2e3d1de8ad204c61d3b2f0b75aee24135a1cb89d`. It is not merged, deployed, or connected to production.

## State And Responsibility

`yfth_direct_referral_reward_candidate.status` is the workflow authority:

| State | Meaning | Allowed transition |
| --- | --- | --- |
| `pending` | B1 responsibility awaiting review | B1 confirms; headquarters cancels |
| `confirmed` | B1 approved the fixed candidate | B1 records offline settlement; headquarters cancels or returns it to pending |
| `settled` | A single immutable offline settlement fact exists | terminal |
| `cancelled` | Candidate is invalid or cancelled | terminal |

B1 is the candidate `store_id`; the platform does not automatically pay C1. C1 has read-only access to their own candidate list. Trusted `franchisee` and `store_manager` contexts can operate only their server-resolved store. Headquarters has explicit API permissions for list, exception cancellation and exception correction. The browser never supplies the effective store, operator, candidate amount, ratio, status, C1 or C2 authority.

## Settlement Fact

Migration `20260717100000_create_yfth_reward_settlement_ledger.php` creates `yfth_direct_referral_reward_settlement_ledger`.

- One row per candidate is enforced by `uniq_yfth_direct_settlement_candidate`.
- The ledger snapshots candidate/store/user/type/amount and records `offline_ref_no`, `proof_ref`, mandatory explanation, operator UID/role and `settled_at`.
- It records an offline business fact only. It creates no wallet balance, brokerage, points, distribution, withdrawal, payout or automatic transfer.
- Candidate mutation and audit use the candidate row lock and the existing `IdempotencyRecordServices`; duplicate requests replay the saved result and cannot create another ledger row.

## Refund Boundary

Stage 3 full-refund handling now cancels an ordinary-mall candidate while it is `pending` or `confirmed`. It keeps a `settled` candidate unchanged because reversal, recovery and partial-refund proportional treatment are expressly out of scope.

## Surfaces

- C1 continues to see only their own reward candidates and the four workflow states.
- The store workbench lists trusted-store candidates, confirms pending candidates and records a line of offline settlement evidence only after confirmation.
- Headquarters lists/filter candidates, sees settlement reference metadata, and can cancel or return a confirmed candidate with a mandatory exception reason.

All pages explicitly describe the record as a pending/offline settlement status, not automatic platform payment.

## Non-Goals

No automatic payout, wallet, withdrawal, WeChat/Alipay split, partial refund reversal, reconciliation system, store takeover, city partner, multi-level referral, order listener redesign, production migration or production rollout is in this stage.
