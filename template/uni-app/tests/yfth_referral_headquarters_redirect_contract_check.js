const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

const navigation = read('libs/yfthReferralNavigation.js');
const accept = read('pages/yfth/referral/accept.vue');
const code = read('pages/yfth/referral/code.vue');
const scan = read('pages/yfth/referral/scan.vue');
const membership = read('pages/yfth/package_membership/index.vue');
const pages = read('pages.json');
const backend = read('../../crmeb/app/services/yfth/PackageMembershipReferralServices.php');
const request = read('utils/request.js');

assert(navigation.includes("YFTH_HEADQUARTERS_HOME_ROUTE = '/pages/index/index'"), 'canonical headquarters homepage route is fixed');
assert(navigation.includes('uni.reLaunch'), 'headquarters homepage uses stable root navigation');
assert(accept.includes('进入商城首页') && accept.includes('当前为普通顾客'), 'success page explains customer status and home action');
assert(accept.includes('scheduleHomeRedirect') && accept.includes('2500'), 'success page auto redirects after a short result display');
assert(!accept.includes('查看套餐会员') && !accept.includes("'/pages/yfth/package_membership/index'"), 'accept page cannot redirect to membership');
assert(code.includes('yfthReferralAcceptRoute') && !code.includes("path: this.inviteLink || '/pages/yfth/package_membership/index'"), 'generated QR and shares use referral accept route');
assert(scan.includes('legacyReferral') && scan.includes('yfthReferralAcceptRoute'), 'scanner normalizes current and legacy invite links');
assert(membership.includes('forwardToReferralAccept') && !membership.includes('acceptYfthDirectReferralInvite'), 'manual and legacy membership invite entries use canonical accept page');
assert(request.includes('.catch((error) => Promise.reject(error || i18n.t(`请求失败`)))'), 'H5 preserves referral business error codes for safe user-facing messages');
assert(pages.includes('接受推荐邀请') && !pages.includes('接受会员邀请'), 'page title does not imply membership grant');
for (const field of ['open_headquarters_home', "'/pages/index/index'", "'customer_status' => 'non_member'", "'is_permanent_member' => false"]) {
  assert(backend.includes(field), `backend acceptance contract contains ${field}`);
}

console.log('YFTH referral headquarters redirect contract check passed.');
