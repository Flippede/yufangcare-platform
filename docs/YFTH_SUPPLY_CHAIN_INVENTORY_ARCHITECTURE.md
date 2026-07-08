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
3. `franchisee` and `store_manager` can create purchase orders and confirm receipt.
4. `store_staff` can read purchase catalog, purchase order status, inventory, and ledger, but cannot create purchase orders or stock in.
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
- Isolated MySQL 8.0.46 full migration `run -> rollback -t 0 -> run` passed against a temporary database; the supply-chain purchase table and admin permission seed were present after rerun and removed after rollback.
- Admin Vue production build passed to a temporary output directory; only existing CSS order, asset-size, and Browserslist warnings were emitted.
- Uni-app executable project checks `yfth_multi_role_shell_contract_check.js` and `yfth_request_fallback_check.js` passed. The repo's uni-app package has no production build script, so HBuilderX/mp-weixin compilation was not run in this branch.
