# YFTH User Role Assets And Referral QR V1

## Production closure

- Business release `0268395bab2ba78bcb908abaf626757958267a00` is deployed at `https://yfth.top` from the preserved feature branch; it is not merged into stable `main`.
- Headquarters user management, stable acceptance accounts, server-validated role switching, permanent-member promotion QR and the multi-input scan page are visible and were verified through their real production surfaces.
- The production acceptance fixture is active for TEST B1. C1 is a permanent member attributed to TEST B1; final C2 is a clean non-member with no attribution or active referral so the user can perform the first binding flow.
- Credentials remain only in server-private mode-0600 files. Production products, orders, uploads, OSS, SMS, WeChat and payment configuration were not replaced, and no paid transaction or SMS was triggered.

## 1. Scope

This stage adds three narrowly scoped surfaces:

1. Headquarters management of existing YFTH store roles.
2. Read-only CRMEB mall assets in the customer center.
3. A permanent-member promotion QR using the existing Stage 2 V2 invitation and one-level referral authority.

It does not create a second identity, recommendation, wallet, coupon, order, or payment system.

## 2. Operating-role authority

- Source of truth: `yfth_user_store_role`.
- Supported store-role grants: `franchisee`, `store_manager`, `store_staff`.
- Headquarters may also grant the independent permanent-membership qualification after selecting its authoritative B1. This path writes the existing permanent-membership current/event and attribution authority; it does not masquerade as a store role or create a paid package snapshot.
- A role is scoped to a concrete active CRMEB `system_store` record.
- One user can hold multiple valid store roles; a grant never deletes customer identity or permanent membership.
- The Admin DTO groups franchisee as one independent operating identity with its explicitly granted store list. Manager and staff remain displayed per store. Server-side role/store validation remains unchanged.
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

## 7. Controlled acceptance fixture

- Migration `20260718110000_create_yfth_acceptance_fixture.php` adds the small `yfth_acceptance_fixture` manifest and three explicit read/generate/reset permissions. The manifest records ownership and lifecycle references; it is not a second user, store, package, membership, or referral model.
- The tool is fail closed unless `YFTH.ACCEPTANCE_FIXTURE_ENABLED=true`. The credential output path is configured by `YFTH.ACCEPTANCE_ACCOUNT_FILE` and must be outside the public web tree.
- Generation creates or repairs only records carrying the fixture marker `[YFTH-ACCEPTANCE-TEST-V1]`. It reuses CRMEB users and stores plus existing YFTH subject, qualification, capability, package activation, membership, role, attribution, referral, audit, and idempotency services.
- Repeated generation is idempotent. Reset requires a reason and only disables marked records or closes the fixture's active referral/attribution through existing authority services. It never physically deletes immutable membership or authority history.
- A closed attribution cannot be rebound. When a fixture C2 has immutable history, regeneration safely rotates to another marked C2 account so repeated acceptance rounds remain possible without corrupting history.

Admin fixture endpoints:

- `GET /adminapi/yfth/user_role/fixture`
- `POST /adminapi/yfth/user_role/fixture/generate`
- `POST /adminapi/yfth/user_role/fixture/reset`
- `POST /adminapi/yfth/user_role/fixture/password/reset`

The acceptance fixture keeps stable `yfth_stg_*` login names without storing passwords in Git or documentation. Password reset is headquarters-only, requires a reason, exposes generated values only in that response, and rewrites the configured server-private mode-0600 credential file. A C2 with immutable attribution/referral history is archived by UID and replaced by a clean user behind the stable C2 acceptance account; history is not deleted or rewritten.

## 8. Operating context and scan surfaces

- `UserIdentityServices` enriches the existing trusted identities with real store names. Customer mode remains a server-validated context, while permanent membership remains a business status rather than a switchable operating role.
- The customer center shows the current trusted operating role/store and routes business identities through the existing role/store switch and workbench guards. Frontend role or store parameters never grant authority.
- `pages/yfth/referral/scan` supports native mp-weixin `uni.scanCode`. H5 opens `getUserMedia` directly, prefers native `BarcodeDetector`, and falls back to bundled `jsQR` frame/image decoding on Safari, WeChat browsers, and desktop browsers that lack `BarcodeDetector`. Image upload and paste-link/token inputs remain available. Only YFTH invite routes or the existing 64-hex invite token are accepted.
- The promotion page can save the generated QR to a local file in H5 and to the photo album in mp-weixin through the existing QR component. Saving does not expose UID or internal authority identifiers.
- The accept result uses the existing invite service and returns safe display names without exposing another user's UID or internal relation/event identifiers.

## 9. Store customer projection

- Stage 1A attribution current/event remains the authority for B1 ownership. `yfth_customer_relation` is the existing store-CRM read model used by the customer list and detail surfaces.
- Successful invite acceptance and headquarters membership grant synchronize an active same-store customer relation in the same business transaction. Replays are idempotent; an active relation for another store fails closed instead of being silently reassigned.
- Existing authority rows can be repaired through the explicit CLI-only `yfth_customer_authority_projection_repair.php`. It requires an execution flag, operator, reason, and consistency validation before creating a missing projection. It never rewrites authority current/event history.
- Fixture reset disables only the fixture-owned customer projection when closing the test referral, so repeated acceptance rounds do not leave a stale visible test customer.
