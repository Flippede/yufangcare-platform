# YFTH Uni-app Build Guide

## Current Project Shape

The mobile client lives in `template/uni-app`.

This checkout is an HBuilderX-style CRMEB uni-app source tree:

- `manifest.json`, `pages.json`, `main.js`, `App.vue`, and CRMEB page modules are present.
- `package.json` currently has no `scripts`; do not invent `npm run dev:h5` or `npm run build:h5`.
- `template/uni-app/node_modules` is not required for the verified HBuilderX compiler path.
- Build tools and generated outputs must stay out of Git.

## Verified Toolchain

Verified on 2026-07-05:

- HBuilderX: `5.14.2026070214`
- HBuilderX path: `C:\Users\zhangxu\.codex\tools\hbuilderx-5.14.2026070214\HBuilderX`
- HBuilderX CLI: `C:\Users\zhangxu\.codex\tools\hbuilderx-5.14.2026070214\HBuilderX\cli.exe`
- HBuilderX compiler entry: `C:\Users\zhangxu\.codex\tools\hbuilderx-5.14.2026070214\HBuilderX\plugins\uniapp-cli\bin\uniapp-cli.js`
- Node.js for legacy CRMEB compilation: `v18.20.8`
- Node path: `C:\Users\zhangxu\.codex\tools\node-v18.20.8-win-x64\node.exe`

The HBuilderX installation was prepared from DCloud's official release source and is stored outside the repository. The portable Node.js runtime was prepared from Node.js official distribution and is also stored outside the repository.

## Why Node 18 Is Used

The current CRMEB uni-app project uses the old `node-sass` compiler path. HBuilderX 5.14's bundled Node is Node 22, whose ABI is not covered by the old `node-sass` binary package used by this project. Direct `cli.exe launch mp-weixin --compile true` therefore reports a missing `win32-x64-127` binding.

For this legacy project, the verified path is:

- use HBuilderX's official `uniapp-cli`;
- run it with portable Node.js 18;
- keep `manifest.json` on the current Sass configuration;
- do not upgrade Vue, uni-app, Webpack, Babel, or switch Sass engines as part of normal build verification.

## H5 Development / Preview Build

```powershell
$node = 'C:\Users\zhangxu\.codex\tools\node-v18.20.8-win-x64\node.exe'
$plugin = 'C:\Users\zhangxu\.codex\tools\hbuilderx-5.14.2026070214\HBuilderX\plugins\uniapp-cli'
$project = 'C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform\template\uni-app'

$env:NODE_ENV = 'development'
$env:UNI_PLATFORM = 'h5'
$env:UNI_INPUT_DIR = $project
$env:UNI_OUTPUT_DIR = Join-Path $project 'unpackage\dist\dev\h5'
$env:UNI_MINIMIZE = 'false'
Remove-Item Env:VUE_CLI_CONTEXT -ErrorAction SilentlyContinue

Push-Location $plugin
& $node --max-old-space-size=5120 --no-warnings (Join-Path $plugin 'bin\uniapp-cli.js')
Pop-Location
```

Verified result:

- build succeeded;
- output directory: `template/uni-app/unpackage/dist/dev/h5`;
- local URL: `http://127.0.0.1:8080/`;
- warnings were limited to existing Vue `v-for` key warnings.

## H5 Production Build

```powershell
$node = 'C:\Users\zhangxu\.codex\tools\node-v18.20.8-win-x64\node.exe'
$plugin = 'C:\Users\zhangxu\.codex\tools\hbuilderx-5.14.2026070214\HBuilderX\plugins\uniapp-cli'
$project = 'C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform\template\uni-app'

$env:NODE_ENV = 'production'
$env:UNI_PLATFORM = 'h5'
$env:UNI_INPUT_DIR = $project
$env:UNI_OUTPUT_DIR = Join-Path $project 'unpackage\dist\build\h5'
$env:UNI_MINIMIZE = 'true'
Remove-Item Env:VUE_CLI_CONTEXT -ErrorAction SilentlyContinue

Push-Location $plugin
& $node --max-old-space-size=5120 --no-warnings (Join-Path $plugin 'bin\uniapp-cli.js')
Pop-Location
```

Verified result:

- build succeeded;
- output directory: `template/uni-app/unpackage/dist/build/h5`;
- `index.html` exists;
- JS, CSS, and static assets were generated;
- measured output: 324 files, 9,790,085 bytes;
- warnings were limited to existing asset-size warnings.

## Local H5 Runtime Validation

Development server validation used:

```text
http://127.0.0.1:8080/
```

Production build validation used a local static server:

```powershell
cd "C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform\template\uni-app\unpackage\dist\build\h5"
python -m http.server 8091
```

Verified with Edge/Chromium:

- customer home opened and showed the CRMEB safe empty storefront state when no local backend was connected;
- user center opened in logged-out state;
- direct workbench access without business context returned to the customer side;
- role switch direct access redirected to login when unauthenticated;
- store switch direct access did not create a redirect loop;
- no JavaScript page error was observed.

Expected local-only resource notes:

- `/api/*` returns 404 when only the static H5 build is served without a CRMEB backend;
- `/statics/images/*` image paths are backend public assets and may 404 under the Python static server;
- these are not production deployment results and are not evidence of a connected backend.

## WeChat Mini Program Compile

Production compile command:

```powershell
$node = 'C:\Users\zhangxu\.codex\tools\node-v18.20.8-win-x64\node.exe'
$plugin = 'C:\Users\zhangxu\.codex\tools\hbuilderx-5.14.2026070214\HBuilderX\plugins\uniapp-cli'
$project = 'C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform\template\uni-app'

$env:NODE_ENV = 'production'
$env:UNI_PLATFORM = 'mp-weixin'
$env:UNI_INPUT_DIR = $project
$env:UNI_OUTPUT_DIR = Join-Path $project 'unpackage\dist\build\mp-weixin'
$env:UNI_MINIMIZE = 'true'
Remove-Item Env:VUE_CLI_CONTEXT -ErrorAction SilentlyContinue

Push-Location $plugin
& $node --max-old-space-size=5120 --no-warnings (Join-Path $plugin 'bin\uniapp-cli.js')
Pop-Location
```

Verified result:

- compile succeeded;
- output directory: `template/uni-app/unpackage/dist/build/mp-weixin`;
- measured output: 1121 files, 7,592,360 bytes;
- no upload was performed;
- no real AppID, private key, AppSecret, or WeChat developer tool login was used.

Known CLI limitation:

```powershell
& "C:\Users\zhangxu\.codex\tools\hbuilderx-5.14.2026070214\HBuilderX\cli.exe" launch mp-weixin --project "<project>" --compile true
```

This command still uses HBuilderX's bundled Node 22 and reports a missing legacy `node-sass` ABI 127 binding. Use the Node 18 + `uniapp-cli.js` path above for this CRMEB Vue2 project unless a separate frontend dependency upgrade is approved.

## Verification Commands

```powershell
cd "C:\Users\zhangxu\Desktop\御方通和\yufangcare-platform"

& "C:\Users\zhangxu\.codex\tools\node-v18.20.8-win-x64\node.exe" template/uni-app/tests/yfth_multi_role_shell_contract_check.js
git diff --check
```

For ESM syntax checks, use HBuilderX's Babel parser or the HBuilderX compiler. Plain `node --check` treats the files as CommonJS and misreports valid HBuilderX alias imports such as `@/utils/cache`.

## Do Not

- Do not commit HBuilderX, Node.js, DCloud cache, generated `unpackage` output, `node_modules`, logs, credentials, or local temporary files.
- Do not upgrade Vue, uni-app, Webpack, Babel, Sass, or CRMEB core dependencies just to make a different command work.
- Do not connect to production databases, copy production `.env`, use production AppID/AppSecret/private keys, upload to WeChat, or deploy servers during local build verification.
- Do not re-open the closed user-token to admin-token boundary in the multi-role shell.
