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

function cacheTags() {
  try {
    const value = JSON.parse(window.localStorage.getItem('UNI-APP-CRMEB:TAG') || '[]');
    return Array.isArray(value) ? value : [];
  } catch (_) {
    return [];
  }
}

export function saveLoginToken(data) {
  const token = String(data?.token || '');
  if (!token) throw new Error('登录凭证无效');
  const expiresAt = Number(data?.expires_time || 0);
  window.localStorage.setItem(TOKEN_KEY, JSON.stringify(token));
  const tags = cacheTags().filter((item) => item?.key !== TOKEN_KEY);
  tags.push({ key: TOKEN_KEY, expire: expiresAt > 0 ? expiresAt : 0 });
  window.localStorage.setItem('UNI-APP-CRMEB:TAG', JSON.stringify(tags));
}

export function clearLoginToken() {
  window.localStorage.removeItem(TOKEN_KEY);
  const tags = cacheTags().filter((item) => item?.key !== TOKEN_KEY);
  window.localStorage.setItem('UNI-APP-CRMEB:TAG', JSON.stringify(tags));
}

export const loginApi = {
  account(account, password) {
    return request('login', {
      method: 'POST',
      body: JSON.stringify({ account, password, spread: 0, agent_id: 0 })
    });
  },
  wechatConfig(url) {
    return request(`wechat/config?url=${encodeURIComponent(url)}`);
  },
  wechatCallback(code) {
    return request(`v2/wechat/auth_login?code=${encodeURIComponent(code)}&spread=&agent_id=0`);
  }
};

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
  return `${window.location.origin}/join/#/login`;
}
