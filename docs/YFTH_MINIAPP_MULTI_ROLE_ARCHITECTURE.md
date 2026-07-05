# YFTH Multi-role Miniapp Shell V1

## Scope

This round turns the static multi-role prototype direction into a formal uni-app shell under `template/uni-app`.

Implemented:

- Reuse the existing CRMEB customer home at `pages/index/index.vue` as the customer storefront and page-decoration carrier.
- Add a YFTH business-role entry in `pages/user/index.vue`.
- Add a shared context helper at `libs/yfthContext.js`.
- Add the unified workbench shell under `pages/yfth/workbench/*`.
- Register the workbench pages in `pages.json`.
- Reuse existing YFTH package, appointment, dynamic-code, writeoff, and CRMEB order pages where links already exist.

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

The customer-to-business transition is intentionally placed in `pages/user/index.vue` through the "御方通和经营工作台" entry. This keeps the customer home as a storefront instead of turning it into an internal workbench.

## Identity Context

The shell reuses existing user-token APIs from the YFTH foundation domain:

- `GET yfth/identities`
- `GET yfth/context`
- `GET yfth/capability/:capability`

`libs/yfthContext.js` caches only the selected role and store context. It does not treat local cache as an authorization source. Every role/store switch calls the server-side context endpoint, which is backed by the existing YFTH identity and store-role validation.

Business roles currently recognized by the shell:

- `franchisee`
- `store_manager`
- `store_staff`
- `service_mentor`

Store-scoped roles must have a server-authorized `store_id`. The front end does not allow arbitrary store input.

## Workbench Shell

`pages/yfth/workbench/index.vue` is a navigation and composition shell, not a new business engine.

It links to existing stable pages:

- Appointment list: `/pages/yfth/appointment/list`
- Service writeoff: `/pages/admin/yfth_writeoff/index`
- CRMEB store order list: `/pages/admin/orderList/index`
- Customer storefront: `/pages/index/index`

The shell keeps unfinished areas as explicit construction placeholders without metrics or fake records. This includes procurement, reward, contract, mentor leads, activity, training material, and fuller customer management.

## Permission Boundary

The miniapp shell only selects a role and target store; real authorization remains server-side:

- Customer login/token remains CRMEB/uni-app native behavior.
- Role and store context is resolved by `yfth/context`.
- Store scope is derived from existing YFTH user identity and store-role services.
- The shell does not trust client-supplied role, store, or permission fields as final authority.

Known limitation:

- The reused writeoff and order pages keep their existing token/API assumptions. This round does not rebuild those pages into a separate user-token workbench API surface.

## Build And Delivery Notes

`template/uni-app/package.json` currently has no npm scripts and no local `node_modules` directory in this checkout. H5/dev/prod builds therefore require the project's existing HBuilderX/uni-app build environment or a later dependency setup task. This round records that limitation rather than inventing a successful build.

## Next Round

Recommended follow-up is a read-only architecture review of the formal miniapp shell before expanding business flows. Later rounds should build real business modules behind the shell one by one, with backend permissions, migrations, and tests added only when those capabilities are actually implemented.
