# YFTH Custom Homepage V1

## Purpose

This module supplies the approved fixed YFTH customer-storefront home layout. It keeps the warm gold header, search surface, two-row shortcut panel, and six two-column content cards from the mobile reference while presenting real CRMEB content.

It is a customer discovery and purchase-entry surface. It is not a store workbench, CRM, order-management page, or a replacement for CRMEB payment.

## Fixed Layout And Configurable Content

The layout is implemented in `template/uni-app/pages/index/components/yfthCustomHome.vue`. The layout itself is versioned source code so it cannot be accidentally changed into a generic mall DIY page. Headquarters configuration changes only display content:

- homepage title and search placeholder;
- shortcut/card title, order, display state, icon, and card image;
- real CRMEB category and product bindings;
- published YFTH package binding;
- safe internal page target.

The Admin page is `御方通和 / 首页配置`. Its API is guarded by the seeded homepage configuration permissions.

## Runtime Data Boundary

`HomepageServices` stores display-only JSON in `yfth_homepage_config`. It reads live data from existing CRMEB and YFTH sources:

- `store_category` for active categories;
- `store_product` for visible, undeleted products and their existing OSS/image URLs;
- `yfth_package_template` for published packages.

There are no hard-coded formal product, category, package, order, payment, or OSS identifiers. If a binding is empty, the service selects the current YFTH-named category where available and otherwise an enabled category. The production configuration page must be used to bind the six content regions deliberately once the official category catalog is complete.

## Navigation

- category card or shortcut: existing CRMEB goods-list route with `cid`;
- product item: existing CRMEB goods-detail route;
- package card or shortcut: existing package list/detail route;
- search: existing CRMEB goods-list route.

No second product, order, package purchase, or payment flow is introduced.

## Compatibility

The existing CRMEB DIY home data is still loaded to preserve the current footer configuration. If the custom-home API cannot be read or configuration is disabled, the existing DIY homepage renders as before. Existing CRMEB DIY data is neither rewritten nor deleted.

## Deployment

Run the one additive migration in an approved environment. It creates `yfth_homepage_config` and the homepage menu/API permissions. Do not use a rollback-to-zero command in production.

For every production release that changes `template/admin`, mirror the contents of `template/admin/dist/` to `crmeb/public/admin/` and delete stale hash files as a single controlled static release. Publish the H5 build output according to the existing web-static deployment layout. The mp-weixin build is for WeChat developer validation and is not uploaded by this module.

No production secret, OSS credential, payment certificate, SMS credential, product data, or DIY record belongs in this module's migration or repository configuration.
