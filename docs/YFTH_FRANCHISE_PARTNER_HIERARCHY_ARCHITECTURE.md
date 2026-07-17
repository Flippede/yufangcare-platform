# YFTH Franchise Opening And Five-level Partner Hierarchy V1

## Scope And Authority

This document is the current authority for the partner extension of the earlier franchise-opening V1. The existing `franchisee` row remains only as a store-operation compatibility permission. Product qualification is held by `yfth_partner_profile` with five ranks: `county_partner`, `prefecture_partner`, `province_partner`, `regional_director`, and `platform_director`.

Permanent membership and first-level customer referral are separate domains. Partner hierarchy never writes CRMEB legacy distribution fields.

## Formal Opening

`partner QR or headquarters direct -> application -> materials -> contract -> offline payment proof -> finance confirmation -> preparation -> acceptance -> headquarters creates/enables store -> county partner grant -> optional manager grant -> opened`

Finance confirmation freezes `yfth_franchise_recruit_source`. Before freeze, headquarters may correct the direct source with a reason. After freeze, source and full chain are immutable. The QR itself cannot confirm payment, create a store, pass acceptance, or grant a role.

Acceptance requires a signed contract, finance-confirmed payment, verified preparation profile, and all required pre-opening tasks approved. `first_purchase` remains an optional post-store supply-chain task because a purchase order requires a real store. Store creation locks the application and preparation-profile rows and is idempotent. Identity grant requires a passed acceptance and an active bound CRMEB store.

## Tables

- Rules: `yfth_partner_rule_version`, `yfth_partner_rank_rule`.
- Current and immutable history: `yfth_partner_profile`, `yfth_partner_relation`, `yfth_partner_rank_event`.
- Recruitment: `yfth_partner_invite`, `yfth_franchise_recruit_source`.
- Opening result: `yfth_partner_opening_performance`.
- Rewards: `yfth_partner_reward_candidate`, `yfth_partner_reward_settlement`.
- Governance: `yfth_partner_warning`, `yfth_partner_promotion_application`.

Current rows have unique active keys. Relations permit one current direct parent and reject self/cyclic parent changes. Promotion applications allow one pending request per partner and only headquarters approval changes the rank.

## Rules And Snapshots

Published rules are versioned. V1 defaults are 89,100 yuan, 440 bottles, platform weighted-dividend qualification 100 BPS, and per-bottle candidate values 40/17/10/8/5 yuan.

At formal opening, the system stores the rule version, amount, bottle count, direct recruiter, full frozen chain, rank at freeze, and store/application identifiers. Later rule, rank, or parent changes never rewrite old performance or candidates. Only real active profiles present in the frozen chain receive candidates; missing levels are not synthesized.

## Reward And Governance Boundary

Candidates use `pending -> confirmed -> settled` or `pending/confirmed -> cancelled`. Settlement records an offline fact and evidence. Duplicate confirm/open/settle calls are idempotent. Cancelled candidates cannot settle.

Promotion/demotion/pause/resume/exit and retention warnings are manual headquarters governance. The system stores rule configuration, applications, events, warnings, qualification snapshots, candidates, and offline settlements. It does not automatically punish, transfer money, calculate complex annual weighted dividends, or write CRMEB money fields.

## Permissions

- Partner users: issue application invite, view own application/team/performance/rules/warnings/rewards, submit promotion application.
- Finance-authorized headquarters: review payment proof and confirm/reject real receipt; cannot change partner hierarchy.
- Opening-authorized headquarters: inspect gates, create the formal store, grant county compatibility permission, optionally grant manager.
- Recruiting-authorized headquarters: manage parent/rank/state, review promotions, inspect performance/warnings, confirm/cancel/settle candidates.
- Store manager/staff/customer/member: no headquarters recruiting administration.

All headquarters APIs use admin token, explicit API permission assertions, and headquarters-scope checks. Partner APIs use CRMEB user token and derive UID server-side.

## Compatibility

Migration maps each active legacy `franchisee` user to one county profile and stores the original role ID. It does not delete or rewrite the role, store, audit, order, or history. The controlled TEST fixture reuses `yfth_stg_b1_franchisee` and displays it as county partner.

## Explicit Non-goals

No bank reconciliation, online franchise-fee order, automatic payout, wallet, withdrawal, automatic rank enforcement, complex annual dividend distribution, multi-level customer referral, or CRMEB order/payment/refund/inventory rewrite is included.
