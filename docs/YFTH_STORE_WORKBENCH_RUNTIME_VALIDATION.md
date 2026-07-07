# YFTH Store Workbench Runtime Validation

## Scope

This document records the local isolated runtime validation for Store Workbench Business Adapter V1.

The validation target was the user-token store workbench path:

```text
CRMEB user token -> AuthTokenMiddleware -> route -> StoreWorkbenchController -> CurrentBusinessContextServices -> StoreWorkbenchBusinessAdapterServices -> reused appointment/writeoff/order services -> MySQL
```

This validation does not approve a `main` merge by itself. The branch still requires a read-only architecture audit before any merge decision.

## Isolated Environment

- PHP: portable PHP 7.4.33.
- MySQL: MySQL Community Server 8.0.46.
- MySQL port: `33219`.
- Temporary database: `yfth_storewb_validation`.
- Redis: Redis server 5.0.14.1.
- Redis port/database: `6381`, DB `14`.
- Local API base URL: `http://127.0.0.1:18081`.
- CRMEB baseline: `crmeb/public/install/crmeb.sql`.
- Migration state: current YFTH migrations were applied after baseline import.

No production `.env`, production database, production Redis, production WeChat configuration, production user data, production AppID/AppSecret, production private key, server deployment, or WeChat upload was used.

Temporary CRMEB users, CRMEB user-token records, stores, identities, appointments, benefits, dynamic codes, writeoff data, and store orders were generated only inside the isolated database. Passwords and token values are intentionally not recorded in this document.

Temporary MySQL, Redis, PHP Redis extension files, API router files, temporary environment files, server lock files, and fixture data were cleaned after validation.

## Runtime Script

The real-flow script is:

```bash
php crmeb/tests/yfth_store_workbench_adapter_real_flow_check.php
```

Default mode performs contract and source-boundary checks. Real HTTP execution is enabled with local environment variables and:

```text
YFTH_STORE_WORKBENCH_REAL_FLOW_EXECUTE=1
```

The script can start a local PHP API server with:

```text
YFTH_STORE_WORKBENCH_START_SERVER=1
```

The local server uses a temporary router that maps only the isolated DB/cache configuration. It does not read the normal project `.env`.

## HTTP Routes Validated

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

The user-token route group has no `writeoff/exception` endpoint.

## Role Matrix Result

- Customer: store workbench routes are forbidden.
- Service mentor: store workbench routes are forbidden.
- Store A staff: can read Store A overview, appointments, appointment detail, writeoff records, and orders; can perform store writeoff; cannot confirm, reject, or cancel appointments.
- Store A manager: can read and operate Store A appointments; can confirm, reject, cancel, and write off within Store A; cannot operate Store B/C/D resources.
- Franchisee with Stores A/B: can switch to Store A or Store B and see only the selected store's data; cannot access Store C; cannot use an all-store context for writes.
- Store B staff: limited to Store B.
- Revoked identity user: forbidden.
- Disabled-store role user: forbidden.
- Invalid/expired token: rejected by the CRMEB token middleware path.

## Appointment Result

Real appointment fixtures covered pending-confirm, confirmed, cancellable, rejected, completed, and cross-store records.

Validated outcomes:

- Store staff read Store A appointment list/detail successfully.
- Store staff confirm/reject/cancel attempts were rejected and produced no business DB writes.
- Store manager confirm changed the appointment to confirmed and wrote a `user_store_role` event.
- Store manager reject changed the appointment to rejected, released related slot/benefit state, and wrote the reject reason/event.
- Store manager cancel changed the appointment to cancelled, released related slot/benefit state, and wrote the cancel reason/event.
- Repeated confirm/reject/cancel requests returned idempotent behavior and did not duplicate events, releases, or benefit changes.
- Cross-store appointment access and writes were rejected.

## Writeoff Result

Digital-code and QR-token flows were both executed through user-token HTTP APIs.

Validated outcomes:

- Digital-code precheck is read-only.
- Digital-code writeoff succeeds for the authorized store and fails for a cross-store real code.
- QR-token precheck is read-only.
- QR-token writeoff succeeds for the authorized store and fails for a cross-store token.
- Repeated writeoff requests return the idempotent result.
- Appointment completion is recorded once.
- Benefit consumption is recorded once.
- Writeoff record is created once.
- Writeoff event/audit path is created once.
- `operator_type = user_store_role` is recorded for store workbench writeoff.
- Wrong digital-code attempts fail, and the sixth tested failure reaches the rate-limit boundary.
- User-token headquarter exception writeoff is unavailable and cannot be reached by forged role/store input.

## Store Order Result

Store order list/detail were validated as read-only.

Scope validation:

- Store staff sees only the current store.
- Store manager sees only the current store.
- Franchisee sees only the currently selected authorized store.
- Customer and service mentor are rejected.
- Forged `store_id` and direct access to another store's order id are rejected.

Field whitelist validation:

- Responses include masked customer name, masked phone, masked address in detail, minimum order identity/status/amount fields, and minimum product item fields.
- Responses do not include full phone, full address, `openid`, `unionid`, merchant callback fields, internal refund fields, backend remark/mark fields, admin fields, request id, idempotency key, token, or internal snapshots.

Read-only validation:

- Order status, paid status, refund status, shipment status, inventory, commission, user points, and user balance snapshots were unchanged before/after list/detail reads.
- No order mutation, shipment, refund, payment, inventory, commission, or package-activation method was called.

## Workbench Metrics Result

The overview route was verified with real fixture data.

- Counts are scoped to the current resolved store.
- Franchisee Store A and Store B contexts return different store-specific metrics.
- Customer and service mentor cannot call overview.
- Empty matching data returns zero values instead of static fake data.
- Staff overview does not expose headquarter exception writeoff permission.

## Admin-Compatible Scope Closure

The implementation no longer describes the user-token store scope as a backend admin-compatible identity.

The store workbench passes:

- `operator_type = user_store_role`
- `operator_uid`
- `role_code`
- `store_id`
- `authorized_store_ids`
- `allowed_actions`
- `source = yfth_user_token_store_workbench`

It does not pass:

- forged admin id
- super-admin state
- backend admin role
- backend `adminInfo`
- `yfth_admin_context`
- `admin_token`

`AdminStoreContextServices` accepts the explicit `yfth_operator_context` and normalizes it as store scope only. Backend-admin context remains separate. Headquarter exception writeoff remains backend-admin only.

## Service Split

The following store-operator wrapper methods were added and delegate to shared core service methods:

- `ServiceAppointmentBookingServices::storeOperatorList`
- `ServiceAppointmentBookingServices::storeOperatorDetail`
- `ServiceAppointmentBookingServices::confirmByStoreOperator`
- `ServiceAppointmentBookingServices::rejectByStoreOperator`
- `ServiceAppointmentBookingServices::cancelByStoreOperator`
- `ServiceAppointmentWriteoffServices::precheckByStoreToken`
- `ServiceAppointmentWriteoffServices::precheckByStoreDigital`
- `ServiceAppointmentWriteoffServices::writeoffByStoreToken`
- `ServiceAppointmentWriteoffServices::writeoffByStoreDigital`
- `ServiceAppointmentWriteoffServices::storeOperatorList`
- `ServiceAppointmentWriteoffServices::storeOperatorDetail`

The existing appointment and writeoff state machines are reused. No second appointment state machine, second writeoff state machine, second token system, or second audit table was introduced.

## Frontend And Build Result

- H5 development build reached the local dev server startup at `http://localhost:8080/`; the long-running dev server was stopped manually after successful compilation.
- H5 production build completed successfully.
- mp-weixin production compile completed successfully.
- The workbench page remains a single page of about 605 lines. It is carrying several modules, but the current state is still readable enough for this closure. A later UX/product pass can split it into dedicated list/detail pages when more workflows are added.

A role-by-role browser walk-through against the live local backend was not executed in this closure. The true backend path was validated through real HTTP requests from the runtime script rather than direct PHP service calls or static H5-only checks.

## Commands Verified In This Round

- PHP syntax checks for changed backend files and test scripts.
- `php crmeb/tests/yfth_store_workbench_adapter_contract_check.php`
- `php crmeb/tests/yfth_service_appointment_contract_check.php`
- `php crmeb/tests/yfth_store_workbench_adapter_real_flow_check.php` with isolated real-flow execution.
- `node template/uni-app/tests/yfth_multi_role_shell_contract_check.js`
- `node template/uni-app/tests/yfth_request_fallback_check.js`
- H5 development compilation.
- H5 production build.
- mp-weixin production compile.
- `git diff --check`

## Remaining Items

- Perform a read-only architecture audit before any `main` merge decision.
- Perform an interactive browser role walk-through against a live local backend if the project controller wants UI-level evidence in addition to the real HTTP script.
- Continue leaving procurement, inventory replenishment, product quota, franchise contracts, recommendation rewards, mentor real business workflows, settlement, revenue sharing, and production deployment out of scope for this branch.
