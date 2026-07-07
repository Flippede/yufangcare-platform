# YFTH Franchise Customer CRM V1 Architecture

## Scope

Franchise Customer CRM V1 establishes the customer relationship foundation for the franchisee and store operation loop. It adds customer attribution, customer list/detail, operating status, customer source, and follow records.

This V1 does not implement recommendation rewards, distribution rebate, franchise contracts, procurement, inventory, product quota, settlement, revenue sharing, or supply-chain flows.

## Stable Base Reused

- Customer identity remains CRMEB `user.uid`.
- No new login, member, user, or account system is introduced.
- User-token business context is resolved by `CurrentBusinessContextServices`.
- Store availability is checked by `StoreAccessServices`.
- Unified YFTH audit writes to `yfth_audit_event` through `AuditEventServices`.
- 5980 package status is read from `yfth_package_instance`.
- Appointment presence is read from `yfth_service_appointment`.

## Tables

### `yfth_customer_relation`

Stores the operating attribution between a CRMEB user and a YFTH store.

Core fields:

- `uid`: CRMEB customer uid.
- `store_id`: operating store.
- `owner_uid`: first binding operator uid.
- `source`: customer source, such as `store_visit`, `qr_scan`, `activity`, `online`, `franchise_referral`, or `headquarters_assign`.
- `customer_status`: display/status field, one of `potential`, `leads`, `registered`, `purchased`, `serving`, `repeat`, `lost`.
- `status`: active/inactive.
- `bind_time`, `create_time`, `update_time`.
- `active_key`: nullable unique guard. Active records use `uid` as the unique key, preventing a customer from being actively owned by multiple stores.

This V1 does not implement headquarters transfer. Later transfer must explicitly close the old active relation and create a new relation through an audited headquarters flow.

### `yfth_customer_follow_record`

Stores customer follow records.

Core fields:

- `customer_relation_id`
- `uid`
- `store_id`
- `operator_uid`
- `follow_type`
- `content`
- `next_follow_time`
- `create_time`

Follow records are append-only in this V1.

## Backend Services

### `FranchiseCustomerServices`

Responsibilities:

- Resolve user-token role and store context.
- Allow only `franchisee`, `store_manager`, and `store_staff`.
- Reject customer and `service_mentor` contexts with `franchise_customer_role_forbidden`.
- Bind first customer attribution for the current store.
- Return current-store customer list and detail.
- Add customer follow records.
- Mask phone numbers and avoid sensitive customer fields.
- Write audit records for attribution binding and follow creation.

### API Controller

`FranchiseCustomerController` exposes only user-token routes:

- `GET /api/yfth/customer/list`
- `POST /api/yfth/customer/relation`
- `GET /api/yfth/customer/:id`
- `POST /api/yfth/customer/:id/follow`

Routes are registered in `crmeb/app/api/route/yfth_service.php` and use `AuthTokenMiddleware`.

There is no adminapi route and no `admin_token` dependency.

## Permission And Isolation

- The frontend may pass `role_code` and `store_id` as requested context, but authorization is recalculated server-side by `CurrentBusinessContextServices`.
- Every list query is constrained by the resolved current `store_id`.
- Detail and follow operations load by `customer_relation_id + store_id + active status`; they do not perform global `uid` detail lookup.
- `store_staff` can view and add follow records in the current store in V1.
- `service_mentor` and normal customer roles cannot access the module.
- Cross-store relation reads and writes are denied by relation-store matching.

## Data Safety

Customer list/detail returns only safe fields:

- `uid`
- `nickname`
- `avatar`
- `phone_masked`
- `source`
- `customer_status`
- package/appointment presence flags
- follow timestamps

It does not return full phone, ID card, address, openid, unionid, payment detail, refund detail, order payment credentials, or internal tokens.

## Audit

Audit domain: `yfth_franchise_customer`.

Recorded actions:

- `customer_relation` / `bind`
- `customer_follow_record` / `create`

Audit fields include operator uid, role code, store id, object type, object id, sanitized before/after snapshots, and time through the existing `yfth_audit_event` table.

## Frontend

New uni-app pages:

- `pages/yfth/workbench/customer/index`
- `pages/yfth/workbench/customer/detail`
- `pages/yfth/workbench/customer/follow`

The store workbench page links to customer management, but the customer module is kept in its own pages to avoid enlarging `workbench/index.vue`.

## Current Limitations

- No automatic customer status state machine.
- No headquarters transfer or reassignment flow.
- No recommendation reward, commission, distribution, or settlement logic.
- No customer import.
- No advanced customer search beyond current safe V1 filters.
- No production deployment or production database migration has been performed.

## Validation Contract

`crmeb/tests/yfth_franchise_customer_contract_check.php` asserts:

- migration/table/index existence;
- user-token routes and middleware;
- service role/store checks;
- customer/source/status fields;
- active relation uniqueness guard;
- masked phone output and sensitive-field absence;
- audit domain/service;
- uni-app API helper and page registration.
