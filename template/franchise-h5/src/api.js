const API_BASE = `${window.location.origin}/api`;
const TOKEN_KEY = 'LOGIN_STATUS_TOKEN';

function tokenValue() {
  const raw = window.localStorage.getItem(TOKEN_KEY) || '';
  if (!raw) return '';
  try {
    const parsed = JSON.parse(raw);
    return typeof parsed === 'string' ? parsed : String(parsed?.data || parsed?.value || '');
  } catch (_) {
    return raw;
  }
}

async function request(path, options = {}) {
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  const token = tokenValue();
  if (token) headers['Authori-zation'] = `Bearer ${token}`;
  const response = await fetch(`${API_BASE}/${path}`, { credentials: 'same-origin', ...options, headers });
  const text = await response.text();
  let payload;
  try { payload = JSON.parse(text); } catch (_) { throw new Error('服务器返回了无法识别的数据'); }
  if (!response.ok || Number(payload.status) !== 200) {
    const error = new Error(payload.msg || payload.message || '请求失败');
    error.status = response.status;
    throw error;
  }
  return payload.data;
}

export const franchiseApi = {
  myApplications() {
    return request('yfth/franchise/application/my');
  },
  detail(id) {
    return request(`yfth/franchise/application/${id}`);
  },
  draft() {
    return request('yfth/franchise/portal/draft');
  },
  saveDraft(data) {
    return request('yfth/franchise/portal/draft', { method: 'POST', body: JSON.stringify(data) });
  },
  submitDraft(data) {
    return request('yfth/franchise/portal/submit', { method: 'POST', body: JSON.stringify(data) });
  }
};

export function hasLoginToken() {
  return Boolean(tokenValue());
}

export function loginUrl() {
  const back = encodeURIComponent(`${window.location.origin}/join/#/workbench`);
  return `/pages/users/login/index?back_url=${back}`;
}
