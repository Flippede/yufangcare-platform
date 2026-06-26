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

Not implemented in this round:

- Appointment creation, confirmation, cancellation, reschedule, or check-in.
- Benefit locking or release.
- Dynamic QR/code writeoff.
- Paid service order creation.
- Notifications, rewards, delivery, inventory, or franchise settlement.

## Data Model

`yfth_service_project` stores headquarters-owned service definitions. It records service code/name/type, description, suggested duration, benefit usage settings, future paid-service flag, status, sort, and operator audit columns.

`yfth_store_service` stores which store can provide which service project. It records store-specific display text, actual duration, confirmation mode, booking enabled flag, advance booking window, reserved cancel deadline, default capacity, timezone, status, and a nullable `active_key` unique constraint for one active binding per store and project.

`yfth_store_service_schedule_rule` stores active weekly service windows by ISO weekday. Overlap is blocked in the service layer and exact duplicate active rules are guarded by `active_key`.

`yfth_store_service_special_day` stores date-level exceptions:

- `closed`: whole-day booking close.
- `extra`: an extra non-overlapping service window.
- `capacity_override`: capacity overlay for calculated slots.

There is no real appointment occupancy table in V1. Appointment rows, service benefit locks, check-in records, dynamic codes, and writeoff records must be introduced in later rounds instead of being stored in order remarks, stock, balance, points, commission, or generic JSON fields.

## Service Classes

The admin and public APIs use the following service layer classes:

- `ServiceProjectServices` for headquarters service project definition.
- `StoreServiceAppointmentServices` for store service authorization and active binding lookup.
- `StoreServiceScheduleServices` for weekly schedule rules, special-day rules, conflict checks, and admin slot preview.
- `ServiceAppointmentQueryServices` for public read-only service, store, date, and slot queries.
- `ServiceAppointmentBaseServices` for shared date, timezone, permission, and audit helpers.

## Slot Strategy

V1 uses realtime calculation from weekly rules plus special-day overlays. No slot instance table is generated yet because there is no real appointment occupancy in this round.

Public slot responses include:

- `capacity`
- `occupied_count`
- `locked_count`
- `remaining_capacity`
- `capacity_source`
- `slot_generation_mode = rule_realtime_with_special_day_overlay`

`occupied_count` and `locked_count` intentionally remain `0` until the next appointment-creation round introduces real records and transactional occupancy.

V1 does not support cross-day service windows. The service layer rejects ranges where the end minute is not within the same service date boundary.

## Permission Boundary

CRMEB admin menu/API permission remains the first gate. Service-layer checks add store-scope constraints from server-side `adminInfo`:

- Headquarters admins can manage global service projects and store authorizations.
- Store managers can manage schedules and special days only for their own store when store scope is present.
- Store staff cannot configure services, schedules, or capacity.

Public APIs do not trust client `store_id` alone. They verify active store state, active service project, active store service authorization, appointment enabled flag, and the store `reservation_service` capability.

## Audit

Service appointment operations reuse the unified YFTH foundation audit path:

- Audit service: `AuditEventServices::recordSafely()`.
- Audit table: `yfth_audit_event`.
- Business domain: `yfth_service_appointment`.
- Shared helper: `ServiceAppointmentBaseServices::recordServiceAudit()`.

The audited object types are `service_project`, `store_service`, `schedule_rule`, and `special_day`. Create, update, and disable operations record operator uid, role code, store id, object type, object id, action, before state, after state, reason, request id, ip, and timestamps through the existing audit table columns. The module does not write `yfth_sensitive_operation_log`, and there is no split where some service appointment changes go to a second audit table.

## Reuse For Next Round

Appointment creation should reuse:

- `ServiceAppointmentQueryServices::daySlots()`
- `ServiceAppointmentQueryServices::slotsForBinding()`
- `StoreServiceAppointmentServices::activeBinding()`
- Schedule and special-day conflict rules in `StoreServiceScheduleServices`
- `yfth_store_service.default_capacity`, `advance_min_minutes`, and `advance_max_days`

The next round should add a real appointment table and transactional occupancy/locking instead of reusing order remarks, stock, balance, points, or commission fields.
