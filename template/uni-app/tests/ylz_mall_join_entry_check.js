const assert = require('assert');
const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '../pages/user/index.vue'), 'utf8');
const start = source.indexOf('goYfthFranchiseApplications() {');
const end = source.indexOf('goYfthWithdrawalBeneficiary()', start);
assert(start >= 0 && end > start, 'Join entry method must exist');
const method = source.slice(start, end).trim().replace(/,$/, '');

function compile(platform) {
  let enabled = true;
  const code = method.split('\n').filter((line) => {
    if (line.includes('// #ifdef H5')) { enabled = platform === 'h5'; return false; }
    if (line.includes('// #ifndef H5')) { enabled = platform !== 'h5'; return false; }
    if (line.includes('// #endif')) { enabled = true; return false; }
    return enabled;
  }).join('\n');
  return new Function('window', 'uni', 'HTTP_REQUEST_URL', `return ({${code}}).goYfthFranchiseApplications;`);
}

for (const isLogin of [false, true]) {
  const context = { isLogin, role: 'store_manager', storeId: 123 };
  const before = JSON.stringify(context);
  const calls = [];
  compile('h5')({ location: { assign: (url) => calls.push(url) } }, {}, '').call(context);
  assert.deepStrictEqual(calls, ['/join/#/home']);
  assert.strictEqual(JSON.stringify(context), before, 'Entry must not mutate login or store context');
}

for (const platform of ['mp-weixin', 'app-plus']) {
  const calls = [];
  compile(platform)({}, { navigateTo: (options) => calls.push(options.url) }, 'https://yfth.top/').call({});
  assert.deepStrictEqual(calls, ['/pages/annex/web_view/index?url=https://yfth.top/join/']);
  let warned = false;
  compile(platform)({}, { showToast: () => { warned = true; } }, '').call({});
  assert(warned, 'Missing portal host must show an error, not navigate to the old application');
}

assert(!method.includes('toLogin'), 'Public join home must not force mall login');
assert(!method.includes("url: '/pages/yfth/franchise/index'"), 'Old entry must not remain');
console.log('YLZ mall join entry: H5, mini-program, App and context preservation checks passed.');
