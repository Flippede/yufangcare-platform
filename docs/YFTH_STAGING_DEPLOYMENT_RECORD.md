# YFTH Staging Deployment Record

## Attempt: 2026-07-14

### Intended Isolation

- Release source: `e1a1f5fd6aa457cd53866953265f30b264f8d00b` from stable `main`.
- Server-only release root, distinct from the formal site directory.
- Planned Compose services: MySQL 8.0.46, Redis, PHP 7.4 FPM, Nginx, queue, timer and Workerman.
- Planned external test port: `39001`; it is inside an already allowed firewall range, but no listener was started.
- Planned data isolation: a new MySQL volume/database/user and a new Redis volume/password/prefix/queue name. All values are generated server-side, mode `0600`, and are not present in this repository.

### Prepared Artifacts

- Exact Git archive of the release commit.
- Tracked `crmeb/public/admin` production assets.
- Existing H5 production build output.
- Server-only Compose, PHP-FPM and Nginx configuration.

### Result

The isolated stack is **not running**. Docker could not pull `mysql:8.0.46`: every configured mirror and an explicit IPv4 Docker Hub endpoint timed out. No images existed locally. Consequently no test database import, migration, queue/timer/WebSocket startup, authenticated browser session, Admin login, customer login, payment flow, refund callback, SMS call, or WeChat authorization was executed.

The host's existing MySQL 5.7 and Redis services are treated as formal-site infrastructure. They were not queried for application data, reused, or changed, because doing so would violate the staging isolation requirement.

### Resume Gate

Provide one of the following without changing the formal site:

1. Approved outbound access to a reachable container registry; or
2. Preloaded compatible images for MySQL 8.0.46, Redis, Nginx, PHP 7.4 and Composer.

Before external user access, also provide a staging DNS/TLS route or explicitly approve port-only testing, plus non-production WeChat, payment, refund-callback and SMS credentials. The next execution must verify migrations, static assets, workers, role-scoped pages and the full staged purchase/payment flow before any release decision.
