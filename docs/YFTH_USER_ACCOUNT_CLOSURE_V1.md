# YFTH Formal User Account Closure V1

## Purpose

This module replaces the temporary headquarters debug purge with a formal account-closure workflow. It provides one deletion service for:

- authenticated customer self-service closure;
- headquarters-assisted closure under explicit headquarters permissions.

It does not introduce a second user system and does not modify CRMEB order, payment, refund, or settlement flows.

## Safety Contract

Account closure is an irreversible hard delete and is permitted only when every database reference to the user can be removed without corrupting immutable business facts.

The service discovers the live schema through `information_schema.COLUMNS` and checks every column named:

- `uid`;
- `user_id`;
- `*_uid`.

Each discovered reference must have one explicit action:

- `delete`: account-owned profile, identity, membership, attribution, referral, acquisition, store-customer, or related projection data;
- `detach`: a non-owner operator/creator reference that can safely become `0`;
- `block`: every unknown or immutable business reference.

Unknown references always block closure. Orders, payments, refunds, bills, fulfilment records, reward/settlement facts, franchise/opening/partner performance, and similar records are not delete-allowlisted.

## Transaction

1. Validate the feature switch and exact confirmation phrase `确认注销`.
2. Headquarters additionally validates headquarters scope, API permission, and a reason of at least four characters.
3. Run preflight and reject any blocking reference.
4. Lock the CRMEB user row inside a database transaction.
5. Repeat preflight after the lock.
6. Delete/detach every explicit reference.
7. Delete the CRMEB user row last.
8. Scan every UID-shaped column again.
9. Roll back the entire transaction if any reference remains.
10. Write one unified `yfth_audit_event` under a random closure number. It records the action, headquarters operator when applicable, and deleted-reference count, but does not retain the deleted UID or personal profile.

There is no partial-success state.

## Interfaces

Customer token API:

- `GET /api/user_cancel/preflight`
- `POST /api/user_cancel`

Headquarters admin API:

- `GET /adminapi/yfth/user_role/user/:uid/closure/preflight`
- `DELETE /adminapi/yfth/user_role/user/:uid/closure`

The customer DTO exposes only `can_close`, confirmation text, safe blocker categories, and a safety message. Internal table names and reference counts are visible only to authorized headquarters operators.

## Store Customer Projection

Successful closure deletes `yfth_customer_relation` and `yfth_customer_follow_record` rows owned by the closing UID. The user therefore no longer appears in the franchise/store customer list. Authority attribution, membership, store roles, referral state, and acquisition acceptance are deleted in the same transaction when no immutable blocker exists.

## Verification

The isolated MySQL Community 8.0.46 real flow covers:

- self-service preflight and exact confirmation;
- complete account, membership, role, attribution, referral-projection, store-customer, and follow-record deletion;
- zero remaining references across all UID-shaped columns;
- registration of the same account and phone as a fresh user;
- headquarters preflight, mandatory reason, and closure;
- an account with a paid order being rejected without partial mutation;
- permission migration run, targeted rollback, rerun, and duplicate run.

Admin, H5, and mp-weixin production builds pass. No production migration or deployment was performed in this development closure.
