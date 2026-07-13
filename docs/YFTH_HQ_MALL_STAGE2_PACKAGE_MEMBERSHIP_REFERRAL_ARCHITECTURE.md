# YFTH Headquarters Mall Stage 2 V2 Architecture

## 1. Status And Scope

Stage 2 V2 implements package-activated permanent membership and the minimum one-level direct-referral loop on branch `codex/yfth-hq-mall-stage2-package-membership-referral-v1`. The stable base is `3ec6c80dbfef4975788414f64ab70c9e439cf117`.

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

## 4. Package Versioning

Publishing a new package rule supersedes the previous rule only by lifecycle status and active key. Previous price, benefits and grant semantics remain unchanged. `PackagePurchaseServices` resolves the authoritative store, requires a membership-granting published package rule and writes the grant decision to the immutable purchase snapshot.

`PackageActivationServices` invokes `PackageMembershipActivationCoordinator` inside the existing activation transaction. The legacy reward hook is skipped for V2 membership-granting snapshots, preventing duplicate or mixed reward domains.

## 5. Invitation And Referral Transaction

Invitation issuance locks the member's existing active invitation row, invalidates it if present, creates a cryptographically random replacement and stores only the token hash. Persisted active membership, consistent active attribution and active store are required.

Invitation acceptance uses the idempotency runner and performs this guarded order:

1. lock and validate the exact invitation by token hash;
2. derive C1/B1 from the invitation and C2 from authentication;
3. reject self-referral, expired/used invitations and existing C2 membership;
4. validate B1 and C1's persisted membership;
5. ensure and lock C1/C2 attribution currents in numeric UID order;
6. assign C2's pristine attribution to B1;
7. create the one-level referral using the updated locked attribution snapshot;
8. mark the invitation used;
9. complete idempotency and write general audit.

Unique constraints and transaction rollback protect repeated or concurrent submissions. There is no client-selected store or referred UID.

## 6. Package Activation Transaction

The existing package activation owns the outer transaction. The coordinator:

1. validates the frozen membership-grant flag and active store;
2. obtains referral membership lock context;
3. locks all related attribution currents in numeric UID order;
4. assigns a pristine buyer attribution when needed;
5. closes an existing active referral with `membership_activated`;
6. locks the active reward rule and prior candidate sequence, then creates the next integer-cent pending candidate;
7. creates or idempotently reuses permanent membership and its event.

Any missing rule, inconsistent authority, unique conflict that is not an exact replay, or other failure rolls back referral closure, candidate and membership together. Concurrent full activation is also protected by the existing package activation locks and the new unique guards.

## 7. Historical Membership

`PackageMembershipServices::effectiveMembership()` recognizes a historical membership only from a real chain of active package instance, activated/succeeded purchase and paid CRMEB order. This read-through does not silently persist data.

Headquarters has controlled `dry_run` and `execute` backfill modes. Execute requires an explicit reason, processes a bounded batch, locks the UID attribution current, writes permanent membership/event and audit, and is idempotent by package-instance and UID unique guards. Migration itself never scans or changes historical business data.

## 8. Reward Candidate Boundary

Package ratios are fixed to 15/25/60 by the published rule validator. Amounts use `intdiv(actual_paid_amount_cent * ratio_bps, 10000)`. Candidate sequence is derived under row locks and protected by a unique referrer/sequence index.

The ordinary-mall extension validates a real paid non-package CRMEB order, active relation, matching B1 store and an active configured rule. No listener calls this extension in V2. Candidate status remains `pending`; no service finalizes, settles, pays or reverses it.

## 9. API And Permission Surface

- User: membership summary, invite issue/accept and own candidate list.
- Store: trusted-context membership and candidate lists for `franchisee` and `store_manager` only.
- Headquarters: member/candidate/rule/backfill APIs protected by explicit admin permissions and headquarters scope.
- Admin and uni-app pages are minimum real API surfaces. Neither page exposes settlement or payout controls.

DTOs are allowlists and do not expose token hashes, canonical source digests, idempotency payloads, internal event rows, payment credentials or private distribution data.

## 10. Deferred Work

Reward finalization, settlement, payout, reversal, refund membership governance, ordinary-mall event wiring, takeover, city partner and production rollout are explicitly outside V2 and require separate design, implementation and review.
