# YFTH Franchise Contract, Preparation And Opening Acceptance V1

## Scope

This V1 extends Franchise Application V1 from `pending_contract` into an offline opening workflow:

`pending_contract -> contract -> user confirm -> headquarters confirm -> signed -> payment proof upload -> finance confirm -> preparation -> acceptance -> headquarters final grant -> opened`.

It does not implement electronic signing, online payment, franchise fee settlement, revenue sharing, recommendation rewards, product quota, procurement payment, or automatic CRMEB store creation.

## Tables

- `yfth_franchise_contract`: offline contract record, one current contract per application.
- `yfth_franchise_payment_proof`: offline payment proof and finance confirmation.
- `yfth_franchise_store_profile`: store preparation profile; not a CRMEB `system_store`.
- `yfth_franchise_preparation_task`: fixed V1 preparation tasks.
- `yfth_franchise_preparation_task_record`: append-only task evidence and operation records.
- `yfth_store_opening_acceptance`: opening acceptance header.
- `yfth_store_opening_acceptance_item`: acceptance checklist.
- `yfth_franchise_identity_grant`: why, who, and which acceptance granted a store-bound role.

## State Machines

Application:

- Existing Franchise Application V1 still controls `submitted -> contacting -> communicating -> inspecting -> pending_contract`.
- Opening V1 controls only `pending_contract -> signed -> preparing -> opened`.
- `signed` is triggered by a signed contract.
- `preparing` is triggered by signed contract plus finance-confirmed payment proof.
- `opened` is triggered only by the final identity-grant transaction after acceptance passed.

Contract:

`draft -> pending_user_confirm -> user_confirmed -> hq_confirmed -> signed`

Payment proof:

`pending_upload -> uploaded -> finance_confirmed`, with `uploaded -> rejected -> uploaded` supported.

Preparation task:

`pending -> in_progress -> submitted -> approved`, with `submitted -> rejected` and resubmission supported.

Acceptance:

`draft -> submitted -> reviewing -> passed`, with `rejected` and `recheck_required` reserved for rework.

Acceptance creation is controlled by the submit action only. Read-only user acceptance detail uses an existing acceptance row when present; if no row exists, it returns a safe `not_started` DTO and does not create `yfth_store_opening_acceptance` or acceptance items.

Acceptance submit and headquarters pass both require the complete upstream opening gate:

- Application status is `preparing`.
- Contract status is `signed`.
- Payment proof status is `finance_confirmed`.
- Store preparation profile exists.
- All fixed V1 required preparation tasks have been generated exactly once by task code.
- Every fixed required preparation task is `approved`.
- `first_purchase` is revalidated read-only against the existing supply-chain purchase order and requires `stocked`.

Headquarters pass additionally requires the store profile to be `verified` or `bound`, a concrete `system_store_id`, and an active CRMEB `system_store`. Passing acceptance still does not grant identity automatically.

Identity grant:

`pending -> active -> revoked`; V1 implements active grants only.

## Permission Boundary

User-token APIs are under `/api/yfth/franchise/opening/*`. Applicants can view their own opening progress, confirm their own contract, upload their own offline payment proof, submit preparation task evidence, and submit their own opening acceptance request.

Applicants cannot change statuses directly, confirm payment, approve tasks, pass acceptance, bind or create a CRMEB store, or grant themselves a role.

Admin APIs are under `/adminapi/yfth/franchise_opening/*` and use admin token middleware plus explicit `SystemRoleServices::assertApiAuthForAdmin()` checks. Services also call `AdminStoreContextServices::assertHeadquarterScope()`, so store-scoped backend accounts cannot manage the opening workflow.

## Store And Identity Boundary

`yfth_franchise_store_profile` is preparation metadata only. It does not create or enable CRMEB `system_store`.

Formal store operation rights are granted only when all conditions are true:

- Contract is `signed`.
- Payment proof is `finance_confirmed`.
- Required preparation tasks are `approved`.
- Opening acceptance is `passed`.
- A valid `system_store_id` is bound.
- Headquarters executes final identity grant.

The final transaction writes `yfth_franchise_identity_grant`, concrete store-bound `yfth_user_store_role` rows, opening-source store capabilities, application status `opened`, and audit records. No global franchisee identity is created.

Opening acceptance `passed` is deliberately separated from identity grant. A second headquarters identity-grant action is still required after acceptance has passed.

## Supply Chain Boundary

The preparation task `first_purchase` can reference an existing YFTH purchase order and verify that it belongs to the bound store and is `stocked`.

This module does not create, audit, ship, receive, or mutate purchase orders, stock balances, or inventory ledgers.

## CRMEB Boundary

This V1 does not create CRMEB `store_order` rows, does not write user balance, points, brokerage, commission, distribution, settlement, payment, refund, CRMEB product stock, SKU stock, or sales.

## Audit

Unified audit uses `yfth_audit_event` through `AuditEventServices`.

Audit domain: `yfth_franchise_opening`.

Core actions include `contract_create`, `contract_user_confirm`, `contract_hq_confirm`, `contract_signed`, `payment_proof_upload`, `payment_finance_confirm`, `payment_reject`, `task_submit`, `task_approve`, `task_reject`, `acceptance_submit`, `acceptance_pass`, `acceptance_reject`, and `identity_grant`.

Sensitive state is sanitized through the existing `YfthFoundationBaseServices::sanitizeState()` path before audit persistence.

## Frontend

Admin page:

- `template/admin/src/pages/yfth/franchiseOpening/index.vue`

Miniapp pages:

- `template/uni-app/pages/yfth/franchise/opening/index.vue`
- `template/uni-app/pages/yfth/franchise/opening/contract.vue`
- `template/uni-app/pages/yfth/franchise/opening/payment.vue`
- `template/uni-app/pages/yfth/franchise/opening/tasks.vue`
- `template/uni-app/pages/yfth/franchise/opening/acceptance.vue`

## Verification

Static checks added:

- `crmeb/tests/yfth_franchise_opening_contract_check.php`
- `crmeb/tests/yfth_franchise_opening_real_flow_check.php`

They check migration shape, API routes, explicit admin permission assertions, user-forbidden fields, controlled state transitions, store-bound identity grant, audit use, supply-chain read-only boundary, and CRMEB order/payment/inventory non-mutation boundaries.

P1 hardening checks now also cover:

- User acceptance detail does not implicitly create acceptance records.
- Finance confirmation no longer pre-creates acceptance.
- Acceptance submit uses the complete upstream gate.
- Headquarters acceptance pass rechecks the same upstream gate plus active store binding.
- Missing, partial, duplicate, or non-approved required tasks cannot satisfy the acceptance gate.
- User-submitted `reviewer_uid` and other operator/store/status fields are rejected.

MySQL 8.0.46 migration validation was completed after the P1 hardening:

- Temporary MySQL 8.0.46 database imported from `crmeb/public/install/crmeb.sql`.
- Temporary `.env` used file cache and the isolated database; production database/config was not used.
- `migrate:run` created the 8 opening tables, key unique indexes, and `yfth-franchise-opening-index` permission.
- `migrate:rollback -t 0` removed the opening tables and permission.
- A second `migrate:run` restored the same tables, indexes, and permission.

## Not Implemented

- Real electronic contract signing.
- Online franchise fee payment.
- CRMEB order/payment/refund integration for franchise fee.
- Production store creation from the user side.
- Revenue sharing, settlement, recommendation rewards, product quota, procurement payment.
- Purchase after-sale reversal.
- Production deployment or production database migration.
