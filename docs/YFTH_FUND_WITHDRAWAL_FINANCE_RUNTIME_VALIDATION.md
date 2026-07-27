# YFTH Partner And Store Withdrawal Finance V1 Runtime Validation

## Environment

- PHP: 7.4.33 portable runtime
- Database: isolated MySQL Community 8.0.46
- Node: 18.20.8 for uni-app production builds
- Baseline: `3c57e2e21f877c76d1c0c5695f81e60d56ff97ec`

No production database, payment provider, SMS provider or WeChat upload was used during local validation.

## PHP And Contract Checks

The modified PHP files passed PHP 7.4 syntax checks.

Focused contract results:

- fund-withdrawal contract: 62 assertions passed;
- franchise-application contract: 210 assertions passed;
- franchise-partner contract: 111 assertions passed;
- automatic-commission contract passed;
- partner-reward unification contract: 34 assertions passed;
- existing reward-settlement contract passed.

## Migration

The V1 migration was verified on isolated MySQL 8.0.46:

- run created the three withdrawal tables and six menu/permission rows;
- targeted rollback removed only this migration's objects;
- rerun restored the same objects;
- duplicate run remained idempotent.

The repository contains two older migration files with the same historical version number `20260718100000`. The isolated lifecycle test controlled that pre-existing collision without renaming applied history. Production release must use a controlled forward execution of the new migration and verify the migration record.

## Withdrawal Real Flow

The real-flow check verified:

- observation-period rows are not withdrawable early;
- partner request creation is idempotent;
- payment before approval is rejected;
- paid confirmation offsets partner sources exactly once;
- rejection releases frozen sources;
- changing/reversing a frozen source blocks later approval;
- a store manager can create and inspect a B1 request;
- a store staff account can inspect but cannot create a B1 request;
- a non-store role cannot access a B1 request;
- approval alone does not offset B1 commission;
- paid confirmation writes one immutable B1 debit ledger and offsets once.

## Franchise Opening And Team Projection

The five-rank real flow verified that headquarters public approval:

- opens the application;
- creates or reuses the store;
- grants the applicant store-manager authority;
- binds the store to the recruiting county partner;
- exposes the store in the partner team/performance projection;
- emits the opening reward once;
- remains idempotent on duplicate approval.

## Frontend Builds

- Admin production build: passed with existing CSS-order and browserslist warnings.
- H5 production build: passed; 366 files, 10,291,165 bytes, `index.html` present.
- mp-weixin production compile: passed; 1,302 files, 8,022,235 bytes, `app.js` present.

The mini-program build retained only the pre-existing skeleton expression warnings. The withdrawal and partner-store pages introduced no new compile warning.

## Remaining Release Work

Before production release:

- create code, database and static-asset backups;
- run only the new forward migration and verify its tables, indexes and permissions;
- deploy the committed Admin and H5 assets;
- clear only project menu/permission caches;
- verify the finance menu, partner withdrawal, B1 withdrawal and county partner team-store projection with private TEST accounts;
- do not perform a real bank payment, SMS send, WeChat payment or mini-program upload.
