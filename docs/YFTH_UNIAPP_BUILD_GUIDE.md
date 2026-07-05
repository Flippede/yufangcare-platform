# YFTH Uni-app Build Guide

## Current Project Shape

The mobile client lives in `template/uni-app`.

This checkout is an HBuilderX-style uni-app source tree:

- `manifest.json`, `pages.json`, `main.js`, `App.vue`, and CRMEB page modules are present.
- `package.json` currently has no `scripts`.
- `template/uni-app/node_modules` is not present in this checkout.
- `package-lock.json` exists, but it is not enough to run H5 build commands by itself.

## Verified Local Tool State

On 2026-07-05, the local checks found:

- `node -v`: `v24.13.0`
- `npm -v`: `11.6.2`
- `template/uni-app/node_modules`: absent
- `npm run dev:h5`: failed with `Missing script: "dev:h5"`
- `npm run build:h5`: failed with `Missing script: "build:h5"`
- Common Windows search paths did not expose a directly runnable `HBuilderX.exe`.

Therefore, this round did not complete a real H5 dev server run or H5 production build. Do not mark H5 build verification as passed until one of the formal toolchains below is available and executed.

## Preferred Build Mode

Use the project's existing HBuilderX/uni-app build environment when available.

Expected manual flow:

1. Open HBuilderX.
2. Open the folder `template/uni-app`.
3. Confirm `manifest.json` and `pages.json` load without conversion.
4. Run H5 preview from HBuilderX.
5. Run production H5 build from HBuilderX.
6. Verify generated output and browser loading.

Expected CLI flow, only when the installed HBuilderX exposes a CLI:

```powershell
cd "C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform\template\uni-app"
# Use the concrete HBuilderX CLI path installed on the machine.
# Run H5 dev/preview and production build according to that installed CLI version.
```

Record the exact executable path and command output in `docs/PROJECT_HANDOFF.md` after a successful run.

## Do Not

- Do not invent `npm run dev:h5` or `npm run build:h5` success while `package.json` has no scripts.
- Do not upgrade Vue, uni-app, Webpack, Babel, or CRMEB core dependencies just to force a build.
- Do not commit `node_modules`, HBuilderX caches, local logs, temporary build tools, credentials, or environment files.
- Do not modify production server files or connect to production databases during mobile build verification.

## Current Verification Boundary

Until the formal HBuilderX/uni-app build environment is restored, verification for the multi-role shell is limited to:

- Static source checks.
- `pages.json` registration checks.
- Auth-boundary contract checks.
- Manual source-level route and token-boundary review.

Current contract check:

```powershell
cd "C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform"
node template/uni-app/tests/yfth_multi_role_shell_contract_check.js
```
