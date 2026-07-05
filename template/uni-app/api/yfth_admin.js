import { HTTP_REQUEST_URL, HEADER, TOKENNAME, TIMEOUT } from '@/config/app';

function adminRequest(url, method, data) {
	const header = Object.assign({}, HEADER);
	const token = uni.getStorageSync('admin_token');
	if (!token) {
		return Promise.reject('admin_token_required');
	}
	header[TOKENNAME] = 'Bearer ' + token;
	return new Promise((resolve, reject) => {
		uni.request({
			url: HTTP_REQUEST_URL + '/adminapi/' + url,
			method: method || 'GET',
			header,
			data: data || {},
			timeout: TIMEOUT,
			success: (res) => {
				if (res.data && res.data.status == 200) {
					resolve(res.data);
				} else {
					reject((res.data && (res.data.msg || res.data.message)) || 'request_failed');
				}
			},
			fail: () => reject('request_failed')
		});
	});
}

export function precheckYfthServiceWriteoff(data) {
	return adminRequest('yfth/service_appointment/writeoff/precheck', 'POST', data || {});
}

export function writeoffYfthServiceByToken(qrToken, data) {
	return adminRequest('yfth/service_appointment/writeoff/token', 'POST', Object.assign({ qr_token: qrToken }, data || {}));
}

export function writeoffYfthServiceByDigital(digitalCode, data) {
	return adminRequest('yfth/service_appointment/writeoff/digital', 'POST', Object.assign({ digital_code: digitalCode }, data || {}));
}
