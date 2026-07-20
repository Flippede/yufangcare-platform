<template>
  <div class="commission-finance">
    <div class="page-head">
      <div>
        <h2>佣金与结算</h2>
        <p>普通商城佣金自动入账；会员套餐继续使用 15% / 25% / 60% 独立规则。</p>
      </div>
      <el-button icon="el-icon-refresh" @click="refreshCurrent">刷新</el-button>
    </div>

    <el-alert title="系统展示税前金额。B1不发起提现，总部按周期生成结算批次；微信分账当前仅保留接入模型。" type="warning" :closable="false" />

    <el-tabs v-model="tab" @tab-click="refreshCurrent">
      <el-tab-pane label="佣金规则" name="rules">
        <div class="toolbar">
          <el-button type="primary" icon="el-icon-plus" @click="openRule">新建规则版本</el-button>
          <el-button @click="loadLegacyReport">历史候选对账</el-button>
        </div>
        <el-table v-loading="loading" :data="rules" border size="small">
          <el-table-column prop="version_no" label="版本" width="80" />
          <el-table-column prop="scope_type" label="范围" width="90" />
          <el-table-column prop="scope_id" label="范围ID" width="90" />
          <el-table-column label="C1比例" width="100"><template slot-scope="{ row }">{{ row.c1_ratio_bps / 100 }}%</template></el-table-column>
          <el-table-column label="B1比例" width="100"><template slot-scope="{ row }">{{ row.b1_ratio_bps / 100 }}%</template></el-table-column>
          <el-table-column prop="observation_days" label="观察期(天)" width="110" />
          <el-table-column label="启用" width="80"><template slot-scope="{ row }">{{ row.enabled ? '是' : '否' }}</template></el-table-column>
          <el-table-column label="生效时间" min-width="150"><template slot-scope="{ row }">{{ timeText(row.effective_at) }}</template></el-table-column>
          <el-table-column label="状态" width="110">
            <template slot-scope="{ row }"><el-tag size="mini" :type="row.status === 'published' ? 'success' : 'info'">{{ ruleStatus(row.status) }}</el-tag></template>
          </el-table-column>
          <el-table-column prop="note" label="说明" min-width="180" show-overflow-tooltip />
          <el-table-column label="操作" width="100">
            <template slot-scope="{ row }"><el-button v-if="row.status === 'draft'" type="text" @click="publishRule(row)">发布</el-button></template>
          </el-table-column>
        </el-table>
        <div class="section-title">
          <div><strong>会员套餐奖励规则</strong><span>15% / 25% / 60% 循环比例独立于普通商城佣金。</span></div>
          <el-button type="primary" plain @click="openPackageRule()">新建套餐规则版本</el-button>
        </div>
        <el-table v-loading="loading" :data="packageRules" border size="small">
          <el-table-column prop="version_no" label="版本" width="80" />
          <el-table-column label="套餐循环比例" min-width="190">
            <template slot-scope="{ row }">{{ row.package_ratio_first_bps / 100 }}% / {{ row.package_ratio_second_bps / 100 }}% / {{ row.package_ratio_third_bps / 100 }}%</template>
          </el-table-column>
          <el-table-column prop="package_observation_days" label="套餐观察期(天)" width="140" />
          <el-table-column label="状态" width="100"><template slot-scope="{ row }">{{ packageRuleStatus(row.status) }}</template></el-table-column>
          <el-table-column label="生效时间" min-width="150"><template slot-scope="{ row }">{{ timeText(row.effective_at) }}</template></el-table-column>
          <el-table-column label="操作" width="130">
            <template slot-scope="{ row }"><el-button v-if="row.status === 'draft'" type="text" @click="openPackageRule(row)">编辑</el-button><el-button v-if="row.status === 'draft'" type="text" @click="publishPackageRule(row)">发布</el-button></template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="自动佣金记录" name="accruals">
        <div class="toolbar">
          <el-select v-model="accrualQuery.status" clearable placeholder="状态">
            <el-option label="观察期中" value="observing" /><el-option label="已入账" value="credited" />
            <el-option label="部分冲正" value="partially_reversed" /><el-option label="已冲正" value="reversed" />
            <el-option label="已取消" value="cancelled" />
          </el-select>
          <el-select v-model="accrualQuery.source_type" clearable placeholder="佣金类型">
            <el-option label="普通商城" value="mall_order_item" /><el-option label="会员套餐" value="package_activation" />
          </el-select>
          <el-input v-model="accrualQuery.order_id" placeholder="订单ID" clearable />
          <el-input v-model="accrualQuery.store_id" placeholder="门店ID" clearable />
          <el-button type="primary" @click="loadAccruals">查询</el-button>
          <el-button type="warning" @click="retryDue">重跑已到期任务</el-button>
        </div>
        <el-table v-loading="loading" :data="accruals" border size="small">
          <el-table-column prop="accrual_no" label="记录号" min-width="190" />
          <el-table-column label="佣金类型" width="120"><template slot-scope="{ row }">{{ sourceType(row.source_type) }}</template></el-table-column>
          <el-table-column prop="order_id" label="订单ID" width="90" />
          <el-table-column label="用户" min-width="130"><template slot-scope="{ row }">{{ row.c1_name || '-' }}<br><small>{{ row.c1_phone_masked || '-' }}</small></template></el-table-column>
          <el-table-column label="B1门店" min-width="140"><template slot-scope="{ row }">{{ row.store_name || `门店 ${row.store_id}` }}</template></el-table-column>
          <el-table-column prop="c1_amount" label="C1佣金" width="100" />
          <el-table-column prop="b1_amount" label="B1佣金" width="100" />
          <el-table-column label="冲正明细" min-width="150"><template slot-scope="{ row }">C1 {{ row.reversed_c1 || '0.00' }} / B1 {{ row.reversed_b1 || '0.00' }}</template></el-table-column>
          <el-table-column label="状态" width="110"><template slot-scope="{ row }">{{ accrualStatus(row.status) }}</template></el-table-column>
          <el-table-column prop="rule_version_id" label="规则版本" width="95" />
          <el-table-column label="观察期结束" min-width="150"><template slot-scope="{ row }">{{ timeText(row.due_at) }}</template></el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="B1结算批次" name="settlements">
        <div class="toolbar">
          <el-select v-model="settlementQuery.status" clearable placeholder="状态">
            <el-option label="待结算" value="pending" /><el-option label="结算中" value="processing" />
            <el-option label="已结算" value="settled" /><el-option label="异常" value="exception" />
            <el-option label="等待配置接收方" value="waiting_receiver" />
          </el-select>
          <el-input v-model="settlementQuery.store_id" placeholder="门店ID" clearable />
          <el-button type="primary" @click="loadSettlements">查询</el-button>
          <el-date-picker v-model="period" type="daterange" value-format="timestamp" range-separator="至" start-placeholder="周期开始" end-placeholder="周期结束" />
          <el-button type="success" @click="generateSettlements">生成结算批次</el-button>
        </div>
        <el-table v-loading="loading" :data="settlements" border size="small">
          <el-table-column prop="batch_no" label="批次号" min-width="190" />
          <el-table-column prop="store_name" label="门店" min-width="130" />
          <el-table-column prop="unsettled_amount" label="未结算金额" width="120" />
          <el-table-column prop="settled_amount" label="已结算金额" width="120" />
          <el-table-column label="周期" min-width="190"><template slot-scope="{ row }">{{ dateText(row.period_start) }} - {{ dateText(row.period_end) }}</template></el-table-column>
          <el-table-column label="状态" width="100"><template slot-scope="{ row }">{{ settlementStatus(row.status) }}</template></el-table-column>
          <el-table-column label="微信分账接收方" min-width="150"><template slot-scope="{ row }">{{ receiverStatus(row) }}</template></el-table-column>
          <el-table-column prop="exception_reason" label="异常原因" min-width="180" show-overflow-tooltip />
          <el-table-column label="操作" width="180">
            <template slot-scope="{ row }">
              <el-button type="text" @click="openReceiver(row.store_id)">接收方</el-button>
              <el-button v-if="row.status === 'pending' || row.status === 'exception'" type="text" @click="startSettlement(row)">进入结算中</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div class="toolbar"><el-input v-model="receiverStoreId" placeholder="门店ID" /><el-button @click="openReceiver(Number(receiverStoreId))">配置分账接收方</el-button></div>
      </el-tab-pane>
    </el-tabs>

    <el-dialog title="新建普通商城佣金规则" :visible.sync="ruleVisible" width="560px">
      <el-form label-width="130px">
        <el-form-item label="适用范围"><el-select v-model="ruleForm.scope_type"><el-option label="全部商品" value="all" /><el-option label="指定分类" value="category" /><el-option label="指定商品" value="product" /></el-select></el-form-item>
        <el-form-item v-if="ruleForm.scope_type !== 'all'" label="范围ID"><el-input-number v-model="ruleForm.scope_id" :min="1" /></el-form-item>
        <el-form-item label="C1比例(BPS)"><el-input-number v-model="ruleForm.c1_ratio_bps" :min="0" :max="10000" /></el-form-item>
        <el-form-item label="B1比例(BPS)"><el-input-number v-model="ruleForm.b1_ratio_bps" :min="0" :max="10000" /></el-form-item>
        <el-form-item label="观察期(天)"><el-input-number v-model="ruleForm.observation_days" :min="0" :max="365" /></el-form-item>
        <el-form-item label="生效时间戳"><el-input-number v-model="ruleForm.effective_at" :min="0" /></el-form-item>
        <el-form-item label="说明"><el-input v-model="ruleForm.note" maxlength="255" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="ruleVisible = false">取消</el-button><el-button type="primary" @click="saveRule">保存草稿</el-button></span>
    </el-dialog>

    <el-dialog title="会员套餐奖励规则" :visible.sync="packageRuleVisible" width="560px">
      <el-form label-width="150px">
        <el-form-item label="套餐循环比例"><el-input value="15% / 25% / 60%" disabled /></el-form-item>
        <el-form-item label="套餐观察期(天)"><el-input-number v-model="packageRuleForm.package_observation_days" :min="0" :max="365" /></el-form-item>
        <el-form-item label="生效时间"><el-date-picker v-model="packageRuleForm.effective_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" /></el-form-item>
        <el-form-item label="失效时间"><el-date-picker v-model="packageRuleForm.expires_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="packageRuleVisible = false">取消</el-button><el-button type="primary" @click="savePackageRule">保存草稿</el-button></span>
    </el-dialog>

    <el-dialog title="B1微信分账接收方" :visible.sync="receiverVisible" width="520px">
      <el-form label-width="130px">
        <el-form-item label="门店ID"><el-input-number v-model="receiverForm.store_id" :min="1" /></el-form-item>
        <el-form-item label="接收方类型"><el-select v-model="receiverForm.receiver_type"><el-option label="商户号" value="MERCHANT_ID" /><el-option label="个人OpenID" value="PERSONAL_OPENID" /></el-select></el-form-item>
        <el-form-item label="接收方账号"><el-input v-model="receiverForm.receiver_account" /></el-form-item>
        <el-form-item label="接收方名称"><el-input v-model="receiverForm.receiver_name" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="receiverVisible = false">取消</el-button><el-button type="primary" @click="saveReceiver">保存</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthCommissionRuleList, yfthCommissionRuleSave, yfthCommissionRulePublish,
  yfthCommissionAccrualList, yfthCommissionSettlementReceiver, yfthCommissionSettlementReceiverSave,
  yfthCommissionSettlementBatchList, yfthCommissionSettlementBatchGenerate,
  yfthCommissionSettlementBatchStart, yfthCommissionRetry, yfthCommissionLegacyReport,
  yfthPackageMembershipRuleList, yfthPackageMembershipRuleSave, yfthPackageMembershipRulePublish,
} from '@/api/yfth';

export default {
  name: 'YfthCommissionFinance',
  data() {
    return {
      tab: 'rules', loading: false, rules: [], packageRules: [], accruals: [], settlements: [],
      accrualQuery: { status: '', source_type: '', order_id: '', store_id: '', page: 1, limit: 50 },
      settlementQuery: { status: '', store_id: '', page: 1, limit: 50 }, period: [],
      ruleVisible: false, packageRuleVisible: false, receiverVisible: false, receiverStoreId: '',
      receiverForm: { store_id: 0, receiver_type: 'MERCHANT_ID', receiver_account: '', receiver_name: '' },
      ruleForm: { scope_type: 'all', scope_id: 0, c1_ratio_bps: 500, b1_ratio_bps: 500, observation_days: 0, enabled: 1, effective_at: 0, expires_at: 0, note: '' },
      packageRuleForm: {},
    };
  },
  created() { this.loadRules(); },
  methods: {
    ruleStatus(v) { return ({ draft: '草稿', published: '已发布', retired: '已替代' })[v] || v; },
    refreshCurrent() { ({ rules: this.loadRules, accruals: this.loadAccruals, settlements: this.loadSettlements }[this.tab] || this.loadRules)(); },
    withLoading(promise) { this.loading = true; return promise.finally(() => { this.loading = false; }); },
    loadRules() { return this.withLoading(Promise.all([
      yfthCommissionRuleList({ limit: 100 }).then((r) => { this.rules = (r.data && r.data.list) || []; }),
      yfthPackageMembershipRuleList({ limit: 100 }).then((r) => { this.packageRules = (r.data && r.data.list) || []; }),
    ])); },
    openRule() { this.ruleVisible = true; },
    saveRule() { yfthCommissionRuleSave(this.ruleForm).then(() => { this.$message.success('规则草稿已保存'); this.ruleVisible = false; this.loadRules(); }); },
    publishRule(row) { this.$confirm('发布后仅影响新订单，历史快照不会重算。', '确认发布').then(() => yfthCommissionRulePublish(row.id)).then(() => { this.$message.success('规则已发布'); this.loadRules(); }); },
    openPackageRule(row) {
      this.packageRuleForm = Object.assign({ id: 0, version_no: 0, package_ratio_first_bps: 1500, package_ratio_second_bps: 2500, package_ratio_third_bps: 6000, package_observation_days: 0, mall_consumption_enabled: 0, mall_consumption_ratio_bps: 0, effective_at: '', expires_at: '' }, row || {});
      this.packageRuleVisible = true;
    },
    savePackageRule() { yfthPackageMembershipRuleSave(this.packageRuleForm).then(() => { this.$message.success('套餐规则草稿已保存'); this.packageRuleVisible = false; this.loadRules(); }); },
    publishPackageRule(row) { this.$confirm('发布后仅影响新的套餐激活，历史奖励快照不会变化。', '确认发布').then(() => yfthPackageMembershipRulePublish(row.id)).then(() => { this.$message.success('套餐规则已发布'); this.loadRules(); }); },
    packageRuleStatus(v) { return ({ draft: '草稿', published: '已发布', superseded: '已替代' })[v] || v; },
    loadLegacyReport() { yfthCommissionLegacyReport().then((r) => { this.$alert(JSON.stringify(r.data || {}, null, 2), '历史候选对账（只读）'); }); },
    loadAccruals() { return this.withLoading(yfthCommissionAccrualList(this.accrualQuery).then((r) => { this.accruals = (r.data && r.data.list) || []; })); },
    retryDue() { yfthCommissionRetry({ limit: 100 }).then((r) => { this.$message.success(`扫描 ${r.data.scanned || 0} 条，入账 ${r.data.credited || 0} 条`); this.loadAccruals(); }); },
    sourceType(v) { return ({ mall_order_item: '普通商城', package_activation: '会员套餐' })[v] || v; },
    accrualStatus(v) { return ({ observing: '观察期中', credited: '已入账', partially_reversed: '部分冲正', reversed: '已冲正', cancelled: '已取消' })[v] || v; },
    settlementStatus(v) { return ({ pending: '待结算', processing: '结算中', settled: '已结算', exception: '异常', waiting_receiver: '等待配置' })[v] || v; },
    dateText(v) { return v ? new Date(Number(v) * 1000).toLocaleDateString() : '-'; },
    timeText(v) { return v ? new Date(Number(v) * 1000).toLocaleString() : '-'; },
    receiverStatus(row) { return row.receiver_status === 'configured' ? (row.receiver_account_masked || '已配置') : '等待配置'; },
    loadSettlements() { return this.withLoading(yfthCommissionSettlementBatchList(this.settlementQuery).then((r) => { this.settlements = (r.data && r.data.list) || []; })); },
    generateSettlements() {
      if (!this.period || this.period.length !== 2) return this.$message.warning('请选择结算周期');
      const data = { period_start: Math.floor(this.period[0] / 1000), period_end: Math.floor((this.period[1] + 86399999) / 1000) };
      yfthCommissionSettlementBatchGenerate(data).then((r) => { this.$message.success(`已生成 ${(r.data && r.data.count) || 0} 个批次`); this.loadSettlements(); });
    },
    startSettlement(row) { this.$confirm('当前仅记录进入结算中，不会真实调用微信分账。', '确认').then(() => yfthCommissionSettlementBatchStart(row.id)).then(() => { this.$message.success('批次已进入结算中'); this.loadSettlements(); }); },
    openReceiver(storeId) {
      if (!storeId) return this.$message.warning('请输入门店ID');
      this.receiverForm = { store_id: storeId, receiver_type: 'MERCHANT_ID', receiver_account: '', receiver_name: '' };
      yfthCommissionSettlementReceiver({ store_id: storeId }).then((r) => { if (r.data && r.data.id) this.receiverForm = Object.assign({}, this.receiverForm, r.data); this.receiverVisible = true; });
    },
    saveReceiver() { yfthCommissionSettlementReceiverSave(this.receiverForm).then(() => { this.$message.success('分账接收方已保存'); this.receiverVisible = false; this.loadSettlements(); }); },
  },
};
</script>

<style scoped lang="scss">
.commission-finance { padding: 20px; background: #fff; min-height: calc(100vh - 104px); }
.page-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
.page-head h2 { margin: 0 0 8px; font-size: 22px; }
.page-head p { margin: 0; color: #777; }
.toolbar { display: flex; gap: 10px; align-items: center; margin: 16px 0; }
.toolbar .el-input, .toolbar .el-select { width: 180px; }
.section-title { display: flex; justify-content: space-between; align-items: center; margin: 24px 0 12px; }
.section-title strong, .section-title span { display: block; }
.section-title span { margin-top: 5px; color: #888; font-size: 13px; }
small { color: #999; }
</style>
