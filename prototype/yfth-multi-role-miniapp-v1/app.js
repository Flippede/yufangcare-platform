const demoData = {
  roles: [
    { key: 'customer', label: '顾客', nav: ['首页', '康养', '商城', '合作中心', '我的'] },
    { key: 'franchisee', label: '加盟商', nav: ['工作台', '门店', '采购', '客户', '我的'] },
    { key: 'manager', label: '店长', nav: ['工作台', '客户', '预约', '核销', '我的'] },
    { key: 'staff', label: '店员', nav: ['工作台', '客户', '核销', '订单', '我的'] },
    { key: 'mentor', label: '服务导师', nav: ['工作台', '线索', '活动', '资料', '我的'] }
  ],
  subjects: [
    { id: 'all', name: '御方通和总部演示主体' },
    { id: 'zz-franchise', name: '郑州康养加盟演示主体' }
  ],
  stores: [
    { id: 'all', name: '全部门店', city: '全部', phone: '演示电话', address: '演示数据汇总视图', hours: '09:00-20:00' },
    { id: 'jinshui', name: '郑州金水店', city: '郑州', phone: '演示电话 0001', address: '郑州市金水区康养路 18 号', hours: '09:00-20:00' },
    { id: 'zhongyuan', name: '郑州中原店', city: '郑州', phone: '演示电话 0002', address: '郑州市中原区桐柏路 66 号', hours: '09:30-19:30' },
    { id: 'jianxi', name: '洛阳涧西店', city: '洛阳', phone: '演示电话 0003', address: '洛阳市涧西区牡丹路 9 号', hours: '10:00-19:00' }
  ],
  services: [
    { id: 'tuina', name: '经络调理', duration: '60分钟', benefit: '5980服务权益', desc: '肩颈腰背舒缓，适合家庭康养体验。' },
    { id: 'moxa', name: '温灸养护', duration: '45分钟', benefit: '服务类权益', desc: '温养脾胃与睡眠调理，需提前预约。' },
    { id: 'assessment', name: '康养评估', duration: '30分钟', benefit: '首诊评估', desc: '服务导师辅助建档，形成后续护理建议。' }
  ],
  products: [
    '草本足浴包', '艾草温灸贴', '家庭理疗垫', '康养茶饮礼盒', '肩颈热敷包'
  ],
  mallCategories: [
    { name: '调养项目', icon: '调', action: 'openView', value: 'wellnessFlow' },
    { name: '套餐', icon: '套', action: 'chooseService', value: 'tuina' },
    { name: '同源产品区', icon: '源', action: 'setTab', value: '商城' },
    { name: '中药日化', icon: '日', action: 'setTab', value: '商城' },
    { name: '化产品区', icon: '护', action: 'setTab', value: '商城' },
    { name: '食硒厨房', icon: '硒', action: 'setTab', value: '商城' },
    { name: '富硒厨房', icon: '厨', action: 'setTab', value: '商城' },
    { name: '产品区', icon: '品', action: 'setTab', value: '商城' },
    { name: '食疗药膳', icon: '膳', action: 'setTab', value: '商城' },
    { name: '营养医学', icon: '养', action: 'setTab', value: '商城' }
  ],
  storefrontSections: [
    {
      title: '调养项目套餐',
      subtitle: '到店服务 · 预约体验',
      action: 'openView',
      value: 'wellnessFlow',
      items: [
        { name: '经络调理套餐', desc: '肩颈腰背舒缓', price: '服务权益', tone: 'service' },
        { name: '温灸养护套餐', desc: '温养睡眠调理', price: '45分钟', tone: 'moxa' }
      ]
    },
    {
      title: '食药同源产品区',
      subtitle: '家庭日常调理',
      action: 'setTab',
      value: '商城',
      items: [
        { name: '康养茶饮礼盒', desc: '草本轻养系列', price: '演示价 ¥168', tone: 'tea' },
        { name: '草本足浴包', desc: '睡前泡脚养护', price: '演示价 ¥69', tone: 'herb' }
      ]
    },
    {
      title: '中药日化产品区',
      subtitle: '外用护理用品',
      action: 'setTab',
      value: '商城',
      items: [
        { name: '艾草温灸贴', desc: '居家温热护理', price: '演示价 ¥89', tone: 'moxa' },
        { name: '肩颈热敷包', desc: '久坐放松随身用', price: '演示价 ¥129', tone: 'wood' }
      ]
    },
    {
      title: '富硒厨房产品区',
      subtitle: '餐桌健康搭配',
      action: 'setTab',
      value: '商城',
      items: [
        { name: '富硒杂粮礼盒', desc: '早餐搭配演示', price: '规划中', tone: 'grain' },
        { name: '食硒厨房组合', desc: '家庭厨房场景', price: '规划中', tone: 'kitchen' }
      ]
    },
    {
      title: '食疗药膳产品区',
      subtitle: '四季食养灵感',
      action: 'setTab',
      value: '商城',
      items: [
        { name: '四季药膳包', desc: '煲汤炖煮场景', price: '规划中', tone: 'soup' },
        { name: '轻养餐食方案', desc: '营养搭配展示', price: '规划中', tone: 'meal' }
      ]
    },
    {
      title: '营养医学产品区',
      subtitle: '评估后推荐',
      action: 'setTab',
      value: '商城',
      items: [
        { name: '家庭营养评估', desc: '服务导师建议', price: '演示服务', tone: 'assessment' },
        { name: '康养档案随访', desc: '长期跟踪规划', price: '规划中', tone: 'record' }
      ]
    }
  ],
  customers: ['赵女士', '王先生', '刘阿姨', '陈先生', '李女士', '周先生', '孙女士'],
  appointments: [
    { id: 'A001', customer: '赵女士', service: '经络调理', store: '郑州金水店', time: '今天 14:00', status: '待确认' },
    { id: 'A002', customer: '王先生', service: '温灸养护', store: '郑州金水店', time: '今天 16:00', status: '已确认' },
    { id: 'A003', customer: '刘阿姨', service: '康养评估', store: '洛阳涧西店', time: '明天 10:30', status: '已确认' }
  ],
  writeoffs: [
    { customer: '王先生', service: '温灸养护', store: '郑州金水店', time: '今天 11:20' },
    { customer: '陈先生', service: '经络调理', store: '郑州中原店', time: '昨天 15:40' }
  ],
  purchases: [
    { name: '草本足浴包补货', qty: 20, status: '演示待提交' },
    { name: '艾草温灸贴补货', qty: 12, status: '演示物流中' }
  ]
};

const defaultState = {
  role: 'customer',
  subject: 'zz-franchise',
  store: 'jinshui',
  tab: '首页',
  view: 'home',
  selectedService: 'tuina',
  selectedDate: '明天',
  selectedSlot: '10:00',
  appointmentConfirmed: false,
  managerConfirmed: false,
  writeoffPrechecked: false,
  writeoffDone: false,
  leadFollowed: false
};

let state = { ...defaultState };

const view = document.getElementById('appView');
const pageTitle = document.getElementById('pageTitle');
const appKicker = document.getElementById('appKicker');
const notice = document.getElementById('notice');
const toast = document.getElementById('toast');

function role() {
  return demoData.roles.find(item => item.key === state.role);
}

function currentStore() {
  return demoData.stores.find(item => item.id === state.store) || demoData.stores[1];
}

function currentService() {
  return demoData.services.find(item => item.id === state.selectedService) || demoData.services[0];
}

function init() {
  fillSelect('roleSelect', demoData.roles.map(item => [item.key, item.label]), state.role);
  fillSelect('subjectSelect', demoData.subjects.map(item => [item.id, item.name]), state.subject);
  fillSelect('storeSelect', demoData.stores.map(item => [item.id, item.name]), state.store);
  document.getElementById('roleSelect').addEventListener('change', event => setRole(event.target.value));
  document.getElementById('subjectSelect').addEventListener('change', event => updateState({ subject: event.target.value }, '经营主体已切换'));
  document.getElementById('storeSelect').addEventListener('change', event => updateState({ store: event.target.value }, '门店已切换'));
  document.body.addEventListener('click', handleClick);
  render();
}

function fillSelect(id, options, value) {
  const select = document.getElementById(id);
  select.innerHTML = options.map(([key, label]) => `<option value="${key}">${label}</option>`).join('');
  select.value = value;
}

function setRole(nextRole) {
  const next = demoData.roles.find(item => item.key === nextRole);
  state.role = nextRole;
  state.tab = next.nav[0];
  state.view = 'home';
  showToast(`已切换为${next.label}`);
  syncControls();
  render();
}

function updateState(patch, message) {
  state = { ...state, ...patch };
  if (message) showToast(message);
  syncControls();
  render();
}

function syncControls() {
  document.getElementById('roleSelect').value = state.role;
  document.getElementById('subjectSelect').value = state.subject;
  document.getElementById('storeSelect').value = state.store;
}

function handleClick(event) {
  const target = event.target.closest('[data-action]');
  if (!target) {
    if (event.target.closest('button')) {
      showToast('这是演示入口，真实功能规划中');
    }
    return;
  }
  const action = target.dataset.action;
  const value = target.dataset.value;
  actions[action]?.(value, target);
}

const actions = {
  resetDemo() {
    state = { ...defaultState };
    syncControls();
    showToast('演示数据已重置');
    render();
  },
  goHome() {
    const current = role();
    updateState({ tab: current.nav[0], view: 'home' }, '已返回身份首页');
  },
  showIdentitySheet() {
    updateState({ view: 'identity' });
  },
  setRole(value) {
    setRole(value);
  },
  setTab(value) {
    updateState({ tab: value, view: 'home' });
  },
  openView(value) {
    updateState({ view: value });
  },
  chooseStore(value) {
    updateState({ store: value }, '当前门店已更新');
  },
  chooseService(value) {
    updateState({ selectedService: value, view: 'serviceDetail' });
  },
  chooseDate(value) {
    updateState({ selectedDate: value });
  },
  chooseSlot(value) {
    updateState({ selectedSlot: value });
  },
  confirmAppointment() {
    updateState({ appointmentConfirmed: true, view: 'appointmentSuccess' }, '预约已生成演示记录');
  },
  managerConfirm() {
    updateState({ managerConfirmed: true, view: 'managerAppointmentDetail' }, '店长已确认预约');
  },
  precheckWriteoff() {
    updateState({ writeoffPrechecked: true, view: 'writeoffPrecheck' }, '核销预检通过');
  },
  finishWriteoff() {
    updateState({ writeoffDone: true, view: 'writeoffSuccess' }, '演示核销成功');
  },
  addFollow() {
    updateState({ leadFollowed: true, view: 'leadDetail' }, '已添加跟进记录');
  }
};

function render() {
  const currentRole = role();
  const miniapp = document.querySelector('.miniapp');
  miniapp.classList.toggle('customer-mode', state.role === 'customer');
  appKicker.textContent = `${currentRole.label} · ${currentStore().name}`;
  pageTitle.textContent = getTitle();
  const isCustomerStorefront = state.role === 'customer' && state.view === 'home' && state.tab === '首页';
  notice.hidden = isCustomerStorefront;
  notice.textContent = '当前为本地交互 Demo，所有内容均为演示数据，不连接真实接口。';
  renderBottomNav(currentRole.nav);
  view.innerHTML = renderView();
  view.scrollTop = 0;
}

function getTitle() {
  if (state.view !== 'home') {
    const names = {
      identity: '身份切换',
      publicStore: '门店对外商店页',
      wellnessFlow: '康养预约',
      serviceDetail: '服务详情',
      appointmentSuccess: '预约成功',
      customerAppointments: '我的预约',
      dynamicCode: '动态核销码',
      managerAppointmentDetail: '预约详情',
      managerCustomer: '客户详情',
      writeoffInput: '数字码核销',
      writeoffPrecheck: '核销预检',
      writeoffSuccess: '核销成功',
      franchiseStoreDetail: '门店经营视图',
      purchaseFlow: '采购流程',
      leadDetail: '线索详情'
    };
    return names[state.view] || state.tab;
  }
  return state.tab;
}

function renderBottomNav(nav) {
  const grid = `repeat(${nav.length}, 1fr)`;
  const html = nav.map(item => {
    const icon = item.slice(0, 1);
    const active = state.tab === item && state.view === 'home' ? ' active' : '';
    return `<button type="button" class="nav-item${active}" data-action="setTab" data-value="${item}">
      <span class="nav-icon">${icon}</span><span>${item}</span>
    </button>`;
  }).join('');
  document.getElementById('bottomNav').style.gridTemplateColumns = grid;
  document.getElementById('bottomNav').innerHTML = html;
}

function renderView() {
  if (state.view !== 'home') return renderSpecialView();
  const map = {
    customer: renderCustomerTab,
    franchisee: renderFranchiseeTab,
    manager: renderManagerTab,
    staff: renderStaffTab,
    mentor: renderMentorTab
  };
  return map[state.role](state.tab);
}

function renderSpecialView() {
  const map = {
    identity: renderIdentity,
    publicStore: renderPublicStore,
    wellnessFlow: renderWellnessFlow,
    serviceDetail: renderServiceDetail,
    appointmentSuccess: renderAppointmentSuccess,
    customerAppointments: renderCustomerAppointments,
    dynamicCode: renderDynamicCode,
    managerAppointmentDetail: renderManagerAppointmentDetail,
    managerCustomer: renderManagerCustomer,
    writeoffInput: renderWriteoffInput,
    writeoffPrecheck: renderWriteoffPrecheck,
    writeoffSuccess: renderWriteoffSuccess,
    franchiseStoreDetail: renderFranchiseStoreDetail,
    purchaseFlow: renderPurchaseFlow,
    leadDetail: renderLeadDetail
  };
  return map[state.view]?.() || renderIdentity();
}

function renderIdentity() {
  return `<div class="section-title">选择演示身份</div>
    <div class="choice-grid">
      ${demoData.roles.map(item => `<button type="button" class="choice ${state.role === item.key ? 'active' : ''}" data-action="setRole" data-value="${item.key}">
        <strong>${item.label}</strong><br><span class="meta">${item.nav.join('｜')}</span>
      </button>`).join('')}
    </div>
    <div class="section-title">选择门店视角</div>
    <div class="choice-grid">
      ${demoData.stores.map(item => `<button type="button" class="choice ${state.store === item.id ? 'active' : ''}" data-action="chooseStore" data-value="${item.id}">
        <strong>${item.name}</strong><br><span class="meta">${item.address}</span>
      </button>`).join('')}
    </div>`;
}

function renderCustomerTab(tab) {
  if (tab === '首页') return renderCustomerHome();
  if (tab === '康养') return renderWellnessFlow();
  if (tab === '商城') return renderMall();
  if (tab === '合作中心') return renderCooperation();
  return renderCustomerMine();
}

function renderCustomerHome() {
  return `<section class="storefront">
    <div class="storefront-top">
      <div class="storefront-titlebar">
        <span class="back-mark">‹</span>
        <strong>御方通和</strong>
        <span class="demo-chip">演示</span>
      </div>
      <button class="search-bar" data-action="setTab" data-value="商城">搜索康养项目、食疗药膳、同源产品</button>
      <div class="storefront-banner">
        <div>
          <p>御方通和家庭康养</p>
          <h3>从调养项目到家庭产品的一站式商城首页</h3>
          <button class="banner-btn" data-action="openView" data-value="wellnessFlow">立即预约服务</button>
        </div>
        <div class="banner-visual"></div>
      </div>
    </div>
    <div class="quick-grid">
      ${demoData.mallCategories.map(item => `<button class="quick-item" data-action="${item.action}" data-value="${item.value}">
        <span class="quick-icon">${item.icon}</span><span>${item.name}</span>
      </button>`).join('')}
    </div>
    <div class="storefront-strip">
      <button data-action="openView" data-value="publicStore"><strong>${currentStore().name}</strong><span>门店对外页 ›</span></button>
      <button data-action="openView" data-value="customerAppointments"><strong>我的预约</strong><span>查看服务码 ›</span></button>
    </div>
    ${demoData.storefrontSections.map(renderStorefrontSection).join('')}
    <div class="storefront-footer-card">
      <strong>合作加盟</strong>
      <p>了解加盟申请、导师跟进和活动协同位置。</p>
      <button class="mini-btn" data-action="setTab" data-value="合作中心">进入合作中心</button>
    </div>
  </section>`;
}

function renderPublicStore() {
  const store = currentStore().id === 'all' ? demoData.stores[1] : currentStore();
  return `<section class="public-store">
    <div class="store-cover">
      <span class="demo-chip">门店展示</span>
      <h3>${store.name}</h3>
      <p>顾客可见的门店介绍页，不展示内部经营数据。</p>
    </div>
    ${storeCard(store)}
    <div class="floor-head"><strong>康养服务</strong><button data-action="openView" data-value="wellnessFlow">预约 ›</button></div>
    <div class="grid-2">${demoData.services.map(serviceMiniCard).join('')}</div>
    <div class="floor-head"><strong>门店商品</strong><button data-action="setTab" data-value="商城">更多 ›</button></div>
    <div class="product-row">${demoData.storefrontSections[1].items.map(productTile).join('')}</div>
    <div class="floor-head"><strong>活动推荐</strong><button>查看更多 ›</button></div>
    <div class="store-event"><div class="event-img"></div><div><strong>门店体验日</strong><p>对外活动展示，不包含客户名单、销售数据、员工业绩、库存成本、核销记录或经营报表。</p></div></div>
  </section>`;
}

function renderWellnessFlow() {
  return `<div class="section-title">选择服务项目</div>
    <div class="choice-grid">${demoData.services.map(item => `<button class="choice ${state.selectedService === item.id ? 'active' : ''}" data-action="chooseService" data-value="${item.id}"><strong>${item.name}</strong><br><span class="meta">${item.duration} · ${item.benefit}</span></button>`).join('')}</div>
    <div class="section-title">选择门店</div>
    <div class="choice-grid">${demoData.stores.filter(item => item.id !== 'all').map(item => `<button class="choice ${state.store === item.id ? 'active' : ''}" data-action="chooseStore" data-value="${item.id}"><strong>${item.name}</strong><br><span class="meta">${item.address}</span></button>`).join('')}</div>
    <div class="section-title">选择日期</div>${choiceRow(['今天', '明天', '周六'], state.selectedDate, 'chooseDate')}
    <div class="section-title">选择时段</div>${choiceRow(['10:00', '14:00', '16:00'], state.selectedSlot, 'chooseSlot')}
    <div class="card"><strong>预约确认</strong><p class="meta">${currentService().name} · ${currentStore().name} · ${state.selectedDate} ${state.selectedSlot}</p><button class="full-btn" data-action="confirmAppointment">确认预约</button></div>
    <button class="ghost-btn full-btn" data-action="openView" data-value="customerAppointments">我的预约</button>`;
}

function renderServiceDetail() {
  const service = currentService();
  return `<div class="card"><strong>${service.name}</strong><p class="meta">${service.desc}</p><div class="tag-row"><span class="tag">${service.duration}</span><span class="tag ok">${service.benefit}</span></div><button class="full-btn" data-action="openView" data-value="wellnessFlow">选择门店和时段</button></div>`;
}

function renderAppointmentSuccess() {
  return `<div class="success"><div class="success-mark">✓</div><h3>预约成功</h3><p class="meta">${currentService().name} · ${currentStore().name}<br>${state.selectedDate} ${state.selectedSlot}</p><button class="full-btn" data-action="openView" data-value="customerAppointments">查看我的预约</button><button class="ghost-btn full-btn" data-action="openView" data-value="dynamicCode">打开动态核销码</button></div>`;
}

function renderCustomerAppointments() {
  return `${appointmentCard('我的预约', '已确认', true)}<button class="full-btn" data-action="openView" data-value="dynamicCode">打开动态核销码</button><button class="ghost-btn full-btn" data-action="openView" data-value="wellnessFlow">修改预约</button><button class="mini-btn full-btn danger-btn" data-action="goHome">取消预约演示</button>`;
}

function renderDynamicCode() {
  return `<div class="code-box"><div class="qr"></div><p class="meta">动态二维码 token 演示，不包含真实密钥。</p><div class="digits">482916</div><p class="meta">六位数字核销码，供店员输码演示。</p></div><button class="full-btn" data-action="setRole" data-value="staff">切换店员去核销</button>`;
}

function renderMall() {
  return `<div class="floor-head"><strong>商城演示</strong><button>分类 ›</button></div>
    ${demoData.storefrontSections.slice(1, 4).map(renderStorefrontSection).join('')}
    <div class="card"><strong>购物车</strong><p class="meta">商品详情、购物车、确认订单、订单列表和订单详情仅做交互占位，不接支付。</p><span class="tag plan">不接真实支付</span></div>`;
}

function renderCooperation() {
  return `<div class="card"><strong>合作加盟入口</strong><p class="meta">用于展示加盟申请、线索跟进与导师协同的位置。</p><div class="tag-row"><span class="tag plan">加盟合同规划中</span><span class="tag plan">奖励台账规划中</span></div><button class="full-btn" data-action="setRole" data-value="mentor">切换导师查看线索</button></div>`;
}

function renderCustomerMine() {
  return `<div class="section-title">我的</div>${simpleList(['我的身份：顾客', '我的5980套餐', '十个月权益', '当前月权益', '我的预约', '我的订单', '我的推荐', '收货地址', '联系客服'], '查看')}<button class="full-btn" data-action="openView" data-value="identity">切换身份</button>`;
}

function renderFranchiseeTab(tab) {
  if (tab === '工作台') return `<div class="grid-2">${stat('今日销售', '¥8,260')}${stat('本月销售', '¥126,800')}${stat('名下门店', '3家')}${stat('今日核销', '12次')}</div><div class="section-title">今日待办</div>${simpleList(['郑州金水店待确认预约 3 条', '郑州中原店库存预警 2 项', '洛阳涧西店待回访客户 4 人'], '处理')}<button class="full-btn" data-action="setTab" data-value="门店">全部门店总览</button><button class="ghost-btn full-btn" data-action="openView" data-value="purchaseFlow">快捷采购</button>`;
  if (tab === '门店') return renderFranchiseStores();
  if (tab === '采购') return renderPurchaseFlow();
  if (tab === '客户') return renderCustomerList(true);
  return `${simpleList(['经营主体：郑州康养加盟演示主体', '加盟合同占位', '名下门店', '员工管理', '推荐加盟线索', '奖励台账占位', '收款设置占位', '退出工作台'], '查看')}<div class="tag-row"><span class="tag plan">加盟合同规划中</span><span class="tag plan">奖励台账规划中</span></div>`;
}

function renderFranchiseStores() {
  return demoData.stores.filter(item => item.id !== 'all').map(store => `<div class="list-card"><strong>${store.name}</strong><p class="meta">今日销售 ¥${store.id === 'jinshui' ? '3,600' : '2,300'} · 今日预约 ${store.id === 'jianxi' ? 2 : 5} · 库存预警 ${store.id === 'zhongyuan' ? 2 : 0}</p><div class="card-actions"><button class="mini-btn" data-action="chooseStore" data-value="${store.id}">切换当前门店</button><button class="primary-btn" data-action="openView" data-value="franchiseStoreDetail">进入门店工作台</button></div></div>`).join('') + '<button class="full-btn" data-action="openView" data-value="purchaseFlow">进入采购流程</button>';
}

function renderFranchiseStoreDetail() {
  return `<div class="hero"><h3>${currentStore().name}</h3><p>加盟商看到的是门店经营视图，区别于顾客对外商店页。</p></div><div class="grid-2">${stat('今日预约', '5')}${stat('今日核销', '4')}${stat('今日销售', '¥3,600')}${stat('库存预警', '1')}</div><div class="section-title">内部入口</div>${simpleList(['客户列表', '预约记录', '核销记录', '库存预警'], '进入')}<button class="full-btn" data-action="setTab" data-value="门店">返回全部门店</button><button class="ghost-btn full-btn" data-action="openView" data-value="purchaseFlow">进入采购流程</button>`;
}

function renderPurchaseFlow() {
  return `<div class="section-title">总部供货商品</div>${demoData.purchases.map(item => `<div class="list-card"><strong>${item.name}</strong><p class="meta">加盟进货价演示 · 数量 ${item.qty} · ${item.status}</p><span class="tag plan">演示流程，不提交真实采购</span></div>`).join('')}<button class="full-btn" data-action="goHome">提交采购演示</button>`;
}

function renderManagerTab(tab) {
  if (tab === '工作台') return `<div class="grid-2">${stat('待确认预约', state.managerConfirmed ? '0' : '3')}${stat('即将到店', '4')}${stat('待核销', '2')}${stat('库存预警', '1')}</div>${appointmentCard('今日待确认预约', state.managerConfirmed ? '已确认' : '待确认', false)}<button class="full-btn" data-action="openView" data-value="managerAppointmentDetail">查看预约</button>`;
  if (tab === '客户') return renderCustomerList(false);
  if (tab === '预约') return `${appointmentCard('今日预约', state.managerConfirmed ? '已确认' : '待确认', false)}<button class="full-btn" data-action="openView" data-value="managerAppointmentDetail">处理预约</button>`;
  if (tab === '核销') return renderWriteoffInput();
  return `${simpleList(['当前门店：' + currentStore().name, '切换门店', '店员管理', '门店信息', '排班设置入口', '库存和补货入口', '操作记录'], '进入')}<span class="tag plan">库存补货规划中</span>`;
}

function renderManagerAppointmentDetail() {
  return `${appointmentCard('预约详情', state.managerConfirmed ? '已确认' : '待确认', false)}<div class="card-actions"><button class="primary-btn" data-action="managerConfirm">确认预约</button><button class="mini-btn danger-btn" data-action="goHome">拒绝预约演示</button><button class="mini-btn" data-action="openView" data-value="wellnessFlow">修改时间</button></div><button class="full-btn" data-action="openView" data-value="managerCustomer">查看客户</button><button class="ghost-btn full-btn" data-action="openView" data-value="writeoffInput">进入核销</button>`;
}

function renderManagerCustomer() {
  return `<div class="card"><strong>赵女士</strong><p class="meta">演示客户 · 5980套餐有效 · 剩余服务权益 6 次</p><div class="tag-row"><span class="tag ok">已建档</span><span class="tag">待回访</span></div><button class="full-btn" data-action="openView" data-value="writeoffInput">进入核销</button></div>${simpleList(['5980套餐', '剩余权益', '服务记录', '回访记录'], '查看')}`;
}

function renderStaffTab(tab) {
  if (tab === '工作台') return `<div class="grid-2">${stat('今日任务', '6')}${stat('待核销', '2')}</div>${simpleList(['被分配客户 4 人', '到店提醒 2 条', '自己的操作记录'], '查看')}<p class="meta">店员视图不展示多门店经营数据、财务数据、店员管理或总部配置。</p>`;
  if (tab === '客户') return renderCustomerList(false, true);
  if (tab === '核销') return renderWriteoffInput();
  if (tab === '订单') return simpleList(['门店订单 10021', '门店订单 10022', '售后处理占位'], '查看');
  return simpleList(['当前门店：' + currentStore().name, '我的授权功能', '自己的操作记录', '退出工作台'], '查看');
}

function renderWriteoffInput() {
  return `<div class="card"><strong>输入六位数字码</strong><p class="meta">演示码：482916。不会连接真实核销接口。</p><div class="code-box"><div class="digits">482916</div></div><button class="full-btn" data-action="precheckWriteoff">核销预检</button></div>${simpleList(['扫码核销模拟', '今日核销记录'], '打开')}`;
}

function renderWriteoffPrecheck() {
  return `<div class="card"><strong>预检通过</strong><p class="meta">客户：赵女士<br>服务项目：${currentService().name}<br>权益项目：5980服务权益<br>门店：${currentStore().name}</p><button class="full-btn" data-action="finishWriteoff">确认核销</button></div>`;
}

function renderWriteoffSuccess() {
  return `<div class="success"><div class="success-mark">✓</div><h3>核销成功</h3><p class="meta">已完成签到、权益消耗和核销记录的演示闭环。</p><button class="full-btn" data-action="goHome">返回工作台</button></div>`;
}

function renderMentorTab(tab) {
  if (tab === '工作台') return `<div class="grid-2">${stat('今日跟进', '5')}${stat('帮扶任务', '3')}</div>${simpleList(['郑州金水店活动复盘', '洛阳涧西店巡店记录', '加盟线索待跟进'], '处理')}<span class="tag plan">个人业绩规划中</span>`;
  if (tab === '线索') return `${simpleList(['张先生 · 意向加盟', '郑州渠道线索 · 待回访', '洛阳合作咨询 · 初访'], '查看详情')}<button class="full-btn" data-action="openView" data-value="leadDetail">查看详情</button>`;
  if (tab === '活动') return simpleList(['社区义诊活动计划', '活动签到', '门店帮扶任务'], '进入');
  if (tab === '资料') return simpleList(['培训资料', '常见问题', '服务标准说明'], '查看');
  return `${simpleList(['服务导师身份', '我的加盟线索', '跟进记录', '巡店记录', '个人业绩占位'], '查看')}<span class="tag plan">真实业绩规划中</span>`;
}

function renderLeadDetail() {
  return `<div class="card"><strong>张先生 · 意向加盟</strong><p class="meta">来源：合作中心演示入口<br>状态：${state.leadFollowed ? '已跟进' : '待跟进'}<br>意向区域：郑州</p><button class="full-btn" data-action="addFollow">添加跟进记录</button><button class="ghost-btn full-btn" data-action="setTab" data-value="活动">查看活动任务</button></div>`;
}

function renderCustomerList(allStores, brief = false) {
  const suffix = allStores ? ' · 含所属门店' : brief ? ' · 简要资料' : ' · 当前门店';
  return demoData.customers.slice(0, brief ? 4 : 7).map(name => `<div class="list-card"><strong>${name}</strong><p class="meta">5980会员${suffix}<br>套餐和权益、服务和预约记录演示</p><button class="mini-btn" data-action="openView" data-value="managerCustomer">查看客户</button></div>`).join('');
}

function storeCard(store) {
  return `<div class="list-card"><strong>${store.name}</strong><p class="meta">${store.address}<br>营业时间：${store.hours}<br>联系方式：${store.phone}</p><div class="card-actions"><button class="mini-btn">导航按钮</button><button class="mini-btn" data-action="openView" data-value="wellnessFlow">预约服务</button></div></div>`;
}

function serviceMiniCard(service) {
  return `<button type="button" class="service-tile" data-action="chooseService" data-value="${service.id}"><span class="tile-image service"></span><strong>${service.name}</strong><p>${service.duration}<br>${service.benefit}</p></button>`;
}

function renderStorefrontSection(section) {
  return `<section class="storefront-floor">
    <div class="floor-head"><div><strong>${section.title}</strong><p>${section.subtitle}</p></div><button data-action="${section.action}" data-value="${section.value}">查看更多 ›</button></div>
    <div class="product-row">${section.items.map(productTile).join('')}</div>
  </section>`;
}

function productTile(item) {
  return `<button class="product-tile" data-action="setTab" data-value="商城">
    <span class="tile-image ${item.tone}"></span>
    <strong>${item.name}</strong>
    <p>${item.desc}</p>
    <em>${item.price}</em>
  </button>`;
}

function stat(label, value) {
  return `<div class="stat-card"><span class="meta">${label}</span><strong>${value}</strong></div>`;
}

function appointmentCard(title, status, mine) {
  const item = demoData.appointments[0];
  return `<div class="list-card"><strong>${title}</strong><p class="meta">${mine ? '我' : item.customer} · ${item.service}<br>${currentStore().name} · ${state.selectedDate} ${state.selectedSlot}</p><div class="tag-row"><span class="tag ${status === '已确认' ? 'ok' : ''}">${status}</span><span class="tag">演示预约</span></div></div>`;
}

function simpleList(items, actionText) {
  return items.map(item => `<div class="list-card"><strong>${item}</strong><p class="meta">本地演示数据</p><button class="mini-btn">${actionText}</button></div>`).join('');
}

function choiceRow(items, current, action) {
  return `<div class="grid-2">${items.map(item => `<button class="choice ${current === item ? 'active' : ''}" data-action="${action}" data-value="${item}">${item}</button>`).join('')}</div>`;
}

function showToast(message) {
  toast.textContent = message;
  toast.classList.add('show');
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => toast.classList.remove('show'), 1600);
}

init();
