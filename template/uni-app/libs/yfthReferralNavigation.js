export const YFTH_HEADQUARTERS_HOME_ROUTE = '/pages/index/index';

export function yfthReferralAcceptRoute(token) {
	return `/pages/yfth/referral/accept?invite_token=${String(token || '').trim().toLowerCase()}`;
}

export function goYfthHeadquartersHome() {
	uni.reLaunch({ url: YFTH_HEADQUARTERS_HOME_ROUTE });
}
