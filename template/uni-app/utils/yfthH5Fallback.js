const H5_DEV_FALLBACK_APIS = [
	'v2/diy/get_diy/default',
	'v2/diy/color_change/',
	'lang_version',
	'basic_config',
	'get_workerman_url',
	'menu/user',
	'copyright',
	'get_open_adv',
	'share',
	'navigation',
	'wechat/get_logo',
	'ajcaptcha'
];

function isHtmlResponse(data) {
	return typeof data === 'string' && (/^\s*<!doctype html/i.test(data) || /^\s*<html[\s>]/i.test(data));
}

function isWhitelistedFallbackApi(url) {
	return H5_DEV_FALLBACK_APIS.some((item) => {
		if (item.charAt(item.length - 1) === '/') {
			return url.indexOf(item) === 0;
		}
		return url === item || url.indexOf(item + '?') === 0;
	});
}

function isLocalDevServer(locationLike, requestBase) {
	if (!locationLike || !requestBase) {
		return false;
	}
	const host = locationLike.hostname;
	const origin = locationLike.protocol + '//' + locationLike.host;
	return origin === requestBase && (host === 'localhost' || host === '127.0.0.1' || host === '::1');
}

function shouldUseH5DevFallback(options) {
	const opt = options || {};
	return opt.nodeEnv === 'development'
		&& Number(opt.statusCode) === 200
		&& isLocalDevServer(opt.location, opt.requestBase)
		&& isWhitelistedFallbackApi(opt.url || '')
		&& isHtmlResponse(opt.data);
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
	if (url === 'basic_config' || url === 'menu/user') {
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
	if (url === 'wechat/get_logo') {
		return {
			logo_url: ''
		};
	}
	if (url === 'ajcaptcha' || url.indexOf('ajcaptcha?') === 0) {
		return {
			originalImageBase64: '',
			token: '',
			secretKey: '',
			wordList: []
		};
	}
	return {};
}

module.exports = {
	H5_DEV_FALLBACK_APIS,
	h5FallbackData,
	isHtmlResponse,
	isLocalDevServer,
	isWhitelistedFallbackApi,
	shouldUseH5DevFallback
};
