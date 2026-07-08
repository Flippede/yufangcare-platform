# YFTH Franchise Application Workflow V1

## Scope

Franchise Application V1 establishes the first headquarters招商 workflow:

- A CRMEB user submits a franchise application from the miniapp cooperation center.
- Headquarters admins view applications in adminapi.
- Headquarters assigns an owner, advances the application status, and records follow-up communication.
- The user can view only their own applications and progress.

This V1 does not create a franchisee identity, store, contract, payment, settlement, procurement, inventory, quota, recommendation reward, or revenue-sharing flow.

## Identity Boundary

- Applicant identity is the existing CRMEB `user.uid`.
- The user API always reads `applicant_uid` from `Request::uid()`.
- The miniapp is forbidden from submitting `uid`, `applicant_uid`, `assigned_uid`, `status`, or `store_id`.
- Franchise application status is not a franchisee identity.
- Franchisee identity can only be granted by a later audited contract/payment/store-opening flow.

## Tables

### `yfth_franchise_application`

Core fields:

- `application_no`: unique application number.
- `applicant_uid`: CRMEB user uid.
- `name`, `phone`, `city`, `region`, `intention_area`, `budget`, `remark`.
- `source`: currently `miniapp_cooperation_center`.
- `status`: workflow status.
- `assigned_uid`: headquarters admin owner id.
- `create_time`, `update_time`.

Indexes include applicant/status, assigned owner/status, status/time, city/status, phone, and the unique application number.

### `yfth_franchise_follow_record`

Core fields:

- `application_id`
- `operator_uid`: headquarters admin id.
- `type`: `phone`, `wechat`, `meeting`, `inspection`, or `other`.
- `content`
- `next_time`
- `create_time`

Follow records are append-only in V1.

## Status Model

Reserved complete lifecycle:

`draft -> submitted -> contacting -> communicating -> inspecting -> pending_contract -> signed -> preparing -> opened -> terminated`

Implemented V1 transitions:

- `draft -> submitted`
- `submitted -> contacting`
- `contacting -> communicating`
- `communicating -> inspecting`
- `inspecting -> pending_contract`

`signed`, `preparing`, `opened`, and `terminated` are reserved and rejected by the V1 status API.

## APIs

### User Token APIs

Registered in `crmeb/app/api/route/yfth_service.php` with `AuthTokenMiddleware`:

- `POST /api/yfth/franchise/application`
- `GET /api/yfth/franchise/application/my`
- `GET /api/yfth/franchise/application/:id`

User list and detail are constrained by `applicant_uid = request uid` and return only masked/safe progress fields.

### Headquarters Admin APIs

Registered in `crmeb/app/adminapi/route/yfth.php` with `AdminAuthTokenMiddleware`, `AdminCheckRoleMiddleware`, and explicit `SystemRoleServices::assertApiAuthForAdmin()` checks:

- `GET /adminapi/yfth/franchise_application/application`
- `GET /adminapi/yfth/franchise_application/application/:id`
- `POST /adminapi/yfth/franchise_application/application/:id/assign`
- `POST /adminapi/yfth/franchise_application/application/:id/status`
- `POST /adminapi/yfth/franchise_application/application/:id/follow`

The menu permission root is `yfth-franchise-application-index`.
The service also calls `AdminStoreContextServices::assertHeadquarterScope()`, so a backend account must be super admin or have headquarters YFTH scope; a store-scoped backend account is not enough.

## Permissions

- Normal CRMEB users can submit and view only their own applications.
- Headquarters admins with the registered API permissions can list, detail, assign, advance status, and add follow records.
- User-token business roles such as `franchisee`, `store_manager`, `store_staff`, and `service_mentor` do not receive headquarters admin APIs.
- This V1 does not expose franchise application management through the store workbench.

## Audit

Unified YFTH audit uses `yfth_audit_event` through `AuditEventServices`.

Audit domain: `yfth_franchise_application`.

Recorded actions:

- `franchise_application` / `submit`
- `franchise_application` / `assign_owner`
- `franchise_application` / `status_change`
- `franchise_follow_record` / `create`

Audit snapshots are sanitized by the existing `YfthFoundationBaseServices::sanitizeState()` path.

## Frontend

Miniapp pages:

- `template/uni-app/pages/yfth/franchise/index.vue`
- `template/uni-app/pages/yfth/franchise/apply.vue`
- `template/uni-app/pages/yfth/franchise/detail.vue`

The user center links to the cooperation center entry. The store workbench is not expanded.

Admin page:

- `template/admin/src/pages/yfth/franchiseApplication/index.vue`

## Current Limitations

- No contract generation or signing.
- No franchise fee payment.
- No automatic franchisee identity grant.
- No store creation or opening acceptance.
- No recommendation reward, procurement, inventory, product quota, settlement, or revenue sharing.
- No production deployment or production database migration has been performed.

## Verification

The static contract check is:

```bash
php crmeb/tests/yfth_franchise_application_contract_check.php
```

It checks table/migration shape, route middleware, admin explicit permission assertions, user DTO safety, page registration, and the absence of direct user-controlled identity/status fields.

This feature-branch closure also verified:

- PHP syntax for changed backend, migration, and test files.
- Isolated MySQL 8.0.46 migration `up/down/up` for `20260708110000_create_yfth_franchise_application_tables.php`; the rollback path drops both application tables and removes the seeded franchise-application menu/API permissions.
- Admin production build with the existing Vue2/ElementUI toolchain. Existing CSS order, asset-size, and Browserslist warnings remain non-blocking.
- uni-app H5 production build through the documented HBuilderX `uniapp-cli` + Node 18 path.
- mp-weixin production compile through the same HBuilderX `uniapp-cli` + Node 18 `--no-opt` path. No WeChat upload was performed.

Not executed in this V1 closure: production deployment, production database migration, live backend browser walkthrough, real AppID/AppSecret/private key usage, and WeChat platform upload.
