# YFTH Headquarters Mall Stage 2 Permanent Membership Minimum Loop

## Audit Findings Closure Status

The first independent architecture review concluded C and blocked merge. The closure on the same feature branch addresses three P1 findings but has not yet received an independent re-review:

1. ThinkORM list/member filters now retain each `where()` return value. Store list/detail/write scope remains derived from authenticated `CurrentBusinessContextServices`; client IDs or filters cannot widen it.
2. The Stage 2 migration now validates complete signatures for all five tables and seven permissions. Wrong same-name tables, indexes or permissions fail closed. A recorded incomplete schema requires reviewed forward repair; a compatible no-record partial state may add only missing tables, indexes or permissions. Down validates all signatures before deleting exact Stage 2 rows and tables.
3. Activation resolves the current referral context before authority locks, builds the full numeric UID set, locks all attribution current rows in ascending order once, then calls transaction-bound attribution/referral methods with that locked set. No later helper acquires a newly discovered smaller UID lock.

The same completed idempotency key may replay its stored activation success. A new key with an already used confirmation token is rejected as `membership_confirmation_code_used`; enrollment activation alone never authorizes arbitrary token replay.

Real isolated HTTP evidence covers two-store list/detail/write isolation, revoked roles, disabled stores, headquarters store/UID/status filters, expired codes, used-code/new-key rejection, reverse-UID process competition and exact authority-fact cardinality. This closure is not reviewed again, not merged and not deployed.

## 1. Stage Scope

Stage 2 implements one practical offline permanent-membership loop. A headquarters operator or an authorized `franchisee` / `store_manager` creates an enrollment for a real active CRMEB store. The customer supplies identity only through a short-lived, single-use code generated under the authenticated customer UID. After the operator confirms receipt of the fixed offline fee of 9800 yuan, the bound customer confirms activation with a second customer-bound code.

This stage does not implement a 9800 package sale, online payment, customer referral binding, reward calculation, reward sequence, wallet, commission, settlement, refund, takeover or CRM projection.

## 2. Authority and Tables

Five Stage 2 tables are introduced:

| Table | Responsibility |
| --- | --- |
| `yfth_permanent_membership_enrollment` | Offline enrollment state: `draft`, `pending_customer_confirmation`, `activated`, `cancelled` |
| `yfth_permanent_membership` | One authoritative active permanent-membership fact per CRMEB UID |
| `yfth_permanent_membership_event` | Append-only versioned membership activation fact |
| `yfth_business_dynamic_code` | Independent hashed business codes for `customer_identity` and `membership_confirmation` |
| `yfth_membership_reward_candidate` | Amount-free, sequence-free, unsettled future reward candidate fact |

`yfth_permanent_membership.uid` and `enrollment_id` are unique. The fixed transaction amount is server-owned `980000` integer cents. `member_5980`, CRMEB paid membership and old referral/reward tables are not membership authority.

## 3. Dynamic Code Boundary

- `customer_identity` is generated only for `Request::uid()`. A refresh marks the prior active code `replaced`.
- The client cannot submit UID, phone, owner UID or target UID to bind an enrollment.
- Only the token hash is persisted; plaintext is returned once.
- A code is short-lived, single-use and protected by a nullable unique `active_key`.
- Store binding locks the code and enrollment. The first authorized store operation consumes the identity code; replacement, expiry, use and cross-scope reuse are rejected.
- `membership_confirmation` is generated only after the customer is bound and offline payment is confirmed. It is fixed to enrollment, target UID and store.

The Stage 2 code table is independent from `yfth_service_dynamic_code`; appointment writeoff semantics are unchanged.

## 4. Atomic Activation

Customer confirmation uses the existing `HqAuthorityOperationRunner` and `yfth_idempotency_record`. One transaction:

1. locks the enrollment and confirmation code;
2. verifies payment, status, target UID, store, expiry and code state;
3. reuses `HqCustomerAttributionServices::assignFirstInTransaction()` to create or confirm same-store permanent attribution;
4. reuses `HqActiveReferralServices::closeForMembershipInTransaction()` to close active/paused occupancy with `membership_activated`;
5. inserts the unique permanent membership and version-1 membership event;
6. inserts one amount-free reward candidate;
7. consumes the confirmation code;
8. marks the enrollment activated and completes idempotency in the same transaction.

Any failure rolls back all business changes. Different-store attribution, historical unassigned, closed or inconsistent Stage 1A authority fails closed. Only replaying the same completed idempotency key returns the existing activation result; a new key with the used token is rejected. Concurrent confirmations serialize on the enrollment and create one membership, event and candidate.

Stage 1A public writers retain their existing runner behavior. The new transaction-bound methods contain the same store, consistency, numeric UID lock and event rules and are used only by the Stage 2 orchestrator. The sole new production canonical source is `permanent_membership_confirmation`; unknown and future sources remain rejected.

## 5. Referral Qualification

An active row in `yfth_permanent_membership` is the only Stage 2 first-level referral qualification fact. `PermanentMembershipReferralQualificationPolicy` queries that authority and store. Customer DTOs expose only the boolean qualification result. Real referral creation remains unavailable, and the existing default Stage 1A referral creation policy remains fail closed.

## 6. Roles, APIs and DTOs

Customer Token:

- generate/refresh customer identity code;
- read own pending enrollment and permanent membership;
- confirm activation with a customer-bound confirmation token.

Store user Token:

- only `franchisee` and `store_manager`;
- store scope is resolved by `CurrentBusinessContextServices`;
- create, bind, confirm offline payment, generate confirmation code, list and read this store's enrollments;
- `store_staff`, `service_mentor`, ordinary customers and cross-store contexts are rejected.

Admin Token:

- explicit API permissions plus `AdminStoreContextServices::assertHeadquarterScope()`;
- list enrollments and members, create for an explicitly selected active store, assist binding/payment/code generation;
- no force activation, attribution rewrite, membership close, refund or repair API.

Customer DTOs exclude source, event, operator, idempotency and reward candidate data. Operator DTOs expose only enrollment workflow fields needed to complete the offline process.

## 7. Frontend Surfaces

- Admin Vue2/ElementUI: enrollment/member views and controlled workflow actions.
- Customer uni-app: identity code, pending enrollment, confirmation and permanent status.
- Store workbench uni-app: authorized store enrollment workflow.

CRMEB login, store context, page decoration, commerce, 5980 package, appointment and writeoff pages remain in place.

## 8. Frozen Boundaries

Not implemented: 9800 business package transaction, online payment, real customer referral binding, 15%/25%/60% rules, sequence allocation, reward ledger posting, settlement, wallet, commission, withdrawal, membership refund/reversal/recovery, store takeover, CRM projection, historical import, production deployment or production migration.
