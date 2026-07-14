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

## Production Release Validation - 2026-07-14

The controlled production release at commit `3e5d73d8b19f020ac48d7dae768e360ffe88229c` completed the following:

1. PHP 7.4 syntax checks passed for `HomepageServices`, both controllers, the migration, and the contract check; the homepage source contract passed.
2. The additive migration completed on the formal MySQL 8 database. `eb_yfth_homepage_config` and all three homepage permissions were verified. No production rollback command was used.
3. Unauthenticated `GET /api/yfth/homepage` returned `200`, `enabled=true`, 12 shortcuts, six sections, four enabled live YFTH products, and existing OSS image URLs.
4. The H5 entry, H5 static CSS/JavaScript, category route, product-detail route, and package-list route returned `200`. `/admin/` and the primary current Admin CSS/JavaScript files returned `200`.
5. Nginx, PHP-FPM, and the dedicated production Queue, Timer, and Workerman services were active after the atomic release. The post-fix runtime log scan found no new homepage API exception.

The release archive and data backup are `/www/backup/yfth-custom-homepage-20260714-1755/`. The previous formal directories remain as controlled rollback sources; do not delete them until post-release acceptance is complete.

An authorised Admin save/read session and an interactive browser visual walkthrough were not executed in this release record. Real payment, SMS, WeChat authorization, refund callback, and WeChat upload were also not executed.

## Honest Content State

The production catalog currently has real YFTH products and an existing category, while published package templates and the full six-category product taxonomy may be configured later. The homepage does not invent items for absent data. The Admin configuration page is the supported route for binding the approved real categories, cards, images, products, and packages.

No real payment, SMS, WeChat authorization, or WeChat upload is part of this validation record.
