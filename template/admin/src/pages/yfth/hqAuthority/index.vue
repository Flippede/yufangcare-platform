<template>
  <div class="hq-authority-management">
    <el-alert title="总部可按“C2 → C1 → 门店”查看用户上级。撤销上级后，该用户变为未绑定门店状态，可重新扫门店码绑定；本页不提供撤销门店功能。" type="info" :closable="false" />
    <el-tabs v-model="activeTab" @tab-click="loadCurrent">
      <el-tab-pane label="用户关系层级" name="hierarchy">
        <div class="toolbar">
          <el-input v-model="hierarchyQuery.keyword" size="small" clearable placeholder="UID / 昵称 / 手机号" />
          <el-input v-model="hierarchyQuery.store_id" size="small" clearable placeholder="门店 ID" />
          <el-select v-model="hierarchyQuery.user_type" size="small" clearable placeholder="用户类型">
            <el-option label="C1 会员" value="c1" />
            <el-option label="C2 普通用户" value="c2" />
            <el-option label="其他普通用户" value="ordinary" />
          </el-select>
          <el-select v-model="hierarchyQuery.binding_status" size="small" clearable placeholder="绑定状态">
            <el-option label="已绑定门店" value="bound" />
            <el-option label="未绑定门店" value="unbound" />
          </el-select>
          <el-button size="small" type="primary" icon="el-icon-search" @click="loadHierarchy(true)">查询</el-button>
        </div>
        <el-table v-loading="loading" :data="hierarchy" size="small" border>
          <el-table-column prop="uid" label="UID" width="85" />
          <el-table-column label="用户" min-width="170">
            <template slot-scope="{ row }">
              <div>{{ row.customer.nickname || '-' }}</div>
              <small>{{ row.customer.phone_masked || '-' }}</small>
            </template>
          </el-table-column>
          <el-table-column label="身份" width="125">
            <template slot-scope="{ row }"><el-tag size="mini" :type="row.user_type === 'c1' ? 'success' : (row.user_type === 'c2' ? 'warning' : 'info')">{{ row.user_type_label }}</el-tag></template>
          </el-table-column>
          <el-table-column label="关系层级" min-width="290">
            <template slot-scope="{ row }">
              <div v-if="row.user_type === 'c2'" class="hierarchy-chain">
                <span class="node current">C2 {{ row.uid }}</span><i class="el-icon-right" />
                <span class="node">C1 {{ row.referrer_uid }} · {{ (row.referrer && row.referrer.nickname) || '-' }}</span><i class="el-icon-right" />
                <span class="node store">{{ (row.store && row.store.name) || '未绑定门店' }}</span>
              </div>
              <div v-else class="hierarchy-chain">
                <span class="node current">{{ row.user_type === 'c1' ? 'C1 会员' : '普通用户' }} {{ row.uid }}</span><i class="el-icon-right" />
                <span class="node store">{{ (row.store && row.store.name) || '未绑定门店' }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="direct_parent_label" label="直接上级" min-width="150" />
          <el-table-column label="绑定状态" width="125">
            <template slot-scope="{ row }"><el-tag size="mini" :type="row.binding_status === 'bound' ? 'success' : 'info'">{{ row.binding_status_label }}</el-tag></template>
          </el-table-column>
          <el-table-column prop="active_child_count" label="下级数" width="75" />
          <el-table-column label="操作" width="120" fixed="right">
            <template slot-scope="{ row }">
              <el-button v-if="canRevokeParent && row.can_revoke_parent" type="text" class="danger-action" @click="revokeParent(row)">撤销上级</el-button>
              <el-tooltip v-else-if="canRevokeParent && row.revoke_block_reason" :content="row.revoke_block_reason" placement="left">
                <span class="disabled-action">不可撤销</span>
              </el-tooltip>
              <span v-else>-</span>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination class="pager" layout="total, prev, pager, next" :total="hierarchyTotal" :page-size="hierarchyQuery.limit" :current-page.sync="hierarchyQuery.page" @current-change="loadHierarchy" />
      </el-tab-pane>

      <el-tab-pane label="门店与合伙人" name="stores">
        <div class="toolbar">
          <el-input v-model="storeQuery.keyword" size="small" clearable placeholder="门店 ID / 名称" />
          <el-select v-model="storeQuery.status" size="small" clearable placeholder="门店状态">
            <el-option label="营业中" value="active" />
            <el-option label="已停用" value="disabled" />
          </el-select>
          <el-button size="small" type="primary" icon="el-icon-search" @click="loadStores(true)">查询</el-button>
        </div>
        <el-table v-loading="loading" :data="stores" size="small" border>
          <el-table-column prop="store_id" label="门店 ID" width="90" />
          <el-table-column prop="store.name" label="门店" min-width="170" />
          <el-table-column prop="store.district" label="地址" min-width="200" />
          <el-table-column label="状态" width="90"><template slot-scope="{ row }"><el-tag size="mini" :type="row.status === 'active' ? 'success' : 'info'">{{ row.status_label }}</el-tag></template></el-table-column>
          <el-table-column prop="partner_count" label="合伙人数" width="100" />
          <el-table-column label="加盟该门店的合伙人" min-width="300">
            <template slot-scope="{ row }">
              <div v-if="row.partners.length" class="partner-list">
                <div v-for="item in row.partners" :key="item.uid" class="partner-item">
                  <strong>{{ item.uid }} · {{ item.partner.nickname || '-' }}</strong>
                  <small>{{ item.partner.phone_masked || '-' }} · {{ item.role_labels.join(' / ') }}</small>
                </div>
              </div>
              <span v-else class="muted">暂无有效加盟合伙人</span>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination class="pager" layout="total, prev, pager, next" :total="storeTotal" :page-size="storeQuery.limit" :current-page.sync="storeQuery.page" @current-change="loadStores" />
      </el-tab-pane>

      <el-tab-pane label="客户归属" name="attribution">
        <div class="toolbar">
          <el-input v-model="attributionQuery.uid" size="small" clearable placeholder="用户 UID" />
          <el-input v-model="attributionQuery.store_id" size="small" clearable placeholder="门店 ID" />
          <el-select v-model="attributionQuery.status" size="small" clearable placeholder="归属状态">
            <el-option label="有效" value="active" />
            <el-option label="暂停" value="paused" />
            <el-option label="未归属" value="unassigned" />
            <el-option label="已关闭" value="closed" />
          </el-select>
          <el-date-picker v-model="attributionDates" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" />
          <el-button size="small" type="primary" icon="el-icon-search" @click="loadAttributions(true)">查询</el-button>
        </div>
        <el-table v-loading="loading" :data="attributions" size="small" border>
          <el-table-column prop="uid" label="UID" width="90" />
          <el-table-column label="客户" min-width="190">
            <template slot-scope="{ row }">
              <div>{{ row.customer.nickname || '-' }}</div>
              <small>{{ row.customer.phone_masked || '-' }}</small>
            </template>
          </el-table-column>
          <el-table-column prop="store.name" label="归属门店" min-width="150" />
          <el-table-column prop="attribution_status_label" label="状态" width="150" />
          <el-table-column prop="source_label" label="来源" width="130" />
          <el-table-column label="一级推荐" width="100">
            <template slot-scope="{ row }">{{ row.has_active_referral ? '存在' : '无' }}</template>
          </el-table-column>
          <el-table-column label="数据状态" width="160">
            <template slot-scope="{ row }">
              <el-tag :type="row.data_inconsistent ? 'danger' : 'success'" size="mini">{{ row.data_inconsistent ? row.data_inconsistent_label : '正常' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="170" fixed="right">
            <template slot-scope="{ row }">
              <el-button type="text" @click="openAttribution(row)">详情</el-button>
              <el-button v-if="canAuditAttribution" type="text" @click="openAttributionEvents(row)">事件</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination class="pager" layout="total, prev, pager, next" :total="attributionTotal" :page-size="attributionQuery.limit" :current-page.sync="attributionQuery.page" @current-change="loadAttributions" />
      </el-tab-pane>

      <el-tab-pane label="一级推荐关系" name="referral">
        <div class="toolbar">
          <el-input v-model="referralQuery.referrer_uid" size="small" clearable placeholder="推荐人 UID" />
          <el-input v-model="referralQuery.referred_uid" size="small" clearable placeholder="被推荐人 UID" />
          <el-input v-model="referralQuery.store_id" size="small" clearable placeholder="门店 ID" />
          <el-select v-model="referralQuery.status" size="small" clearable placeholder="关系状态">
            <el-option label="有效" value="active" />
            <el-option label="暂停" value="paused" />
            <el-option label="关闭" value="closed" />
            <el-option label="失效" value="invalid" />
          </el-select>
          <el-button size="small" type="primary" icon="el-icon-search" @click="loadReferrals(true)">查询</el-button>
        </div>
        <el-table v-loading="loading" :data="referrals" size="small" border>
          <el-table-column prop="relation_display" label="关系标识" width="130" />
          <el-table-column label="推荐人" min-width="170">
            <template slot-scope="{ row }">{{ row.referrer_uid }} · {{ row.referrer.nickname || '-' }}</template>
          </el-table-column>
          <el-table-column label="被推荐人" min-width="170">
            <template slot-scope="{ row }">{{ row.referred_uid }} · {{ row.referred.nickname || '-' }}</template>
          </el-table-column>
          <el-table-column prop="store.name" label="门店" min-width="140" />
          <el-table-column prop="relation_status_label" label="状态" width="120" />
          <el-table-column prop="source_label" label="来源" width="130" />
          <el-table-column label="操作" width="170" fixed="right">
            <template slot-scope="{ row }">
              <el-button type="text" @click="openReferral(row)">详情</el-button>
              <el-button v-if="canAuditReferral" type="text" @click="openReferralEvents(row)">事件</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination class="pager" layout="total, prev, pager, next" :total="referralTotal" :page-size="referralQuery.limit" :current-page.sync="referralQuery.page" @current-change="loadReferrals" />
      </el-tab-pane>
    </el-tabs>

    <el-drawer title="只读详情" :visible.sync="detailVisible" size="48%">
      <div class="drawer-body" v-if="detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item v-for="item in detailFields" :key="item.label" :label="item.label">{{ item.value }}</el-descriptions-item>
        </el-descriptions>
      </div>
    </el-drawer>

    <el-drawer title="权威事件时间线" :visible.sync="eventVisible" size="55%" @closed="events = []">
      <div class="drawer-body">
        <el-timeline v-if="events.length">
          <el-timeline-item v-for="item in events" :key="item.event_no" :timestamp="formatTime(item.event_time)" placement="top">
            <strong>{{ item.event_type }}</strong>
            <div class="event-line">{{ item.before_status || '-' }} → {{ item.after_status || '-' }}</div>
            <div class="event-line">来源：{{ item.source_type }} / {{ item.source_id || '-' }}</div>
            <div class="event-line">操作：{{ item.operator_role_code || '-' }} · {{ item.operator_uid || 0 }}</div>
          </el-timeline-item>
        </el-timeline>
        <el-empty v-else description="暂无权威事件" />
      </div>
    </el-drawer>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import {
  yfthHqAuthorityAttributionDetail,
  yfthHqAuthorityAttributionEvents,
  yfthHqAuthorityAttributionList,
  yfthHqAuthorityReferralDetail,
  yfthHqAuthorityReferralEvents,
  yfthHqAuthorityReferralList,
  yfthRelationshipRevokeParent,
  yfthRelationshipStoreHierarchy,
  yfthRelationshipUserHierarchy,
} from '@/api/yfth';
const { createRequestGeneration } = require('./requestGeneration');

export default {
  name: 'YfthHqAuthorityRead',
  data() {
    return {
      activeTab: 'hierarchy',
      loading: false,
      hierarchyQuery: { keyword: '', store_id: '', user_type: '', binding_status: '', page: 1, limit: 20 },
      hierarchy: [],
      hierarchyTotal: 0,
      storeQuery: { keyword: '', status: '', page: 1, limit: 20 },
      stores: [],
      storeTotal: 0,
      attributionQuery: { uid: '', store_id: '', status: '', page: 1, limit: 20 },
      attributionDates: [],
      attributions: [],
      attributionTotal: 0,
      referralQuery: { referrer_uid: '', referred_uid: '', store_id: '', status: '', page: 1, limit: 20 },
      referrals: [],
      referralTotal: 0,
      detailVisible: false,
      detail: null,
      detailType: '',
      eventVisible: false,
      events: [],
    };
  },
  computed: {
    ...mapState('userInfo', ['userInfo', 'uniqueAuth']),
    isSuperAdmin() {
      return Number((this.userInfo && this.userInfo.level) || -1) === 0;
    },
    canAuditAttribution() {
      return this.isSuperAdmin || (this.uniqueAuth || []).includes('yfth-hq-authority-attribution-audit');
    },
    canAuditReferral() {
      return this.isSuperAdmin || (this.uniqueAuth || []).includes('yfth-hq-authority-referral-audit');
    },
    canRevokeParent() {
      return this.isSuperAdmin || (this.uniqueAuth || []).includes('yfth-hq-relationship-parent-revoke');
    },
    detailFields() {
      if (!this.detail) return [];
      if (this.detailType === 'attribution') {
        return [
          { label: '用户 UID', value: this.detail.uid },
          { label: '客户', value: `${this.detail.customer.nickname || '-'} ${this.detail.customer.phone_masked || ''}` },
          { label: '门店', value: (this.detail.store && this.detail.store.name) || '-' },
          { label: '状态', value: this.detail.attribution_status_label },
          { label: '来源', value: this.detail.source_label },
          { label: '一级推荐', value: this.detail.has_active_referral ? '存在' : '无' },
          { label: '绑定时间', value: this.formatTime(this.detail.bound_at) },
          { label: '数据状态', value: this.detail.data_inconsistent ? this.detail.data_inconsistent_label : '正常' },
        ];
      }
      return [
        { label: '关系标识', value: this.detail.relation_display },
        { label: '推荐人', value: `${this.detail.referrer_uid} · ${this.detail.referrer.nickname || '-'}` },
        { label: '被推荐人', value: `${this.detail.referred_uid} · ${this.detail.referred.nickname || '-'}` },
        { label: '门店', value: (this.detail.store && this.detail.store.name) || '-' },
        { label: '状态', value: this.detail.relation_status_label },
        { label: '来源', value: this.detail.source_label },
        { label: '开始时间', value: this.formatTime(this.detail.started_at) },
        { label: '结束说明', value: this.detail.close_label || '-' },
      ];
    },
  },
  watch: {
    canAuditAttribution(value) {
      if (!value) this.clearAuditState('attribution');
    },
    canAuditReferral(value) {
      if (!value) this.clearAuditState('referral');
    },
  },
  created() {
    this.requestGeneration = createRequestGeneration();
  },
  mounted() {
    this.loadHierarchy(true);
  },
  beforeDestroy() {
    this.requestGeneration.destroy();
    this.clearSensitiveState();
  },
  methods: {
    loadCurrent() {
      this.requestGeneration.invalidateAll();
      this.clearSensitiveState();
      if (this.activeTab === 'hierarchy') this.loadHierarchy();
      else if (this.activeTab === 'stores') this.loadStores();
      else if (this.activeTab === 'attribution') this.loadAttributions();
      else this.loadReferrals();
    },
    loadHierarchy(reset) {
      if (reset === true) this.hierarchyQuery.page = 1;
      const params = this.compactQuery(this.hierarchyQuery);
      const identity = `hierarchy:${JSON.stringify(params)}`;
      const ticket = this.requestGeneration.next('hierarchy-list', identity);
      this.hierarchy = [];
      this.hierarchyTotal = 0;
      this.loading = true;
      yfthRelationshipUserHierarchy(params).then((res) => {
        if (!this.requestGeneration.isCurrent(ticket, identity)) return;
        this.hierarchy = (res.data && res.data.list) || [];
        this.hierarchyTotal = Number((res.data && res.data.count) || 0);
      }).catch(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.failClosed();
      }).finally(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.loading = false;
      });
    },
    loadStores(reset) {
      if (reset === true) this.storeQuery.page = 1;
      const params = this.compactQuery(this.storeQuery);
      const identity = `stores:${JSON.stringify(params)}`;
      const ticket = this.requestGeneration.next('store-list', identity);
      this.stores = [];
      this.storeTotal = 0;
      this.loading = true;
      yfthRelationshipStoreHierarchy(params).then((res) => {
        if (!this.requestGeneration.isCurrent(ticket, identity)) return;
        this.stores = (res.data && res.data.list) || [];
        this.storeTotal = Number((res.data && res.data.count) || 0);
      }).catch(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.failClosed();
      }).finally(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.loading = false;
      });
    },
    loadAttributions(reset) {
      if (reset === true) this.attributionQuery.page = 1;
      const params = this.compactQuery(Object.assign({}, this.attributionQuery, {
        start_date: this.attributionDates[0] || '',
        end_date: this.attributionDates[1] || '',
      }));
      const identity = `attribution:${JSON.stringify(params)}`;
      const ticket = this.requestGeneration.next('attribution-list', identity);
      this.attributions = [];
      this.attributionTotal = 0;
      this.loading = true;
      yfthHqAuthorityAttributionList(params).then((res) => {
        if (!this.requestGeneration.isCurrent(ticket, identity)) return;
        this.attributions = (res.data && res.data.list) || [];
        this.attributionTotal = Number((res.data && res.data.count) || 0);
      }).catch(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.failClosed();
      }).finally(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.loading = false;
      });
    },
    loadReferrals(reset) {
      if (reset === true) this.referralQuery.page = 1;
      const params = this.compactQuery(this.referralQuery);
      const identity = `referral:${JSON.stringify(params)}`;
      const ticket = this.requestGeneration.next('referral-list', identity);
      this.referrals = [];
      this.referralTotal = 0;
      this.loading = true;
      yfthHqAuthorityReferralList(params).then((res) => {
        if (!this.requestGeneration.isCurrent(ticket, identity)) return;
        this.referrals = (res.data && res.data.list) || [];
        this.referralTotal = Number((res.data && res.data.count) || 0);
      }).catch(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.failClosed();
      }).finally(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.loading = false;
      });
    },
    openAttribution(row) {
      this.closeDetail();
      const identity = `attribution:${row.attribution_id}`;
      const ticket = this.requestGeneration.next('detail', identity);
      yfthHqAuthorityAttributionDetail(row.attribution_id).then((res) => {
        if (!this.requestGeneration.isCurrent(ticket, identity)) return;
        this.detail = res.data.attribution;
        this.detailType = 'attribution';
        this.detailVisible = true;
      }).catch(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.failClosed();
      });
    },
    openReferral(row) {
      this.closeDetail();
      const identity = `referral:${row.referral_id}`;
      const ticket = this.requestGeneration.next('detail', identity);
      yfthHqAuthorityReferralDetail(row.referral_id).then((res) => {
        if (!this.requestGeneration.isCurrent(ticket, identity)) return;
        this.detail = res.data.referral;
        this.detailType = 'referral';
        this.detailVisible = true;
      }).catch(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.failClosed();
      });
    },
    openAttributionEvents(row) {
      this.closeEvents();
      if (!this.canAuditAttribution) return;
      const identity = `attribution:${row.attribution_id}`;
      const ticket = this.requestGeneration.next('events', identity);
      yfthHqAuthorityAttributionEvents(row.attribution_id).then((res) => {
        if (!this.requestGeneration.isCurrent(ticket, identity) || !this.canAuditAttribution) return;
        this.events = (res.data && res.data.list) || [];
        this.eventVisible = true;
      }).catch(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.failClosed();
      });
    },
    openReferralEvents(row) {
      this.closeEvents();
      if (!this.canAuditReferral) return;
      const identity = `referral:${row.referral_id}`;
      const ticket = this.requestGeneration.next('events', identity);
      yfthHqAuthorityReferralEvents(row.referral_id).then((res) => {
        if (!this.requestGeneration.isCurrent(ticket, identity) || !this.canAuditReferral) return;
        this.events = (res.data && res.data.list) || [];
        this.eventVisible = true;
      }).catch(() => {
        if (this.requestGeneration.isCurrent(ticket, identity)) this.failClosed();
      });
    },
    revokeParent(row) {
      if (!this.canRevokeParent || !row.can_revoke_parent || !row.attribution_id) return;
      const chain = row.user_type === 'c2'
        ? `C2 ${row.uid} → C1 ${row.referrer_uid} → ${(row.store && row.store.name) || '门店'}`
        : `${row.user_type === 'c1' ? 'C1 会员' : '用户'} ${row.uid} → ${(row.store && row.store.name) || '门店'}`;
      this.$prompt(
        `即将撤销：${chain}。撤销后该用户为“未绑定门店”，需重新扫门店码绑定。请填写撤销原因：`,
        '撤销上级关系',
        {
          confirmButtonText: '确认撤销',
          cancelButtonText: '取消',
          inputPlaceholder: '必填，将记入审计日志',
          inputValidator: (value) => {
            const text = String(value || '').trim();
            if (!text) return '请填写撤销原因';
            if (text.length > 255) return '撤销原因不能超过 255 个字';
            return true;
          },
          type: 'warning',
        }
      ).then(({ value }) => {
        const nonce = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
        const requestId = `hq-parent-revoke:${row.attribution_id}:${nonce}`.slice(0, 64);
        this.loading = true;
        return yfthRelationshipRevokeParent(row.attribution_id, {
          reason: String(value || '').trim(),
          request_id: requestId,
          idempotency_key: requestId,
        }).then(() => {
          this.$message.success('上级关系已撤销，用户当前未绑定门店');
          this.loadHierarchy();
        }).finally(() => {
          this.loading = false;
        });
      }).catch((error) => {
        if (error !== 'cancel' && error !== 'close') this.loadHierarchy();
      });
    },
    compactQuery(query) {
      return Object.keys(query).reduce((result, key) => {
        if (query[key] !== '' && query[key] !== null && query[key] !== undefined) result[key] = query[key];
        return result;
      }, {});
    },
    closeDetail() {
      this.requestGeneration.invalidate('detail');
      this.detailVisible = false;
      this.detail = null;
      this.detailType = '';
    },
    closeEvents() {
      this.requestGeneration.invalidate('events');
      this.eventVisible = false;
      this.events = [];
    },
    clearAuditState(type) {
      this.closeEvents();
    },
    clearSensitiveState() {
      this.loading = false;
      this.hierarchy = [];
      this.hierarchyTotal = 0;
      this.stores = [];
      this.storeTotal = 0;
      this.attributions = [];
      this.attributionTotal = 0;
      this.referrals = [];
      this.referralTotal = 0;
      this.detailVisible = false;
      this.detail = null;
      this.detailType = '';
      this.eventVisible = false;
      this.events = [];
    },
    failClosed() {
      this.requestGeneration.invalidateAll();
      this.clearSensitiveState();
    },
    formatTime(value) {
      const timestamp = Number(value || 0);
      if (!timestamp) return '-';
      return new Date(timestamp * 1000).toLocaleString('zh-CN', { hour12: false });
    },
  },
};
</script>

<style scoped>
.hq-authority-management { padding: 16px; }
.toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 14px 0 12px; }
.toolbar .el-input, .toolbar .el-select { width: 150px; }
.toolbar .el-date-editor { width: 260px; }
.pager { margin-top: 14px; text-align: right; }
.drawer-body { padding: 0 24px 24px; }
.event-line { color: #606266; margin-top: 5px; }
.hierarchy-chain { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
.hierarchy-chain .node { padding: 3px 7px; border-radius: 4px; background: #f5f7fa; color: #606266; }
.hierarchy-chain .current { background: #ecf5ff; color: #409eff; }
.hierarchy-chain .store { background: #f0f9eb; color: #67c23a; }
.partner-list { display: flex; flex-direction: column; gap: 8px; padding: 4px 0; }
.partner-item { display: flex; flex-direction: column; }
.danger-action { color: #f56c6c; }
.disabled-action, .muted { color: #909399; font-size: 12px; }
small { color: #909399; }
</style>
