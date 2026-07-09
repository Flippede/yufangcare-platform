# YFTH Product Quota / Return Goods Quota Ledger V1

## Scope

This V1 establishes an independent YFTH product-equivalent quota ledger:

- Headquarters manually creates, confirms, rejects, reverses, freezes, unfreezes, and closes quota accounts and grant orders.
- Franchisee and store manager can read their own store quota account, summary, and ledger.
- The account balance is an integer-cent product-equivalent amount used for later business decisions only.
- The ledger is append-only for amount changes and records the immutable before/after balance.
- Source snapshots are stored for auditability.

This V1 does not implement purchase-order offset, reservation, consumption, automatic reward conversion, opening auto-grant, after-sale quota return, online payment, withdrawal, settlement, or revenue sharing.

## Tables

- `yfth_product_quota_account`: current store quota account by `store_id + quota_type`.
- `yfth_product_quota_ledger`: immutable quota movement ledger.
- `yfth_product_quota_grant_order`: headquarters manual grant draft/confirm/reject/reverse workflow.
- `yfth_product_quota_adjustment`: append-only correction, freeze, unfreeze, close, and reverse records.
- `yfth_product_quota_source_snapshot`: source payload snapshot for grant and ledger traceability.

Important uniqueness guards:

- `uniq_yfth_product_quota_account_active`: one active account per store and quota type.
- `uniq_yfth_product_quota_ledger_idempotency`: duplicate grant/adjustment ledger guard.
- `uniq_yfth_product_quota_grant_idempotency`: duplicate grant draft guard.
- `uniq_yfth_product_quota_adjustment_dedupe`: duplicate adjustment guard.

P1 idempotency closure:

- `yfth_product_quota_grant_order.idempotency_key` is mandatory, non-null, normalized server-side, and protected by `uniq_yfth_product_quota_grant_idempotency`.
- `yfth_product_quota_adjustment.dedupe_key` is mandatory, non-null, normalized server-side, and protected by `uniq_yfth_product_quota_adjustment_dedupe`.
- The service rejects missing grant idempotency keys and missing adjustment dedupe keys.
- Reusing the same key with the same payload returns the existing grant or adjustment result.
- Reusing the same key with a different payload is rejected with `product_quota_idempotency_payload_mismatch`.
- Manual adjustment rechecks the dedupe key after locking the quota account row, before changing the balance, so duplicate HTTP retries cannot apply the amount twice.
- Account creation also re-reads the existing active account after an `active_key` unique collision, preventing duplicate first-write races from failing before grant idempotency can be resolved.

All amount columns use integer cents. The implementation does not use PHP float math for quota amounts.

## State Model

Account:

`active -> frozen -> active`

`active / frozen -> closed`

Grant order:

`draft -> confirmed`

`draft -> rejected`

`confirmed -> reversed`

Only confirmed grants and manual adjustments change the account balance. Rejecting a grant changes only the grant state.

## Source Boundary

Allowed in V1:

- `headquarters_manual_grant`: headquarters manual grant.
- `franchise_opening_initial_quota`: manual headquarters grant based on a real opened franchise application, verified/bound store profile, valid CRMEB store, and active store-bound identity grant.

Reserved but rejected in V1:

- `referral_reward_converted`
- `purchase_after_sale_return`

The service does not create quota from referral rewards, purchase after-sale, opening acceptance, or supply-chain events automatically.

## Permission Boundary

Headquarters admin APIs:

- Use CRMEB admin token middleware and explicit `SystemRoleServices::assertApiAuthForAdmin()` checks.
- Service layer also requires `AdminStoreContextServices::assertHeadquarterScope()`.
- Headquarters can manage accounts, grant orders, adjustments, and status changes.

User APIs:

- Use CRMEB user token middleware.
- Read context is resolved through `CurrentBusinessContextServices`.
- Only `franchisee` and `store_manager` can read product quota for their resolved store.
- `store_staff`, `service_mentor`, customer, and member identities cannot read this quota module.
- User-side requests reject sensitive or cross-store query fields such as `uid`, `owner_uid`, `operator_uid`, `source_id`, `idempotency_key`, and balance internals.

## Data Boundary

This module does not write:

- CRMEB `store_order`.
- CRMEB product stock, SKU stock, or sales.
- CRMEB order, payment, or refund state.
- User balance, points, brokerage, distribution, commission, settlement, or withdrawal data.
- YFTH purchase order, shipment, receipt, inventory balance, or inventory ledger.
- YFTH reward ledger, settlement records, or referral attribution.

The UI wording intentionally presents this as product-equivalent quota, not withdrawable money.

## APIs And Pages

Headquarters admin APIs are under `/adminapi/yfth/product_quota/*`.

User read-only APIs are:

- `/api/yfth/product_quota/summary`
- `/api/yfth/product_quota/account`
- `/api/yfth/product_quota/account/:id`
- `/api/yfth/product_quota/ledger`

Frontend:

- Admin page: `template/admin/src/pages/yfth/productQuota/index.vue`.
- Uni-app read-only pages: `template/uni-app/pages/yfth/product_quota/index.vue`, `ledger.vue`, and `detail.vue`.
- Store workbench links the quota entry only for `franchisee` and `store_manager`.

## Audit And Idempotency

All sensitive headquarters operations write unified YFTH audit events to `yfth_audit_event.business_domain = yfth_product_quota`.

Amount changes use idempotency or dedupe keys:

- Grant draft creation requires a client operation key from the admin UI/API and stores the server-normalized `product_quota_grant_create:{admin_id}:{hash}` key.
- Grant confirmation ledger key: `product_quota_grant_confirm:{grant_id}`.
- Grant reverse ledger key: `product_quota_grant_reverse:{grant_id}`.
- Manual correction requires a client operation key from the admin UI/API and stores the server-normalized `product_quota_adjustment_post:{admin_id}:{hash}` key.
- Adjustment ledger key: `product_quota_adjustment:{adjustment_id}`.
- Freeze, unfreeze, and close use deterministic version-scoped adjustment dedupe keys.

The admin page generates operation keys when the grant or manual-adjustment dialog opens and also guards repeated local submits with `grantSubmitting` and `adjustSubmitting`.

Audit snapshots are sanitized to avoid storing raw internal source snapshots in the audit event payload.

## Verification

Added:

- `crmeb/tests/yfth_product_quota_contract_check.php`.
- `crmeb/tests/yfth_product_quota_real_flow_check.php`.

The real-flow script runs source guards by default and can validate indexes, non-null idempotency columns, uniqueness, service-level duplicate grant creation, duplicate grant confirmation, duplicate manual increase/decrease, payload mismatch rejection, frozen-account write blocking, audit writes, and CRMEB boundary snapshots on isolated MySQL when `YFTH_PRODUCT_QUOTA_REAL_FLOW_EXECUTE=1` and `YFTH_REAL_FLOW_ISOLATED_DB=1` are set.

Executed in this branch:

- PHP 7.4 syntax check passed for changed PHP files.
- `yfth_product_quota_contract_check.php` passed with 88 assertions.
- `yfth_product_quota_real_flow_check.php` passed in default source-guard mode.
- `yfth_product_quota_real_flow_check.php` passed in isolated MySQL 8.0.46 mode, covering real index existence, duplicate active account blocking, and duplicate ledger idempotency blocking.
- MySQL 8.0.46 migration `run -> rollback -t 0 -> run` passed on a temporary local database; rollback removed the five product quota tables and rerun restored the five tables plus the account, ledger, grant, and adjustment unique guards.
- Adjacent supply-chain, referral-reward, and franchise-opening contract checks passed.
- Admin production build passed with existing CSS order, asset-size, and Browserslist warnings.
- Uni-app request/context Node checks passed.
- `git diff --check` passed with only line-ending warnings.

P1 idempotency closure validation added in this round:

- Contract assertions now cover mandatory non-null grant idempotency and adjustment dedupe keys, service-side normalization, duplicate-result replay, payload-mismatch rejection, admin controller pass-through, and admin page operation-key generation.
- Real-flow isolated MySQL mode now covers missing key rejection, duplicate grant create returning the existing grant, duplicate grant confirm keeping one ledger, duplicate manual increase/decrease preserving a single balance mutation, same-key different-payload rejection, frozen-account write blocking, unified audit writes, and unchanged CRMEB order/product/SKU/user boundary snapshots.
- Latest P1 closure execution: PHP 7.4 syntax passed for changed PHP files; product quota contract check passed with 104 assertions; product quota real-flow check passed in default and isolated MySQL 8.0.46 modes; MySQL migration `run -> rollback -t 0 -> rerun -> duplicate run` passed; adjacent supply-chain, referral-reward, and franchise-opening contract checks passed; admin production build passed with existing warnings; uni-app Node checks, H5 production build, and mp-weixin production compile passed; `git diff --check main..HEAD` passed.

## Not Implemented

- Purchase order quota offset, reservation, consumption, release, or recovery.
- Reward ledger conversion into product quota.
- Franchise opening automatic quota grant.
- Purchase after-sale return quota.
- Online payment, withdrawal, settlement, revenue sharing, or financial reconciliation.
- CRMEB distribution integration.
- Production deployment and production database migration.
