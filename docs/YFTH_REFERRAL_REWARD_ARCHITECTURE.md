# YFTH Referral Relationship And Read-only Reward Ledger V1

## Scope

This V1 establishes an independent YFTH referral and reward-ledger domain:

- Referral code and link source.
- Referral candidate relation.
- C-side `package_5980` attribution.
- B-side `franchise_opening` attribution.
- Idempotent referral event.
- Immutable published reward rule version and rule snapshot.
- Observing period.
- Read-only reward ledger.
- Headquarters offline settlement marker.
- Append-only manual reversal/adjustment record.
- User-side and franchisee-side read-only display.
- Headquarters rule, candidate, event, attribution, ledger, settlement, reverse, and scan page/API.

It does not implement automatic payment, withdrawal, revenue sharing, product quota return, CRMEB distribution settlement, or online settlement.

## Tables

- `yfth_referral_code`: server-generated referral codes. Owner uid, role, and store are resolved on the server.
- `yfth_referral_candidate`: candidate relation. It is not an effective reward. Active uniqueness is guarded by `active_key`.
- `yfth_referral_event`: idempotent business events. Uniqueness is `scene + event_type + idempotency_key`.
- `yfth_referral_attribution`: final read-only attribution binding a candidate to a business object.
- `yfth_reward_rule_version`: reward rule versions. Published versions are immutable.
- `yfth_reward_rule_item`: fixed rule items. Amounts use integer `amount_cent`.
- `yfth_reward_ledger`: read-only reward ledger. It is not a withdrawable balance.
- `yfth_reward_ledger_snapshot`: append-only rule/referral/business snapshots.
- `yfth_reward_adjustment`: append-only reverse, void, manual adjustment, and remark records.
- `yfth_reward_settlement_record`: headquarters offline settlement marker, not system payment.

## State Machines

Candidate:

`candidate -> registered -> bound -> attributed -> expired / invalid`

C-side package flow:

`candidate -> registered -> package_paid -> package_activated -> observing -> valid -> pending_settlement -> settled`

B-side franchise flow:

`application_submitted -> contacting -> inspecting -> pending_contract -> signed -> preparing -> opened -> observing -> valid -> pending_settlement -> settled`

Only `package_activated` and `franchise_opened` create an observing ledger in V1. Registration, application submission, signed, and preparing states do not create an effective reward.

Negative events such as package refund/close/freeze or franchise termination/revoke reverse existing ledgers through append-only adjustment records.

## Attribution Boundary

C-side attribution is allowed only when the referred uid has an active candidate in `package_5980` and the business object belongs to that user.

B-side attribution is allowed only when the referred uid has an active candidate in `franchise_opening` and the business object is that user's franchise application/opening/store object.

Attribution only binds YFTH referral records to existing business objects. It does not change package, refund, franchise application, contract, payment, acceptance, opening, supply-chain, or inventory state.

## Reward Rules

Rules are created as drafts. Publishing a rule makes the version immutable. Later edits must copy a new draft version.

Ledgers store rule snapshots through `yfth_reward_ledger_snapshot`; they do not read mutable rule definitions after creation.

Amounts use integer cents (`amount_cent`) only. The service does not use PHP float money calculation.

## Read-only Ledger Boundary

`yfth_reward_ledger` records the reward observation and confirmation state only:

- `observing`
- `valid`
- `pending_settlement`
- `settled`
- `invalid`
- `reversed`

`settled` means headquarters marked an offline settlement record. It does not mean the system paid money.

The reward domain does not write:

- CRMEB `user_spread`
- CRMEB `user_brokerage`
- CRMEB `user_bill`
- CRMEB `now_money`
- integral/points
- CRMEB withdrawal records
- CRMEB `store_order`

It also does not call CRMEB withdrawal, commission, balance, order, payment, refund, or stock mutation services.

## APIs

User-token APIs:

- `POST /api/yfth/referral/code`
- `GET /api/yfth/referral/code`
- `POST /api/yfth/referral/bind`
- `GET /api/yfth/referral/candidates`
- `GET /api/yfth/referral/ledger`
- `GET /api/yfth/referral/ledger/:id`

Headquarters admin-token APIs:

- `GET /adminapi/yfth/referral_reward/rule`
- `POST /adminapi/yfth/referral_reward/rule`
- `POST /adminapi/yfth/referral_reward/rule/:id/publish`
- `POST /adminapi/yfth/referral_reward/rule/:id/copy`
- `GET /adminapi/yfth/referral_reward/candidate`
- `GET /adminapi/yfth/referral_reward/event`
- `GET /adminapi/yfth/referral_reward/attribution`
- `GET /adminapi/yfth/referral_reward/ledger`
- `GET /adminapi/yfth/referral_reward/ledger/:id`
- `POST /adminapi/yfth/referral_reward/ledger/:id/settle`
- `POST /adminapi/yfth/referral_reward/ledger/:id/cancel_settlement`
- `POST /adminapi/yfth/referral_reward/ledger/:id/reverse`
- `POST /adminapi/yfth/referral_reward/scan`

## Permissions

User APIs use CRMEB user token. They forbid client-submitted `owner_uid`, `referrer_uid`, `store_id`, reward amount, ledger status, settlement status, and reverse status.

Headquarters APIs use `AdminAuthTokenMiddleware`, `AdminCheckRoleMiddleware`, explicit `SystemRoleServices::assertApiAuthForAdmin()`, and service-level `AdminStoreContextServices::assertHeadquarterScope()`.

Store-scoped backend accounts cannot access headquarters referral reward management.

## Audit And Idempotency

Audit domain: `yfth_referral_reward`.

Core audit actions:

- `referral_code_create`
- `referral_candidate_bind`
- `referral_event_record`
- `referral_attribution_create`
- `reward_rule_create`
- `reward_rule_publish`
- `reward_rule_copy`
- `reward_ledger_create`
- `reward_ledger_valid`
- `reward_settlement_mark`
- `reward_settlement_cancel`
- `reward_reverse`
- `reward_scan`

Snapshots are sanitized through the existing `YfthFoundationBaseServices::sanitizeState()` path. Payload snapshots must not store raw phone numbers, openid, unionid, identity data, payment data, secrets, tokens, API keys, or attachments.

Idempotency and duplicate guards:

- Referral event: `scene + event_type + idempotency_key`.
- Ledger: `scene + business_type + business_id + referrer_uid + rule_item_id`.
- Candidate active relation: `scene + referred_uid` or server-side phone hash key.
- Settlement marker: one active settlement key per ledger.
- Adjustment: append-only, no physical deletion of the original ledger.

## Frontend

Admin page:

- `template/admin/src/pages/yfth/referralReward/index.vue`

Uni-app pages:

- `template/uni-app/pages/yfth/referral/index.vue`
- `template/uni-app/pages/yfth/referral/code.vue`
- `template/uni-app/pages/yfth/referral/candidates.vue`
- `template/uni-app/pages/yfth/referral/ledger.vue`
- `template/uni-app/pages/yfth/referral/ledger_detail.vue`

The mobile pages avoid withdrawal, wallet-arrival, and cash-balance language. They use "奖励台账", "总部确认状态", and "线下结算状态".

## Verification

Added checks:

- `crmeb/tests/yfth_referral_reward_contract_check.php`
- `crmeb/tests/yfth_referral_reward_real_flow_check.php`

They validate migration shape, indexes, permissions, user-forbidden fields, admin permission assertions, published-rule immutability, integer-cent amounts, event idempotency, read-only ledger snapshots, append-only adjustments, offline settlement markers, and CRMEB distribution/balance/order/stock non-mutation boundaries.

## Not Implemented

- Automatic cash payment.
- Withdrawal.
- CRMEB distribution or brokerage integration.
- Online settlement or revenue sharing.
- Product quota return.
- Recommendation reward automatic payment.
- Complex multi-level team reward.
- Full external event listener integration into package/franchise flows.
- Production deployment.
- Production database migration.
