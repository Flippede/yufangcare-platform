# YFTH Release Scope - Package Membership And Direct Referral V2

> Current authoritative scope. This document supersedes the former standalone permanent-membership enrollment and standalone fixed-price business-package design. The former Stage 2 branch and documents are historical references only and must not be merged or used as implementation authority.

## 1. Product Rules

- The platform has one configurable core package model. `5980` and `9800` are not business constants.
- Package price, benefits, service period and the permanent-membership grant flag are maintained through versioned package rules.
- A paid transaction freezes its rule version, actual paid amount, currency, benefits and membership-grant decision. Later rule publication never rewrites a historical transaction snapshot.
- Successful activation of a package whose frozen snapshot grants membership creates permanent YFTH membership. There is no separate membership product, enrollment, store cash confirmation or fixed membership fee.
- A historical user with a valid paid and successfully activated package instance is recognized through read-through membership and can be persisted by the controlled headquarters backfill.
- Refund status does not revoke permanent membership in V2. Revocation, reversal and dispute handling require a separately reviewed later stage.

## 2. C1, C2 And B1

- C1 must have persisted active permanent membership before issuing an invitation.
- The invitation is an opaque 256-bit token. Only its SHA-256 hash is stored, one active invitation is allowed per C1, and issuing a new invitation invalidates the previous active invitation.
- C2 is derived exclusively from the authenticated user token. C2 must not already be a permanent member.
- Accepting a valid invitation atomically assigns C2's first headquarters attribution to C1's B1 store and creates one active direct-referral relation from C1 to C2.
- C1 and C2 therefore share B1 permanently through the Stage 1A authority tables. Client-supplied UID, owner UID, referrer UID, store ID or source key is never authoritative.
- Before C2's package activation, package purchase must use B1. A request attempting to select another store fails closed.
- When C2's package activates, the referral closes with `membership_activated`; C2 becomes independently qualified to issue invitations. No multi-level referral tree is created.

## 3. Candidate Reward Rules

- Package activation uses the frozen actual paid amount in integer cents.
- The same referrer receives package-activation candidates in a continuing three-position cycle: sequence 1/4/7 uses 15%, sequence 2/5/8 uses 25%, and sequence 3/6/9 uses 60%.
- Ratios are represented in basis points (`1500`, `2500`, `6000`) and candidate amounts use integer arithmetic.
- A candidate is observation-only state with status `pending`. It is not a wallet balance, commission, payout, settlement, accounting ledger or evidence that money was paid.
- Ordinary headquarters-mall consumption has a versioned configurable ratio and a tested candidate service entry. V2 deliberately does not wire a CRMEB order listener, because doing so would expand the payment/refund boundary. No ordinary mall candidate is claimed unless the service is explicitly invoked by a later reviewed integration.
- C2's closed referral does not generate further ordinary-consumption candidates for C1.

## 4. Authority And Store Boundaries

- CRMEB `user.uid` remains the only user identity.
- Permanent attribution and direct-referral current/event authority remain in the Stage 1A tables.
- Package activation, referral acceptance and historical activation are the only new production authority sources.
- User APIs trust the authenticated UID. Store APIs trust `CurrentBusinessContextServices`; only `franchisee` and `store_manager` may read the store surface.
- Headquarters writes require the dedicated admin permission and headquarters scope. Backfill execute mode requires an explicit reason.
- Disabled stores, revoked store roles, inconsistent Stage 1A current/event data and cross-store requests fail closed.

## 5. Frozen Boundaries

V2 does not implement or authorize:

- standalone membership sale or enrollment;
- fixed `5980` or `9800` pricing in production code;
- reward finalization, wallet credit, commission, settlement, payout, withdrawal or reversal;
- CRMEB distribution-field reuse;
- refund-driven membership revocation;
- ordinary mall order listener, payment listener or refund listener for candidates;
- store takeover, city partner, multi-level referral or historical CRM referral import;
- production deployment, production migration or production data backfill.

## 6. Release Gate

The feature branch is `codex/yfth-hq-mall-stage2-package-membership-referral-v1`, based on stable `main` `3ec6c80dbfef4975788414f64ab70c9e439cf117`. It is not merged into `main`. The next and only gate is an independent read-only Architecture Auditor review.
