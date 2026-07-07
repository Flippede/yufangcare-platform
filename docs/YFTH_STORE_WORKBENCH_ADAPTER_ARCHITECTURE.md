# YFTH Store Workbench Business Adapter V1

## Scope

This round connects the existing miniapp business-role shell to real store-scoped business data for the first time.

Implemented:

- Store appointment management for `franchisee`, `store_manager`, and `store_staff` user-token identities.
- Store service writeoff precheck, token writeoff, digital-code writeoff, writeoff record list/detail, and writeoff result lookup.
- Store order read-only list/detail lookup.
- A user-token API controller and a thin backend adapter service that reuse existing appointment, writeoff, identity, and order data access capabilities.
- A uni-app workbench page that calls the new user-token APIs instead of admin-token APIs.

Not implemented:

- No new appointment, writeoff, order, payment, refund, or package state machine.
- No new database table or migration.
- No order modification, order fulfillment, delivery, refund, payment, or inventory action.
- No procurement, product quota, franchise contract, recommendation reward, settlement, or mentor business workflow.

## Trust Boundary

The miniapp still authenticates with the normal CRMEB user token. The store workbench does not expose or reuse `admin_token`.

Routes are registered in `crmeb/app/api/route/yfth_service.php` under `yfth/store_workbench/*` and use the existing user-token middleware:

- `AuthTokenMiddleware`
- `StationOpenMiddleware`
- `AllowOriginMiddleware`

The backend resolves every request through `CurrentBusinessContextServices::fromRequest()`. The client-provided `role_code` and `store_id` are treated only as requested context; the server validates the current user, role, store relation, store status, and role permissions before delegating to any business service.

## Backend Adapter

The adapter is `crmeb/app/services/yfth/StoreWorkbenchBusinessAdapterServices.php`.

It intentionally stays thin:

- Appointment list/detail/confirm/reject/cancel delegates to `ServiceAppointmentBookingServices`.
- Writeoff precheck/token/digital/list/detail/result delegates to `ServiceAppointmentWriteoffServices`.
- Store order list/detail uses `StoreOrderDao` and `StoreOrderCartInfoDao` for read-only scoped queries.
- Store-role context is converted into an explicit YFTH business operator context, not a forged backend administrator context.

The API controller is `crmeb/app/api/controller/v1/yfth/StoreWorkbenchController.php`.

## Store Operator Context

The previous "admin-compatible store scope" wording is historical and should not be used for the current implementation.

The current user-token workbench creates an `operator_info` payload containing only:

- `yfth_operator_context.operator_type = user_store_role`
- `operator_uid`
- `role_code`
- `store_id`
- `authorized_store_ids`
- `primary_role_code`
- `permission_scope`
- `allowed_actions`
- `source = yfth_user_token_store_workbench`

It does not set a backend admin id, backend admin role, super-admin flag, `level`, `adminInfo`, or `yfth_admin_context`. `AdminStoreContextServices` now recognizes this explicit operator context separately from normal backend-admin context and normalizes it to store-scope data with `admin_id = 0`, `is_super = false`, `is_headquarter = false`, and `operator_type = user_store_role`.

This keeps the reused appointment/writeoff permission path limited to store scope. It does not grant access to adminapi, backend role permissions, backend login, or headquarter exception writeoff.

## Service Split

The adapter does not copy the appointment or writeoff state machines.

`ServiceAppointmentBookingServices` now exposes store-operator wrappers:

- `storeOperatorList`
- `storeOperatorDetail`
- `confirmByStoreOperator`
- `rejectByStoreOperator`
- `cancelByStoreOperator`

The existing backend-admin methods and the new user-store methods delegate into shared private core methods. The operation action names remain distinct (`store_confirm`, `store_reject`, `store_cancel`) so idempotency and audit can record the source without creating duplicate state transitions.

`ServiceAppointmentWriteoffServices` now exposes store-operator wrappers:

- `precheckByStoreToken`
- `precheckByStoreDigital`
- `writeoffByStoreToken`
- `writeoffByStoreDigital`
- `storeOperatorList`
- `storeOperatorDetail`
- `writeoffResultForAppointmentByStoreOperator`

The final writeoff transaction, appointment completion, benefit consumption, writeoff record creation, event creation, and idempotency paths remain shared with the existing writeoff implementation. Store workbench writeoff uses `store_writeoff_token` and `store_writeoff_digital` idempotency actions and records `operator_type = user_store_role`.

The legacy `used_admin_id` column continues to store the numeric operator id for compatibility with the existing table shape. For user-token store workbench writes, that value is the CRMEB user UID and must be interpreted together with `operator_type = user_store_role`.

Writeoff result lookup is read-only but still store-scoped. `StoreWorkbenchBusinessAdapterServices::writeoffResult()` resolves the current user-token store scope and delegates only to `ServiceAppointmentWriteoffServices::writeoffResultForAppointmentByStoreOperator($appointmentId, $operatorInfo)`. That service loads the appointment first, verifies that the operator can read the appointment's store, returns `status = none` only after that same-store check, and re-validates any succeeded writeoff record's `appointment_id` and `store_id` before returning the minimal `formatWriteoffRecord(..., false)` payload. The store workbench adapter must not call the legacy unscoped `writeoffResultForAppointment()` method.

## Role Rules

- `franchisee`: may read store dashboard data, appointments, writeoff records, and store orders; may confirm, reject, cancel, and write off within authorized stores.
- `store_manager`: same operational scope as franchisee, limited to authorized stores.
- `store_staff`: may read appointments, writeoff records, and store orders; may perform writeoff; may not confirm, reject, or cancel appointments.
- `service_mentor`: remains outside this store-workbench adapter and does not receive store appointment/writeoff/order powers in this round.

Cross-store access is blocked by server-side store-scope validation. The frontend cannot grant itself store access by changing local context.

## Store Orders

Store order support is read-only:

- Lists are scoped by `store_id`.
- Detail lookup requires the order's `store_id` to match the resolved current store.
- Returned customer name, phone, and address are masked for workbench display.
- No order payment, refund, shipment, verification, inventory, or lifecycle method is called by the adapter.

## Frontend

The workbench page is `template/uni-app/pages/yfth/workbench/index.vue`.

It now shows real store modules:

- Today's overview.
- Appointment list and appointment detail actions.
- Writeoff scan/input, precheck, confirm, result, and record list.
- Store order read-only list/detail.
- Existing role and store switching.

The page imports store-workbench functions from `template/uni-app/api/yfth.js`. It does not import `api/yfth_admin.js`, does not use `admin_token`, and does not route user-token identities into admin pages.

## Data And Migration

This round adds no database migration. It reuses:

- YFTH identity and store-role tables from the business foundation domain.
- Appointment and slot data from Service Appointment V1.
- Dynamic-code/writeoff records from Writeoff V1.
- CRMEB store order and order cart info tables for read-only queries.

## Audit And Idempotency

Appointment and writeoff mutations continue to go through the existing appointment/writeoff services, so their existing timeline, audit, writeoff record, and idempotency behavior is preserved.

The adapter passes the current user id and validated store role into the delegated service context. It does not create a parallel audit table and does not duplicate state-machine writes.

Audit/event rows include the operator id, role code, and operator type where the underlying V1 tables support those fields. Appointment events and writeoff records were verified with `operator_type = user_store_role` for store workbench operations.

Headquarter exception writeoff remains backend-admin only through `exceptionWriteoff()`. The user-token store workbench route group has no `writeoff/exception` route and the adapter does not call `exceptionWriteoff()`.

## Runtime Validation

The isolated runtime validation script is:

```bash
php crmeb/tests/yfth_store_workbench_adapter_real_flow_check.php
```

It performs lightweight static contract checks by default. When the local isolated environment variables are provided and `YFTH_STORE_WORKBENCH_REAL_FLOW_EXECUTE=1`, it starts or uses a local CRMEB API endpoint and validates the full HTTP path against real MySQL and Redis data.

The completed validation used:

- PHP 7.4.33 portable runtime.
- MySQL Community Server 8.0.46.
- Temporary MySQL port `33219`.
- Temporary database `yfth_storewb_validation`.
- Redis 5.0.14.1 on port `6381`, database `14`.
- Local API base URL `http://127.0.0.1:18081`.
- CRMEB install baseline from `crmeb/public/install/crmeb.sql`.
- Current YFTH migrations applied after baseline import.
- Temporary CRMEB users, tokens, stores, identities, appointments, service benefits, dynamic codes, writeoff records, and store orders.

Validated HTTP routes include:

- `GET /api/yfth/store_workbench/overview`
- `GET /api/yfth/store_workbench/appointments`
- `GET /api/yfth/store_workbench/appointments/:id`
- `POST /api/yfth/store_workbench/appointments/:id/confirm`
- `POST /api/yfth/store_workbench/appointments/:id/reject`
- `POST /api/yfth/store_workbench/appointments/:id/cancel`
- `POST /api/yfth/store_workbench/writeoff/precheck`
- `POST /api/yfth/store_workbench/writeoff/token`
- `POST /api/yfth/store_workbench/writeoff/digital`
- `GET /api/yfth/store_workbench/writeoff/records`
- `GET /api/yfth/store_workbench/writeoff/records/:id`
- `GET /api/yfth/store_workbench/writeoff/result/:id`
- `GET /api/yfth/store_workbench/orders`
- `GET /api/yfth/store_workbench/orders/:id`

Validation results:

- Customer and service mentor tokens are forbidden from the store workbench.
- Revoked identity and disabled-store role are forbidden.
- Store staff can read appointments/orders and execute writeoff, but cannot confirm, reject, or cancel appointments.
- Store manager can confirm, reject, and cancel only authorized-store appointments.
- Franchisee can switch between authorized stores A and B, cannot use store C, and cannot perform write operations in an all-store context.
- Cross-store appointment, writeoff, and order access is denied without business writes.
- Cross-store writeoff result lookup by appointment id is denied for staff, manager, and franchisee contexts from another store, and the failure path leaves appointment, dynamic-code, benefit-lock, benefit-item, event, audit, and writeoff-record snapshots unchanged.
- Same-store writeoff result lookup succeeds for staff, manager, and franchisee, same-store unwritten appointments return `status = none`, and customer, service mentor, revoked identity, disabled-store role, and missing appointment paths fail safely.
- Writeoff result responses are field-whitelisted and do not expose dynamic tokens, digital codes, token hashes, admin token material, full user contact fields, idempotency keys, internal snapshots, or raw operator ids.
- Digital-code precheck is read-only.
- Digital-code and QR-token writeoff are idempotent; appointment completion, writeoff record, event, and benefit consumption are created exactly once.
- Wrong digital-code attempts are limited; the sixth failure is rate-limited in the tested boundary.
- Store order list/detail responses are store-scoped, field-whitelisted, masked, and read-only.
- User-token headquarter exception writeoff is unavailable.

The validation did not use production `.env`, production database, production Redis, production user data, real AppID/AppSecret, WeChat upload, or server deployment. Temporary MySQL, Redis, PHP extension files, API router files, environment files, and fixture data were cleaned after the run.

The 2026-07-07 P1 cross-store writeoff-result rerun used the same script against MySQL Community Server 8.0.46, an isolated database named `yfth_storewb_validation_*`, a temporary local API server at `http://127.0.0.1:18121`, and file cache driver. Redis probe was not executed in that rerun because the portable PHP runtime did not load a Redis extension; the real route, middleware, controller, adapter, service, and MySQL path were still exercised through HTTP.

## Verification

The lightweight backend contract check is:

```bash
php crmeb/tests/yfth_store_workbench_adapter_contract_check.php
php crmeb/tests/yfth_store_workbench_adapter_real_flow_check.php
```

The uni-app shell contract check is:

```bash
node template/uni-app/tests/yfth_multi_role_shell_contract_check.js
node template/uni-app/tests/yfth_request_fallback_check.js
```

Runtime H5 and mp-weixin build verification should continue to follow `docs/YFTH_UNIAPP_BUILD_GUIDE.md`.

This branch still needs a read-only architecture audit before any `main` merge decision.
