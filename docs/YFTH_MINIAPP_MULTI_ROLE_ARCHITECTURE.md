# YFTH Multi-role Miniapp Shell V1

## Store Workbench Business Adapter V1 - 2026-07-06

The store workbench shell has now been connected to real user-token backend APIs for the first store-scoped business round.

Completed in this adapter round:

- Store appointment list/detail, manager/franchisee confirm, reject, and cancel.
- Store staff read-only appointment management plus writeoff entry.
- Service writeoff token/digital precheck, confirmation, result, and record lookup.
- Store order read-only list/detail.
- Real server-side role and store validation through the existing YFTH business context.
- Customer-token and `admin_token` isolation preserved.
- The previous admin-compatible wording has been replaced by an explicit `yfth_operator_context` with `operator_type = user_store_role`; the user-token workbench does not forge backend admin identity.
- Isolated MySQL 8.0.46 plus Redis plus local CRMEB HTTP validation now covers the store workbench route group through real CRMEB user tokens.

The adapter is documented in `docs/YFTH_STORE_WORKBENCH_ADAPTER_ARCHITECTURE.md`.
The runtime validation facts are documented in `docs/YFTH_STORE_WORKBENCH_RUNTIME_VALIDATION.md`.

The earlier shell limitation that user-token store writeoff, store orders, and appointment management were not open is now historical for the shell V1 closure. It no longer represents the current state of the workbench adapter branch.

## Scope

This round turns the static multi-role prototype direction into a formal uni-app shell under `template/uni-app`.

Implemented:

- Reuse the existing CRMEB customer home at `pages/index/index.vue` as the customer storefront and page-decoration carrier.
- Add a YFTH business-role entry in `pages/user/index.vue`.
- Add a shared context helper at `libs/yfthContext.js`.
- Add the unified workbench shell under `pages/yfth/workbench/*`.
- Register the workbench pages in `pages.json`.
- Keep existing YFTH package, appointment, dynamic-code, and CRMEB customer pages as the real customer surface.
- Close user-token workbench links that previously pointed at admin writeoff/order pages, then reconnect store appointment/writeoff/order capabilities through the formal user-token store workbench adapter.

Not implemented:

- No backend API, database table, migration, or state-machine change.
- No recommendation, reward, procurement, inventory, settlement, contract, delivery, or mentor business workflow.
- No fake dashboard statistics or fake submission flow.
- No replacement of CRMEB login, token, page decoration, order, payment, refund, or package activation logic.

## Customer Surface

The customer role remains on the CRMEB storefront architecture:

- `pages/index/index.vue` continues to fetch and render backend page-decoration data such as search/header, menu grid, swiper, image blocks, rich text, product lists, and footer navigation.
- The native CRMEB user token and login flow remain unchanged.
- The customer bottom navigation is still provided by the existing CRMEB page/footer mechanism. This round does not replace it with a separate static footer.

The customer-to-business transition is intentionally placed in `pages/user/index.vue` through the "御方通和经营工作台" entry. The entry is shown only when `yfth/identities` returns at least one business role for the current logged-in user. This keeps the customer home as a storefront instead of turning it into an internal workbench.

## Identity Context

The shell reuses existing user-token APIs from the YFTH foundation domain:

- `GET yfth/identities`
- `GET yfth/context`
- `GET yfth/capability/:capability`

`libs/yfthContext.js` caches only the selected role and store context. It does not treat local cache as an authorization source. Every role/store switch calls the server-side context endpoint, which is backed by the existing YFTH identity and store-role validation. The cached context is also bound to the current CRMEB `uid`; login, logout, token invalidation, or user switching clears the cached role/store selection.

Business roles currently recognized by the shell:

- `franchisee`
- `store_manager`
- `store_staff`
- `service_mentor`

Store-scoped roles must have a server-authorized `store_id`. The front end does not allow arbitrary store input.

## Workbench Shell

`pages/yfth/workbench/index.vue` is a navigation and composition shell, not a new business engine.

It links only to surfaces that fit the current user-token boundary:

- Customer storefront: `/pages/index/index`
- Role switch: `/pages/yfth/workbench/role_switch`
- Store switch: `/pages/yfth/workbench/store_switch`

The shell now connects store appointment management, service writeoff, and CRMEB store order read-only views through the store workbench adapter. Remaining unfinished areas stay explicit placeholders without fake records, including procurement, reward, contract, mentor leads, activity, training material, and fuller customer management.

## Permission Boundary

The miniapp shell only selects a role and target store; real authorization remains server-side:

- Customer login/token remains CRMEB/uni-app native behavior.
- Role and store context is resolved by `yfth/context`.
- Store scope is derived from existing YFTH user identity and store-role services.
- The shell does not trust client-supplied role, store, or permission fields as final authority.
- Direct access to the workbench without a valid business context clears local YFTH context and returns to the customer storefront.
- `api/yfth_admin.js` no longer falls back to the customer token. It requires `admin_token` and fails closed with `admin_token_required` when that token is absent.

Historical shell limitation:

- Before the store workbench adapter branch, writeoff, backend store orders, and store appointment management required a formal business-side authentication adapter or backend user-token API. That limitation is historical for this branch.

Current state:

- The formal backend user-token adapter now exists for store appointment management, service writeoff, and store order read-only lookup.
- Store workbench API calls use CRMEB user token routes under `yfth/store_workbench/*`; they do not call adminapi and do not request or persist `admin_token`.
- Store workbench operations pass an explicit `user_store_role` operator context into the reused appointment/writeoff services and keep backend-admin context separate.
- The adapter still does not implement procurement, inventory replenishment, product quota, franchise contracts, recommendation rewards, mentor real business, settlement, or revenue sharing.

## P1 Audit Fix - 2026-07-05

The architecture audit result before this fix was C, with the branch not allowed to merge. This round only closes the listed request-layer, user-center entry, and footer tolerance issues. It does not add new business modules and still requires a follow-up read-only architecture re-review before any main merge decision.

### H5 HTML Response Boundary

Root cause: `utils/request.js` previously treated any H5 HTML response as a successful empty CRMEB API response. That could mask production login expiry, permission denial, gateway/PHP/database errors, or other non-JSON API failures.

Fix:

- HTML response classification was extracted to `utils/yfthH5Fallback.js`.
- Development fallback is allowed only when all of these are true: H5 development mode, HTTP 200, local devServer origin, confirmed full HTML document, and explicit endpoint whitelist.
- Production H5 never converts HTML to success. Non-200 HTML and non-whitelisted HTML reject through the normal request flow.
- The whitelist is limited to local page-decoration/bootstrap endpoints such as `basic_config`, `menu/user`, `navigation`, `share`, `copyright`, `wechat/get_logo`, and `ajcaptcha`. YFTH business APIs such as `yfth/identities`, appointment, writeoff, package, order, payment, and refund endpoints are not whitelisted.
- `/api/get_script` now ignores full HTML only for local H5 development fallback and still checks HTTP status.

### User-center Business Entry Lifecycle

Root cause: the YFTH business entry in `pages/user/index.vue` was not reliably refreshed from the normal logged-in `onShow()` path.

Fix:

- `onShow()` and `onLoadFun()` now reset the entry to false before loading.
- User info is refreshed first; identity loading starts only after a current UID is available.
- `yfthBusinessIdentityRequestSeq` plus current UID comparison prevents stale async identity results from writing after logout or user switch.
- Request failure, no identity, revoked identity, and logged-out state all keep the entry hidden.
- `user/set_visit` failures are swallowed because visit logging must not break user-center rendering or identity gating.

### Footer Request Failure Tolerance

Root cause: `components/pageFooter/index.vue` could call `setNavigationInfo({})` on request failure and clear a valid existing footer.

Fix:

- Request failure now calls `keepCurrentNavigation()`.
- A valid current footer or valid cached footer is preserved on version/navigation request failure.
- A successful explicit empty config may still hide the footer.
- No-cache plus request failure remains a safe hidden state.

### Workbench Compatibility

- Workbench identity list keys now use the normalized `identity_key` field instead of non-H5 `:key` expressions.
- Direct role-switch identity failure clears YFTH context and returns safely to the customer side.

## Build And Delivery Notes

`template/uni-app` remains an HBuilderX-style CRMEB uni-app project rather than an npm-script driven project. `package.json` still has no `dev:h5` or `build:h5` script, so the verified build path is the DCloud/HBuilderX compiler.

Verified on 2026-07-05:

- HBuilderX: `5.14.2026070214`, installed outside the repository at `C:\Users\zhangxu\.codex\tools\hbuilderx-5.14.2026070214\HBuilderX`.
- Node.js for the legacy CRMEB compiler path: `v18.20.8`, installed outside the repository at `C:\Users\zhangxu\.codex\tools\node-v18.20.8-win-x64`.
- H5 development build: passed with `UNI_PLATFORM=h5`, output `template/uni-app/unpackage/dist/dev/h5`, local URL `http://127.0.0.1:8080/`.
- H5 production build: passed with `UNI_PLATFORM=h5`, output `template/uni-app/unpackage/dist/build/h5`.
- H5 browser validation: customer home, user center, workbench direct access, role switch, and store switch were opened in Edge/Chromium. The customer home renders a safe empty CRMEB storefront state when no local backend is connected, and direct workbench access without business identity is blocked back to the customer side.
- WeChat mini program production compile: passed through HBuilderX `uniapp-cli` + Node 18 with `UNI_PLATFORM=mp-weixin`, output `template/uni-app/unpackage/dist/build/mp-weixin`.
- P1 audit fix verification: `yfth_request_fallback_check.js` and `yfth_multi_role_shell_contract_check.js` both passed; browser validation covered customer home, user center, direct workbench access, role switch, store switch, mocked customer/store_staff/store_manager/franchisee/service_mentor identities, identity-request failure, and cached footer preservation on request failure.
- Local Node/V8 optimization crashes were reproduced during production compile retries. `mp-weixin` completed with the same HBuilderX `uniapp-cli` and Node 18 plus `--no-opt`; this is a local compiler runtime flag, not a source or dependency change.

The HBuilderX `cli.exe launch mp-weixin --compile true` entry still invokes HBuilderX's bundled Node 22 and reports a missing old `node-sass` ABI 127 binary. This is a tooling compatibility boundary, not a business-code failure. Do not switch the project to Dart Sass or upgrade the CRMEB frontend stack without a separate compatibility task.

No HBuilderX program files, Node runtime, DCloud cache, `node_modules`, generated `unpackage` output, credentials, or local logs are committed.

## Contract Check

The lightweight Node contract check is:

```bash
node template/uni-app/tests/yfth_multi_role_shell_contract_check.js
node template/uni-app/tests/yfth_request_fallback_check.js
```

It verifies page registration, business-entry gating, role whitelist usage, uid-bound context caching, admin API fail-closed behavior, no user-token workbench links to admin writeoff/order pages, CRMEB storefront decoration preservation, and the H5 HTML fallback boundary.

## Next Round

Recommended follow-up is a read-only architecture review of Store Workbench Business Adapter V1 before any `main` merge decision. Later rounds should build real business modules behind the shell one by one, with backend permissions, migrations, and tests added only when those capabilities are actually implemented.
