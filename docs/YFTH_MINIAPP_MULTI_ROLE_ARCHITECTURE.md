# YFTH Multi-role Miniapp Shell V1

## Scope

This round turns the static multi-role prototype direction into a formal uni-app shell under `template/uni-app`.

Implemented:

- Reuse the existing CRMEB customer home at `pages/index/index.vue` as the customer storefront and page-decoration carrier.
- Add a YFTH business-role entry in `pages/user/index.vue`.
- Add a shared context helper at `libs/yfthContext.js`.
- Add the unified workbench shell under `pages/yfth/workbench/*`.
- Register the workbench pages in `pages.json`.
- Keep existing YFTH package, appointment, dynamic-code, and CRMEB customer pages as the real customer surface.
- Close user-token workbench links that previously pointed at admin writeoff/order pages until a formal store-side authentication adapter exists.

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

The shell keeps unfinished areas as explicit construction placeholders without metrics or fake records. This includes store appointment management, service writeoff, CRMEB store orders, procurement, reward, contract, mentor leads, activity, training material, and fuller customer management.

## Permission Boundary

The miniapp shell only selects a role and target store; real authorization remains server-side:

- Customer login/token remains CRMEB/uni-app native behavior.
- Role and store context is resolved by `yfth/context`.
- Store scope is derived from existing YFTH user identity and store-role services.
- The shell does not trust client-supplied role, store, or permission fields as final authority.
- Direct access to the workbench without a valid business context clears local YFTH context and returns to the customer storefront.
- `api/yfth_admin.js` no longer falls back to the customer token. It requires `admin_token` and fails closed with `admin_token_required` when that token is absent.

Known limitation:

- Writeoff, backend store orders, and store appointment management still require a formal business-side authentication adapter or backend user-token API. This round intentionally closes those links instead of routing customer tokens into admin API pages.

## Build And Delivery Notes

`template/uni-app/package.json` currently has no npm scripts and no local `node_modules` directory in this checkout. The local machine has DCloud configuration/cache directories, but no directly discoverable HBuilderX executable in the checked paths. H5/dev/prod builds therefore require the project's existing HBuilderX/uni-app build environment or a later dependency setup task. See `docs/YFTH_UNIAPP_BUILD_GUIDE.md`; do not record a successful H5 build unless the real toolchain has run.

## Contract Check

The lightweight Node contract check is:

```bash
node template/uni-app/tests/yfth_multi_role_shell_contract_check.js
```

It verifies page registration, business-entry gating, role whitelist usage, uid-bound context caching, admin API fail-closed behavior, no user-token workbench links to admin writeoff/order pages, and CRMEB storefront decoration preservation.

## Next Round

Recommended follow-up is a read-only architecture review of the formal miniapp shell before expanding business flows. Later rounds should build real business modules behind the shell one by one, with backend permissions, migrations, and tests added only when those capabilities are actually implemented.
