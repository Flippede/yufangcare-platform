# YFTH User Role Assets And Referral QR V1 Runtime Validation

## Fullscreen referral scanner production closure - 2026-07-16

- Feature commit `c635afd` is deployed at `https://yfth.top`; backup `/www/backup/yfth-fullscreen-referral-scan-20260716-202201` and release `/www/releases/yfth-fullscreen-referral-scan-20260716-202201` are retained.
- Real browser verification found the top profile scan icon, no legacy member scan card, successful navigation to the full-screen scanner, hidden video playback controls, and visible album/invite fallback controls. The deployed H5 contains one current referral-scan chunk and no old `打开摄像头扫码` string.
- HTTP checks for home and scan routes returned 200 and Nginx configuration/reload passed. The automated browser did not expose a physical camera, so optical QR recognition remains a device acceptance item. The mp-weixin build was retained as a release artifact and was not uploaded to WeChat.
- Production database, `.env`, uploads, OSS, SMS, WeChat, payment, products, orders, identity, attribution, and referral facts were unchanged.

## Fullscreen referral scanner validation - 2026-07-16

- The customer center exposes scanning as a profile-header icon and no longer renders the large referral-scan card in the member section.
- H5 compiles an automatically opened, full-height camera surface with a scan frame, back action, album QR decoding, explicit camera-error state, and compact invite-code fallback. mp-weixin compiles the native full-screen `uni.scanCode` flow with `onlyFromCamera: false`, allowing the platform scanner to use either camera or album.
- The dedicated role/assets/referral contract, existing multi-role shell check, request-fallback check, H5 production build, mp-weixin production compile, and `git diff --check` passed. The automated environment did not provide a physical camera and this record does not claim an optical scan or WeChat-platform upload.

## Member grant, scan and customer projection production closure - 2026-07-16

- Production business release: `072eff50fadfd5d80e1317c1913b5ab966ffd779`; URL: `https://yfth.top`; backup: `/www/backup/yfth-member-scan-customer-20260716-193253`; release: `/www/releases/yfth-member-scan-customer-20260716-193253`.
- The verified full MySQL 8 backup contains 248 table definitions. Migration `20260718130000` completed and the package-source column is nullable for headquarters-granted memberships.
- The controlled authority projection repair returned `scanned=2`, `created=2`, `existing=0`, `failed=0`. Follow-up SQL reported two active authority rows, two active store-customer rows, and zero missing projections; both visible customers belong to marked TEST B1.
- Authenticated production Admin rendered the user-role page and visible permanent-member/franchisee/manager-staff actions. The public H5 scan page rendered the camera, image-upload, and invite-input paths. Deployed bundles contain the `jsQR` fallback, QR save action, and new Admin identity labels; required JS/CSS assets returned HTTP 200.
- The automated Chrome session did not expose a usable camera device, so no optical camera scan was claimed. H5 camera entry no longer depends on `BarcodeDetector`, and the mp-weixin production compile retains native `uni.scanCode`; no WeChat upload was performed.
- Production services remained active and recent application logs had no fatal/uncaught/parse-error match. CRMEB has no `cache:clear` command in this version; that non-existent command was allowed to fail without using Redis `FLUSHALL` or changing unrelated cache data.
- No real SMS, payment, refund, payout, WeChat authorization, production rollback, or WeChat upload was executed.

## Member grant, scan fallback and store-customer projection validation - 2026-07-16

- PHP 7.4.33 syntax passed for the changed services, migration, repair command, and tests.
- The dedicated contract and Stage 2 V2 source guard passed. The role-management real flow proved headquarters permanent-member grant, duplicate idempotency, independent membership/store-role semantics, authoritative B1 attribution, customer projection, and store-side grant denial.
- Isolated MySQL Community 8.0.46 validation passed for migration `20260718130000`: run made `source_package_instance_id` nullable, targeted rollback restored the non-null package-only shape when no headquarters-granted facts existed, and rerun restored the forward schema.
- The Stage 2 V2 real flow proved invite acceptance creates store-CRM visibility, then deliberately removed the projection and proved the controlled authority backfill restores it. The acceptance-fixture flow proved C2 visibility and fixture-only projection disable on reset.
- Admin production build passed with existing CSS-order and stale Browserslist warnings. H5 production build passed with existing asset-size recommendations. mp-weixin production compile passed with existing skeleton-key and component-subpackage recommendations.
- Compiled H5 includes camera decoding with native `BarcodeDetector` plus bundled `jsQR` fallback; compiled mp-weixin retains native `uni.scanCode`. The promotion QR save actions compiled for both targets.
- Existing uni-app multi-role and request-fallback checks passed. `git diff --check` passed with line-ending notices only.
- No real SMS, payment, refund, payout, production rollback, WeChat authorization, or WeChat upload was executed. Production deployment evidence must be recorded separately after controlled backup and release.

## Admin user-role navigation production hotfix - 2026-07-16

- Root cause: `template/admin/src/pages/user/list/index.vue` used `/yfth/user-role` as an absolute path, escaping the Admin `/admin` route prefix and loading the customer shell.
- The user-list action now uses the registered `yfth_user_role` route name. The production bundle contains the named route and no longer contains the obsolete absolute path.
- `npm run build` completed with the project's existing CSS-order and Browserslist warnings. The rebuilt Admin output was deployed after backup `/www/backups/yfth-admin-user-role-20260716-153030`.
- Production PHP 7.4 syntax and `yfth_user_role_assets_referral_contract_check.php` passed. A real authenticated browser click opened `/admin/yfth/user-role?uid=13` and rendered the role-management page, fixture panel, and UID 13 data instead of a blank page.
- No migration, database write, customer-side bundle, production configuration, payment, SMS, or WeChat operation was performed.

## Final production login, identity and referral closure - 2026-07-16

- Production business release commit: `0268395bab2ba78bcb908abaf626757958267a00`; URL: `https://yfth.top`; backup: `/www/backup/yfth-user-login-identity-referral-20260716-114210`.
- All five stable customer-side accounts completed real `POST /api/login` token login. The separate headquarters account completed real `POST /adminapi/login`; customer accounts were confirmed absent from the Admin table and the headquarters account absent from the CRMEB customer table.
- Real HTTP checks resolved franchisee, store manager and store staff to TEST B1. A headquarters grant/revoke round-trip wrote two audit facts and left no active temporary role. Staff reward-settlement access and a manager request for an ungranted store were both rejected.
- A real C1 invite was issued and accepted by C2. C2 received the existing active one-level referral and authoritative TEST B1 attribution; self-scan and duplicate acceptance did not create another relation. The fixture was then reset and regenerated, leaving the stable C2 account as a non-member with no attribution and no active referral for user acceptance.
- Real browser checks completed account login, the four-item customer footer, C1 member/promotion/scan surfaces, the visible headquarters role-management and fixture page, and the customer-to-manager role switch into the TEST B1 workbench. Browser console error count was zero on the checked H5 and Admin tabs.
- Production migration `20260718120000 AddYfthAcceptancePasswordResetPermission` is up. Server source, Admin assets and H5 assets exactly match the uploaded release. MySQL 8, Redis, PHP-FPM, Nginx, Queue, Timer and Workerman were active, and recent application logs contained no fatal/uncaught/parse-error match.
- The credential files remain private and mode `0600`; no password is recorded here. No SMS, real payment, refund, WeChat authorization, payout, production rollback or WeChat upload was executed.

## Login, headquarters user list and stable fixture closure - 2026-07-16

- PHP 7.4.33 syntax passed for the changed controller, service, route, migration, and test files.
- `yfth_user_role_assets_referral_contract_check.php` passed, including the native user-list YFTH summary and the headquarters-only password-reset permission.
- On a fresh isolated MySQL Community 8.0.46 database, all migrations ran successfully. `yfth_acceptance_fixture_real_flow_check.php` proved that all five stable `yfth_stg_*` CRMEB user accounts can obtain user tokens through the original account-password login service.
- The same fixture flow proved duplicate generation is idempotent, C1 can bind C2 to the same B1, self-scan is rejected, reset closes only marked test authority, immutable facts remain, and regeneration creates a fresh C2 while retaining the stable acceptance account name.
- `yfth_user_role_management_real_flow_check.php` passed for search, CRMEB mall-asset DTOs, three store-role grants, native user-list summaries, multi-store identities, duplicate idempotency, store-side denial, inactive-store denial, revoke, audit, and unchanged customer/member/assets facts.
- Admin production build passed and was mirrored into `crmeb/public/admin`. H5 production and mp-weixin production builds passed with only the project's existing CSS-order, Browserslist, asset-size, skeleton-key, and subpackage recommendations.
- No real SMS, payment, refund, WeChat authorization, payout, WeChat upload, production database rollback, or production deployment was executed during this local validation.

## Production acceptance closure - 2026-07-15

- Feature commit: `5a36968fb121ac3bf9ce324103cb3954ad2af003`; production URL: `https://yfth.top`.
- Backup: `/www/backup/yfth-role-test-referral-20260715-201819`; release artifacts: `/www/releases/yfth-role-test-referral-20260715-201819`.
- Production migration `20260718110000 CreateYfthAcceptanceFixture` completed. Nginx, PHP-FPM 7.4, MySQL 8.0.46, Queue, Timer, and Workerman were active after the controlled restart.
- Admin browser verification reached `御方通和 / 用户经营身份`, displayed the fixture panel from the visible menu page, generated the five marked accounts, and repeated generation without increasing the store, user, role, or membership counts.
- The production credential file exists outside the web tree at `/www/private/yfth-acceptance/yfth-production-test-accounts.txt`, mode `0600`. Password contents are not recorded in this document.
- Real production HTTP checks passed for C1/C2/B1 account login, invite issue/accept, duplicate idempotency, self-scan denial, B1 franchisee/manager/staff contexts, C2 forged-manager denial, staff settlement denial, and cross-store denial. The resulting referral and attribution are both active for the marked B1.
- Public browser verification rendered the deployed referral scan page with H5 camera, QR-image upload, and pasted invite input. Exact release comparisons reported no source, Admin, H5 asset, page, static, or index drift. Required public/API/static requests returned successfully and no recent severe application log match was found.
- Production C2 was intentionally left as a non-member with an active referral so the user can inspect the pre-package state. The existing Stage 2 isolated real-flow evidence covers membership activation closing the relation; no fake production payment or membership transition was performed merely to repeat that assertion.
- No SMS, real payment, refund, payout, WeChat authorization, WeChat upload, production rollback, or production data cleanup was executed.

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
