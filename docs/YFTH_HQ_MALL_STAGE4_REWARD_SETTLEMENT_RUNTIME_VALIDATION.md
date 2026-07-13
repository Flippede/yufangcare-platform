# YFTH Headquarters Mall Stage 4 Reward Settlement Runtime Validation

## Intended Checks

- PHP syntax for Stage 4 model, DAO, service, Controllers, migration and tests.
- `php crmeb/tests/yfth_reward_settlement_contract_check.php`.
- An isolated MySQL 8 real-flow run with `YFTH_REWARD_SETTLEMENT_REAL_FLOW_EXECUTE=1`: package and mall candidate handling, trusted-store confirmation/settlement, cross-store rejection, duplicate settlement, ledger uniqueness and audit evidence.
- Migration run, rollback and rerun against the same isolated database.
- Direct Stage 2/Stage 3 contract regressions and affected Admin/uni-app build checks when their toolchains are available.

## Required Behaviour

- Both package and ordinary-mall candidates share the same four-state handling boundary without recalculating snapshot economics.
- A B1 store cannot operate another B1's candidate.
- C1 remains read-only and sees no other user's candidate data.
- Confirm/settle requests require a request ID. Settlement additionally requires a remark plus an offline reference or proof reference.
- Repeated settlement cannot create a second ledger. Cancelled candidates cannot settle. Settled candidates are terminal.
- Full Stage 3 refunds cancel only unfinalized ordinary-mall candidates.

## Environment Note

The current workstation has no callable PHP executable, MySQL client/server, Docker runtime or local isolated Stage 4 database. Therefore PHP syntax, PHP contract/real-flow, migration run/rollback/rerun and HTTP flow have not been executed in this round. The two existing Node uni-app checks and the affected JavaScript/Vue parser check were executed locally; the Admin production build was attempted but exceeded 120 seconds without output and was terminated without producing `template/admin/dist`.

This document remains a validation plan until the PHP/MySQL commands have been executed in a local isolated MySQL 8 environment. No production MySQL/Redis connection, production migration, deployment, or WeChat upload is permitted.
