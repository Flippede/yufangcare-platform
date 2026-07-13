# YFTH Headquarters Mall Stage 2 Runtime Validation

## Audit Closure Validation - 2026-07-13

- First independent review conclusion: C; the three P1 findings are implemented for another independent review. No approval or merge authorization is claimed.
- PHP 7.4.33 syntax passed for every modified PHP file. Stage 2, Stage 1A and Stage 1B contract/source guards, the 5980 package-benefit contract and legacy referral-reward contract passed.
- MySQL Community Server 8.0.46 ran on isolated port `33313` with database `yfth_stage2_audit_validation_20260713`. Full migration through Stage 2 completed with exit 0.
- Stage 2 migration lifecycle passed create/duplicate-up/down/rerun plus compatible missing-index repair. Fail-closed counterexamples passed for missing `uid`, wrong unique index, duplicate permission, wrong URL/method/auth type/parent/path, recorded missing index/permission, and down against a wrong permission signature without partial deletion.
- Real HTTP passed two-store list/detail isolation; cross-store bind/payment/confirmation-code denial; revoked role and disabled store denial; headquarters enrollment/member store, UID and status filters; expired code; same-key success replay; used code with a new key rejection; cross-store attribution rollback; and different-key concurrent confirmation with exactly one activation.
- Reverse UID competition used two independent PHP processes with referrer UID lower than target UID. Membership activation completed in one transaction attempt while a concurrent referral pause used the same authority UIDs; no deadlock/lock-wait error or partial membership/event/candidate state occurred.
- Ten-table SHA-256 snapshots covered all five Stage 2 tables, both attribution tables, both referral tables and `yfth_idempotency_record`. Pre-run failed operations changed no business authority table; replay of the same failed key left all ten hashes unchanged. Successful concurrency left one membership, one membership event and one reward candidate.
- Because shared Stage 1A transaction-bound production services changed, the complete Stage 1A isolated real-flow was rerun and passed two-store/referrer/cycle competition, lock-wait retry and real deadlock retry. Stage 1B contract/source guards passed; its 192-request read-only flow was not rerun because Stage 1B read services and DTOs did not change.
- Admin production build used Node.js 18.20.8 / npm 10.8.2 and completed with exit 0: 608 files, 39,716,763 bytes and 12 permanent-membership matches. Existing 11 CSS-order/Browserslist warnings remain non-blocking. Output stayed outside the repository.
- H5 and mp-weixin production builds were not rerun because this closure did not modify uni-app source. The prior successful build evidence remains recorded below and is not presented as a rerun.
- This closure has not been independently re-reviewed and is not merged into `main`. No production database/Redis connection, production migration, deployment or WeChat upload occurred.

## 1. Environment

- Branch baseline: `3ec6c80dbfef4975788414f64ab70c9e439cf117`
- PHP: portable PHP 7.4.33 with `pdo_mysql`
- Database: MySQL Community Server 8.0.46 on local isolated port `33312`
- Database name: `yfth_stage2_validation_20260712`
- Cache: isolated file cache
- Admin: Node.js 18.20.8 / npm 10.8.2
- Uni-app: HBuilderX 5.14 compiler with Node.js 18.20.8
- No production MySQL, Redis, server, credential or data was used.

## 2. Migration Evidence

The isolated database was initialized from the CRMEB installation snapshot. Import alone used a non-strict session because the historical CRMEB SQL contains legacy zero-date defaults. Application migrations then ran in the normal MySQL 8 application session from foundation through Stage 2.

Passed:

- full `migrate:run` through `20260715100000`;
- Stage 2 direct up;
- duplicate up;
- direct down with all five tables and seven permissions removed;
- rerun;
- duplicate rerun;
- MySQL version and isolated database guards.

## 3. Stage 2 HTTP and Concurrency Evidence

`yfth_permanent_membership_real_flow_check.php` ran through a real local PHP HTTP server and real Auth Token/Admin Token middleware. Passed scenarios include:

- identity-code generation and refresh;
- old-code replacement rejection and plaintext-not-persisted proof;
- store enrollment creation and authenticated customer UID binding;
- unpaid confirmation-code rejection;
- fixed 9800 offline payment confirmation;
- customer-bound membership confirmation;
- one permanent membership and version-1 event;
- same-store attribution preservation;
- active referral close with `membership_activated`;
- active-member referral qualification;
- one amount-free reward candidate;
- used-code and idempotent replay without duplicate writes;
- different-store attribution conflict with no membership/event/candidate/code-consumption partial state;
- no-attribution customer first permanent attribution;
- two independent PHP HTTP workers confirming the same enrollment concurrently, both receiving success while membership/event/candidate each remain one row;
- `store_staff` and ordinary customer denial;
- trusted store scope and cross-store denial;
- ordinary headquarters role with explicit API permissions;
- no-permission Admin denial;
- headquarters create requiring an explicit active store.

## 4. Regression Evidence

Passed:

- 28 changed PHP files under PHP 7.4.33 syntax check;
- Stage 2 contract and source guard;
- Stage 1A contract and source guard;
- Stage 1A isolated real flow, including two-process attribution/referral competition, direct-cycle protection, lock-wait retry and real deadlock retry;
- Stage 1B contract and source guard;
- 5980 package-benefit contract;
- legacy referral-reward contract;
- existing multi-role shell, request fallback and Stage 1B frontend stale-state Node checks;
- `git diff --check` for tracked changes;
- sensitive-information scan over changed code and documentation.

Stage 1B 192-request read-only flow was not rerun because Stage 1B read services and DTOs were not modified; its contract/source guard passed. The Stage 1A real flow was rerun because shared writer transaction boundaries changed.

## 5. Production Builds

- Admin production final rerun: exit 0, 608 files, 39,716,763 bytes, 12 permanent-membership asset matches. Existing CSS ordering and stale Browserslist warnings remain non-blocking.
- H5 production: exit 0, 351 files, 9,928,703 bytes, 46 permanent-membership asset matches. Existing asset-size warnings remain non-blocking.
- mp-weixin production: exit 0, 1,242 files, 7,791,148 bytes, 12 permanent-membership asset matches. Existing skeleton key and subpackage component recommendations remain non-blocking.

All build outputs were written outside the repository. No generated output is intended for commit.

## 6. Cleanup and Release State

Temporary HTTP servers were stopped after each test. The isolated database was dropped, MySQL port `33312` was shut down, the original `.env` was restored with SHA-256 `1FEAB6EE35F27EFB592701D08B54C0ABF826EBA3D5BA60351F1B425E45CA0452`, and all three external build directories and Stage 2 temporary logs were removed. No production deployment, production migration, production database/Redis connection or WeChat upload occurred.

This implementation has not received an independent architecture review and is not approved for merge.
