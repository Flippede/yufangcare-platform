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
- Store-role context is converted into an admin-compatible store scope only inside the backend service so existing appointment/writeoff permission checks can be reused without exposing an admin token to the miniapp.

The API controller is `crmeb/app/api/controller/v1/yfth/StoreWorkbenchController.php`.

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

## Verification

The lightweight backend contract check is:

```bash
php crmeb/tests/yfth_store_workbench_adapter_contract_check.php
```

The uni-app shell contract check is:

```bash
node template/uni-app/tests/yfth_multi_role_shell_contract_check.js
node template/uni-app/tests/yfth_request_fallback_check.js
```

Runtime H5 and mp-weixin build verification should continue to follow `docs/YFTH_UNIAPP_BUILD_GUIDE.md`.
