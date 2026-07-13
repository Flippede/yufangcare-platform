# YFTH Headquarters Mall Stage 2 V2 Runtime Validation

## 1. Current Gate

- First independent architecture review conclusion: C, merge blocked.
- Closure branch: `codex/yfth-hq-mall-stage2-package-membership-referral-v1`; start commit: `78a0473b73d7a2f5b07ee3a1f70be262f6846abe`.
- Stable `main` / `origin/main`: `3ec6c80dbfef4975788414f64ab70c9e439cf117`.
- The findings below are implemented and verified, but no follow-up architecture approval is claimed. The branch remains unmerged.

## 2. Environment

- PHP: portable PHP `7.4.33` with the repository-external test ini.
- Database: isolated MySQL Community Server `8.0.46`, port `33317`, database `yfth_stage2_v2_audit_validation`.
- Admin build: existing `template/admin/node_modules`; Node.js `24.13.0`, npm `11.6.2`; no dependency installation or upgrade.
- Production MySQL, Redis, deployment server and WeChat platform were not accessed.

## 3. Migration And Historical Semantics

The isolated lifecycle passed full `migrate:run`, `migrate:rollback -t 0` and full rerun. Rollback removed Stage 2 tables and all seven permissions; rerun restored them. The executable health check passed the recorded migration, nullable legacy grant columns, critical unique indexes and permission signatures.

Historical rule/snapshot `grants_permanent_membership = NULL` was verified as auditable pre-V2 package semantics, while explicit `0` remained non-granting and new rules/snapshots wrote explicit values. Migration performed no historical business scan or bulk update. A frozen historical transaction stayed unchanged.

A historical paid and successfully activated user was recognized before backfill, retained permanent membership after package purchase/instance refund and closure fields changed, and issued a real invitation without a persisted membership row. Dry-run backfill was read-only; execute then persisted the same membership idempotently.

## 4. Stage 2 V2 Executable Evidence

All changed/new PHP files passed PHP 7.4 syntax. These checks passed with exit code 0:

- `yfth_package_membership_referral_contract_check.php`;
- `yfth_package_membership_referral_source_guard.php`;
- `yfth_package_membership_referral_migration_health_check.php`;
- `yfth_package_membership_referral_real_flow_check.php`;
- `yfth_package_membership_referral_http_flow_check.php`.

Real-flow coverage passed user DTO recursive forbidden-field checks, invitation rotation/replay, C1/C2/B1 attribution, cross-store denial, package activation and rollback, 15/25/60 integer candidates, refunded/child/deleted ordinary-order rejection, disabled store and revoked role behavior. Two concurrent package activations produced continuous unique sequences. Concurrent invitation acceptance and package activation completed without deadlock or lock-wait failure and left either no relation or one atomically closed relation with its one candidate, never an active relation beside membership.

The HTTP check exercised authenticated membership summary, candidate list and invitation issuance through a local PHP HTTP server against the isolated database. It returned real JSON, opaque invitation tokens and no other UID, reward sequence or rule-version internals. The child server explicitly reuses the loaded portable PHP ini so PDO availability is part of the repeatable harness.

## 5. Direct Regression Evidence

The directly affected regressions passed with exit code 0:

- package benefit contract;
- Stage 1A contract;
- Stage 1A production-entry source guard;
- Stage 1A isolated MySQL real-flow, including two-store and two-referrer contention, cycle prevention, same-referrer parallel relations, lock-wait retry and real deadlock retry.

The Stage 2 real-flow itself also uses the real existing package activation service and verifies duplicate paid activation produces one package instance, one membership and one candidate. Unrelated Stage 1B 192-request HTTP evidence, the full project suite, H5 and mp-weixin builds were intentionally not repeated.

## 6. Frontend And Repository Checks

Admin `npm run build` passed with exit code 0. Its 11 warnings were the existing CSS-order, Browserslist-age and asset-size warnings; no framework or dependency version changed and build output remains uncommitted.

Final release checks include all changed PHP syntax, Stage 2 contract/source guard, `git diff --check` and a sensitive-information/generated-artifact scan. User-owned `项目文档/*`, TXT, DOCX and Word lock files are excluded from staging and remain untouched by this task.

## 7. Execution Notes

- The first baseline SQL import hit the CRMEB legacy zero-date default under MySQL 8 strict mode. The fresh isolated database was rebuilt and only the import session used compatible `sql_mode`; project configuration and migrations were unchanged.
- The first local HTTP server and Stage 1A worker invocations did not inherit the parent's `-c` ini and failed before business assertions with `could not find driver`. The repeatable command/harness was corrected to pass the same portable ini, after which the complete checks passed.
- These failed harness attempts are not counted as business verification passes.

## 8. Next Gate

The only next action is an independent read-only Architecture Auditor review. Until it passes, Stage 2 V2 must not merge into `main`, and settlement, refund reversal, CRMEB mall-order listeners, store takeover or later business stages remain unauthorized.
