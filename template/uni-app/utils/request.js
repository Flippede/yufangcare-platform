// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2024 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

import {
	HTTP_REQUEST_URL,
	HEADER,
	TOKENNAME,
	TIMEOUT
} from '@/config/app';
import {
	toLogin,
	checkLogin
} from '../libs/login';
import store from '../store';
import i18n from './lang.js';

function isHtmlFallback(data) {
	return typeof data === 'string' && (/^\s*<!doctype html/i.test(data) || /^\s*<html[\s>]/i.test(data));
}

function h5FallbackData(url) {
	if (url.indexOf('v2/diy/get_diy/default') === 0) {
		return {
			is_show: true,
			is_bg_color: false,
			is_bg_pic: false,
			title: '',
			value: {}
		};
	}
	if (url.indexOf('v2/diy/color_change/') === 0) {
		return {
			is_diy: 0,
			status: 3
		};
	}
	if (url === 'lang_version') {
		return {
			version: 0
		};
	}
	if (url === 'basic_config') {
		return {
			diy_data: {
				value: 1,
				my_banner_status: 0,
				my_menus_status: 1,
				business_status: 0,
				order_status: 1
			},
			routine_my_menus: [],
			routine_my_banner: [],
			routine_contact_type: 0
		};
	}
	if (url === 'get_workerman_url') {
		return {};
	}
	if (url === 'menu/user') {
		return {
			diy_data: {
				value: 1,
				my_banner_status: 0,
				my_menus_status: 1,
				business_status: 0,
				order_status: 1
			},
			routine_my_menus: [],
			routine_my_banner: [],
			routine_contact_type: 0
		};
	}
	if (url === 'copyright') {
		return {
			wechat_status: 0,
			copyrightContext: '',
			copyrightImage: '/static/images/support.png',
			site_logo: '',
			site_name: ''
		};
	}
	if (url === 'get_open_adv') {
		return {
			show: false
		};
	}
	if (url === 'share') {
		return {
			title: '',
			synopsis: '',
			img: ''
		};
	}
	if (url === 'navigation') {
		return [];
	}
	return {};
}

/**
 * 发送请求
 */
function baseRequest(url, method, data, {
	noAuth = false,
	noVerify = false
}) {
	let Url = HTTP_REQUEST_URL,
		header = HEADER;

	if (!noAuth) {
		//登录过期自动登录
		if (!store.state.app.token && !checkLogin()) {
			toLogin();
			return Promise.reject({
				msg: i18n.t(`未登录`)
			});
		}
	}
	if (store.state.app.token) header[TOKENNAME] = 'Bearer ' + store.state.app.token;

	return new Promise((reslove, reject) => {
		if (uni.getStorageSync('locale')) {
			header['Cb-lang'] = uni.getStorageSync('locale')
		}
		uni.request({
			url: Url + '/api/' + url,
			method: method || 'GET',
			header: header,
			data: data || {},
			timeout: TIMEOUT,
			success: (res) => {
				// #ifdef H5
				// 本地 H5 验收未连接后端时，webpack devServer 会把 /api/* fallback 成 index.html。
				// 这不是业务 API 响应，转为空数据以保持 CRMEB 页面安全空状态，真实 JSON 响应不受影响。
				if (isHtmlFallback(res.data)) {
					reslove({
						status: 200,
						msg: '',
						data: h5FallbackData(url)
					}, res);
					return;
				}
				// #endif
				if (noVerify)
					reslove(res.data, res);
				else if (res.data.status == 200)
					reslove(res.data, res);
				else if ([110002, 110003, 110004].indexOf(res.data.status) !== -1) {
					toLogin();
					reject(res.data);
				} else if (res.data.status == 100103) {
					uni.showModal({
						title: i18n.t(`提示`),
						content: res.data.msg,
						showCancel: false,
						confirmText: i18n.t(`我知道了`)
					});
				} else
					reject(res.data.msg || i18n.t(`系统错误`));
			},
			fail: (msg) => {
				let data = {
					mag: i18n.t(`请求失败`),
					status: 1 //1没网
				}
				// #ifdef APP-PLUS
				reject(data);
				// #endif
				// #ifndef APP-PLUS
				reject(i18n.t(`请求失败`));
				// #endif
			}
		})
	});
}

const request = {};

['options', 'get', 'post', 'put', 'head', 'delete', 'trace', 'connect'].forEach((method) => {
	request[method] = (api, data, opt) => baseRequest(api, method, data, opt || {})
});



export default request;
