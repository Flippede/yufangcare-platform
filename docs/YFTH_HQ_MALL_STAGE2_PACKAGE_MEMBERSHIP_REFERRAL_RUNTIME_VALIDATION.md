# YFTH Headquarters Mall Stage 2 V2 Runtime Validation

## 1. Environment

- Branch: `codex/yfth-hq-mall-stage2-package-membership-referral-v1`
- Base `main` / `origin/main`: `3ec6c80dbfef4975788414f64ab70c9e439cf117`
- PHP: portable PHP `7.4.33`, repository-external runtime
- Database: isolated MySQL Community Server `8.0.46`, port `33317`, database `yfth_stage2_v2_validation`
- Admin build: existing `template/admin/node_modules`, production build, no dependency installation or upgrade
- Uni-app build: repository-external HBuilderX `5.14.2026070214` `uniapp-cli` with Node.js `18.20.8`
- Production MySQL, Redis, deployment server and WeChat platform were not accessed.

## 2. Migration Evidence

On a freshly rebuilt isolated database, the repository migration lifecycle completed:

1. full `migrate:run`;
2. `migrate:rollback -t 0`;
3. full rerun;
4. duplicate run.

The final migration creates all five Stage 2 V2 tables, both package grant columns, exact unique/index signatures and headquarters permissions. Rollback removes its permissions/tables/columns after strict ownership/signature validation, and rerun restores them. Migration contains no historical business scan or automatic backfill.

## 3. Stage 2 V2 Checks

All changed/new PHP files passed PHP 7.4 syntax. The following executable checks passed with exit code 0:

- `yfth_package_membership_referral_contract_check.php`
- `yfth_package_membership_referral_source_guard.php`
- `yfth_package_membership_referral_real_flow_check.php`

Real-flow coverage includes configurable old/new package prices, immutable historical snapshots, historical package recognition, controlled backfill, nonmember invitation rejection, single-active invitation rotation, acceptance idempotent replay without duplicate relation/event, C1/C2/B1 attribution, cross-store purchase rejection, package activation, referral closure, cyclic 15/25/60 integer candidates, ordinary-mall extension rules, refund non-revocation, audit/idempotency, disabled store and revoked role behavior.

Two-process cases passed for concurrent C2 activations and duplicate full paid package activation. Candidate sequence remained unique and contiguous. Missing reward rule rolled back referral closure, membership and candidate. CRMEB wallet/reward funding state remained unchanged.

## 4. Regression Evidence

The following checks passed with exit code 0:

- Stage 1A contract and source guard;
- Stage 1A isolated MySQL real-flow, including two-store competition, two-referrer competition, cycle contention, lock-wait retry and real deadlock retry;
- Stage 1B contract and source guard;
- Stage 1B isolated MySQL real HTTP flow: 192 requests, permission matrix, consistency counterexamples and unchanged hashes for both authority current tables, both event tables and `yfth_idempotency_record` around every request;
- package benefit contract;
- monthly benefit fulfillment contract;
- service appointment/writeoff contract;
- uni-app multi-role shell, request fallback and Stage 1B frontend stale-state checks.

The first Stage 1B HTTP invocation used a quoted child-process ini argument and failed before business assertions with `could not find driver`. The command was corrected to pass the no-space ini path directly; the complete 192-request run then passed. No project source change was needed for this harness invocation issue.

## 5. Frontend Builds

Admin `npm run build` passed with exit code 0. Warnings were the project's existing CSS-order, Browserslist-age and asset-size notices.

H5 production build passed with exit code 0 after invoking `uniapp-cli` from its required plugin working directory. Final measured output was 742 files and 27,022,089 bytes. An initial call from the repository root returned `command "uni-build" does not exist`; that failed invocation is not counted as build evidence.

`mp-weixin` production compile passed with exit code 0 using Node 18 and `--no-opt`. Final measured output was 1,242 files and 7,792,414 bytes. A new compound `:key` warning from the Stage 2 store page was fixed and both H5 and mp-weixin were rebuilt. Remaining messages are the existing skeleton key and component subpackage suggestions.

No build output, HBuilderX program, Node runtime, DCloud cache, `node_modules`, AppID, key or upload credential is part of the feature change. No WeChat upload was performed.

## 6. Current Gate

This development evidence does not self-approve architecture or merge. Stage 2 V2 remains on its feature branch and awaits an independent read-only Architecture Auditor review.
