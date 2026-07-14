# YFTH Custom Homepage V1 Runtime Validation

## Local Build Evidence

The following commands completed on the feature branch:

```powershell
cd template/admin
npm run build
```

The Admin build completed successfully. CRMEB retained existing CSS-order, Browserslist-age, and asset-size warnings; no homepage compilation error occurred. The built contents were mirrored to `crmeb/public/admin/` without nesting `dist` and without retaining stale hash files.

```powershell
# Existing HBuilderX uniapp-cli with the repository-compatible Node 18 runtime
UNI_PLATFORM=h5 NODE_ENV=production <uniapp-cli>
UNI_PLATFORM=mp-weixin NODE_ENV=production <uniapp-cli>
```

Both H5 and mp-weixin production compiles completed. Existing missing-export, size, component key, and subpackage-placement warnings remained, but did not block either build.

## Required Release Validation

Before production traffic is switched, execute:

1. PHP 7.4 syntax checks for `HomepageServices`, both controllers, the migration, and the contract check.
2. The `yfth_custom_homepage_contract_check.php` source contract.
3. The additive migration against the intended MySQL 8 database and a check that `yfth_homepage_config` and all three seeded permissions exist.
4. An unauthenticated public request to `/api/yfth/homepage` and an authorised Admin configuration save/read.
5. H5 visual smoke: header, 12 shortcut positions, six two-column cards, real product image, category jump, product detail jump, and package-list entry.
6. Admin static smoke: `/admin` loads without primary JS/CSS 404s and the `首页配置` route can be opened by an authorised role.

## Honest Content State

The production catalog currently has real YFTH products and an existing category, while published package templates and the full six-category product taxonomy may be configured later. The homepage does not invent items for absent data. The Admin configuration page is the supported route for binding the approved real categories, cards, images, products, and packages.

No real payment, SMS, WeChat authorization, or WeChat upload is part of this validation record.
