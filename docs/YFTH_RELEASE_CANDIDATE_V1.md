# YFTH Release Candidate V1

## Scope

This candidate joins the existing CRMEB storefront with the completed YFTH package membership, one-level referral, ordinary-mall reward candidate, reward confirmation, and offline settlement-ledger capabilities. It does not introduce automatic payment or a new commerce/payment stack.

### Core Experience

1. Headquarters configures package rules and publishes the current direct-referral rule version.
2. A non-member enters the version-controlled package list from the user centre or package-membership page, chooses a published package and service store, accepts the agreement, then completes the existing intent/order/payment path and receives permanent membership after activation.
3. The member issues one active invite; a non-member accepts it and inherits the same authoritative B1 store.
4. A paid, valid headquarters-mall main order for the invited non-member creates a snapshot-only ordinary-mall reward candidate when the referral and rule remain active.
5. Package activation for that invited customer closes the referral and creates the frozen 15/25/60 package candidate. Later ordinary-mall consumption does not create a new candidate for the closed referral.
6. The responsible B1 confirms the candidate and records an offline settlement reference. C1 sees only their own read-only candidate status; headquarters can list and handle exceptions.

The ledger is an offline business record. It is not a wallet, payout, withdrawal, payment split, or proof that the platform transferred funds.

## Release Surface

- **Headquarters Admin:** the `御方通和` menu provides package/benefit configuration, package membership and first-level referral rules, candidate filtering, and authorised exception actions.
- **Customer:** the user centre provides `购买康养套餐` and `套餐会员与一级推荐`. A non-member can use either route to open the public published-package list; the list opens a dynamic package detail, then store selection, agreement confirmation, existing order intent, CRMEB order creation, and the existing payment component. No fixed template ID or page-decoration configuration is required.
- **Store Workbench:** an authorised `franchisee` or `store_manager` opens the package-membership workbench to see only their store's candidates, confirm them, and record an offline settlement reference.

## Deployment Checklist

### Before Migration

- Take a tested backup of the target database and upload directory. Confirm a rollback owner and maintenance window.
- Use a fresh deployment checkout or a controlled release directory. Do not overwrite a running site in place.
- Start from the CRMEB base schema. For a fresh MySQL 8 database, import `crmeb/public/install/crmeb.sql` before application migrations. That legacy baseline includes zero-date defaults, so perform only this import with `sql_mode='NO_AUTO_VALUE_ON_ZERO'` (or the equivalent approved compatibility mode); restore the normal release SQL mode afterwards.
- Set PHP 7.4-compatible runtime extensions, including `pdo_mysql`, `mysqli`, `mbstring`, `openssl`, `fileinfo`, and `redis`. The configured queue defaults to Redis, so missing `redis` is a release blocker rather than an optional optimisation.
- Use MySQL 8.0.x and a dedicated least-privilege database account. Configure Redis for the application and every queue worker. Keep `APP_DEBUG=false` in the release environment. Do not commit `.env` or real credentials.
- Give the PHP-FPM/worker account write access to `crmeb/runtime/` and the configured upload directory. Do not follow the old blanket `777` guidance: use the deployment account/group and least-privilege permissions, then verify cache, logs, and uploads can be written.
- Confirm the administrator role receives the YFTH menu/API permissions seeded by the migrations; do not manually create duplicate permissions.

### Database and Workers

Run from `crmeb` after the environment points to the intended non-production or approved release database:

```powershell
php think migrate:run
php think queue:listen --queue
php think timer start --d
```

Use Supervisor, systemd, or the platform's process manager for long-running queue and timer workers. CRMEB's configured Redis cache/queue connection must be reachable by every PHP worker. Validate the migration in a staging database first; production migration remains a separate approval step.

Never use `php think migrate:rollback --target 0` as a production rollback. It is an isolated-installation test only and removes all recorded application migrations. Production recovery requires an approved backup/restore or a reviewed forward migration plan.

### Frontend Builds

```powershell
cd template/admin
npm run build
```

Publish `template/admin/dist` to the configured Admin static root only as part of a controlled deployment. The repository's deployed Admin path is normally `crmeb/public/admin`; do not nest it as `admin/dist`.

The repository version-controls the published Admin assets. After a successful local build, update that tree before release so the server cannot serve stale hashed files:

```powershell
$source = (Resolve-Path template/admin/dist).Path
$target = (Resolve-Path crmeb/public/admin).Path
robocopy $source $target /MIR /R:2 /W:1
if ($LASTEXITCODE -ge 8) { throw 'Admin asset mirror failed' }
Test-Path "$target/index.html"
```

On a Linux release directory, use the equivalent `rsync -a --delete template/admin/dist/ crmeb/public/admin/`. Before switching traffic, verify that every `/admin/system_static/...` reference in `crmeb/public/admin/index.html` exists under `crmeb/public/admin/`. Back up the currently served static directory or release directory first; do not mix assets from two builds.

For uni-app, use the existing external HBuilderX `uniapp-cli` with a Node 18 runtime because this CRMEB project retains the legacy `node-sass` dependency. Build both production targets with `NODE_ENV=production` and `UNI_PLATFORM=h5` or `UNI_PLATFORM=mp-weixin`; outputs remain under `template/uni-app/unpackage/dist/build/`. Do not commit these outputs or upload to WeChat during candidate validation.

### Manual External Configuration

- Set the production/staging domain, HTTPS certificate, Nginx/PHP-FPM routing, and allowed client origins for the selected environment.
- Complete WeChat public-account/mini-program AppID, AppSecret, payment merchant, API certificate/key, callback URL, and allowed request-domain settings through approved secret management and CRMEB configuration screens. These values are intentionally absent from the repository.
- Configure the SMS provider credentials, signature/template approvals, object-storage credentials if used, and mail/notification configuration if enabled.
- Verify payment callback reachability before enabling real payment. The candidate does not treat a frontend-only success page as payment confirmation.

## Staging Smoke Walk

1. Sign in to Admin and confirm the YFTH menus and package/referral rule page load for an authorised role.
2. Use isolated accounts to complete the core experience above, including B1 cross-store denial and C1 own-data-only reads.
3. Confirm Admin, H5, and mp-weixin build assets load without blocking 404s or console errors.
4. Confirm Redis, queue, and timer workers are healthy and that payment/SMS/WeChat values remain test credentials in staging.
5. Record any manual payment, refund, or settlement evidence outside source control.

## Candidate Validation Evidence

- An isolated MySQL Community 8.0.46 database was seeded from the repository CRMEB base schema and completed `migrate:run`, rollback to target `0`, and `migrate:run` again. The permanent-membership, reward-candidate, and offline-settlement-ledger tables and the settlement-candidate unique index were checked after rerun. The rollback-to-zero operation was an isolated test only and is prohibited in production.
- The isolated service-level core flow passed: permanent member C1 invited C2; C2 accepted the shared B1 attribution; an eligible ordinary CRMEB-order fixture produced a versioned snapshot candidate; C2 package activation closed the referral and created the package candidate; later ordinary consumption was skipped; B1 confirmed and recorded offline settlement; C1, B1, and headquarters reads remained scoped. No CRMEB balance, brokerage, points, distribution, or withdrawal field was written. This was not an HTTP, browser, or real-payment callback execution.
- Admin production build, uni-app H5 production build, and uni-app mp-weixin production build passed in the prior candidate closure. This entry closure reruns the two uni-app production builds and the package-entry contract; Admin is rebuilt and its published static tree is mirrored only because the repository's current Admin source changes still require a published-asset refresh.
- No isolated server deployment, authenticated browser session, real payment callback, or WeChat upload was performed because approved test-host routing and external test credentials are not present in this workspace. A staged browser walk is still required before a release decision.

## Not Included

- Automatic payout, wallet, withdrawal, payment split, reconciliation, or partial-refund reversal.
- City partner, store takeover, multi-level referral, or a new business domain.
- Production migration, production deployment, WeChat upload, or use of production secrets.
