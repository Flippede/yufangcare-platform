# YFTH Franchise Partner Hierarchy V1 Runtime Validation

## Headquarters Manual Grant Production Release - 2026-07-17

- Production backend and permission migration use functional commit `fa9502d4780b026999e5be86bfa368e1eea93f13`; the deployed Admin includes follow-up display fix `802b1560d38535510743dfa2da8e9d1ef20bcf0d` so only platform director displays the no-parent hint.
- Before release, a full MySQL dump and the affected code, Admin, and `.env` were backed up under `/root/yfth-backups/partner-manual-grant-20260717-175013`. The initially generated dump was rejected after a tablespace privilege warning; it was recreated with `--no-tablespaces`, `pipefail`, gzip verification, and the MySQL completion marker before deployment continued.
- Migration `20260719110000` changed from `down` to `up` and registered exactly the two expected hidden API permissions. No production rollback was executed.
- Public Admin returned HTTP 200, all four index-referenced JS/CSS assets existed, and an unauthenticated parent-option request reached the new route and failed safely with the existing expired-login response.
- A signed-in production browser verified the visible grant entry, all five rank options, `province_partner -> regional_director`, and `platform_director -> no parent`. No grant was submitted during smoke verification, so production user identities were not changed by the deployment check.
- The Admin-only follow-up release backed up its predecessor at `/root/yfth-backups/partner-manual-grant-ui-fix-20260717-180131/admin-fa9502d`. No `.env`, upload, OSS, SMS, WeChat, payment certificate, order, product, or user business data was replaced.

## Headquarters Manual Grant Validation - 2026-07-17

- PHP 7.4 syntax passed for the partner service, user-role projection/controller/routes, permission migration, and focused tests.
- The partner contract passed 103 assertions and covers the adjacent-parent matrix, server guards, routes, permissions, Admin surface, and store-independent profile source.
- Isolated MySQL Community 8.0.46 permission migration run, targeted rollback to `20260719100000`, rerun, and duplicate run passed. The rollback removed only the two new API permissions and preserved the existing partner tables.
- The real flow granted platform director without a parent, then regional, province, prefecture, and county with exactly one adjacent parent each. Missing parent, cross-level parent, active-rank overwrite, and invalid parent change were rejected. Duplicate identical grant was idempotent, four non-top active relations remained, five immutable `headquarters_grant` rank events existed, and unified audit remained populated.
- Admin production build passed with the existing 11 non-blocking CSS-order warnings. The built user-role route chunk contains the grant dialog and parent-option API. H5 and mp-weixin were not rebuilt because this change has no user-side source modification.
- This section records local isolated validation only. Production migration, deployment, and browser verification are appended only after they actually complete.

## Production Release - 2026-07-17

- Functional commit: `040db75a5c575ba5c05e5d3c35d154eeeda49419` on preserved branch `codex/yfth-franchise-opening-partner-hierarchy-v1`; no main merge was performed.
- Production URL: `https://yfth.top`; application root: `/www/wwwroot/CRMEB-master/crmeb`.
- Backup: `/www/backup/yfth-partner-hierarchy-20260717-153425`; retained release: `/www/releases/yfth-partner-hierarchy-20260717-153425`.
- The pre-release full database dump passed gzip verification. Code/Admin/H5, `.env`, checksums, and table-count evidence were retained before release.
- Production migration `20260719100000 CreateYfthFranchisePartnerHierarchy` ran successfully on the independent MySQL 8 production database. Post-release checks found 12 hierarchy/opening tables and 20 active menu/API permissions.
- Published production rule `YFTH-PARTNER-V1` contains the five expected per-bottle snapshots: county `40.00`, prefecture `17.00`, province `10.00`, regional director `8.00`, and platform director `5.00`.
- Admin and H5 artifacts were deployed. Browser verification rendered the branded user login and the authenticated headquarters partner-management page. Public H5/Admin/partner routes returned HTTP 200; Redis, PHP-FPM, and two queue processes were healthy; the recent application log scan found no fatal/uncaught/SQLSTATE match.
- The production `.env` checksum remained unchanged. Existing uploads, OSS, SMS, WeChat, payment certificates, products, users, stores, orders, and other business facts were preserved. No real payment, SMS, refund, payout, rollback, or WeChat upload was executed.
- The mp-weixin production artifact was built and retained for later platform upload; it was not uploaded in this release.

## Environment

- Branch start: `c39dcf873b04c5c390f841789effc0cc260612be`.
- PHP: portable PHP 7.4.33.
- Database: isolated MySQL Community 8.0.46, database name `yfth_partner_validation_20260717`.
- No production database, Redis, SMS, payment, or WeChat operation was used during local validation.

## Migration

- `migrate:run`: passed.
- Targeted rollback to `20260718150000`: passed and removed the partner migration.
- Rerun: passed.
- Duplicate run: passed as no-op.
- Restored result: 12 partner tables, 20 page/API permissions, published V1 89,100/440/100-BPS rule, 40/17/10/8/5 rank values, and unique guards for current profiles, relations, invites, source, performance, candidate rank, settlement, and pending promotion.

## Real Flow

`yfth_franchise_partner_real_flow_check.php` passed against MySQL 8.0.46. It verified:

- Five real TEST partner profiles and four non-cyclic current hierarchy edges.
- Legacy franchisee role reference preserved on the county profile.
- Partner invite captures the direct recruiter and five-rank chain; finance confirmation freezes it; correction after freeze fails.
- Self-parent and cyclic parent changes fail.
- Formal opening grants county partner bound to the store.
- One performance and exactly five real-person candidates are created: 17,600 / 7,480 / 4,400 / 3,520 / 2,200 yuan.
- Duplicate formal opening creates no duplicate profile, performance, or candidate.
- Confirmation and offline settlement are idempotent; cancelled candidates cannot settle.
- Promotion application is idempotent and only headquarters approval changes rank.
- Publishing a new rule does not rewrite historical performance or candidate snapshots.
- Partner actions write `yfth_audit_event`; CRMEB balance, points, and brokerage remain unchanged.

The acceptance fixture real flow also passed with nine stable accounts, isolated credential file, account/password login, idempotent generation, C1-to-C2 referral projection, controlled reset, immutable membership preservation, and customer rotation after prior immutable history.

## Static And Build Checks

- PHP syntax: all changed/new PHP files passed.
- Partner contract: 90 assertions passed.
- Opening contract: 135 assertions passed.
- Opening real-flow source guard: 40 assertions passed.
- Existing user-role/assets/referral contract: passed.
- Existing multi-role shell and request fallback checks: passed.
- Admin production build: passed with 11 existing non-blocking warnings.
- H5 production build: passed with existing export and asset-size warnings.
- mp-weixin production compile: passed with existing skeleton-key/component-placement notices.
- `git diff --check`: passed before documentation closure and is rerun before commit.

## Not Claimed

No real bank receipt, payment, SMS, WeChat upload, automatic payout, automatic rank enforcement, production migration, or production deployment is claimed by this local validation record. Production release evidence is appended only after backup and deployment succeed.
