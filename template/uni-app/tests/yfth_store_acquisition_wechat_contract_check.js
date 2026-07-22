const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

function requireText(source, needle, label) {
  if (!source.includes(needle)) throw new Error(`Missing ${label}: ${needle}`);
}

const login = read('libs/login.js');
const wechat = read('libs/wechat.js');
const accept = read('pages/yfth/store_acquisition/accept.vue');

[
  ['hasPendingStoreAcquisition()', 'pending acquisition detection'],
  ['location.replace(target)', 'full-document WeChat login navigation'],
  ['?yfth_flow=store_acquisition', 'explicit acquisition login flow'],
].forEach(([needle, label]) => requireText(login, needle, label));

[
  ["'yfth_store_acquisition'", 'compact OAuth state'],
  ['encodeURIComponent(state)', 'encoded OAuth state'],
  ['state=${encodedState}', 'encoded state in OAuth URL'],
].forEach(([needle, label]) => requireText(wechat, needle, label));

[
  ['resolving: false', 'resolve single-flight state'],
  ['if (this.resolving || this.submitting || this.redirecting', 'duplicate lifecycle guard'],
  ['finally(() => { this.resolving = false; })', 'resolve guard cleanup'],
].forEach(([needle, label]) => requireText(accept, needle, label));

console.log('YFTH store acquisition WeChat contract check passed.');
