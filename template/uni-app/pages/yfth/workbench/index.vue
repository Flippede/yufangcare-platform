<template>
	<view class="yfth-shell">
		<view class="header">
			<view>
				<view class="eyebrow">御方通和经营工作台</view>
				<view class="title">{{ context.role_name_cn || '经营身份' }}工作台</view>
				<view class="sub">{{ context.store_name || '请选择授权门店' }}</view>
			</view>
		</view>

		<view v-if="loading" class="empty">正在读取服务端身份上下文...</view>
		<view v-else-if="error" class="empty error">
			<view>{{ error }}</view>
			<button @click="load">重新加载</button>
		</view>
		<block v-else>
			<view v-if="context.requires_store && storeIdentities.length > 1" class="switch-row">
				<button @click="goStoreSwitch">切换门店</button>
			</view>

			<view class="notice">
				当前门店权限由服务端按用户 Token、经营身份和授权门店重新校验；本地缓存只保存选择结果，不能作为授权依据。
			</view>

			<view v-if="storeRoleReady">
				<view v-if="pane === 'dashboard'" class="section">
					<view class="commission-overview" @click="goCommission">
						<view><text class="commission-label">门店未结算佣金</text><text class="commission-total">¥ {{ commissionAccount.unsettled || '0.00' }}</text></view>
						<view class="commission-split">已结算 {{ commissionAccount.settled || '0.00' }} · C1待结算 {{ commissionAccount.c1_pending || '0.00' }}</view>
					</view>
					<view class="metrics">
						<view v-for="item in dashboardCards" :key="item.key" class="metric" @click="tapDashboard(item)">
							<view class="metric-value">{{ item.value }}</view>
							<view class="metric-title">{{ item.title }}</view>
							<view class="metric-desc">{{ item.desc }}</view>
						</view>
					</view>
					<view v-if="businessTools.length" class="business-tools">
						<view class="business-tools-head">
							<view class="panel-title">经营工具</view>
							<view class="business-tools-note">只展示当前身份可用的独立能力</view>
						</view>
						<view class="tool-grid">
							<view v-for="item in businessTools" :key="item.key" class="tool-card" @click="tapBusinessTool(item)">
								<view class="tool-icon">{{ item.icon }}</view>
								<view class="tool-copy">
									<view class="tool-title">{{ item.title }}</view>
									<view class="tool-desc">{{ item.desc }}</view>
								</view>
								<view class="tool-arrow">›</view>
							</view>
						</view>
					</view>
				</view>

				<view v-else-if="pane === 'appointments'" class="section">
					<view class="panel">
						<view class="panel-head">
							<view class="panel-title">门店预约</view>
							<button class="mini" @click="loadAppointments(true)">刷新</button>
						</view>
						<view class="filter-tabs">
							<view v-for="item in appointmentTabs" :key="item.value" :class="['tab', appointmentWhere.status === item.value ? 'active' : '']" @click="changeAppointmentStatus(item.value)">
								{{ item.label }}
							</view>
						</view>
						<view v-if="appointmentLoading" class="inline-empty">正在加载预约...</view>
						<view v-else-if="!appointments.length" class="inline-empty">当前筛选下暂无预约记录。</view>
						<view v-else>
							<view v-for="item in appointments" :key="item.id" class="list-card">
								<view class="row-main">
									<view>
										<view class="strong">{{ item.service_name || '服务项目' }}</view>
										<view class="muted">{{ item.date_text }} {{ item.start_time_text }}-{{ item.end_time_text }}</view>
										<view class="muted">预约号 {{ item.appointment_no }}</view>
									</view>
									<view class="status">{{ item.status_text }}</view>
								</view>
								<view class="button-row">
									<button @click="viewAppointment(item)">详情</button>
									<button v-if="item.actions && item.actions.can_confirm" @click="operateAppointment(item, 'confirm')">确认</button>
									<button v-if="item.actions && item.actions.can_reject" @click="operateAppointment(item, 'reject')">拒绝</button>
									<button v-if="item.actions && item.actions.can_cancel" @click="operateAppointment(item, 'cancel')">取消</button>
									<button v-if="item.actions && item.actions.can_writeoff" @click="openWriteoffFor(item)">去核销</button>
								</view>
							</view>
						</view>
						<view v-if="selectedAppointment.id" class="detail-box">
							<view class="panel-title">预约详情</view>
							<view class="detail-line">服务：{{ selectedAppointment.service_name }}</view>
							<view class="detail-line">权益：{{ selectedAppointment.benefit_name || '服务权益' }}</view>
							<view class="detail-line">状态：{{ selectedAppointment.status_text }}</view>
							<view class="detail-line">备注：{{ selectedAppointment.user_note || '无' }}</view>
							<view v-if="selectedAppointment.writeoff_result && selectedAppointment.writeoff_result.status !== 'none'" class="detail-line">核销：{{ selectedAppointment.writeoff_result.status }}</view>
						</view>
					</view>
				</view>

				<view v-else-if="pane === 'writeoff'" class="section">
					<view class="panel">
						<view class="panel-head">
							<view>
								<view class="panel-title">会员开通申请</view>
								<view class="muted">用户完成线下套餐购买申请后，由所属门店店长或店员处理。</view>
							</view>
							<button class="mini" @click="loadMembershipApplications">刷新</button>
						</view>
						<view v-if="membershipApplicationLoading" class="inline-empty">正在加载会员申请...</view>
						<view v-else-if="!membershipApplications.length" class="inline-empty">暂无待处理会员申请。</view>
						<view v-else>
							<view v-for="item in membershipApplications" :key="item.id" class="list-card membership-application">
								<view class="row-main">
									<view>
										<view class="strong">{{ item.applicant && item.applicant.name || '御方通和用户' }}</view>
										<view class="muted">{{ item.applicant && item.applicant.phone_masked || '手机号未提供' }}</view>
									</view>
									<view class="status">{{ membershipStatusText(item.status) }}</view>
								</view>
								<view class="application-summary">
									<view>申请套餐：御方通和9800元康养会员套餐</view>
									<view>线下金额：￥{{ membershipAmount(item.amount_cents) }}</view>
									<view v-if="item.upstream_member && item.upstream_member.exists">
										上一级会员：{{ item.upstream_member.name }} {{ item.upstream_member.phone_masked }}
									</view>
									<view v-else>上一级会员：无（仅开通会员，不产生直推阶梯奖励）</view>
									<view>申请时间：{{ formatTime(item.add_time) }}</view>
								</view>
								<view v-if="item.status === 'pending_store_review'" class="membership-actions">
									<input
										:value="membershipRejectReasons[item.id] || ''"
										placeholder="拒绝时填写原因"
										@input="setMembershipRejectReason(item.id, $event)"
									/>
									<view class="button-row">
										<view
											class="membership-action primary"
											hover-class="membership-action-active"
											@tap.stop="approveMembershipApplication(item)"
										>确认开通</view>
										<view
											class="membership-action"
											hover-class="membership-action-active"
											@tap.stop="rejectMembershipApplication(item)"
										>拒绝申请</view>
									</view>
								</view>
							</view>
						</view>
					</view>

					<view class="panel">
						<view class="panel-head">
							<view class="panel-title">服务核销</view>
							<button class="mini" @click="loadWriteoffRecords(true)">记录</button>
						</view>
						<view class="writeoff-box">
							<button class="primary" @click="scanQr">扫码预检</button>
							<view class="manual-code">
								<input v-model="writeoffForm.digital_code" maxlength="6" type="number" placeholder="输入6位数字核销码" />
								<button @click="precheckDigital">预检</button>
							</view>
							<input v-model="writeoffForm.qr_token" class="token-input" placeholder="H5调试可粘贴二维码 token" />
							<button @click="precheckToken">二维码 token 预检</button>
						</view>
						<view v-if="writeoffPrecheck.appointment" class="detail-box">
							<view class="panel-title">预检结果</view>
							<view class="detail-line">预约：{{ writeoffPrecheck.appointment.appointment_no }}</view>
							<view class="detail-line">服务：{{ writeoffPrecheck.appointment.service_name }}</view>
							<view class="detail-line">时间：{{ writeoffPrecheck.appointment.date_text }} {{ writeoffPrecheck.appointment.start_time_text }}-{{ writeoffPrecheck.appointment.end_time_text }}</view>
							<button class="primary" @click="submitWriteoff">确认核销</button>
						</view>
						<view v-if="writeoffResult.status" class="result-box">
							<view class="strong">核销结果：{{ writeoffResult.status }}</view>
							<view v-if="writeoffResult.record">核销单号：{{ writeoffResult.record.writeoff_no }}</view>
						</view>
					</view>

					<view class="panel">
						<view class="panel-title">最近核销记录</view>
						<view v-if="writeoffRecords.length">
							<view v-for="item in writeoffRecords" :key="item.id" class="compact-row">
								<view>
									<view class="strong">{{ item.writeoff_no }}</view>
									<view class="muted">{{ item.writeoff_method }} · {{ formatTime(item.writeoff_time) }}</view>
								</view>
								<view class="status">{{ item.status }}</view>
							</view>
						</view>
						<view v-else class="inline-empty">暂无核销记录。</view>
					</view>
				</view>

				<view v-else-if="pane === 'orders'" class="section">
					<view class="panel">
						<view class="panel-head">
							<view class="panel-title">门店订单只读</view>
							<button class="mini" @click="loadOrders(true)">刷新</button>
						</view>
						<view class="search-row">
							<input v-model="orderWhere.order_sn" placeholder="订单号搜索" @confirm="loadOrders(true)" />
							<button @click="loadOrders(true)">查询</button>
						</view>
						<view v-if="orderLoading" class="inline-empty">正在加载订单...</view>
						<view v-else-if="!orders.length" class="inline-empty">暂无门店订单。</view>
						<view v-else>
							<view v-for="item in orders" :key="item.id" class="list-card" @click="viewOrder(item)">
								<view class="row-main">
									<view>
										<view class="strong">{{ item.order_id }}</view>
										<view class="muted">{{ item.real_name_masked }} {{ item.user_phone_masked }}</view>
										<view class="muted">{{ formatTime(item.add_time) }}</view>
									</view>
									<view class="price">￥{{ item.pay_price }}</view>
								</view>
								<view class="muted">{{ item.status_text }} · {{ item.total_num }}件</view>
							</view>
						</view>
						<view v-if="selectedOrder.id" class="detail-box">
							<view class="panel-title">订单详情</view>
							<view class="detail-line">订单号：{{ selectedOrder.order_id }}</view>
							<view class="detail-line">收货人：{{ selectedOrder.real_name_masked }} {{ selectedOrder.user_phone_masked }}</view>
							<view class="detail-line">地址：{{ selectedOrder.user_address_masked || '已脱敏' }}</view>
							<view class="detail-line">实付：￥{{ selectedOrder.pay_price }}</view>
							<view v-for="item in selectedOrder.items || []" :key="item.item_key" class="compact-row">
								<view>
									<view class="strong">{{ item.product_name || '商品' }}</view>
									<view class="muted">{{ item.sku || '默认规格' }}</view>
								</view>
								<view>x{{ item.cart_num }}</view>
							</view>
						</view>
					</view>
				</view>

				<view v-else-if="pane === 'stores'" class="panel">
					<view class="panel-title">授权门店范围</view>
					<view v-if="storeIdentities.length">
						<view v-for="item in storeIdentities" :key="item.identity_key" class="compact-row">
							<view>
								<view class="strong">{{ item.store_name || ('门店ID ' + item.store_id) }}</view>
								<view class="muted">{{ item.role_name_cn }}</view>
							</view>
							<button @click="switchStore(item.store_id)">进入</button>
						</view>
					</view>
					<view v-else class="inline-empty">暂无服务端返回的门店范围。</view>
				</view>

				<view v-else class="panel">
					<view class="panel-title">{{ paneTitle }}</view>
					<view class="inline-empty">{{ paneEmptyText }}</view>
				</view>
			</view>

			<view v-else class="empty">
				当前身份不是门店经营身份，或尚未选择具体授权门店。请确认店长、店员或招商合伙人身份及门店授权。
			</view>
		</block>

		<view v-if="navItems.length" class="nav">
			<view
				v-for="item in navItems"
				:key="item.title"
				:class="['nav-item', activeNav(item) ? 'active' : '']"
				@click="tapNav(item)"
			>
				<text>{{ item.title }}</text>
			</view>
		</view>
	</view>
</template>

<script>
import {
	approveYfthStorePermanentMembership,
	cancelYfthStoreWorkbenchAppointment,
	confirmYfthStoreWorkbenchAppointment,
	getYfthStorePermanentMemberships,
	getYfthStoreWorkbenchAppointmentDetail,
	getYfthStoreWorkbenchAppointments,
	getYfthStoreWorkbenchOrderDetail,
	getYfthStoreWorkbenchOrders,
	getYfthStoreWorkbenchOverview,
	getYfthStoreCommissionSummary,
	getYfthStoreWorkbenchWriteoffRecords,
	precheckYfthStoreWorkbenchWriteoff,
	rejectYfthStorePermanentMembership,
	rejectYfthStoreWorkbenchAppointment,
	writeoffYfthStoreWorkbenchByDigital,
	writeoffYfthStoreWorkbenchByToken
} from '@/api/yfth.js';
import {
	currentContext,
	enterYfthBusinessMall,
	enterYfthBusinessUserCenter,
	isBusinessRole,
	leaveYfthBusinessMall,
	leaveYfthBusinessUserCenter,
	loadYfthIdentities,
	resolveDominantYfthContext,
	roleNav,
	switchYfthStore
} from '@/libs/yfthContext.js';

export default {
	data() {
		const cachedContext = currentContext();
		return {
			loading: true,
			error: '',
			pane: 'dashboard',
			context: cachedContext && isBusinessRole(cachedContext.role_code) ? cachedContext : {},
			identities: [],
			overview: {},
			commissionAccount: {},
			appointments: [],
			appointmentLoading: false,
			appointmentWhere: { status: '', page: 1, limit: 10 },
			selectedAppointment: {},
			writeoffForm: { qr_token: '', digital_code: '' },
			writeoffMethod: '',
			writeoffPrecheck: {},
			writeoffResult: {},
			writeoffRecords: [],
			membershipApplications: [],
			membershipApplicationLoading: false,
			membershipRejectReasons: {},
			orders: [],
			orderLoading: false,
			orderWhere: { order_sn: '', page: 1, limit: 10 },
			selectedOrder: {}
		};
	},
	computed: {
		isPartnerRole() {
			return ['county_partner', 'prefecture_partner', 'province_partner', 'regional_director', 'platform_director'].indexOf(this.context.role_code) !== -1;
		},
		navItems() {
			if (!this.context || !isBusinessRole(this.context.role_code)) return [];
			return roleNav(this.context.role_code);
		},
		storeRoleReady() {
			return (['store_manager', 'store_staff'].indexOf(this.context.role_code) !== -1 || this.isPartnerRole) && Number(this.context.store_id) > 0;
		},
		canReadProductQuota() {
			return this.context.role_code === 'store_manager' || this.isPartnerRole;
		},
		canReadPackageMembership() {
			return ['store_manager', 'store_staff'].indexOf(this.context.role_code) !== -1;
		},
		canPurchaseInventory() {
			return this.context.role_code === 'store_manager';
		},
		canIssueAcquisitionCode() {
			return ['store_manager', 'store_staff'].indexOf(this.context.role_code) !== -1;
		},
		canReadCustomerAttribution() {
			return this.context.role_code === 'store_manager';
		},
		storeIdentities() {
			const role = this.context.role_code;
			return this.identities.filter((item) => item.role_code === role && item.store_id);
		},
		appointmentTabs() {
			const cards = [
				{ label: '全部', value: '' },
				{ label: '待确认', value: 'pending_confirm' },
				{ label: '待到店', value: 'confirmed' },
				{ label: '已完成', value: 'completed' },
				{ label: '已取消', value: 'cancelled' }
			];
		},
		dashboardCards() {
			const metrics = this.overview.metrics || {};
			return [
				{ key: 'today_appointments', title: '今日预约', value: metrics.today_appointments || 0, desc: '当前门店今日服务预约', pane: 'appointments' },
				{ key: 'pending_confirm', title: '待确认', value: metrics.pending_confirm || 0, desc: '需要店长处理', pane: 'appointments' },
				{ key: 'confirmed_waiting_arrival', title: '待到店', value: metrics.confirmed_waiting_arrival || 0, desc: '已确认等待用户到店', pane: 'writeoff' },
				{ key: 'today_writeoffs', title: '今日核销', value: metrics.today_writeoffs || 0, desc: '今日服务权益核销记录', pane: 'writeoff' },
				{ key: 'today_store_orders', title: '今日支付订单', value: metrics.today_store_orders || 0, desc: '门店今日已支付主订单', pane: 'orders' },
				{ key: 'pending_store_orders', title: '待处理订单', value: metrics.pending_store_orders || 0, desc: '门店待发货或待核销订单', pane: 'orders' },
				{ key: 'monthly_benefit_pickup', title: '权益自提', value: '领', desc: '当前门店产品类月度权益自提确认', pane: 'monthly_benefit_pickup' },
				{ key: 'customers', title: '客户关系', value: 'CRM', desc: '当前门店客户、状态和跟进记录', pane: 'customers' }
			];
			if (this.canReadCustomerAttribution) {
				cards.push({ key: 'customer_attribution', title: '客户归属', value: '只读', desc: '当前门店正式归属客户与推荐状态', pane: 'customer_attribution' });
			}
			return cards;
		},
		businessTools() {
			const tools = [];
			tools.push({ key: 'commission', icon: '账', title: '佣金与结算', desc: '未结算、已结算、C1申请与结算明细' });
			if (this.isPartnerRole) {
				tools.push({ key: 'partner', icon: '招', title: '招商合伙人工作台', desc: '申请二维码、团队、业绩、职级与招商收益' });
			}
			if (this.canPurchaseInventory) {
				tools.push({ key: 'purchase', icon: '采', title: '进入采购库存', desc: '采购单、收货与门店库存' });
			}
			if (this.canReadProductQuota) {
				tools.push({ key: 'product_quota', icon: '额', title: '进入产品额度', desc: '查看门店产品额度台账' });
			}
			if (this.canReadPackageMembership) {
				tools.push({ key: 'package_membership', icon: '会', title: '进入套餐会员', desc: '查看本店套餐会员与奖励' });
			}
			if (this.canIssueAcquisitionCode) {
				tools.push({ key: 'acquisition_code', icon: '码', title: '进入我的门店获客码', desc: '出示专属码绑定门店客户' });
			}
			return tools;
		},
		paneTitle() {
			const titles = {
				customers: '客户入口',
				mine: '我的经营身份',
				leads: '线索',
				activities: '活动',
				materials: '资料'
			};
			return titles[this.pane] || '建设中';
		},
		paneEmptyText() {
			if (['leads', 'activities', 'materials'].indexOf(this.pane) !== -1) {
				return '导师业务域尚未开放，当前仅保留正式导航外壳。';
			}
			return '当前入口已预留，后续接入真实业务列表；本页不展示假数据。';
		}
	},
	onLoad(options) {
		this.pane = options.pane || 'dashboard';
	},
	onShow() {
		leaveYfthBusinessMall();
		leaveYfthBusinessUserCenter();
		this.load();
	},
	methods: {
		load() {
			this.loading = true;
			this.error = '';
			loadYfthIdentities()
				.then((identities) => resolveDominantYfthContext(identities).then((context) => ({ identities, context })))
				.then(({ identities, context }) => {
					if (!context.is_business_role || !isBusinessRole(context.role_code)) {
						uni.reLaunch({ url: '/pages/index/index' });
						return;
					}
					this.identities = identities;
					this.context = context;
					if (this.storeRoleReady) {
						this.loadOverview();
						this.loadPane();
					}
				})
				.catch((err) => {
					this.error = String((err && err.msg) || err || '身份上下文读取失败');
					uni.showToast({ title: this.error, icon: 'none' });
					setTimeout(() => {
						uni.reLaunch({ url: '/pages/index/index' });
					}, 800);
				})
				.finally(() => {
					this.loading = false;
				});
		},
		contextParams(extra) {
			return Object.assign({
				role_code: this.context.role_code,
				store_id: this.context.store_id
			}, extra || {});
		},
		loadOverview() {
			return Promise.all([
				getYfthStoreWorkbenchOverview(this.contextParams()),
				getYfthStoreCommissionSummary(this.contextParams())
			]).then(([overview, commission]) => {
				this.overview = overview.data || {};
				this.commissionAccount = (commission.data && commission.data.account) || {};
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		loadPane() {
			if (this.pane === 'appointments') {
				this.loadAppointments(true);
			} else if (this.pane === 'writeoff') {
				this.loadWriteoffRecords(true);
				this.loadMembershipApplications();
			} else if (this.pane === 'orders') {
				this.loadOrders(true);
			}
		},
		loadAppointments(reset) {
			if (reset) {
				this.appointmentWhere.page = 1;
				this.appointments = [];
				this.selectedAppointment = {};
			}
			this.appointmentLoading = true;
			getYfthStoreWorkbenchAppointments(this.contextParams(this.appointmentWhere)).then((res) => {
				this.appointments = (res.data && res.data.list) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.appointmentLoading = false;
			});
		},
		changeAppointmentStatus(status) {
			this.appointmentWhere.status = status;
			this.loadAppointments(true);
		},
		viewAppointment(item) {
			getYfthStoreWorkbenchAppointmentDetail(item.id, this.contextParams()).then((res) => {
				this.selectedAppointment = (res.data && res.data.appointment) || {};
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		operateAppointment(item, action) {
			const actionText = { confirm: '确认', reject: '拒绝', cancel: '取消' }[action] || '处理';
			uni.showModal({
				title: actionText + '预约',
				content: '确认对预约 ' + item.appointment_no + ' 执行' + actionText + '操作？',
				success: (modal) => {
					if (!modal.confirm) return;
					const payload = {
						reason: 'store_workbench_' + action,
						idempotency_key: this.idempotencyKey(action, item.id)
					};
					const api = action === 'confirm'
						? confirmYfthStoreWorkbenchAppointment
						: (action === 'reject' ? rejectYfthStoreWorkbenchAppointment : cancelYfthStoreWorkbenchAppointment);
					api(item.id, this.contextParams(payload)).then(() => {
						uni.showToast({ title: actionText + '成功', icon: 'success' });
						this.loadOverview();
						this.loadAppointments(true);
					}).catch((err) => {
						uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
					});
				}
			});
		},
		openWriteoffFor(item) {
			this.pane = 'writeoff';
			this.writeoffResult = {};
			this.writeoffPrecheck = {};
			uni.showToast({ title: '请扫码或输入数字码', icon: 'none' });
			this.loadWriteoffRecords(true);
			this.loadMembershipApplications();
		},
		scanQr() {
			// #ifdef H5
			uni.showToast({ title: 'H5 请使用数字码或粘贴 token', icon: 'none' });
			// #endif
			// #ifndef H5
			uni.scanCode({
				success: (res) => {
					this.writeoffForm.qr_token = this.normalizeQrToken(res.result || '');
					this.precheckToken();
				},
				fail: () => {
					uni.showToast({ title: '扫码失败', icon: 'none' });
				}
			});
			// #endif
		},
		precheckToken() {
			const token = this.normalizeQrToken(this.writeoffForm.qr_token);
			if (!token) {
				uni.showToast({ title: '请先扫码或粘贴 token', icon: 'none' });
				return;
			}
			this.writeoffMethod = 'token';
			this.doPrecheck({ qr_token: token });
		},
		precheckDigital() {
			const code = String(this.writeoffForm.digital_code || '').trim();
			if (!/^\d{6}$/.test(code)) {
				uni.showToast({ title: '请输入6位数字码', icon: 'none' });
				return;
			}
			this.writeoffMethod = 'digital';
			this.doPrecheck({ digital_code: code });
		},
		doPrecheck(payload) {
			this.writeoffPrecheck = {};
			this.writeoffResult = {};
			precheckYfthStoreWorkbenchWriteoff(this.contextParams(payload)).then((res) => {
				this.writeoffPrecheck = res.data || {};
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		submitWriteoff() {
			if (!this.writeoffPrecheck.appointment) {
				uni.showToast({ title: '请先完成预检', icon: 'none' });
				return;
			}
			uni.showModal({
				title: '确认核销',
				content: '核销后将完成预约并消耗对应服务权益，是否继续？',
				success: (modal) => {
					if (!modal.confirm) return;
					const payload = this.contextParams({
						idempotency_key: this.idempotencyKey('writeoff', this.writeoffPrecheck.appointment.id)
					});
					const promise = this.writeoffMethod === 'digital'
						? writeoffYfthStoreWorkbenchByDigital(this.writeoffForm.digital_code, payload)
						: writeoffYfthStoreWorkbenchByToken(this.normalizeQrToken(this.writeoffForm.qr_token), payload);
					promise.then((res) => {
						this.writeoffResult = res.data || {};
						uni.showToast({ title: '核销成功', icon: 'success' });
						this.writeoffPrecheck = {};
						this.loadOverview();
						this.loadWriteoffRecords(true);
					}).catch((err) => {
						uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
					});
				}
			});
		},
		loadWriteoffRecords() {
			getYfthStoreWorkbenchWriteoffRecords(this.contextParams({ page: 1, limit: 5 })).then((res) => {
				this.writeoffRecords = (res.data && res.data.list) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		loadMembershipApplications() {
			this.membershipApplicationLoading = true;
			getYfthStorePermanentMemberships(this.contextParams({
				status: 'pending_store_review',
				page: 1,
				limit: 50
			})).then((res) => {
				this.membershipApplications = (res.data && res.data.list) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err || '会员申请读取失败'), icon: 'none' });
			}).finally(() => {
				this.membershipApplicationLoading = false;
			});
		},
		setMembershipRejectReason(id, event) {
			this.$set(this.membershipRejectReasons, id, String((event && event.detail && event.detail.value) || ''));
		},
		approveMembershipApplication(item) {
			uni.showModal({
				title: '确认开通会员',
				content: '确认 ' + ((item.applicant && item.applicant.name) || '该用户') + ' 已完成线下购买，并开通永久会员？',
				success: (modal) => {
					if (!modal.confirm) return;
					approveYfthStorePermanentMembership(item.id, this.contextParams({
						idempotency_key: this.idempotencyKey('membership_approve', item.id)
					})).then(() => {
						uni.showToast({ title: '会员已开通', icon: 'success' });
						this.loadMembershipApplications();
						this.loadOverview();
					}).catch((err) => {
						uni.showToast({ title: String((err && err.msg) || err || '会员开通失败'), icon: 'none' });
					});
				}
			});
		},
		rejectMembershipApplication(item) {
			const reason = String(this.membershipRejectReasons[item.id] || '').trim();
			if (reason.length < 2) {
				uni.showToast({ title: '请填写拒绝原因', icon: 'none' });
				return;
			}
			uni.showModal({
				title: '拒绝会员申请',
				content: '确认拒绝 ' + ((item.applicant && item.applicant.name) || '该用户') + ' 的会员开通申请？',
				success: (modal) => {
					if (!modal.confirm) return;
					rejectYfthStorePermanentMembership(item.id, this.contextParams({
						reason,
						idempotency_key: this.idempotencyKey('membership_reject', item.id)
					})).then(() => {
						uni.showToast({ title: '申请已拒绝', icon: 'success' });
						this.$delete(this.membershipRejectReasons, item.id);
						this.loadMembershipApplications();
					}).catch((err) => {
						uni.showToast({ title: String((err && err.msg) || err || '拒绝申请失败'), icon: 'none' });
					});
				}
			});
		},
		membershipAmount(amountCents) {
			return (Number(amountCents || 0) / 100).toFixed(2);
		},
		membershipStatusText(status) {
			const labels = {
				pending_store_review: '待门店处理',
				activated: '已开通',
				rejected: '已拒绝',
				cancelled: '已取消'
			};
			return labels[status] || status || '未知状态';
		},
		loadOrders(reset) {
			if (reset) {
				this.orderWhere.page = 1;
				this.orders = [];
				this.selectedOrder = {};
			}
			this.orderLoading = true;
			getYfthStoreWorkbenchOrders(this.contextParams(this.orderWhere)).then((res) => {
				this.orders = (res.data && res.data.list) || [];
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			}).finally(() => {
				this.orderLoading = false;
			});
		},
		viewOrder(item) {
			getYfthStoreWorkbenchOrderDetail(item.id, this.contextParams()).then((res) => {
				this.selectedOrder = (res.data && res.data.order) || {};
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		},
		normalizeQrToken(value) {
			const text = String(value || '').trim();
			if (!text) return '';
			const match = text.match(/[?&](qr_token|token|yfth_writeoff_token)=([^&]+)/);
			return match ? decodeURIComponent(match[2]) : text;
		},
		idempotencyKey(action, id) {
			return 'yfth_store_workbench_' + action + '_' + id + '_' + Date.now();
		},
		formatTime(value) {
			const ts = Number(value || 0);
			if (!ts) return '-';
			const date = new Date(ts * 1000);
			const pad = (n) => (n < 10 ? '0' + n : '' + n);
			return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
		},
		goStoreSwitch() {
			uni.navigateTo({ url: '/pages/yfth/workbench/store_switch' });
		},
		goCustomers() {
			const role = encodeURIComponent(this.context.role_code || '');
			const storeId = Number(this.context.store_id || 0);
			uni.navigateTo({
				url: `/pages/yfth/workbench/customer/index?role_code=${role}&store_id=${storeId}`,
				fail: (err) => {
					uni.showToast({ title: String((err && err.errMsg) || '客户页面打开失败'), icon: 'none' });
				}
			});
		},
		goPurchase() {
			uni.navigateTo({ url: '/pages/yfth/workbench/purchase/index' });
		},
		goProductQuota() {
			uni.navigateTo({ url: '/pages/yfth/product_quota/index' });
		},
		goPackageMembership() {
			uni.navigateTo({ url: '/pages/yfth/workbench/package_membership/index' });
		},
		goCommission() {
			uni.navigateTo({ url: '/pages/yfth/workbench/commission/index' });
		},
		goMonthlyBenefitPickup() {
			uni.navigateTo({ url: '/pages/yfth/workbench/monthly_benefit_pickup' });
		},
		goAcquisitionCode() {
			const role = encodeURIComponent(this.context.role_code || '');
			const storeId = Number(this.context.store_id || 0);
			uni.navigateTo({ url: `/pages/yfth/store_acquisition/code?role_code=${role}&store_id=${storeId}` });
		},
		goCustomerAttribution() {
			if (!this.canReadCustomerAttribution) {
				uni.showToast({ title: '当前身份无权查看客户归属', icon: 'none' });
				return;
			}
			uni.navigateTo({ url: '/pages/yfth/workbench/customer_attribution/index' });
		},
		activeNav(item) {
			return (item.pane || 'dashboard') === this.pane && !item.url;
		},
		tapNav(item) {
			if (item.url) {
				leaveYfthBusinessMall();
				leaveYfthBusinessUserCenter();
				if (item.action === 'mall') enterYfthBusinessMall();
				if (item.action === 'user_center') enterYfthBusinessUserCenter();
				const fn = item.type === 'switchTab' ? uni.switchTab : uni.navigateTo;
				fn({
					url: item.url,
					fail: (err) => {
						leaveYfthBusinessMall();
						leaveYfthBusinessUserCenter();
						uni.showToast({ title: String((err && err.errMsg) || '页面打开失败'), icon: 'none' });
					}
				});
				return;
			}
			this.openPane(item.pane || 'dashboard');
		},
		openPane(pane) {
			if (pane === 'customer_attribution') {
				this.goCustomerAttribution();
				return;
			}
			if (pane === 'customers') {
				this.goCustomers();
				return;
			}
			if (pane === 'monthly_benefit_pickup') {
				this.goMonthlyBenefitPickup();
				return;
			}
			this.pane = pane || 'dashboard';
			this.loadPane();
		},
		tapDashboard(item) {
			this.openPane(item.pane || 'dashboard');
		},
			tapBusinessTool(item) {
			const actions = {
				commission: this.goCommission,
				partner: this.goPartnerWorkbench,
				purchase: this.goPurchase,
				product_quota: this.goProductQuota,
				package_membership: this.goPackageMembership,
				acquisition_code: this.goAcquisitionCode
			};
			const action = actions[item && item.key];
			if (typeof action === 'function') action.call(this);
		},
		goPartnerWorkbench() {
			uni.navigateTo({ url: '/pages/yfth/franchise/partner/index' });
		},
		switchStore(storeId) {
			switchYfthStore(storeId).then(() => {
				uni.showToast({ title: '门店已切换', icon: 'success' });
				this.load();
			}).catch((err) => {
				uni.showToast({ title: String((err && err.msg) || err), icon: 'none' });
			});
		}
	}
};
</script>

<style scoped>
.yfth-shell { min-height: 100vh; background: #f6f0e6; padding: 24rpx 24rpx 130rpx; }
.header { border-radius: 18rpx; background: linear-gradient(135deg, #4f3424, #a5763b); color: #fff; padding: 28rpx; display: flex; justify-content: space-between; gap: 18rpx; }
.eyebrow { color: #f2dfb5; font-size: 22rpx; }
.title { font-size: 38rpx; font-weight: 700; margin-top: 8rpx; }
.sub { margin-top: 8rpx; color: #f7e8d0; font-size: 24rpx; }
button { font-size: 26rpx; }
.light { background: #fffaf2; color: #6d4b31; border-radius: 12rpx; height: 64rpx; line-height: 64rpx; padding: 0 20rpx; }
.primary { background: #6f4c2f; color: #fff; border-radius: 12rpx; }
.mini { background: #fff7e9; color: #6f4c2f; border-radius: 10rpx; height: 56rpx; line-height: 56rpx; padding: 0 18rpx; margin: 0; }
.switch-row { display: flex; gap: 18rpx; margin: 22rpx 0; }
.switch-row button { flex: 1; background: #fff; color: #6f4c2f; border-radius: 12rpx; }
.notice { background: #fff8e8; color: #8a5a3c; border: 1rpx solid #ead7a8; padding: 18rpx; border-radius: 12rpx; font-size: 24rpx; margin-bottom: 20rpx; }
.commission-overview { display: flex; align-items: center; justify-content: space-between; gap: 20rpx; padding: 24rpx; border-radius: 16rpx; background: #755331; color: #fff; }
.commission-overview > view:first-child { display: flex; flex-direction: column; gap: 8rpx; }
.commission-label { color: #eedcc4; font-size: 21rpx; }.commission-total { font-size: 38rpx; font-weight: 700; }.commission-split { max-width: 320rpx; color: #f0dfca; font-size: 21rpx; line-height: 1.55; text-align: right; }
.section { display: flex; flex-direction: column; gap: 18rpx; }
.metrics { display: grid; grid-template-columns: 1fr 1fr; gap: 18rpx; }
.metric, .panel, .list-card, .detail-box, .result-box { background: #fff; border-radius: 16rpx; padding: 24rpx; box-shadow: 0 10rpx 26rpx rgba(70, 45, 30, .06); }
.metric-value { color: #6f4c2f; font-size: 42rpx; font-weight: 700; }
.metric-title, .panel-title, .strong { font-size: 30rpx; font-weight: 700; color: #2d2434; }
.metric-desc, .muted { color: #786b73; font-size: 24rpx; margin-top: 8rpx; }
.panel-head, .row-main, .compact-row { display: flex; align-items: center; justify-content: space-between; gap: 18rpx; }
.business-tools { margin-top: 4rpx; }
.business-tools-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16rpx; margin: 0 4rpx 16rpx; }
.business-tools-note { color: #9b8a7d; font-size: 21rpx; text-align: right; }
.tool-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18rpx; }
.tool-card { min-height: 150rpx; background: #fff; border: 1rpx solid #eee3d4; border-radius: 16rpx; padding: 22rpx; box-shadow: 0 10rpx 26rpx rgba(70, 45, 30, .06); display: grid; grid-template-columns: 54rpx 1fr 20rpx; align-items: center; gap: 14rpx; }
.tool-icon { width: 54rpx; height: 54rpx; border-radius: 14rpx; background: #f2e7d4; color: #7a5631; display: flex; align-items: center; justify-content: center; font-size: 25rpx; font-weight: 700; }
.tool-copy { min-width: 0; }
.tool-title { color: #2d2434; font-size: 27rpx; font-weight: 700; line-height: 1.35; }
.tool-desc { color: #88796f; font-size: 22rpx; line-height: 1.45; margin-top: 8rpx; }
.tool-arrow { color: #a47c4c; font-size: 38rpx; line-height: 1; }
.button-row, .filter-tabs, .search-row, .manual-code { display: flex; gap: 14rpx; margin-top: 18rpx; }
.button-row button, .search-row button, .manual-code button { flex: 1; background: #fff7e9; color: #6f4c2f; border-radius: 10rpx; }
.filter-tabs { overflow-x: auto; }
.tab { flex: 0 0 auto; padding: 12rpx 20rpx; border-radius: 999rpx; background: #fff7e9; color: #8a725c; font-size: 24rpx; }
.tab.active { background: #6f4c2f; color: #fff; }
.status { color: #6f4c2f; font-weight: 700; font-size: 24rpx; }
.price { color: #9a4f2f; font-size: 32rpx; font-weight: 700; }
.list-card { margin-top: 18rpx; }
.detail-box, .result-box { margin-top: 18rpx; background: #fffaf2; box-shadow: none; }
.detail-line { color: #5e5147; font-size: 25rpx; margin-top: 10rpx; }
.membership-application { border: 1rpx solid #eee1cf; box-shadow: none; }
.application-summary { margin-top: 18rpx; padding: 18rpx; border-radius: 12rpx; background: #fffaf2; color: #5e5147; font-size: 24rpx; line-height: 1.75; }
.membership-actions { margin-top: 18rpx; }
.membership-actions .button-row { align-items: stretch; }
.membership-actions .membership-action {
	flex: 1;
	min-width: 0;
	height: 64rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	background: #fff7e9;
	color: #6f4c2f;
	border-radius: 10rpx;
	font-size: 28rpx;
	line-height: 1;
}
.membership-actions .membership-action-active { opacity: 0.78; }
.membership-actions .button-row .primary { background: #6f4c2f; color: #fff; }
.inline-empty { margin-top: 18rpx; color: #786b73; background: #fffaf2; border-radius: 12rpx; padding: 22rpx; text-align: center; }
.empty { margin-top: 80rpx; text-align: center; color: #786b73; background: #fff; border-radius: 16rpx; padding: 34rpx; }
.error { color: #a74e4e; }
input { background: #fffaf2; border-radius: 10rpx; padding: 0 20rpx; height: 64rpx; line-height: 64rpx; font-size: 26rpx; flex: 1; }
.token-input { margin-top: 16rpx; width: auto; }
.nav { position: fixed; left: 0; right: 0; bottom: 0; height: 106rpx; background: #fffaf4; border-top: 1rpx solid #eadfce; display: flex; z-index: 30; }
.nav-item { min-width: 0; flex: 1; display: flex; align-items: center; justify-content: center; color: #786b73; font-size: 23rpx; white-space: nowrap; }
.nav-item.active { color: #6f4c2f; font-weight: 700; }
</style>
