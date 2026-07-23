# YFTH Supply Chain And Store Inventory V1

## Scope

This V1 adds the data and workflow foundation for headquarters supply catalog, store purchase orders, shipment, receipt, store inventory balance, immutable inventory ledger, and alert rules.

It is intentionally not a finance, settlement, payment, revenue sharing, recommendation reward, or CRMEB sales-order module.

## Boundaries

- CRMEB `store_product` is the product SPU source.
- CRMEB `store_product_attr_value.unique` is the SKU identity.
- YFTH purchase inventory is stored only in `yfth_inventory_balance`.
- YFTH stock movements are stored only in `yfth_inventory_ledger`.
- Purchase orders are stored only in `yfth_purchase_order` and `yfth_purchase_order_item`.
- The workflow does not create CRMEB `store_order` rows.
- The workflow does not call CRMEB sales stock methods such as `decStockIncSales()` or `incStockDecSales()`.
- The workflow does not write user balance, brokerage, points, payment, refund, or settlement data.

## Tables

- `yfth_supply_catalog`: headquarters purchase catalog referencing CRMEB products.
- `yfth_purchase_order`: store purchase order header.
- `yfth_purchase_order_item`: purchase order line snapshots.
- `yfth_stock_location`: headquarters/store warehouse location.
- `yfth_inventory_balance`: current store inventory balance by location and SKU.
- `yfth_inventory_ledger`: immutable inventory movement ledger.
- `yfth_purchase_shipment`: headquarters shipment record.
- `yfth_purchase_receipt`: store receipt and stock-in record.
- `yfth_inventory_alert_rule`: low-stock warning rule.

## Store-Side Flow

1. Store operator enters the user-token workbench.
2. Server resolves the real YFTH business context through `CurrentBusinessContextServices`.
3. Only `store_manager` can enter the procurement center, create purchase orders, and confirm receipt.
4. `store_staff` and partner identities cannot create purchase orders; profit visibility belongs to the separate partner workbench.
5. Client-submitted `store_id`, `store_ids`, role, or operator fields in write bodies are rejected.
6. Purchase order list/detail and inventory queries are always scoped to the resolved current store.

## Headquarters Flow

Headquarters operators use admin-token APIs:

- Manage `yfth_supply_catalog`.
- Review purchase orders.
- Arrange shipment.
- View shipments, inventory, ledger, and alert rules.

Every headquarters API method calls `SystemRoleServices::assertApiAuthForAdmin()` before service execution, and the service also requires `AdminStoreContextServices::assertHeadquarterScope()`.

## Status Model

Purchase order:

`submitted -> approved -> shipped -> stocked`

Alternative audit path:

`submitted -> rejected`

Receipt V1 combines received, checked, and stocked into a single idempotent stock-in action after shipment. The receipt table keeps explicit `received_time` and `stocked_time` so a later V2 can split physical receiving and inspection.

## Audit And Idempotency

- Purchase submit, audit, shipment, receipt, and stock-in write YFTH audit events under `yfth_supply_chain`.
- Store purchase creation and receipt support YFTH idempotency keys via `IdempotencyRecordServices`.
- Inventory ledger rows are append-only in service behavior; stock balance updates are accompanied by ledger rows.

## P1 Hardening After Architecture Review

The first architecture review found merge-blocking concurrency and permission risks. This branch now closes the P1 items without expanding V1 scope:

- Headquarters shipment now locks the `yfth_purchase_order` row inside the shipment transaction and rechecks status under the lock. Only `approved` can create the first shipment; later `shipped` or `stocked` requests return the existing shipment result and never create a second shipment.
- Store receipt now locks the `yfth_purchase_order` row inside the receipt transaction and rechecks status under the lock. Only `shipped` can stock in; `stocked` returns the existing receipt result and does not write a second receipt, ledger set, or inventory increment.
- V1 uniqueness guards are enforced in the migration: one shipment per purchase order, one receipt per purchase order, one receipt per shipment, one order item per purchase-order SKU, and one inventory ledger row per `business_type + business_id + location_id + sku_unique`.
- Store write operations now require explicit `store_purchase` capability. An empty capability set no longer defaults to allow. This covers purchase order creation and receipt stock-in.
- Purchase order creation and receipt use server-side deterministic idempotency fallbacks. Receipt fallback is `supply_receive:{store_id}:{purchase_order_id}`; business uniqueness and status locks remain the final anti-duplicate guard.

Related P2 fixes included in the same closure:

- Purchase amount snapshots are calculated with integer cents and normalized decimal strings, not PHP float multiplication.
- Store-type catalog matching uses exact `FIND_IN_SET` token matching instead of `%like%`.
- Catalog updates preserve original `created_uid` and `create_time`; request bodies cannot overwrite creation metadata.

The V1 remains single-shipment and single-receipt by design. Multi-shipment, partial receiving, purchase payment, aftersales, product quota, recommendation reward, settlement, and CRMEB consumer order inventory deduction remain out of scope.

## Not Implemented

- Procurement payment.
- Accounts payable.
- Franchise settlement.
- Revenue sharing.
- Recommendation reward.
- CRMEB sales order fulfillment.
- Consumer order auto-deducting store inventory.
- Return/reversal workflow.
- Multi-shipment partial receiving.
- Production deployment or production database migration.

## Verification

Added contract check:

`crmeb/tests/yfth_supply_chain_contract_check.php`

The contract check validates table names, indexes, routes, middleware, API permission assertions, role split, rejection of client store fields, CRMEB product/SKU reuse, independent inventory tables, ledger writes, and absence of CRMEB sales-stock/order mutation calls.

Executed checks in this branch:

- PHP syntax check passed for changed backend, migration, and test files.
- `php crmeb/tests/yfth_supply_chain_contract_check.php` passed with 50 assertions.
- P1/P2 closure added `crmeb/tests/yfth_supply_chain_real_flow_check.php`. It performs source guard checks by default and can verify duplicate shipment, duplicate receipt, and duplicate ledger unique guards against isolated MySQL when `YFTH_SUPPLY_CHAIN_REAL_FLOW_EXECUTE=1` and `YFTH_REAL_FLOW_ISOLATED_DB=1` are provided.
- Isolated MySQL 8.0.46 full migration `run -> rollback -t 0 -> run` passed against a temporary database; the supply-chain purchase table and admin permission seed were present after rerun and removed after rollback.
- Admin Vue production build passed to a temporary output directory; only existing CSS order, asset-size, and Browserslist warnings were emitted.
- Uni-app executable project checks `yfth_multi_role_shell_contract_check.js` and `yfth_request_fallback_check.js` passed. The repo's uni-app package has no production build script, so HBuilderX/mp-weixin compilation was not run in this branch.

Current local P1 closure verification:

- `git diff --check` passed with only line-ending warnings.
- Bundled Node ran `template/uni-app/tests/yfth_multi_role_shell_contract_check.js` and `template/uni-app/tests/yfth_request_fallback_check.js`; both passed.
- A Node source-guard check confirmed the row lock, strict `store_purchase` capability, deterministic receipt idempotency fallback, no PHP float money calculation, exact store-type matching, catalog creation-field preservation, and new uniqueness guards.
- PHP CLI and isolated MySQL were not available in the current shell, so PHP syntax, PHP contract/real-flow scripts, and MySQL migration rerun/rollback were not re-executed in this closure round.
