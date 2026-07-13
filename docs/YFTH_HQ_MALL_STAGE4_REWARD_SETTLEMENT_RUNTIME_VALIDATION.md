# YFTH Headquarters Mall Stage 4 Reward Settlement Runtime Validation

## Isolated Environment

- Runtime: portable PHP 7.4.33 with the existing test `php-yfth-test.ini` and MySQL Community Server 8.0.46.
- Database: a fresh local isolated database on `127.0.0.1:33329`; no production MySQL, Redis, configuration, migration, deployment, or WeChat upload was used.
- Admin build: existing `template/admin/node_modules` with Node 22.22.2 and npm 11.6.2.

## Executed Checks

- PHP syntax passed for every Stage 4 PHP file changed from the development baseline, including the settlement service, migration, contract check, and real-flow check.
- `php crmeb/tests/yfth_reward_settlement_contract_check.php` passed. It verifies four-state handling, locks, idempotency, audit wiring, explicit permissions/routes, refund transition support, role surfaces, and the absence of CRMEB balance, brokerage, score, withdrawal, or order-update writes in the settlement service.
- `YFTH_REWARD_SETTLEMENT_REAL_FLOW_EXECUTE=1 php crmeb/tests/yfth_reward_settlement_real_flow_check.php` passed against the isolated MySQL 8 database.
- `php think migrate:run`, `php think migrate:rollback -t 0`, and a second `php think migrate:run` all passed. The first run created the settlement ledger and its candidate/settlement-number unique keys, plus the three headquarters permissions; rollback removed both table and permissions; rerun restored them.
- `npm run build` in `template/admin` completed successfully in about 188 seconds. Existing CSS-order and stale Browserslist warnings did not block the production build.

## Real-flow Evidence

- Both immutable package-activation and ordinary-mall candidates were confirmed by the trusted B1 store context.
- A duplicate confirmation returned the existing confirmed result without a second transition; duplicate settlement returned the existing ledger and left exactly one ledger row.
- A cross-store B1 context was rejected. A cancelled candidate could not settle. A settled candidate could not be returned to pending through the ordinary headquarters correction path.
- A C1 with effective permanent membership saw only its own candidate records, and the user DTO omitted referrer/referred UIDs, reward sequence, rule version, and source-business internals.
- Full CRMEB refunds cancelled isolated ordinary-mall candidates in `pending` and `confirmed`. A `settled` candidate remained settled and reported that it was no longer an unsettled candidate; no automatic reversal was performed.
- The contract guard confirms Stage 4 does not write CRMEB balances, commissions, points, distribution, withdrawal, or CRMEB order data.

## Validation Fixes

- The migration now keeps Phinx method visibility compatible, reads existing system-menu rows through the adapter shape used by the project, and uses permission menu names that fit CRMEB's `system_menus.menu_name` length.
- The settlement response now preserves the service's idempotent-result flag for a repeat state operation with a different request ID.
- The contract route assertion now matches the existing grouped headquarters route, and the real-flow script covers DTO scope plus refund and terminal-state cases.

## Remaining Boundary

- This is direct Stage 4 validation evidence only. The feature branch is not merged to `main` and has not received an independent architecture review in this round.
- Automatic payout, wallet, withdrawal, payment split, reconciliation, partial-refund reversal, store takeover, city partner, multi-level referral, production migration, production deployment, and WeChat upload remain out of scope.
