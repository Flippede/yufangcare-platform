# YFTH Monthly Benefit Fulfillment V1

## Scope

Monthly Benefit Fulfillment V1 closes the product-benefit part of the 5980 ten-month benefit plan. It lets a user claim an available product benefit item, then lets headquarters confirm, reject, prepare, ship, complete, cancel, or mark exception, and lets authorized store operators confirm same-store self pickup.

This module does not implement service appointment, dynamic writeoff, product quota, referral reward, supply-chain purchase, CRMEB order fulfillment, payment, settlement, or production deployment.

## Data Model

New tables:

- `yfth_benefit_fulfillment`
- `yfth_benefit_fulfillment_event`

Authoritative benefit ownership remains in existing package-benefit tables: `yfth_package_instance`, `yfth_benefit_plan`, `yfth_benefit_period`, and `yfth_benefit_item`.

`yfth_benefit_fulfillment.active_key = benefit_item:{id}` is unique for non-terminal fulfillment rows. Cancel and reject clear this key so the user can claim the same still-available product benefit again. `idempotency_key` is unique for the user claim entry.

## State Machine

V1 fulfillment statuses are `pending_confirm`, `confirmed`, `preparing`, `shipped`, `picked_up`, `completed`, `cancelled`, `rejected`, and `exception`.

Architecture review P1 root cause was that headquarters `complete` could previously move `confirmed` directly to `completed`, and `completed` immediately called `consumeProductBenefit()`. That could consume a monthly product benefit before preparation, shipment, or pickup had actually happened.

P1 closure:

- Express delivery completion path is now `pending_confirm -> confirmed -> preparing -> shipped -> completed`.
- Headquarters `complete` for express delivery accepts only `shipped`; `pending_confirm`, `confirmed`, and `preparing` cannot be completed.
- Self pickup path is `pending_confirm -> confirmed -> preparing -> pickup_confirm -> completed` in V1. The store workbench `pickup_confirm` action is the explicit same-store pickup action and records event type `pickup_confirm`.
- Headquarters `complete` no longer bypasses self-pickup confirmation from `confirmed` or `preparing`.
- `cancelled`, `rejected`, `exception`, and already `completed` records cannot be completed through a new illegal source transition.
- Express `ship` requires both non-empty `delivery_company` and non-empty `delivery_no`.

Completion consumes the product benefit exactly once by updating `yfth_benefit_item.status = used`, `fulfillment_status = product_fulfilled`, `quantity_available = 0.00`, and `quantity_used = quantity_total`. It also increments `yfth_benefit_period.fulfilled_item_count` and `yfth_package_instance.fulfilled_count`.

Repeated `complete` or repeated same-store `pickup_confirm` returns the already completed fulfillment and does not run a second benefit consumption. The benefit item, period fulfilled count, and package instance fulfilled count are guarded by locked YFTH rows plus idempotency records.

## Trust Boundary

User claim accepts `benefit_item_id` only as a selector. The service locks and re-reads the benefit item, plan, period, and package instance, then derives `uid`, `store_id`, `package_instance_id`, `benefit_plan_id`, and `benefit_period_id` server-side.

User payloads containing `uid`, `owner_uid`, `store_id`, package/plan/period ids, status, product snapshot, quantity-used fields, or `active_key` are rejected at service layer, not only by controllers.

Express delivery requires a real CRMEB `user_address` record owned by the current user. Self pickup requires an active CRMEB `system_store` as pickup store.

## Permissions

User-token APIs:

- `GET /api/yfth/monthly_benefit/current`
- `GET /api/yfth/monthly_benefit/history`
- `GET /api/yfth/monthly_benefit/fulfillment/:id`
- `POST /api/yfth/monthly_benefit/claim`
- `POST /api/yfth/monthly_benefit/fulfillment/:id/cancel`

Store workbench user-token APIs:

- `GET /api/yfth/store_workbench/monthly_benefit/pickup`
- `GET /api/yfth/store_workbench/monthly_benefit/pickup/:id`
- `POST /api/yfth/store_workbench/monthly_benefit/pickup/:id/confirm`

Headquarters admin APIs:

- `/adminapi/yfth/monthly_benefit/fulfillment`
- `/adminapi/yfth/monthly_benefit/fulfillment/:id`
- `confirm`, `reject`, `prepare`, `ship`, `complete`, `exception`, and `cancel`

Store workbench APIs resolve `CurrentBusinessContextServices` and only allow `franchisee`, `store_manager`, or `store_staff` for the current active store. Admin writes require CRMEB admin token, explicit `SystemRoleServices::assertApiAuthForAdmin`, and headquarters scope through `AdminStoreContextServices::assertHeadquarterScope`.

## Idempotency And Audit

User claim requires a non-empty operation key or `Idempotency-Key` header and records it through `yfth_idempotency_record`. Cancel, headquarters status changes, and store self-pickup confirmation also call `IdempotencyRecordServices::begin/complete/fail`; the default server-side fallback key includes action, fulfillment id, and operator identity, while client operation keys are still checked for payload mismatch.

All fulfillment status changes write `yfth_benefit_fulfillment_event` and `yfth_audit_event`. Audit domain: `yfth_monthly_benefit_fulfillment`.

Product-benefit final consumption also writes the existing package-benefit audit path for `benefit_item` with action `product_fulfillment_complete`.

## CRMEB Boundary

V1 explicitly does not create CRMEB `store_order`, modify CRMEB order/payment/refund/product stock/SKU stock/sales, write YFTH supply-chain inventory balances or ledgers, write product quota accounts or ledgers, or write user balance, points, brokerage, distribution, commission, withdrawal, settlement, or revenue sharing.

## Frontend

Admin page:

- `template/admin/src/pages/yfth/monthlyBenefitFulfillment/index.vue`

Uni-app pages:

- `template/uni-app/pages/yfth/monthly_benefit/index.vue`
- `template/uni-app/pages/yfth/monthly_benefit/history.vue`
- `template/uni-app/pages/yfth/monthly_benefit/detail.vue`
- `template/uni-app/pages/yfth/workbench/monthly_benefit_pickup.vue`

The pages call real APIs and show empty states when no data exists. They do not use static fake fulfillment data.

## Verification

Added checks:

- `crmeb/tests/yfth_monthly_benefit_fulfillment_contract_check.php`
- `crmeb/tests/yfth_monthly_benefit_fulfillment_real_flow_check.php`

The real-flow check supports source-guard mode by default and isolated MySQL 8 mode through `YFTH_MONTHLY_BENEFIT_REAL_FLOW_EXECUTE=1` plus `YFTH_REAL_FLOW_ISOLATED_DB=1`.

Executed validation for this feature branch:

- PHP syntax check for all new and modified PHP files.
- `php crmeb/tests/yfth_monthly_benefit_fulfillment_contract_check.php`.
- `php crmeb/tests/yfth_monthly_benefit_fulfillment_real_flow_check.php` source guard mode.
- MySQL 8.0.46 isolated validation on temporary database `yfth_monthly_benefit_validation`: migration run, rollback to 0, rerun, duplicate run, index checks, duplicate active fulfillment guard, and duplicate idempotency guard.
- Isolated MySQL service-level real-flow coverage: user claim success, duplicate claim idempotency, claim payload mismatch, non-owner claim rejection, unopened/expired/frozen/closed/refunded claim rejection, service-layer `active_key` rejection, pending and confirmed user cancellation behavior, preparing/shipped/completed cancellation rejection, headquarters legal express path, `confirmed -> completed` rejection, `preparing -> completed` rejection, shipping field validation, repeated complete one-time benefit consumption, rejected/cancelled/exception complete rejection, same-store pickup confirmation, cross-store pickup rejection, customer/service-mentor rejection, store-staff/store-manager/franchisee pickup permission, final benefit consumption once, event timeline, audit writes, and unchanged CRMEB/order/stock/product-quota/supply-chain/reward boundary snapshots.
- Adjacent contracts: package benefit, service appointment, supply chain, and product quota.
- Admin production build from `template/admin`.
- Existing uni-app Node checks: multi-role shell contract and request fallback.
- H5 production build through repository-external HBuilderX `uniapp-cli` plus Node 18.20.8.
- mp-weixin production compile through repository-external HBuilderX `uniapp-cli --no-opt` plus Node 18.20.8.
- `git diff --check main..HEAD`.

No WeChat upload was performed, and no production AppID, private key, upload key, server, or production database was used.

## Not Implemented

- automatic monthly product shipping
- integration with CRMEB logistics orders
- CRMEB order creation for product benefit delivery
- product quota deduction or offset
- supply-chain stock deduction for monthly benefit shipping
- delivery after-sale reversal
- benefit recovery after completed fulfillment
- WeChat subscription message or SMS notification
- production deployment
- production database migration
