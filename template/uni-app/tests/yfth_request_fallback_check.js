const assert = require('assert');
const {
	h5FallbackData,
	isHtmlResponse,
	isWhitelistedFallbackApi,
	shouldUseH5DevFallback
} = require('../utils/yfthH5Fallback.js');

const html = '<!DOCTYPE html><html><body>error</body></html>';
const location = {
	protocol: 'http:',
	host: '127.0.0.1:8080',
	hostname: '127.0.0.1'
};

function decision(options) {
	return shouldUseH5DevFallback(Object.assign({
		nodeEnv: 'production',
		statusCode: 200,
		requestBase: 'http://127.0.0.1:8080',
		location,
		url: 'basic_config',
		data: html
	}, options || {}));
}

assert.strictEqual(isHtmlResponse(html), true, 'html response must be detected');
assert.strictEqual(isWhitelistedFallbackApi('basic_config'), true, 'basic_config is local dev fallback whitelist');
assert.strictEqual(isWhitelistedFallbackApi('wechat/get_logo'), true, 'login logo is local dev fallback whitelist');
assert.strictEqual(isWhitelistedFallbackApi('ajcaptcha?captchaType=clickWord'), true, 'captcha bootstrap is local dev fallback whitelist');
assert.strictEqual(isWhitelistedFallbackApi('yfth/service/appointment/my'), false, 'business APIs must not be fallback whitelisted');

assert.strictEqual(decision({ nodeEnv: 'production', statusCode: 401 }), false, 'production 401 html must not become success');
assert.strictEqual(decision({ nodeEnv: 'production', statusCode: 403 }), false, 'production 403 html must not become success');
assert.strictEqual(decision({ nodeEnv: 'production', statusCode: 500 }), false, 'production 500 html must not become success');
assert.strictEqual(decision({ nodeEnv: 'production', statusCode: 200, url: 'yfth/identities' }), false, 'production 200 html non-whitelist must not become success');
assert.strictEqual(decision({ nodeEnv: 'production', statusCode: 200, data: { status: 200, data: {} } }), false, 'production json is handled by normal request flow');
assert.strictEqual(decision({ nodeEnv: 'development', statusCode: 200, url: 'basic_config' }), true, 'development local whitelist html may become safe fallback');
assert.strictEqual(decision({ nodeEnv: 'development', statusCode: 200, url: 'wechat/get_logo' }), true, 'development local login logo html may become safe fallback');
assert.strictEqual(decision({ nodeEnv: 'development', statusCode: 200, url: 'ajcaptcha?captchaType=clickWord' }), true, 'development local captcha html may become safe fallback');
assert.strictEqual(decision({ nodeEnv: 'development', statusCode: 500, url: 'basic_config' }), false, 'development 500 html must not become fallback success');
assert.strictEqual(decision({ nodeEnv: 'development', statusCode: 200, requestBase: 'https://api.example.com' }), false, 'remote development html must not become fallback success');

const basicConfig = h5FallbackData('basic_config');
assert.strictEqual(basicConfig.diy_data.value, 1, 'basic_config fallback must keep user center safe defaults');
assert.deepStrictEqual(h5FallbackData('navigation'), [], 'navigation fallback remains explicit empty config');
assert.strictEqual(h5FallbackData('wechat/get_logo').logo_url, '', 'login logo fallback must be empty');
assert.deepStrictEqual(h5FallbackData('ajcaptcha?captchaType=clickWord').wordList, [], 'captcha fallback must not create a real captcha challenge');

console.log('YFTH request fallback check passed.');
