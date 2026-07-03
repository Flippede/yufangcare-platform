# YFTH Service Appointment Domain V1

This document records the first foundation layer for service appointment, check-in, and dynamic benefit writeoff.

## Scope

Implemented in this round:

- Service project definitions.
- Store service authorization.
- Weekly schedule rules.
- Special date close, extra slot, and capacity override rules.
- Admin configuration APIs and page.
- Public read-only APIs for service projects, available stores, dates, and slots.
- User appointment creation, my-list/detail, cancellation, and reschedule APIs/pages.
- Admin appointment list/detail, manual confirm, reject, and cancel APIs/page actions.
- Transactional slot capacity instances and service-benefit locks for 5980 package service benefits.
- User dynamic QR token and 6-digit digital writeoff code generation, refresh, and status query.
- Store precheck, QR writeoff, digital-code writeoff, headquarters exception writeoff, and writeoff record list/detail.
- Atomic check-in, final service-benefit consumption, writeoff record creation, appointment completion, event timeline, audit, and idempotent repeat handling.

Not implemented in this round:

- Writeoff reversal, refund recovery, or undo.
- Service review.
- Automatic no-show processing.
- Paid service order creation.
- Notifications, rewards, delivery, inventory, family-member booking, staff resource scheduling, or franchise settlement.

## Data Model

`yfth_service_project` stores headquarters-owned service definitions. It records service code/name/type, description, suggested duration, benefit usage settings, future paid-service flag, status, sort, and operator audit columns.

`yfth_store_service` stores which store can provide which service project. It records store-specific display text, actual duration, confirmation mode, booking enabled flag, advance booking window, reserved cancel deadline, default capacity, timezone, status, and a nullable `active_key` unique constraint for one active binding per store and project.

`yfth_admin_store_scope` stores backend administrator YFTH business scope resolved from the CRMEB admin token chain. `store_id = 0` with `role_code = headquarter_operator` is headquarters scope; store-scoped roles use active `system_store.id` with `franchisee`, `store_manager`, or `store_staff`. The service appointment write path no longer trusts client-injected `store_id`, `store_ids`, or `yfth_store_role_code` fields.

`yfth_store_service_schedule_rule` stores active weekly service windows by ISO weekday. Overlap is blocked in the service layer and exact duplicate active rules are guarded by `active_key`.

`yfth_store_service_special_day` stores date-level exceptions:

- `closed`: whole-day booking close.
- `extra`: an extra non-overlapping service window.
- `capacity_override`: capacity overlay for calculated slots.

`yfth_service_appointment` stores user-created appointments. It references the user, store, store service, service project, concrete 5980 package instance, benefit plan, benefit period, benefit item, slot snapshot, status, confirm mode, source type, user note, cancellation/rejection/confirmation operator fields, idempotency key, request id, and store/service/benefit snapshots.

`yfth_service_appointment_slot` stores lockable capacity instances created on demand from the realtime schedule result. The unique `slot_key` is `store_service_id + service_date + start_minute + end_minute`; `locked_count` represents pending manual confirmations and `occupied_count` represents confirmed appointments.

`yfth_service_benefit_lock` stores service-benefit locks independently from orders, balance, points, stock, commission, and order remarks. The nullable unique `active_key` prevents more than one active lock for the same concrete benefit item.

`yfth_service_appointment_event` stores appointment status, slot-change, and writeoff history for create, confirm, reject, cancel, reschedule, `checked_in`, `benefit_written_off`, and `completed` operations.

`yfth_service_dynamic_code` stores dynamic writeoff code records. It keeps only SHA-256 hashes for QR tokens and 6-digit numeric codes, status, issued/expire/used/invalidated times, audit attempt counters, operator metadata after use, request id, a nullable unique `active_key` that permits only one active code per appointment, and a nullable unique `digital_active_key` that permits only one active identical numeric code in the same store.

`yfth_service_writeoff_record` stores successful service writeoff records. It references appointment, user, store, service project, concrete package instance, plan, period, benefit item, benefit lock, optional dynamic code, writeoff method, operator, before/after statuses, writeoff time, service-side reason, idempotency key, request id, and sanitized snapshot. A nullable unique `active_key` prevents more than one successful writeoff for one appointment.

`yfth_service_appointment` also records `check_in_at`, `writeoff_at`, `completed_at`, `writeoff_id`, `writeoff_store_id`, `writeoff_operator_id`, `writeoff_operator_type`, and `writeoff_method`.

`yfth_service_benefit_lock` also records `writeoff_id`, `consumed_time`, and `consume_reason` when the locked service benefit is finally consumed.

## Service Classes

The admin and public APIs use the following service layer classes:

- `ServiceProjectServices` for headquarters service project definition.
- `StoreServiceAppointmentServices` for store service authorization and active binding lookup.
- `StoreServiceScheduleServices` for weekly schedule rules, special-day rules, conflict checks, and admin slot preview.
- `ServiceAppointmentQueryServices` for public read-only service, store, date, and slot queries.
- `ServiceAppointmentBookingServices` for appointment creation, status transitions, slot capacity locking, benefit locking, idempotency, user operations, and admin operations.
- `ServiceAppointmentWriteoffServices` for dynamic code generation, code status, store precheck, QR/digital/headquarters writeoff, writeoff record list/detail, completion transaction, events, audit, and idempotency.
- `ServiceBenefitConsumptionServices` for final service benefit consumption. It locks and validates the benefit item, package instance, plan, and period, uses the package benefit state machine for `available -> used`, and updates fulfilled counters.
- `ServiceAppointmentBaseServices` for shared date, timezone, permission, and audit helpers.
- `AdminStoreContextServices` for resolving backend admin headquarters/store scope from `yfth_admin_store_scope`.

## Slot Strategy

V1 keeps realtime calculation from weekly rules plus special-day overlays as the source of truth for available time windows. When a user creates or reschedules an appointment, the booking service revalidates the realtime result and creates or reuses one `yfth_service_appointment_slot` row for the selected slot.

Public slot responses include:

- `capacity`
- `occupied_count`
- `locked_count`
- `remaining_capacity`
- `capacity_source`
- `slot_generation_mode = rule_realtime_with_special_day_overlay`

`occupied_count` and `locked_count` are `0` before any booking exists. Once appointments are created, public slot responses overlay persisted slot counts and return true remaining capacity. Pending manual appointments increment `locked_count`; confirmed appointments increment `occupied_count`. Cancellation, rejection, and reschedule release the old counter.

V1 does not support cross-day service windows. The service layer rejects ranges where the end minute is not within the same service date boundary.

## Permission Boundary

CRMEB admin menu/API permission remains the first gate. Service-layer checks add store-scope constraints from server-side `adminInfo`:

- Headquarters admins can manage global service projects and store authorizations.
- `AdminAuthTokenMiddleware` enriches `adminInfo` through `AdminStoreContextServices` after `AdminAuthServices::parseToken()`.
- Store managers can manage schedules and special days only for their own active store when a server-side scope row is present.
- Store staff cannot configure service projects, store authorizations, schedules, or capacity even if menu/API permission is misconfigured.
- Store staff, store managers, and franchisees can write off only appointments belonging to their own scoped active store.
- Headquarters and super admins can use the explicit headquarters exception writeoff API for exception handling; they are not silently treated as same-store staff on normal code writeoff.
- Non-super administrators without `yfth_admin_store_scope` rows cannot gain store write access by sending `store_id` or role-like fields from the client.
- Stopped or deleted stores fail write-scope checks and cannot be configured by store-scoped users.

Public APIs do not trust client `store_id` alone. They verify active store state, active service project, active store service authorization, appointment enabled flag, and the store `reservation_service` capability.

Public project detail responses use the `ServiceProjectServices::publicProjectRow()` whitelist. Backend maintenance fields such as creator/updater/disabled columns, timestamps, close reason, and raw benefit-template id storage are not exposed.

Date input accepts only real calendar dates in `YYYY-MM-DD` or `YYYYMMDD` form. Invalid dates such as `20260231`, `2026-02-31`, `20261301`, `20260000`, and non-leap `20260229` are rejected; leap day `20280229` is accepted.

Store-service authorization identity is immutable after creation. Updating `store_id` or `service_project_id` on an existing `yfth_store_service` row is rejected; changes requiring a different store/project pair must create a new authorization record and preserve history.

## Dynamic Code And Writeoff

- Default check-in window is 30 minutes before appointment start to 120 minutes after appointment end.
- Only the appointment owner can generate a dynamic code.
- The appointment must be `confirmed`, uncompleted, uncancelled, unrejected, in the check-in window, and must have an active `yfth_service_benefit_lock`.
- Code generation returns plaintext QR token and 6-digit code only once in the response. The database stores hashes only.
- Refreshing a code invalidates previous active unused codes for the appointment.
- Code TTL is 300 seconds. Expired, invalidated, used, or out-of-scope digital codes cannot complete a non-completed appointment.
- Numeric-code precheck is read-only. It does not increment `attempt_count`, change code status, invalidate codes, write appointments, consume benefit locks, or create writeoff records.
- Numeric-code lookup first resolves the real backend administrator scope through `AdminStoreContextServices`, then searches only active unexpired issued codes inside that trusted store scope. Random missing codes, other-store real codes, expired codes, invalidated codes, and rate-limited requests return the same safe error semantics.
- Numeric-code brute-force protection is request scoped instead of global-code scoped. The short-window key contains administrator id, trusted store scope, request IP, and the digital writeoff scene. Failed numeric precheck/writeoff requests increment this counter; successful writeoff clears it. The first through configured maximum failed requests execute normally, and the next request is temporarily limited.
- `attempt_count` is retained only as an audit/statistics column and is not used to let one store consume another store's code attempts.
- Code generation writes `digital_active_key = store_id + ':' + digital_code_hash` and relies on `uniq_yfth_svc_code_store_digital_active`. If a same-store numeric collision occurs, generation retries with a finite limit. The same numeric code may exist in different stores, but lookup never trusts client `store_id`.
- Completed repeats are recognized through the locked appointment/writeoff record and return already-written-off rather than consuming again.
- Headquarters exception writeoff requires a non-empty service-side reason. The reason is persisted to the writeoff record, appointment events, and unified YFTH audit.

Normal writeoff runs in one transaction:

1. Lock appointment and dynamic code when applicable.
2. Resolve real backend admin token scope through `AdminStoreContextServices`.
3. Revalidate active store, appointment status, dynamic-code window, and active service-benefit lock.
4. Create one `yfth_service_writeoff_record` guarded by appointment-level unique `active_key`.
5. Lock benefit item, package instance, plan, and period.
6. Mark the service benefit item `used` with `fulfillment_status = service_writeoff`.
7. Mark the service benefit lock `consumed`.
8. Mark the appointment `completed` and write `check_in_at`, `writeoff_at`, and `completed_at`.
9. Mark the dynamic code `used`.
10. Record `checked_in`, `benefit_written_off`, and `completed` events plus unified YFTH audit entries.

No undo, reversal, refund recovery, review, auto no-show, offline code, printed code, or independent paid service order exists in this V1.

## Audit

Service appointment operations reuse the unified YFTH foundation audit path:

- Audit service: `AuditEventServices::recordSafely()`.
- Audit table: `yfth_audit_event`.
- Business domain: `yfth_service_appointment`.
- Shared helper: `ServiceAppointmentBaseServices::recordServiceAudit()`.

The audited object types include `service_project`, `store_service`, `schedule_rule`, `special_day`, `appointment`, `dynamic_code`, and `writeoff_record`. Create, update, disable, booking transition, dynamic-code generation, and writeoff operations record operator uid, role code, store id, object type, object id, action, before state, after state, reason, request id, ip, and timestamps through the existing audit table columns. The module does not write `yfth_sensitive_operation_log`, and there is no split where some service appointment changes go to a second audit table.

## Reuse For Next Round

Further appointment and fulfillment development should reuse:

- `ServiceAppointmentQueryServices::daySlots()`
- `ServiceAppointmentQueryServices::slotsForBinding()`
- `ServiceAppointmentBookingServices::adminDetail()`
- `ServiceAppointmentWriteoffServices::writeoffResultForAppointment()`
- `StoreServiceAppointmentServices::activeBinding()`
- Schedule and special-day conflict rules in `StoreServiceScheduleServices`
- `yfth_service_appointment`, `yfth_service_appointment_slot`, `yfth_service_benefit_lock`, `yfth_service_dynamic_code`, and `yfth_service_writeoff_record`
- `yfth_store_service.default_capacity`, `advance_min_minutes`, `advance_max_days`, and `cancel_deadline_minutes`

Future rounds should add reversal/no-show/notification/paid-service-order behavior as separate modules instead of reusing order remarks, stock, balance, points, or commission fields.

## Booking V1 On 2026-07-03

- Added booking tables: `yfth_service_appointment`, `yfth_service_appointment_slot`, `yfth_service_benefit_lock`, and `yfth_service_appointment_event`.
- Creation supports only concrete service-type 5980 benefit items. Product benefits, unavailable/expired/refunded/used/locked items, disabled projects, disabled store bindings, inactive stores, stores without `reservation_service`, invalid dates, and full slots are rejected.
- Writes use `yfth_idempotency_record` through `IdempotencyRecordServices` with business domain `yfth_service_appointment`.
- Manual-confirm store services create `pending_confirm` appointments and lock capacity; admin confirm moves locked capacity to occupied. Auto-confirm store services create `confirmed` appointments and occupy capacity immediately.
- User cancel, admin reject, admin cancel, and user reschedule release or move slot counters and release service-benefit locks where appropriate.
- Reserved statuses `signed_in` and `no_show` remain future state names. `completed` is now used by writeoff V1 after successful service writeoff and final benefit consumption.

## Check-in And Writeoff V1 On 2026-07-03

- Added writeoff migration: `20260703120000_create_yfth_service_writeoff_tables.php`.
- Added menu permission migration: `20260703120010_seed_yfth_service_writeoff_menus.php`.
- Added digital-code hardening migration: `20260703130000_harden_yfth_service_dynamic_codes.php`.
- Added tables/models/DAOs: `yfth_service_dynamic_code` and `yfth_service_writeoff_record`.
- Added writeoff fields to `yfth_service_appointment` and final consumption fields to `yfth_service_benefit_lock`.
- Added user APIs: `GET yfth/service/appointment/:id/code_status` and `POST yfth/service/appointment/:id/code`.
- Added admin APIs: writeoff list/detail, precheck, QR writeoff, digital writeoff, result query, and headquarters exception writeoff.
- Added uni-app appointment detail dynamic code display with QR component, 6-digit code, countdown, refresh, and writeoff result.
- Added uni-app store writeoff page with `uni.scanCode`, manual 6-digit code input, precheck, and confirm writeoff.
- Added admin appointment/writeoff visibility for writeoff method, time, status, writeoff records, and headquarters exception writeoff.
- User appointment list/detail output now uses a whitelist and no longer exposes raw events, raw benefit locks, request ids, idempotency keys, snapshots, or backend operator fields.
- User reschedule now locks old/new slots in stable slot id order and retries deadlock/lock-wait timeout a finite number of times.

## P1 Hardening On 2026-06-27

This section is historical context for the pre-booking hardening round; Booking V1 status is recorded above.

- Added backend admin scope migration/table: `yfth_admin_store_scope`.
- Added `YfthAdminStoreScope`, `YfthAdminStoreScopeDao`, and `AdminStoreContextServices`.
- `AdminAuthTokenMiddleware` now enriches real token-derived admin info with YFTH headquarters/store context.
- Service appointment writes require explicit server-side headquarters scope, super admin level, or active store-manager/franchisee scope for the target store.
- Store staff and no-scope administrators are rejected at service-layer write boundaries.
- Public service project detail uses a strict whitelist.
- Service date parsing now performs real calendar validation for both numeric and dashed formats.
- No appointment creation, check-in, dynamic code, writeoff, benefit locking, or paid service order table was introduced.
