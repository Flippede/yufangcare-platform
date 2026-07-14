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
const {
	h5FallbackData,
	isHtmlResponse,
	shouldUseH5DevFallback
} = require('./yfthH5Fallback.js');

function h5FetchRequest(url, method, data, header, noVerify, requestBase) {
	const requestData = data || {};
	let requestUrl = requestBase.replace(/\/$/, '') + '/api/' + url;
	const requestMethod = (method || 'GET').toUpperCase();
	const isQueryMethod = ['GET', 'HEAD', 'OPTIONS'].includes(requestMethod);
	if (isQueryMethod) {
		const query = Object.keys(requestData).filter((key) => requestData[key] !== undefined && requestData[key] !== null)
			.map((key) => encodeURIComponent(key) + '=' + encodeURIComponent(requestData[key])).join('&');
		if (query) requestUrl += (requestUrl.indexOf('?') === -1 ? '?' : '&') + query;
	}
	const options = {
		method: requestMethod,
		headers: header,
		credentials: 'same-origin'
	};
	if (!isQueryMethod) options.body = JSON.stringify(requestData);

	return window.fetch(requestUrl, options).then((response) => response.text().then((text) => {
		let responseData = text;
		try {
			responseData = JSON.parse(text);
		} catch (e) {}
		const res = { statusCode: response.status, data: responseData };
		if (shouldUseH5DevFallback({
			nodeEnv: process.env.NODE_ENV,
			location: window.location,
			requestBase,
			statusCode: res.statusCode,
			url,
			data: res.data
		})) {
			return { status: 200, msg: '', data: h5FallbackData(url) };
		}
		if (isHtmlResponse(res.data)) return Promise.reject(i18n.t(`绯荤粺閿欒`));
		if (res.statusCode && Number(res.statusCode) !== 200) {
			if (Number(res.statusCode) === 401) toLogin();
			return Promise.reject({
				status: res.statusCode,
				msg: res.data && (res.data.msg || res.data.message) || i18n.t(`璇锋眰澶辫触`)
			});
		}
		if (noVerify || (res.data && res.data.status == 200)) return res.data;
		if (res.data && [110002, 110003, 110004].indexOf(res.data.status) !== -1) {
			toLogin();
			return Promise.reject(res.data);
		}
		if (res.data && res.data.status == 100103) {
			uni.showModal({
				title: i18n.t(`鎻愮ず`),
				content: res.data.msg,
				showCancel: false,
				confirmText: i18n.t(`鎴戠煡閬撲簡`)
			});
		}
		return Promise.reject(res.data && res.data.msg || i18n.t(`绯荤粺閿欒`));
	}));
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
	// #ifdef H5
	if (typeof window !== 'undefined' && typeof window.fetch === 'function') {
		if (uni.getStorageSync('locale')) header['Cb-lang'] = uni.getStorageSync('locale');
		return h5FetchRequest(url, method, data, header, noVerify, Url).catch(() => Promise.reject(i18n.t(`璇锋眰澶辫触`)));
	}
	// #endif

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
				if (shouldUseH5DevFallback({
					nodeEnv: process.env.NODE_ENV,
					location: typeof window !== 'undefined' ? window.location : null,
					requestBase: Url,
					statusCode: res.statusCode,
					url,
					data: res.data
				})) {
					reslove({
						status: 200,
						msg: '',
						data: h5FallbackData(url)
					}, res);
					return;
				}
				if (isHtmlResponse(res.data)) {
					reject(i18n.t(`系统错误`));
					return;
				}
				// #endif
				if (res.statusCode && Number(res.statusCode) !== 200) {
					if (Number(res.statusCode) === 401) {
						toLogin();
					}
					reject({
						status: res.statusCode,
						msg: res.data && (res.data.msg || res.data.message) || i18n.t(`请求失败`)
					});
					return;
				}
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
