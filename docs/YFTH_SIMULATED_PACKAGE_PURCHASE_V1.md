# YFTH Controlled Simulated Package Purchase V1

## Purpose

This capability gives acceptance users a complete package activation path without a real payment. It is not a discounted production sale and does not replace the existing CRMEB package order or payment flow.

## Entry And Eligibility

- Only the marked `YFTH-TEST-PACKAGE-V1` template is accepted.
- The existing acceptance-fixture feature switch must be enabled.
- The current published rule must contain the acceptance marker, grant permanent membership, and have a price of `0.10` yuan.
- The customer must be logged in, have a bound phone, be a non-member, and have an authoritative active upstream merchant.
- The authoritative merchant is resolved by the server from current YFTH attribution or membership facts. A client-supplied store cannot override it.

The customer path is:

`套餐详情 -> 显示上级商家 -> 模拟购买协议 -> 0.1元模拟购买确认 -> 现有套餐结果页`

## Written Facts

The simulation writes the existing agreement snapshot, package purchase snapshot, package purchase and package instance tables. It invokes `PackageMembershipActivationCoordinator`, so membership, attribution, direct-referral closure and package reward candidates continue to use the existing authoritative rules.

The simulation explicitly does not:

- create a CRMEB `store_order`;
- invoke WeChat, balance or offline payment;
- send an SMS;
- write a real payment transaction;
- accept a client-selected store;
- run for ordinary production packages.

## Idempotency And Order Boundary

The user row is locked before checking or creating a simulated purchase. The source is fixed as `controlled_simulated_purchase`, and the same user/template pair replays the completed purchase result.

Real package instances keep a unique nullable `order_unique_key`. Rows backed by CRMEB orders set it from the positive order ID; simulated rows use `order_id=0` and a null key. This permits multiple users to run the acceptance simulation while retaining uniqueness for every real CRMEB order.

## Verification

- PHP 7.4 syntax and focused contracts passed.
- Existing acceptance fixture generation, login, B1 attribution and C1/C2 invitation flow passed.
- MySQL Community 8.0.46 migration run, targeted rollback, rerun and duplicate run passed on an isolated database.
- The dedicated real flow verified unbound rejection, B1 display, one successful activation, duplicate replay, no CRMEB order, permanent membership, direct-referral closure and repeat-member rejection.
- H5 production and mp-weixin production compiles passed.

No real payment, SMS, production database mutation or WeChat upload is part of this capability.
