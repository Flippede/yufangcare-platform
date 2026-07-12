const assert = require('assert');
const admin = require('../../admin/src/pages/yfth/hqAuthority/requestGeneration.js');
const mobile = require('../libs/yfthRequestGeneration.js');

async function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((ok, fail) => { resolve = ok; reject = fail; });
  return { promise, resolve, reject };
}

async function staleResponseScenario(factory, label) {
  const gate = factory();
  const slow = await deferred();
  const fast = await deferred();
  let state = [];
  const first = gate.next('list', 'store:A');
  const firstRun = slow.promise.then((value) => {
    if (gate.isCurrent(first, 'store:A')) state = value;
  });
  gate.invalidateAll();
  state = [];
  const second = gate.next('list', 'store:B');
  const secondRun = fast.promise.then((value) => {
    if (gate.isCurrent(second, 'store:B')) state = value;
  });
  fast.resolve(['B']);
  await secondRun;
  slow.resolve(['A']);
  await firstRun;
  assert.deepStrictEqual(state, ['B'], label + ': stale store/tab response must be discarded');
}

async function permissionAndDestroyScenario(factory, label) {
  const gate = factory();
  let list = ['old'];
  let detail = { id: 1 };
  let events = [{ id: 1 }];
  const clear = () => { list = []; detail = null; events = []; };
  const audit = gate.next('events', 'audit:on');
  gate.invalidate('events');
  clear();
  assert.strictEqual(gate.isCurrent(audit, 'audit:on'), false, label + ': revoked audit request invalidated');
  assert.deepStrictEqual([list, detail, events], [[], null, []], label + ': permission failure clears sensitive state');
  const pending = gate.next('detail', 'uid:1');
  gate.destroy();
  assert.strictEqual(gate.isCurrent(pending, 'uid:1'), false, label + ': destroyed page rejects late response');
}

(async () => {
  await staleResponseScenario(admin.createRequestGeneration, 'admin attribution/referral generation');
  await permissionAndDestroyScenario(admin.createRequestGeneration, 'admin permission cleanup');
  await staleResponseScenario(mobile.createRequestGeneration, 'store switch generation');
  await permissionAndDestroyScenario(mobile.createRequestGeneration, 'store role/disabled cleanup');
  await staleResponseScenario(mobile.createRequestGeneration, 'customer uid switch generation');
  console.log('YFTH Stage 1B frontend stale-state and request-order checks passed.');
})().catch((error) => {
  console.error(error.stack || error);
  process.exit(1);
});
