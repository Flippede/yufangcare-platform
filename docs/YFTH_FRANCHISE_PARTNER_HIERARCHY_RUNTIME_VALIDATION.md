# YFTH Franchise Partner Hierarchy V1 Runtime Validation

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
