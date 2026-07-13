# YFTH Headquarters Mall Stage 2 V2 Architecture

## 1. Status And Scope

Stage 2 V2 implements package-activated permanent membership and the minimum one-level direct-referral loop on branch `codex/yfth-hq-mall-stage2-package-membership-referral-v1`. The stable base is `3ec6c80dbfef4975788414f64ab70c9e439cf117`.

The first independent architecture review concluded C and blocked merge. The root findings were inconsistent pre-backfill membership semantics, unsafe broad legacy grant defaults, user DTO exposure, incomplete ordinary-order guards, a reversed/stale invite-activation lock path, and no executable recorded-migration health gate. These findings are implemented for follow-up review; no approval or merge is claimed.

The stopped branch `codex/yfth-hq-mall-stage2-permanent-membership-v1` remains historical at its own commit. No whole commit from that branch was cherry-picked. Its standalone enrollment, fixed membership fee and store cash-confirmation model are not present in V2.

## 2. Reused Stable Authorities

- CRMEB `user.uid` is the user authority.
- Existing CRMEB order, payment and package activation remain the transaction trigger; no second order or payment system is introduced.
- `yfth_package_rule_version`, purchase and purchase snapshots remain the package/pricing authority.
- Stage 1A `yfth_hq_customer_attribution_current/event` remain permanent B-store attribution authority.
- Stage 1A `yfth_hq_active_referral_current/event` remain direct-referral authority.
- Existing `IdempotencyRecordServices`, `AuditEventServices`, store access and user-store-role services are reused.

Production source types are restricted to `package_membership_referral_invite`, `package_membership_activation` and `historical_package_activation`. Legacy customer CRM, reward ledger, `member_5980`, CRMEB distribution fields and client-provided source identifiers are not accepted as authority.

## 3. Schema

Migration `20260716100000_create_yfth_package_membership_referral_v2.php` adds `grants_permanent_membership` to package-rule and purchase-snapshot tables and creates five domain tables:

| Table | Responsibility |
| --- | --- |
| `yfth_permanent_membership` | One active permanent membership authority per UID, frozen package/store/price source |
| `yfth_permanent_membership_event` | Append-only membership activation evidence, unique by membership version and canonical source |
| `yfth_direct_referral_invite` | Hashed invitation, expiry/use state and one-active-invite guard |
| `yfth_direct_referral_rule_version` | Versioned 15/25/60 package rules plus optional ordinary-mall ratio |
| `yfth_direct_referral_reward_candidate` | Immutable-source, sequence-unique, pending candidate observation |

Important unique guards cover membership UID, membership package instance, membership event source, invite token hash, active invite owner, rule version, active rule, candidate source and referrer sequence. No core business object is stored as an opaque JSON blob.

The two added grant columns use three auditable meanings: explicit `1` grants membership, explicit `0` does not, and `NULL` means a pre-V2 package rule or immutable transaction snapshot governed by established package semantics. New rules and snapshots always write explicit `0/1`. Migration performs no historical business scan or bulk rewrite, so it cannot silently recast every existing transaction as non-granting.

## 4. Package Versioning

Publishing a new package rule supersedes the previous rule only by lifecycle status and active key. Previous price, benefits and grant semantics remain unchanged. `PackagePurchaseServices` resolves the authoritative store, requires a membership-granting published package rule and writes the grant decision to the immutable purchase snapshot.

`PackageActivationServices` invokes `PackageMembershipActivationCoordinator` inside the existing activation transaction. The legacy reward hook is skipped for V2 membership-granting snapshots, preventing duplicate or mixed reward domains.

## 5. Invitation And Referral Transaction

Invitation issuance accepts effective membership authority, including a verified historical paid-and-activated package before backfill. A historical member's trusted package source may establish pristine attribution without persisting membership. The service rotates the existing active invitation and stores only the new cryptographically random token hash.

Invitation acceptance uses the idempotency runner and performs this guarded order:

1. derive C1/B1 from the stored invitation snapshot and C2 from authentication;
2. lock C2 attribution as the shared invite/activation serialization gate;
3. lock C1 attribution, then lock and revalidate the exact invitation;
4. reject self-referral, expired/used invitations and effective C2 membership;
5. validate B1 and C1's effective membership;
6. assign C2's pristine attribution to B1;
7. create the one-level referral using the updated locked attribution snapshot;
8. mark the invitation used;
9. complete idempotency and write general audit.

Unique constraints and transaction rollback protect repeated or concurrent submissions. There is no client-selected store or referred UID.

## 6. Package Activation Transaction

The existing package activation owns the outer transaction. The coordinator:

1. validates the frozen membership-grant flag and active store;
2. locks the buyer attribution as the same shared serialization gate used by invitation acceptance;
3. only after that lock, reads and locks any active relation and then its referrer attribution;
4. assigns a pristine buyer attribution when needed;
5. closes an existing active referral with `membership_activated`;
6. locks the active reward rule and prior candidate sequence, then creates the next integer-cent pending candidate;
7. creates or idempotently reuses permanent membership and its event.

Any missing rule, inconsistent authority, unique conflict that is not an exact replay, or other failure rolls back referral closure, candidate and membership together. Concurrent full activation is also protected by the existing package activation locks and the new unique guards.

## 7. Historical Membership

`PackageMembershipServices::effectiveMembership()` recognizes historical permanent membership from durable facts: a real CRMEB paid main order, a succeeded package activation and its frozen purchase/instance chain. It deliberately does not depend on mutable current package status, purchase status or `member_5980`, so later refund/closure cannot make an unbackfilled historical member lose invitation eligibility. Read-through itself does not persist membership.

Headquarters has controlled `dry_run` and `execute` backfill modes. Execute requires an explicit reason, processes a bounded batch, locks the UID attribution current, writes permanent membership/event and audit, and is idempotent by package-instance and UID unique guards. Migration itself never scans or changes historical business data.

## 8. Reward Candidate Boundary

Package ratios are fixed to 15/25/60 by the published rule validator. Amounts use `intdiv(actual_paid_amount_cent * ratio_bps, 10000)`. Candidate sequence is derived under row locks and protected by a unique referrer/sequence index.

The ordinary-mall extension validates a real paid, unrefunded, undeleted, uncancelled main non-package CRMEB order, active relation, matching B1 store and an active configured rule. No listener calls this extension in V2. Candidate status remains `pending`; no service finalizes, settles, pays or reverses it.

## 9. API And Permission Surface

- User: membership summary, invite issue/accept and own candidate list.
- Store: trusted-context membership and candidate lists for `franchisee` and `store_manager` only.
- Headquarters: member/candidate/rule/backfill APIs protected by explicit admin permissions and headquarters scope.
- Admin and uni-app pages are minimum real API surfaces. Neither page exposes settlement or payout controls.

DTOs are role-specific allowlists. User responses omit other users' UID, owner/referrer/referred UID, reward sequence, rule-version ID and other internal implementation fields. Store and headquarters DTOs retain only fields needed by their authorized workflows and never return raw models. Token hashes, canonical source digests, idempotency payloads, internal event rows, payment credentials and private distribution data remain excluded.

`PackageMembershipReferralMigrationHealthServices` is the small forward-repair gate for an already recorded migration. It checks the two grant-column signatures, critical unique indexes and all seven permission signatures. Missing or mismatched recorded state reports `forward_repair_required`; it never attempts an unreviewed repair.

## 10. Deferred Work

Reward finalization, settlement, payout, reversal, refund membership governance, ordinary-mall event wiring, takeover, city partner and production rollout are explicitly outside V2 and require separate design, implementation and review.
