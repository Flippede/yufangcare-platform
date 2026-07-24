# YFTH Partner Context Isolation V1

## Purpose

Partner ranks and store operating roles are separate business subjects.

The partner hierarchy is:

`platform_director -> regional_director -> province_partner -> prefecture_partner -> county_partner`

Store operating roles remain:

`store_manager / store_staff`

A partner can recruit, manage or receive business facts related to a store without inheriting the store's operational identity.

## Authority Boundary

- Partner authority comes only from an active and effective `yfth_partner_profile`.
- Partner business context always returns `store_id=0`.
- Partner context never returns store capabilities.
- `primary_store_id` and partner-store bindings describe management scope; they are not store-login authority.
- Store appointments, verification, orders, customers, inventory and benefit fulfilment continue to require an active `yfth_user_store_role`.
- Frontend role selection is display state only. Every API continues to verify the effective server-side subject.

## Partner Experience

The fixed partner navigation is:

1. Workbench
2. Team
3. Franchise applications
4. Earnings
5. Headquarters mall
6. Personal center

The partner workbench and personal center show:

- current partner rank and superior;
- direct reports and managed/recruited stores;
- franchise-application QR code;
- recruitment reward;
- procurement profit;
- opening-service reward;
- platform dividend when applicable;
- pending, settled and cancelled partner earnings.

They do not show C1/B1 store commission, store appointments, verification, store orders, store customers or benefit pickup.

## QR Boundary

The partner QR code opens the franchise application flow and records the recruiting partner source. It does not:

- establish a C1-to-C2 referral;
- assign a normal customer to a store;
- grant membership;
- grant store-manager or store-staff authority.

## Test Identity Boundary

The isolated acceptance fixture reuses five marked accounts in one adjacent hierarchy. The county partner can be bound to the TEST B1 store for recruitment/management reporting, but it must not have an active manager or staff role.

Credentials are never committed. They remain in the server-only mode-600 acceptance-account file.

## Validation Gate

Focused validation must prove:

- each of the five ranks has one profile-sourced partner identity;
- each partner context has `store_id=0` and no store capabilities;
- the county partner exposes managed-store count without a store role;
- the five-rank hierarchy and partner QR source remain valid;
- partner reward and product-quota flows remain idempotent;
- H5 and mp-weixin role shells use the partner navigation and preserve the normal four-item customer mall navigation.
