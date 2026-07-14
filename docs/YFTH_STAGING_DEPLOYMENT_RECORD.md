# YFTH Staging Deployment Record

## Recovery: 2026-07-14

### Result

The isolated Release Candidate environment is running from server-only root
`/www/staging/yfth-rc-v1` at [http://39.107.70.253:39001](http://39.107.70.253:39001).
It runs release `e1a1f5fd6aa457cd53866953265f30b264f8d00b` from stable `main`.

Docker Hub and configured mirror access remain unavailable, so the environment was recovered without retrying those endpoints:

- Native MySQL Community `8.0.46` is bound to `127.0.0.1:3307`, with a dedicated staging data directory, database and database user.
- Native Redis is bound to `127.0.0.1:6380`, with its own data directory, password, cache prefix and queue name.
- A private PHP `7.4.33` FPM pool listens on `127.0.0.1:9074`; its required `fileinfo` extension is built and loaded only from the staging tree.
- A separate Nginx master serves port `39001`, the tracked `crmeb/public/admin` assets, and the H5 production output. The formal Nginx virtual hosts were not changed.
- The staging queue listener, timer and Workerman processes are running with separate PID and log paths. Timer status is healthy; Workerman exposes its staging-only `40001`, `40002` and loopback `40003` listeners.

All secrets and test-only credentials remain in server-only mode-`0600` files. They are not stored in this repository or this record.

### Database And Release Preparation

- The repository CRMEB base schema was imported into the isolated MySQL 8 database using its documented one-time compatibility SQL mode.
- `php think migrate:run` completed, and migration status reports the YFTH migrations through the Stage 4 reward-settlement ledger as `up`.
- The tracked Admin production assets are served from `crmeb/public/admin`; the previously built H5 output is served from the staging release. There is no nested Admin `dist` directory.
- A single published package and minimal test accounts exist only in the isolated database so that public package and authenticated customer paths can be exercised. They are not production data.

### Executed Smoke Checks

- External HTTP checks returned `200` for `/healthz`, `/`, `/admin/`, `/api/yfth/package/list`, and the staged package detail endpoint.
- A real staging Admin login API request succeeded; the authenticated headquarters reward-settlement candidate list returned successfully.
- A real staging customer account/password login succeeded. Browser smoke checks loaded the published package list, package detail, and the package-membership/invitation page without page errors.
- The customer has no assigned store role, and the store-workbench API rejected that request with `store_workbench_role_forbidden` as expected. A role-scoped B1/store-workbench acceptance session still requires separately configured staging store-role data.
- MySQL reports `8.0.46`; Redis `PING` returned `PONG`; the queue, timer and Workerman processes were confirmed live. The only browser network failure observed was the optional DCloud telemetry collector, which does not affect application routing or API calls.

### External Integration Gate

No non-production WeChat authorization, WeChat payment, payment-refund callback, SMS, or mini-program platform credentials were available. Those integrations were not invoked and no success is claimed. A dedicated staging DNS name and TLS certificate are also still pending; current controlled access is HTTP on the isolated port.

The environment is ready for technical and non-payment user smoke testing. Full user acceptance and any release decision remain blocked on non-production external credentials, role-scoped store test data, and real callback testing.

### Isolation Confirmation

The formal MySQL 5.7 instance, formal Redis instance, formal application data, formal site directory and formal Nginx configuration were not reused, queried for business data, migrated, stopped or changed. No production deployment, production migration, WeChat upload, or production credential use occurred.
