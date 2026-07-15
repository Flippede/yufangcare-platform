# YFTH User Role Assets And Referral QR V1 Runtime Validation

## Environment

- PHP: portable PHP 7.4.33
- Database: isolated MySQL Community 8.0.46 on a non-production port/database
- Frontend: existing Vue 2 Admin toolchain and HBuilderX uni-app compiler
- Production data or credentials: not used during development validation

## Executed checks

- PHP syntax passed for all added and changed PHP files.
- `yfth_user_role_assets_referral_contract_check.php` passed.
- `yfth_user_role_management_real_flow_check.php` passed with headquarters grant/revoke, duplicate idempotency, inactive-store rejection, store-account denial, multi-store roles, audit evidence, and unchanged customer/member/assets assertions.
- Existing `yfth_package_membership_referral_contract_check.php` passed.
- Existing Stage 2 V2 real flow passed after adding self-scan, expired-token, conflicting-store attribution, existing-active-referral and permanent-member scan regressions.
- Migration run passed; targeted rollback removed the five new menu/permission records; rerun restored exactly five distinct permission records.
- Admin production build passed and its output was mirrored to `crmeb/public/admin` without retaining stale hashes.
- H5 production build passed. Existing bundle-size recommendations remained non-blocking.
- mp-weixin production compile passed. Existing skeleton-key and subpackage-placement recommendations remained non-blocking.
- Existing multi-role shell and request fallback checks passed.
- `git diff --check` and targeted sensitive/funding-field scans passed.

## Real-flow results

- Headquarters can grant and revoke `franchisee`, `store_manager`, and `store_staff` against active stores.
- One user can retain roles in multiple stores; duplicate mutations do not create duplicate active roles.
- Store-side contexts cannot elevate users and inactive stores cannot receive grants.
- Customer identity, permanent membership, CRMEB balance, points and coupons are not changed by role mutations.
- A permanent member can issue a rotating promotion token and a valid non-member can accept it.
- Successful acceptance creates the existing one-level referral and authoritative shared B1 attribution.
- Self-scan, expired token, conflicting B1 attribution, existing active referral and permanent-member recipient are rejected without duplicate relations or attribution takeover.

## Not executed in local validation

- No real SMS, WeChat authorization, payment, refund, payout or WeChat upload.
- No production database or production Redis mutation.
- No automatic reward funding or settlement.

Production backup, migration and online smoke evidence are intentionally recorded only after controlled deployment.
