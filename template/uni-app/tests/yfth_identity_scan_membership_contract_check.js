const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const scan = fs.readFileSync(path.join(root, 'pages/yfth/referral/scan.vue'), 'utf8');
const service = fs.readFileSync(path.join(root, '../../crmeb/app/services/yfth/PermanentMembershipServices.php'), 'utf8');
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

assert(scan.indexOf('const identityToken = this.extractIdentityToken(value)') < scan.indexOf('const target = this.extractTarget(value)'), 'identity codes are dispatched before referral codes');
assert(scan.includes("['store_manager', 'store_staff']"), 'only store managers and staff can activate an identity code');
assert(scan.includes('loadYfthIdentities') && scan.includes('resolveYfthContext'), 'identity scans recover an authoritative store operator context from the server');
assert(scan.includes("left.role_code === 'store_manager'"), 'store operator recovery is not displaced by a higher-priority partner identity');
assert(scan.includes('resolveMembershipOperatorContext()') && scan.includes('confirmIdentityActivation(identityToken, context)'), 'identity activation uses the recovered operator context');
assert(!scan.includes("if (!this.isStoreMembershipOperator) {\n\t\t\t\t\treturn this.toast"), 'identity codes are not rejected from stale local customer context');
assert(scan.includes('确认该顾客属于当前门店并已完成线下购买'), 'membership activation requires explicit confirmation');
assert(scan.includes('activateYfthStorePermanentMembershipIdentity'), 'identity activation uses the existing permanent membership API');
assert(scan.includes("activationState = 'confirm'") && scan.includes("activationState = 'submitting'"), 'identity activation confirmation and progress are rendered in page');
assert(scan.includes("activationState = 'success'") && scan.includes('会员开通成功'), 'identity activation success is rendered in page');
assert(scan.includes("activationState = 'error'") && scan.includes('会员开通未完成'), 'identity activation errors are rendered in page');
assert(scan.includes("uni.switchTab({\n\t\t\t\turl: '/pages/user/index'"), 'successful identity activation returns to the user center');
assert(!scan.includes("uni.showModal({\n\t\t\t\ttitle: '会员开通成功'"), 'success feedback does not depend on a modal immediately after the native scanner closes');
assert(scan.includes('offline_membership_store_attribution_mismatch') && scan.includes('该用户未绑定当前门店'), 'cross-store rejection is explicit');
assert(scan.includes('permanent_membership_already_exists') && scan.includes('已经是永久会员'), 'existing membership result is explicit');
assert(scan.includes('该码为用户身份码，仅限当前门店的店长或店员核验'), 'ordinary users cannot execute membership activation');
assert(service.includes('assertAuthoritativeStore($uid, $storeId)'), 'backend verifies authoritative store ownership');
assert(service.includes("['store_manager', 'store_staff']"), 'backend enforces store operator roles');
assert(service.includes('activateOfflineEnrollment'), 'identity activation reuses the existing membership and reward workflow');

console.log('YFTH identity scan membership contract check passed.');
