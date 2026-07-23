<template>
  <div class="partner-page">
    <div class="summary-grid">
      <div v-for="item in rankCards" :key="item.code" class="summary-item">
        <span>{{ item.name }}</span><strong>{{ item.count }}</strong>
      </div>
      <div class="summary-item warning"><span>待确认收益</span><strong>{{ dashboard.pending_rewards || 0 }}</strong></div>
      <div class="summary-item"><span>有效开店</span><strong>{{ dashboard.valid_openings || 0 }}</strong></div>
    </div>

    <el-alert :title="dashboard.disclaimer || '招商收益候选仅记录业务事实，不代表平台自动打款。'" type="warning" :closable="false" />

    <el-card shadow="never" class="content-card">
      <el-tabs v-model="tab" @tab-click="loadTab">
        <el-tab-pane label="合伙人管理" name="partners">
          <div class="toolbar">
            <el-input v-model.trim="partnerQuery.keyword" clearable placeholder="昵称、账号、手机号、门店或 UID" @keyup.enter.native="loadPartners(true)" />
            <el-select v-model="partnerQuery.rank_code" clearable placeholder="职级">
              <el-option v-for="item in rankOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-select v-model="partnerQuery.status" clearable placeholder="状态">
              <el-option label="有效" value="active" /><el-option label="暂停" value="paused" /><el-option label="退出" value="exited" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="loadPartners(true)">查询</el-button>
          </div>
          <el-table v-loading="loading" :data="partners" border size="small">
            <el-table-column prop="uid" label="UID" width="80" />
            <el-table-column label="合伙人" min-width="180"><template slot-scope="{ row }"><b>{{ row.nickname || row.account || '-' }}</b><div class="muted">{{ row.phone_masked }}</div></template></el-table-column>
            <el-table-column prop="rank_name" label="招商职级" width="130" />
            <el-table-column prop="store_name" label="主门店" min-width="170" />
            <el-table-column prop="status" label="状态" width="90" />
            <el-table-column label="来源" min-width="150"><template slot-scope="{ row }">{{ sourceName(row.source_type) }}</template></el-table-column>
            <el-table-column label="操作" width="240" fixed="right"><template slot-scope="{ row }">
              <el-button type="text" @click="showPartner(row)">详情</el-button>
              <el-button type="text" @click="changeRank(row)">职级/状态</el-button>
              <el-button type="text" @click="changeParent(row)">调整上级</el-button>
            </template></el-table-column>
          </el-table>
          <el-pagination class="pager" layout="total,prev,pager,next" :total="partnerTotal" :page-size="partnerQuery.limit" :current-page.sync="partnerQuery.page" @current-change="loadPartners" />
        </el-tab-pane>

        <el-tab-pane label="开店业绩" name="performance">
          <el-table v-loading="loading" :data="performances" border size="small">
            <el-table-column prop="performance_no" label="业绩编号" min-width="190" />
            <el-table-column prop="application_id" label="申请 ID" width="100" />
            <el-table-column prop="store_name" label="正式门店" min-width="160" />
            <el-table-column prop="direct_partner_uid" label="直接招商人 UID" width="130" />
            <el-table-column prop="order_amount" label="金额快照" width="120" />
            <el-table-column prop="bottle_count" label="瓶数快照" width="100" />
            <el-table-column prop="status" label="状态" width="90" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="采购分润" name="procurementProfit">
          <el-alert title="店长采购单收货入库后，系统按下单时冻结的五级比例和上下级快照生成分润台账；不代表已自动支付。" type="info" :closable="false" />
          <el-table v-loading="loading" :data="procurementProfits" border size="small" class="governance-table">
            <el-table-column prop="purchase_no" label="采购单号" min-width="180" />
            <el-table-column prop="store_name" label="采购门店" min-width="150" />
            <el-table-column prop="nickname" label="收益合伙人" min-width="130" />
            <el-table-column prop="rank_code" label="职级快照" width="150" />
            <el-table-column label="采购金额" width="120"><template slot-scope="{ row }">{{ moneyCent(row.base_amount_cent) }}</template></el-table-column>
            <el-table-column label="比例" width="90"><template slot-scope="{ row }">{{ Number(row.rate_bps || 0) / 100 }}%</template></el-table-column>
            <el-table-column label="分润金额" width="120"><template slot-scope="{ row }">{{ moneyCent(row.amount_cent) }}</template></el-table-column>
            <el-table-column prop="status" label="状态" width="100" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="开店服务奖励" name="openingReward">
          <el-alert title="加盟费线下处理。当前仅县级合伙人按有效开店获得服务奖励，上级职级接口保留且默认金额为 0。" type="warning" :closable="false" />
          <el-table v-loading="loading" :data="openingRewards" border size="small" class="governance-table">
            <el-table-column prop="application_id" label="加盟申请 ID" width="120" />
            <el-table-column prop="store_name" label="正式门店" min-width="160" />
            <el-table-column prop="nickname" label="县级合伙人" min-width="140" />
            <el-table-column label="服务奖励" width="130"><template slot-scope="{ row }">{{ moneyCent(row.amount_cent) }}</template></el-table-column>
            <el-table-column prop="status" label="状态" width="100" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="平台加权分红" name="dividends">
          <div class="toolbar">
            <el-date-picker v-model="dividendPeriod" type="month" value-format="yyyy-MM" placeholder="选择月份" />
            <el-button type="primary" @click="generateDividend">生成分红批次</el-button>
          </div>
          <el-alert title="平台董事采购分润和平台总业绩加权分红分别计算；采购分润比例可设为 0。批次仅形成台账，不代表已支付。" type="info" :closable="false" />
          <el-table v-loading="loading" :data="dividends" border size="small" class="governance-table">
            <el-table-column prop="period_key" label="月份" width="100" />
            <el-table-column label="平台采购业绩" width="140"><template slot-scope="{ row }">{{ moneyCent(row.performance_cent) }}</template></el-table-column>
            <el-table-column label="分红池比例" width="110"><template slot-scope="{ row }">{{ Number(row.pool_bps || 0) / 100 }}%</template></el-table-column>
            <el-table-column label="分红池" width="120"><template slot-scope="{ row }">{{ moneyCent(row.pool_cent) }}</template></el-table-column>
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column label="董事分配" min-width="260"><template slot-scope="{ row }"><span v-for="item in row.items" :key="item.id" class="rule-pill">{{ item.nickname || item.beneficiary_uid }} {{ moneyCent(item.amount_cent) }}</span></template></el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="收益与线下结算" name="rewards">
          <div class="toolbar"><el-select v-model="rewardQuery.status" clearable placeholder="状态" @change="loadRewards(true)"><el-option v-for="s in ['pending','confirmed','settled','cancelled']" :key="s" :label="s" :value="s" /></el-select></div>
          <el-table v-loading="loading" :data="rewards" border size="small">
            <el-table-column prop="candidate_no" label="候选编号" min-width="195" />
            <el-table-column prop="nickname" label="收益人" min-width="130" />
            <el-table-column prop="rank_name_snapshot" label="职级快照" width="120" />
            <el-table-column prop="store_name" label="来源门店" min-width="150" />
            <el-table-column label="计算快照" width="175"><template slot-scope="{ row }">{{ row.bottle_count }} 瓶 × {{ row.reward_per_bottle }} 元</template></el-table-column>
            <el-table-column prop="amount" label="候选金额" width="110" />
            <el-table-column prop="status" label="状态" width="95" />
            <el-table-column label="操作" width="210" fixed="right"><template slot-scope="{ row }">
              <el-button v-if="row.status === 'pending'" type="text" @click="rewardAction(row, 'confirm')">确认</el-button>
              <el-button v-if="['pending','confirmed'].includes(row.status)" type="text" class="danger" @click="rewardAction(row, 'cancel')">取消</el-button>
              <el-button v-if="row.status === 'confirmed'" type="text" @click="settle(row)">记录线下结算</el-button>
            </template></el-table-column>
          </el-table>
          <el-pagination class="pager" layout="total,prev,pager,next" :total="rewardTotal" :page-size="rewardQuery.limit" :current-page.sync="rewardQuery.page" @current-change="loadRewards" />
        </el-tab-pane>

        <el-tab-pane :key="'rules-pane-' + rulesRenderKey" label="职级规则" name="rules">
          <div class="toolbar"><el-button type="primary" icon="el-icon-plus" @click="openRule">复制当前规则</el-button></div>
          <div :key="'rules-' + rulesRenderKey" v-loading="rulesLoading" class="partner-rule-list">
            <div v-for="rule in partnerRules" :key="rule.id" class="partner-rule-card">
              <div class="partner-rule-head">
                <div>
                  <strong>{{ rule.rule_no }}</strong>
                  <span>版本 {{ rule.version_no }}</span>
                  <span>{{ rule.status === 'published' ? '已发布' : '草稿' }}</span>
                </div>
                <el-button v-if="rule.status === 'draft'" type="text" @click="publishRule(rule)">发布</el-button>
              </div>
              <div class="partner-rule-summary">
                <span>单笔金额：{{ rule.order_amount }}</span>
                <span>瓶数：{{ rule.bottle_count }}</span>
                <span>平台董事加权：{{ Number(rule.platform_dividend_bps || 0) / 100 }}%</span>
              </div>
              <div class="partner-rule-ranks">
                <div v-for="rank in rule.rank_rules" :key="rank.rank_code" class="partner-rule-rank">
                  <strong>{{ rank.rank_name }}</strong>
                  <span>采购分润 {{ Number(rank.procurement_rate_bps || 0) / 100 }}%</span>
                  <span>开店服务奖励 {{ moneyCent(rank.opening_reward_amount_cent) }}</span>
                </div>
              </div>
            </div>
            <div v-if="!rulesLoading && partnerRules.length === 0" class="partner-rule-empty">暂无职级规则</div>
          </div>
        </el-tab-pane>

        <el-tab-pane label="保级预警" name="warnings">
          <el-table v-loading="loading" :data="warnings" border size="small">
            <el-table-column prop="partner_uid" label="合伙人 UID" width="110" /><el-table-column prop="rank_name" label="职级" width="120" />
            <el-table-column prop="period_key" label="考核周期" width="120" /><el-table-column prop="warning_type" label="预警类型" width="120" />
            <el-table-column prop="status" label="状态" width="90" /><el-table-column prop="resolution" label="人工处理" min-width="180" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="晋级申请" name="promotions">
          <el-table v-loading="loading" :data="promotions" border size="small">
            <el-table-column prop="application_no" label="申请编号" min-width="190" />
            <el-table-column label="申请人" min-width="150"><template slot-scope="{ row }">{{ row.nickname || row.account || row.partner_uid }}<div class="muted">{{ row.phone_masked }}</div></template></el-table-column>
            <el-table-column prop="from_rank_name" label="当前职级" width="120" />
            <el-table-column prop="target_rank_name" label="申请职级" width="120" />
            <el-table-column prop="apply_reason" label="申请说明" min-width="180" />
            <el-table-column prop="status" label="状态" width="90" />
            <el-table-column label="操作" width="150"><template slot-scope="{ row }"><template v-if="row.status === 'pending'"><el-button type="text" @click="reviewPromotion(row, 'approve')">通过</el-button><el-button type="text" class="danger" @click="reviewPromotion(row, 'reject')">驳回</el-button></template></template></el-table-column>
          </el-table>
        </el-tab-pane>
        <el-tab-pane label="开店商品额度" name="openingQuota">
          <el-alert title="仅直属招商合伙人的前 3 家有效门店获得商品额度：20% / 30% / 50%；第 4 家起不奖励。" type="info" :closable="false" />
          <el-table v-loading="loading" :data="openingQuotas" border size="small" class="governance-table">
            <el-table-column prop="application_id" label="申请 ID" width="100" />
            <el-table-column prop="partner_uid" label="直属合伙人 UID" width="130" />
            <el-table-column prop="nickname" label="合伙人" min-width="130" />
            <el-table-column prop="store_name" label="额度归属店铺" min-width="160" />
            <el-table-column prop="sequence_no" label="有效开店序号" width="110" />
            <el-table-column label="比例" width="90"><template slot-scope="{ row }">{{ row.ratio_bps / 100 }}%</template></el-table-column>
            <el-table-column label="商品额度" width="120"><template slot-scope="{ row }">{{ moneyCent(row.quota_amount_cent) }}</template></el-table-column>
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column label="操作" width="100"><template slot-scope="{ row }"><el-button v-if="row.status === 'pending'" type="text" @click="confirmOpeningQuota(row)">确认额度</el-button></template></el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="奖励事件治理" name="rewardEvents">
          <div class="toolbar">
            <el-select v-model="eventQuery.status" clearable placeholder="事件状态" @change="loadRewardEvents"><el-option v-for="s in ['pending','processing','failed','succeeded','ignored']" :key="s" :label="s" :value="s" /></el-select>
            <el-button type="primary" @click="retryRewardEvents">重试待处理/失败事件</el-button>
            <el-button @click="scanRewardConsistency">一致性检查</el-button>
          </div>
          <el-table v-loading="loading" :data="rewardEvents" border size="small">
            <el-table-column prop="event_no" label="事件编号" min-width="190" />
            <el-table-column prop="event_type" label="事件类型" width="170" />
            <el-table-column label="业务来源" min-width="170"><template slot-scope="{ row }">{{ row.source_type }} #{{ row.source_id }}</template></el-table-column>
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column prop="retry_count" label="重试次数" width="90" />
            <el-table-column prop="last_error" label="最近错误" min-width="260" show-overflow-tooltip />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="历史迁移异常" name="migrationIssues">
          <el-table v-loading="loading" :data="migrationIssues" border size="small">
            <el-table-column prop="issue_type" label="异常类型" width="180" />
            <el-table-column prop="uid" label="UID" width="90" />
            <el-table-column prop="store_id" label="店铺 ID" width="100" />
            <el-table-column prop="status" label="状态" width="90" />
            <el-table-column prop="resolution" label="处理说明" min-width="220" />
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <el-dialog title="招商合伙人详情" :visible.sync="detailVisible" width="760px">
      <div v-if="detail" class="detail-grid">
        <div><span>当前职级</span><b>{{ detail.profile.rank_name }}</b></div><div><span>状态</span><b>{{ detail.profile.status }}</b></div>
        <div><span>直接上级</span><b>{{ (detail.parent && (detail.parent.nickname || detail.parent.account)) || '总部直营' }}</b></div>
        <div><span>任职门店</span><b>{{ detail.profile.store_name || detail.profile.primary_store_id || '-' }}</b></div>
        <div><span>个人有效开店</span><b>{{ detail.performance.personal_openings }}</b></div><div><span>团队有效开店</span><b>{{ detail.performance.team_openings }}</b></div>
      </div>
      <h4>直属成员</h4><el-table :data="detail ? detail.direct_children : []" border size="mini"><el-table-column prop="partner_uid" label="UID" width="90" /><el-table-column prop="nickname" label="姓名" /><el-table-column prop="rank_code" label="职级" /></el-table>
      <h4>合伙人归属店铺（与店长权限独立）</h4>
      <el-table :data="detail ? detail.store_bindings : []" border size="mini"><el-table-column prop="store_id" label="店铺 ID" width="100" /><el-table-column prop="store_name" label="店铺" /><el-table-column prop="source_type" label="归属来源" width="160" /></el-table>
      <h4>可选店长权限</h4>
      <el-table :data="detail ? detail.manager_roles : []" border size="mini"><el-table-column prop="store_id" label="店铺 ID" width="100" /><el-table-column prop="store_name" label="店铺" /><el-table-column prop="role_code" label="权限" width="130" /></el-table>
    </el-dialog>

    <el-dialog title="创建职级规则草稿" :visible.sync="ruleVisible" width="680px">
      <el-form label-width="150px"><el-form-item label="有效开店金额"><el-input v-model="ruleForm.order_amount" /></el-form-item><el-form-item label="每单瓶数"><el-input-number v-model="ruleForm.bottle_count" :min="1" /></el-form-item>
        <el-form-item label="董事分红池(BPS)"><el-input-number v-model="ruleForm.platform_dividend_bps" :min="0" :max="10000" /></el-form-item>
        <template v-for="rank in rankOptions">
          <el-form-item :key="rank.value + '-bottle'" :label="rank.label + '/瓶'"><el-input v-model="ruleForm.rank_rules[rank.value].reward_per_bottle" /></el-form-item>
          <el-form-item :key="rank.value + '-purchase'" :label="rank.label + '采购(%)'"><el-input-number v-model="ruleForm.rank_rules[rank.value].procurement_percent" :min="0" :max="100" :precision="2" /></el-form-item>
          <el-form-item :key="rank.value + '-opening'" :label="rank.label + '开店奖励'"><el-input-number v-model="ruleForm.rank_rules[rank.value].opening_reward_amount" :min="0" :precision="2" /></el-form-item>
        </template>
        <el-form-item label="变更原因"><el-input v-model.trim="ruleForm.reason" type="textarea" /></el-form-item></el-form>
      <span slot="footer"><el-button @click="ruleVisible=false">取消</el-button><el-button type="primary" @click="saveRule">保存草稿</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthPartnerDashboard, yfthPartnerDetail, yfthPartnerList, yfthPartnerParentChange,
  yfthPartnerPerformances, yfthPartnerRankChange, yfthPartnerRewardAction, yfthPartnerRewards,
  yfthPartnerRules, yfthPartnerRulePublish, yfthPartnerRuleSave, yfthPartnerWarnings,
  yfthPartnerPromotions, yfthPartnerPromotionReview,
  yfthRewardEventList, yfthRewardEventRetry, yfthOpeningQuotaAwards, yfthOpeningQuotaConfirm,
  yfthRewardConsistency, yfthPartnerMigrationIssues, yfthPartnerProcurementProfits,
  yfthPartnerOpeningRewards, yfthPartnerDividends, yfthPartnerDividendGenerate,
} from '@/api/yfth';

export default {
  data() {
    return {
      tab: 'partners', loading: false, dashboard: {}, rankOptions: [], partners: [], partnerTotal: 0,
      partnerQuery: { keyword: '', rank_code: '', status: '', page: 1, limit: 20 },
      performances: [], rewards: [], rewardTotal: 0, rewardQuery: { status: '', page: 1, limit: 20 },
      procurementProfits: [], openingRewards: [], dividends: [], dividendPeriod: '',
      partnerRules: [], rulesLoading: false, rulesRenderKey: 0, warnings: [], promotions: [], openingQuotas: [], rewardEvents: [], migrationIssues: [],
      eventQuery: { status: '', page: 1, limit: 100 }, detail: null, detailVisible: false, ruleVisible: false,
      ruleForm: { order_amount: '89100.00', bottle_count: 440, platform_dividend_bps: 100, rank_rules: {}, reason: '' },
    };
  },
  computed: {
    rankCards() { return this.rankOptions.map((item) => ({ code: item.value, name: item.label, count: (this.dashboard.rank_counts || {})[item.value] || 0 })); },
  },
  created() { this.loadDashboard(); this.loadPartners(); this.loadRules(); },
  methods: {
    loadDashboard() { return yfthPartnerDashboard().then((res) => { this.dashboard = res.data || {}; this.rankOptions = this.dashboard.rank_options || []; }); },
    loadTab() { ({ partners: this.loadPartners, performance: this.loadPerformances, procurementProfit: this.loadProcurementProfits, openingReward: this.loadOpeningRewards, dividends: this.loadDividends, rewards: this.loadRewards, rules: this.loadRules, warnings: this.loadWarnings, promotions: this.loadPromotions, openingQuota: this.loadOpeningQuotas, rewardEvents: this.loadRewardEvents, migrationIssues: this.loadMigrationIssues }[this.tab] || (() => {})).call(this); },
    loadPartners(reset) { if (reset === true) this.partnerQuery.page = 1; this.loading = true; return yfthPartnerList(this.partnerQuery).then((res) => { const d = res.data || {}; this.partners = d.list || []; this.partnerTotal = Number(d.count || 0); if (d.rank_options) this.rankOptions = d.rank_options; }).finally(() => { this.loading = false; }); },
    loadPerformances() { this.loading = true; return yfthPartnerPerformances({ page: 1, limit: 100 }).then((res) => { this.performances = (res.data || {}).list || []; }).finally(() => { this.loading = false; }); },
    loadProcurementProfits() { this.loading = true; return yfthPartnerProcurementProfits({ page: 1, limit: 100 }).then((res) => { this.procurementProfits = (res.data || {}).list || []; }).finally(() => { this.loading = false; }); },
    loadOpeningRewards() { this.loading = true; return yfthPartnerOpeningRewards({ page: 1, limit: 100 }).then((res) => { this.openingRewards = (res.data || {}).list || []; }).finally(() => { this.loading = false; }); },
    loadDividends() { this.loading = true; return yfthPartnerDividends({ page: 1, limit: 100 }).then((res) => { this.dividends = (res.data || {}).list || []; }).finally(() => { this.loading = false; }); },
    generateDividend() { if (!this.dividendPeriod) return this.$message.warning('请选择月份'); return yfthPartnerDividendGenerate({ period_key: this.dividendPeriod }).then(() => { this.$message.success('分红批次已生成'); return this.loadDividends(); }); },
    loadRewards(reset) { if (reset === true) this.rewardQuery.page = 1; this.loading = true; return yfthPartnerRewards(this.rewardQuery).then((res) => { const d = res.data || {}; this.rewards = d.list || []; this.rewardTotal = Number(d.count || 0); }).finally(() => { this.loading = false; }); },
    loadRules() {
      this.rulesLoading = true;
      return yfthPartnerRules().then((res) => {
        const d = res.data || {};
        this.$set(this, 'partnerRules', Array.isArray(d.list) ? d.list : []);
        if (Array.isArray(d.rank_options)) this.$set(this, 'rankOptions', d.rank_options);
        this.rulesRenderKey += 1;
      }).finally(() => {
        this.rulesLoading = false;
        this.$nextTick(() => this.$forceUpdate());
      });
    },
    loadWarnings() { this.loading = true; return yfthPartnerWarnings({ page: 1, limit: 100 }).then((res) => { this.warnings = (res.data || {}).list || []; }).finally(() => { this.loading = false; }); },
    loadPromotions() { this.loading = true; return yfthPartnerPromotions({ page: 1, limit: 100 }).then((res) => { this.promotions = (res.data || {}).list || []; }).finally(() => { this.loading = false; }); },
    loadOpeningQuotas() { this.loading = true; return yfthOpeningQuotaAwards({ page: 1, limit: 100 }).then((res) => { this.openingQuotas = (res.data || {}).list || []; }).finally(() => { this.loading = false; }); },
    confirmOpeningQuota(row) { return this.$confirm('确认后商品额度将进入绑定门店的可用额度，是否继续？', '确认开店商品额度').then(() => yfthOpeningQuotaConfirm(row.id)).then(() => { this.$message.success('商品额度已确认并入账'); this.loadOpeningQuotas(); }); },
    loadRewardEvents() { this.loading = true; return yfthRewardEventList(this.eventQuery).then((res) => { this.rewardEvents = (res.data || {}).list || []; }).finally(() => { this.loading = false; }); },
    retryRewardEvents() { return yfthRewardEventRetry({ limit: 100 }).then((res) => { const d = res.data || {}; this.$message.success(`处理 ${d.selected || 0} 条，成功 ${d.succeeded || 0} 条`); return this.loadRewardEvents(); }); },
    scanRewardConsistency() { return yfthRewardConsistency({ limit: 100 }).then((res) => { const d = res.data || {}; if (Number(d.count || 0) > 0) this.$message.warning(`发现 ${d.count} 条奖励一致性异常，请按返回明细处理`); else this.$message.success('未发现奖励事件与结果缺失'); }); },
    loadMigrationIssues() { this.loading = true; return yfthPartnerMigrationIssues({ page: 1, limit: 100 }).then((res) => { this.migrationIssues = (res.data || {}).list || []; }).finally(() => { this.loading = false; }); },
    moneyCent(value) { return `¥${(Number(value || 0) / 100).toFixed(2)}`; },
    reviewPromotion(row, action) { this.$prompt('请输入总部审核原因', action === 'approve' ? '通过晋级申请' : '驳回晋级申请').then(({ value }) => yfthPartnerPromotionReview(row.id, { action, reason: value })).then(() => { this.$message.success('晋级申请已处理'); this.loadPromotions(); this.loadPartners(); this.loadDashboard(); }); },
    showPartner(row) { yfthPartnerDetail(row.uid).then((res) => { this.detail = res.data || null; this.detailVisible = true; }); },
    changeRank(row) { this.openRankDialog(row); },
    openRankDialog(row) { this.$prompt('目标职级：county_partner / prefecture_partner / province_partner / regional_director / platform_director；或 pause / resume / exit', '调整职级或状态', { inputValue: row.rank_code }).then(({ value }) => {
      const raw = String(value || '').trim(); const action = ['pause', 'resume', 'exit'].includes(raw) ? raw : ((this.rankOptions.find((x) => x.value === raw) || {}).level > row.rank_level ? 'promote' : 'demote');
      return this.$prompt('请输入总部人工审核原因', '操作原因').then(({ value: reason }) => yfthPartnerRankChange(row.uid, { action, target_rank: ['pause','resume','exit'].includes(raw) ? '' : raw, reason }));
    }).then(() => { this.$message.success('合伙人职级或状态已更新'); this.loadPartners(); this.loadDashboard(); }).catch(() => {}); },
    changeParent(row) { this.$prompt('请输入直接上级 UID，填 0 表示总部直营', '调整招商上级').then(({ value }) => this.$prompt('请输入调整原因', '操作原因').then(({ value: reason }) => yfthPartnerParentChange(row.uid, { parent_uid: Number(value || 0), reason }))).then(() => { this.$message.success('招商上级已更新'); this.loadPartners(); }).catch(() => {}); },
    rewardAction(row, action) { this.$prompt('请输入操作原因', action === 'confirm' ? '确认收益候选' : '取消收益候选').then(({ value }) => yfthPartnerRewardAction(row.id, action, { reason: value })).then(() => { this.$message.success('操作完成'); this.loadRewards(); this.loadDashboard(); }); },
    settle(row) { this.$prompt('请输入线下凭证编号或附件说明', '记录线下结算').then(({ value: evidence }) => this.$prompt('请输入结算说明', '操作原因').then(({ value: reason }) => yfthPartnerRewardAction(row.id, 'settle', { evidence, reason }))).then(() => { this.$message.success('线下结算事实已记录'); this.loadRewards(); }); },
    openRule() { const source = this.partnerRules.find((item) => item.status === 'published') || this.partnerRules[0] || {}; const map = {}; this.rankOptions.forEach((rank) => { const current = (source.rank_rules || []).find((item) => item.rank_code === rank.value) || {}; map[rank.value] = { reward_per_bottle: current.reward_per_bottle || '0.00', procurement_percent: Number(current.procurement_rate_bps || 0) / 100, opening_reward_amount: Number(current.opening_reward_amount_cent || 0) / 100 }; }); this.ruleForm = { order_amount: source.order_amount || '89100.00', bottle_count: Number(source.bottle_count || 440), platform_dividend_bps: Number(source.platform_dividend_bps || 100), rank_rules: map, reason: '' }; this.ruleVisible = true; },
    saveRule() { if (!this.ruleForm.reason) return this.$message.warning('必须填写规则变更原因'); const payload = JSON.parse(JSON.stringify(this.ruleForm)); Object.keys(payload.rank_rules || {}).forEach((code) => { const row = payload.rank_rules[code]; row.procurement_rate_bps = Math.round(Number(row.procurement_percent || 0) * 100); row.opening_reward_amount_cent = Math.round(Number(row.opening_reward_amount || 0) * 100); delete row.procurement_percent; delete row.opening_reward_amount; }); yfthPartnerRuleSave(payload).then(() => { this.$message.success('规则草稿已保存'); this.ruleVisible = false; this.loadRules(); }); },
    publishRule(row) { this.$prompt('请输入发布原因', '发布规则').then(({ value }) => yfthPartnerRulePublish(row.id, { reason: value })).then(() => { this.$message.success('新规则已发布，历史快照不会重算'); this.loadRules(); }); },
    sourceName(value) { return { franchise_opening: '正式开店', legacy_franchisee_migration: '历史身份迁移' }[value] || value || '-'; },
  },
};
</script>

<style scoped>
.partner-page { padding: 16px; }
.governance-table { margin-top: 14px; }
.summary-grid { display: grid; grid-template-columns: repeat(7, minmax(120px, 1fr)); gap: 10px; margin-bottom: 14px; }
.summary-item { min-height: 76px; padding: 14px; border: 1px solid #e8ded0; border-radius: 6px; background: #fff; }
.summary-item span { display: block; color: #8b765f; font-size: 12px; }.summary-item strong { display: block; margin-top: 8px; color: #5f4228; font-size: 24px; }
.summary-item.warning { border-color: #e6b968; background: #fff9ec; }.content-card { margin-top: 14px; }.toolbar { display: flex; gap: 10px; margin-bottom: 14px; }.toolbar .el-input { width: 300px; }.pager { margin-top: 16px; text-align: right; }.muted { margin-top: 4px; color: #999; font-size: 12px; }.danger { color: #f56c6c; }.rule-pill { display: inline-block; margin: 2px 6px 2px 0; padding: 3px 7px; border-radius: 4px; background: #f5eee5; color: #785737; font-size: 12px; }.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }.detail-grid>div { padding: 12px; background: #f7f4ef; }.detail-grid span { display: block; color: #999; font-size: 12px; }.detail-grid b { display: block; margin-top: 5px; }
.partner-rule-list { min-height: 120px; }
.partner-rule-card { margin-bottom: 12px; padding: 16px; border: 1px solid #e8ded0; border-radius: 6px; background: #fff; }
.partner-rule-head { display: flex; align-items: center; justify-content: space-between; }
.partner-rule-head>div { display: flex; align-items: center; gap: 16px; color: #8b765f; }
.partner-rule-head strong { color: #30261f; font-size: 16px; }
.partner-rule-summary { display: flex; gap: 28px; margin-top: 12px; padding: 10px 12px; background: #f8f5f0; color: #6f5b47; }
.partner-rule-ranks { display: grid; grid-template-columns: repeat(5, minmax(150px, 1fr)); gap: 10px; margin-top: 12px; }
.partner-rule-rank { padding: 12px; border: 1px solid #efe5d7; border-radius: 4px; }
.partner-rule-rank strong, .partner-rule-rank span { display: block; }
.partner-rule-rank span { margin-top: 6px; color: #7e6b58; font-size: 12px; }
.partner-rule-empty { padding: 36px; text-align: center; color: #999; }
@media (max-width: 1280px) { .summary-grid { grid-template-columns: repeat(4, 1fr); } }
</style>
