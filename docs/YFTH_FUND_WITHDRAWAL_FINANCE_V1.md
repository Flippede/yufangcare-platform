# YFTH Partner And Store Withdrawal Finance V1

## Scope

This version adds a controlled withdrawal workflow for:

- county, prefecture, province and regional partners;
- platform directors;
- B1 stores represented by an active store manager.

It does not implement online payment, automatic bank transfer, administrator identity switching or a second account ledger.

## Eligible Funds

Partner withdrawals can allocate only confirmed partner-reward rows that:

- belong to the current partner;
- have passed the configured observation period;
- are not refunded, reversed, disputed or already allocated;
- have a positive remaining amount.

Store withdrawals can allocate only confirmed B1 commission ledger rows that have a positive unallocated balance. A request freezes exact source rows; later review cannot silently substitute different sources.

Store managers may submit requests. Store staff may inspect the store summary and request history but cannot submit a withdrawal.

## State Machine

The request states are:

- `pending`: sources are frozen and waiting for headquarters finance;
- `approved`: finance has approved the request for external payment;
- `rejected`: the request is closed and frozen sources are released;
- `paid`: finance has recorded the external bank/U-shield payment and the frozen source amounts are offset.

Approval and payment confirmation revalidate the frozen source rows. Duplicate creation, review and payment calls are idempotent. A request cannot be marked paid before approval.

## Finance Boundary

Headquarters Admin exposes a standalone top-level `财务` module with the `提现审核` page. Finance operators can:

- filter partner and store requests;
- inspect the complete request and source allocation;
- approve or reject with a reason;
- record an external payment reference;
- confirm paid only after the manual payment is complete.

The application does not execute bank payments. The menu and API permissions are independent, which reserves a clean boundary for a future finance-only headquarters account.

Receiver names and bank-account values are encrypted at rest. List responses are masked and do not return raw account details. The detail endpoint is protected by headquarters finance permissions.

## Existing Settlement Boundaries

- The C1-to-B1 offline settlement process remains unchanged.
- Store managers and staff can complete eligible C1 requests for their own store.
- Headquarters finance does not handle C1 settlement.
- The old B1 withdrawal/batch write entries are disabled and retained only for historical read-only records.

## Franchise Projection Closure

When headquarters approves an offline franchise application, the same transaction:

- marks the application opened;
- selects or creates the authoritative CRMEB store;
- grants the applicant the active store-manager role;
- binds the store to the recruiting county partner;
- projects the store into the county partner's team and performance view.

Repeated approval does not create another store, role, binding or reward event.

## Not Implemented

- automatic bank or WeChat transfer;
- finance administrator identity switching;
- bypass of order, refund, dispute or observation-period guards;
- staff-created B1 withdrawals;
- headquarters handling of C1 offline settlement;
- production payment credentials in source control.
