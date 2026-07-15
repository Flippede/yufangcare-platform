# YFTH User Role Assets And Referral QR V1

## 1. Scope

This stage adds three narrowly scoped surfaces:

1. Headquarters management of existing YFTH store roles.
2. Read-only CRMEB mall assets in the customer center.
3. A permanent-member promotion QR using the existing Stage 2 V2 invitation and one-level referral authority.

It does not create a second identity, recommendation, wallet, coupon, order, or payment system.

## 2. Operating-role authority

- Source of truth: `yfth_user_store_role`.
- Supported grants: `franchisee`, `store_manager`, `store_staff`.
- A role is scoped to a concrete active CRMEB `system_store` record.
- One user can hold multiple valid store roles; a grant never deletes customer identity or permanent membership.
- Headquarters scope is enforced in the controller and service. Store accounts cannot grant or revoke roles.
- Grant and revoke require a reason and write `yfth_audit_event` with operator, store, role, target and before/after facts.
- Repeated grant or revoke is idempotent. Revocation is a status transition, not physical deletion.

Admin endpoints:

- `GET /adminapi/yfth/user_role/user`
- `GET /adminapi/yfth/user_role/user/:uid`
- `POST /adminapi/yfth/user_role/user/:uid/grant`
- `POST /adminapi/yfth/user_role/role/:id/revoke`

The migration `20260718100000_add_yfth_user_role_management_permissions.php` installs the page and four API permissions. It adds no business table.

## 3. Mall assets

The customer center reuses the authenticated CRMEB personal-home response:

- balance: `now_money`
- points: `integral`
- coupons: existing coupon count

These values are labelled as mall assets. YFTH direct-referral candidates and offline settlement facts remain separate and are not credited to CRMEB balance, points, brokerage, distribution, or withdrawal records.

## 4. Promotion QR and invitation acceptance

- Eligibility comes from effective permanent membership, including historical paid package read-through semantics.
- QR payload is the H5 invite-accept route with the existing random 64-hex Stage 2 V2 token.
- Only the token is carried by the QR. Internal UID, relation ID and authority rows are not exposed.
- A successful acceptance reuses existing Stage 1A attribution and Stage 2 V2 one-level referral services, so C1 and C2 share C1's authoritative B1.
- If authentication is required, the token is retained locally and the accept page resumes after existing CRMEB login completes.
- Self-scan, expired/rotated token, permanent-member C2, conflicting B1 attribution, historical rebind and existing active referral fail closed.
- No CRMEB `user_spread`, old distribution QR, multi-level relation, balance credit or payout is used.

## 5. UI surfaces

- Admin: `御方通和 / 用户经营身份`.
- Customer center: compact mall balance, points and coupons; member-only promotion, attribution, package membership and rewards group.
- Promotion page: QR, share/copy action, authoritative store, invited count and reward entry.
- Invite accept page: explicit processing, success, rejection and login-continuation states.

## 6. Frozen boundaries

CRMEB login/token, users, stores, products, orders, payment, refund, coupons and wallet fields remain authoritative and unchanged. Package activation, referral candidate generation, reward confirmation and offline settlement state machines are reused without redesign.
