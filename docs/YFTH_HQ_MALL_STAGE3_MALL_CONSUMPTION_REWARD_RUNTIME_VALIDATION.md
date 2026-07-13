# YFTH Headquarters Mall Stage 3 Runtime Validation

## 1. Environment

- Feature branch: `codex/yfth-hq-mall-stage3-mall-consumption-reward-v1`
- Stable baseline: `423a1d3ac03d0c4771ac4350334e95c3c2509b3e`
- PHP: portable PHP 7.4.33 with the isolated YFTH test ini
- Database: external portable MySQL Community Server 8.0.46 on a temporary local port and isolated database
- Node/npm for Admin: Node.js 24.13.0 / npm 11.6.2, existing `node_modules` reused
- Node for uni-app: portable Node.js 18.20.8 with the existing HBuilderX 5.14 uniapp-cli
- Production MySQL/Redis, production configuration, production AppID and upload keys were not used.

## 2. PHP And Static Contracts

All commands below exited `0` unless noted otherwise.

- PHP syntax passed for `DirectReferralRewardServices`, both Stage 3 listeners, `app/event.php`, the two Stage 3 tests and the two directly updated Stage 2 tests.
- `php crmeb/tests/yfth_mall_consumption_reward_contract_check.php`: passed.
- `php crmeb/tests/yfth_package_membership_referral_contract_check.php`: passed.
- `php crmeb/tests/yfth_package_membership_referral_source_guard.php`: passed.
- `php crmeb/tests/yfth_hq_authority_foundation_contract_check.php`: passed.
- `php crmeb/tests/yfth_hq_authority_foundation_source_guard.php`: initially reported the intentional new event entry as unapproved; after adding only `app/event.php` and the two dedicated Stage 3 listeners to its exact allowlist, it passed. No path-pattern prohibition was relaxed.

## 3. Isolated MySQL 8.0.46 Real Flow

`YFTH_MALL_CONSUMPTION_REWARD_REAL_FLOW_EXECUTE=1 php crmeb/tests/yfth_mall_consumption_reward_real_flow_check.php` passed against a temporary isolated database. Evidence covered:

- valid paid ordinary main order creates exactly one candidate with frozen order, amount, ratio, rule, C1, C2 and B1;
- duplicate payment delivery reuses the same candidate;
- missing ratio safely skips without changing the CRMEB order;
- package, child, deleted, system-deleted, cancelled, unpaid, refunded and invalid-status orders create no candidate;
- no referral creates no candidate and no authority placeholder;
- closed referral creates no later candidate;
- C1/C2 B1 mismatch fails closed;
- partial refund leaves pending state unchanged;
- full refund cancels the pending candidate;
- repeated full-refund delivery creates no duplicate transition or audit;
- user, trusted store and headquarters reads preserve role/store isolation and user DTO privacy;
- no package reward candidate is created by the ordinary-order path.

The directly affected Stage 2 command `YFTH_PACKAGE_MEMBERSHIP_REFERRAL_REAL_FLOW_EXECUTE=1 php crmeb/tests/yfth_package_membership_referral_real_flow_check.php` also passed. It retained package activation, historical membership, invitation, concurrency and continuous unique sequence behavior.

Stage 3 has no schema change, so no new migration run/rollback/rerun was executed. The reused Stage 2 migration health gate was exercised by the Stage 2 real-flow regression. The temporary MySQL server was shut down after validation.

## 4. Frontend And Build Evidence

- `npm run build` in `template/admin`: exit `0`, about 145.5 seconds. Existing dependency/CSS-order/browser-data/asset warnings remained; no source compile error occurred.
- H5 production build through existing uniapp-cli: exit `0`, 284.9 seconds. It reported existing asset-size warnings only.
- mp-weixin production compile through existing uniapp-cli: exit `0`, 157.7 seconds. It reported existing skeleton-key and subpackage placement suggestions only.
- `node template/uni-app/tests/yfth_multi_role_shell_contract_check.js`: passed.
- `node template/uni-app/tests/yfth_request_fallback_check.js`: passed.

Generated Admin `dist` and uni-app `unpackage` output are local verification artifacts and are not part of this feature commit.

## 5. Final Repository Checks

- `git diff --check`: passed; Git emitted Windows LF-to-CRLF advisory warnings only.
- The staged scope is restricted to Stage 3 backend/listeners/tests, the directly affected Stage 1A/Stage 2 guards, three existing candidate pages and these three Markdown documents.
- No `.env`, credential, private key, upload key, test database, log, `node_modules`, Admin `dist` or uni-app `unpackage` artifact is included.
- User-owned `项目文档/*`, TXT, DOCX, MD and Word lock files remain untouched and excluded from staging.

## 6. Result And Next Gate

Development and direct validation are complete. This record does not claim independent architecture approval. The branch remains unmerged and undeployed; the next gate is an independent read-only Architecture Auditor review.
