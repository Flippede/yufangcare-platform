# YFTH User Role Assets And Referral QR V1 Runtime Validation

## Acceptance fixture and scan closure - 2026-07-15

- Added migration `20260718110000_create_yfth_acceptance_fixture.php`. Isolated MySQL Community 8.0.46 `run -> targeted rollback -> rerun` passed; rollback removed the manifest and all three permissions, and rerun restored the table, unique fixture key, and permissions.
- PHP 7.4.33 syntax passed for every backend, migration, config, and test file changed by this closure.
- `yfth_acceptance_fixture_real_flow_check.php` passed: headquarters-only generation, private credentials, five marked accounts, B1 store/roles, C1 membership, C2 non-member state, reward rules, duplicate-generation idempotency, C1-to-C2 acceptance, self-scan rejection, safe reset, immutable fact preservation, C2 rotation after closed history, regeneration, and second reset.
- `yfth_user_role_management_real_flow_check.php` passed against the same isolated database: headquarters search/grant/revoke, multi-store roles, duplicate idempotency, store-side denial, inactive-store denial, audit, and unchanged customer/member/mall-asset facts.
- `yfth_user_role_assets_referral_contract_check.php`, the existing package/referral contract, `yfth_multi_role_shell_contract_check.js`, and `yfth_request_fallback_check.js` passed.
- Admin production build completed with the existing Vue 2 CSS-order and stale Browserslist warnings. The output was mirrored into `crmeb/public/admin`, and the generated role-management chunk contains the fixture API/page.
- H5 production build completed using HBuilderX `uniapp-cli`, Node 18.20.8, and the HBuilderX plugin directory as `VUE_CLI_CONTEXT`; existing missing-export and asset-size warnings remained non-blocking.
- mp-weixin production compile completed with `--no-opt`; existing skeleton-key, missing-export, and component-subpackage recommendations remained non-blocking.
- No real payment, SMS, WeChat authorization, refund, payout, production rollback, or WeChat upload was executed.

## Environment

- PHP: portable PHP 7.4.33
- Database: isolated MySQL Community 8.0.46 on a non-production port/database
- Frontend: existing Vue 2 Admin toolchain and HBuilderX uni-app compiler
- Production data or credentials: not used during development validation

## Executed checks

- PHP syntax passed for all added and changed PHP files.
- `yfth_user_role_assets_referral_contract_check.php` passed.
- `yfth_user_role_management_real_flow_check.php` passed with headquarters grant/revoke, duplicate idempotency, inactive-store rejection, store-account denial, multi-store roles, audit evidence, and unchanged customer/member/assets assertions.
- Existing `yfth_package_membership_referral_contract_check.php` passed.
- Existing Stage 2 V2 real flow passed after adding self-scan, expired-token, conflicting-store attribution, existing-active-referral and permanent-member scan regressions.
- Migration run passed; targeted rollback removed the five new menu/permission records; rerun restored exactly five distinct permission records.
- Admin production build passed and its output was mirrored to `crmeb/public/admin` without retaining stale hashes.
- H5 production build passed. Existing bundle-size recommendations remained non-blocking.
- mp-weixin production compile passed. Existing skeleton-key and subpackage-placement recommendations remained non-blocking.
- Existing multi-role shell and request fallback checks passed.
- `git diff --check` and targeted sensitive/funding-field scans passed.

## Real-flow results

- Headquarters can grant and revoke `franchisee`, `store_manager`, and `store_staff` against active stores.
- One user can retain roles in multiple stores; duplicate mutations do not create duplicate active roles.
- Store-side contexts cannot elevate users and inactive stores cannot receive grants.
- Customer identity, permanent membership, CRMEB balance, points and coupons are not changed by role mutations.
- A permanent member can issue a rotating promotion token and a valid non-member can accept it.
- Successful acceptance creates the existing one-level referral and authoritative shared B1 attribution.
- Self-scan, expired token, conflicting B1 attribution, existing active referral and permanent-member recipient are rejected without duplicate relations or attribution takeover.

## Not executed in local validation

- No real SMS, WeChat authorization, payment, refund, payout or WeChat upload.
- No production database or production Redis mutation.
- No automatic reward funding or settlement.

Production backup, migration and online smoke evidence are intentionally recorded only after controlled deployment.

## Production deployment evidence

- Production URL: `https://yfth.top`
- Production feature commit: `d94294361f020d9cea98b82aece165869c51e100`
- Deployment completed: `2026-07-15 18:35:19`
- Backup: `/www/backup/yfth-user-role-assets-referral-20260715-183455`
- mp-weixin acceptance artifact: `/www/releases/yfth-user-role-assets-referral/20260715-183455/mp-weixin.tar.gz`
- Migration `20260718100000 AddYfthUserRoleManagementPermissions` is up.
- Nginx, PHP-FPM, MySQL 8.0.46, Queue, Timer and Workerman were confirmed active using their real production service names.
- Public H5 shell, customer center, Admin shell/static assets and the headquarters user-role page returned successfully. The authenticated headquarters page loaded six real CRMEB users with masked phone data; unauthenticated role-API access returned the existing login-expired JSON response.
- The authenticated customer-center browser session displayed real CRMEB mall balance, points and coupon count, the explicit mall-assets/YFTH-rewards separation, and the permanent-member promotion eligibility state without blocking console errors.
- Existing `.env`, uploads, runtime payment certificates, OSS, SMS, WeChat and payment configuration were preserved. No real payment, SMS, WeChat authorization, refund, payout or WeChat upload was performed.
