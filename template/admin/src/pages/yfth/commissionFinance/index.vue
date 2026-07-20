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
          <el-table-column label="状态" width="110">
            <template slot-scope="{ row }"><el-tag size="mini" :type="row.status === 'published' ? 'success' : 'info'">{{ ruleStatus(row.status) }}</el-tag></template>
          </el-table-column>
          <el-table-column prop="note" label="说明" min-width="180" show-overflow-tooltip />
          <el-table-column label="操作" width="100">
            <template slot-scope="{ row }"><el-button v-if="row.status === 'draft'" type="text" @click="publishRule(row)">发布</el-button></template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="自动入账" name="accruals">
        <div class="toolbar">
          <el-select v-model="accrualQuery.status" clearable placeholder="状态">
            <el-option label="观察期中" value="observing" /><el-option label="已入账" value="credited" />
            <el-option label="部分冲正" value="partially_reversed" /><el-option label="已冲正" value="reversed" />
            <el-option label="已取消" value="cancelled" />
          </el-select>
          <el-input v-model="accrualQuery.order_id" placeholder="订单ID" clearable />
          <el-input v-model="accrualQuery.store_id" placeholder="门店ID" clearable />
          <el-button type="primary" @click="loadAccruals">查询</el-button>
          <el-button type="warning" @click="retryDue">重跑已到期任务</el-button>
        </div>
        <el-table v-loading="loading" :data="accruals" border size="small">
          <el-table-column prop="accrual_no" label="记录号" min-width="190" />
          <el-table-column prop="source_type" label="来源" width="140" />
          <el-table-column prop="order_id" label="订单ID" width="90" />
          <el-table-column prop="store_id" label="B1门店" width="90" />
          <el-table-column prop="c1_uid" label="C1 UID" width="90" />
          <el-table-column prop="c1_amount" label="C1佣金" width="100" />
          <el-table-column prop="b1_amount" label="B1佣金" width="100" />
          <el-table-column prop="status" label="状态" width="120" />
          <el-table-column prop="due_at" label="到期时间" min-width="120" />
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="账户与流水" name="accounts">
        <div class="toolbar">
          <el-select v-model="accountType"><el-option label="用户账户" value="user" /><el-option label="门店账户" value="store" /></el-select>
          <el-input v-model="accountId" :placeholder="accountType === 'user' ? '用户UID' : '门店ID'" clearable />
          <el-button type="primary" @click="loadAccount">查询账户</el-button>
          <el-button @click="openAdjustment">余额调整</el-button>
        </div>
        <div v-if="accountResult" class="account-strip">
          <div v-for="item in accountMetrics" :key="item.label"><strong>{{ item.value }}</strong><span>{{ item.label }}</span></div>
        </div>
        <el-table v-loading="loading" :data="ledgers" border size="small">
          <el-table-column prop="ledger_no" label="流水号" min-width="190" />
          <el-table-column prop="bucket" label="余额类型" width="130" />
          <el-table-column prop="direction" label="方向" width="90" />
          <el-table-column prop="amount" label="金额" width="100" />
          <el-table-column prop="source_type" label="业务来源" min-width="150" />
          <el-table-column prop="source_order_id" label="订单ID" width="90" />
          <el-table-column prop="reason" label="原因" min-width="150" />
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="结算批次" name="settlements">
        <div class="toolbar">
          <el-select v-model="settlementQuery.status" clearable placeholder="状态">
            <el-option label="待结算" value="pending" /><el-option label="结算中" value="processing" />
            <el-option label="已结算" value="settled" /><el-option label="异常" value="exception" />
          </el-select>
          <el-input v-model="settlementQuery.store_id" placeholder="门店ID" clearable />
          <el-button type="primary" @click="loadSettlements">查询</el-button>
          <el-date-picker v-model="period" type="daterange" value-format="timestamp" range-separator="至" start-placeholder="周期开始" end-placeholder="周期结束" />
          <el-button type="success" @click="generateSettlements">生成结算批次</el-button>
        </div>
        <el-table v-loading="loading" :data="settlements" border size="small">
          <el-table-column prop="batch_no" label="批次号" min-width="190" />
          <el-table-column prop="store_name" label="门店" min-width="130" />
          <el-table-column prop="amount" label="分账金额" width="110" />
          <el-table-column label="周期" min-width="190"><template slot-scope="{ row }">{{ dateText(row.period_start) }} - {{ dateText(row.period_end) }}</template></el-table-column>
          <el-table-column label="状态" width="100"><template slot-scope="{ row }">{{ settlementStatus(row.status) }}</template></el-table-column>
          <el-table-column prop="receiver_account_masked" label="B1接收方" min-width="150" />
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

    <el-dialog title="人工余额调整" :visible.sync="adjustVisible" width="520px">
      <el-alert title="扣减后允许形成负余额，后续佣金优先抵扣；调整会生成不可删除流水。" type="warning" :closable="false" />
      <el-form label-width="120px">
        <el-form-item label="账户"><el-input :value="`${accountType}:${accountId}`" disabled /></el-form-item>
        <el-form-item v-if="accountType === 'store'" label="门店结算台账"><el-input value="未结算佣金" disabled /></el-form-item>
        <el-form-item label="调整金额(分)"><el-input-number v-model="adjustForm.delta_cent" /></el-form-item>
        <el-form-item label="原因"><el-input v-model="adjustForm.reason" minlength="4" maxlength="255" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="adjustVisible = false">取消</el-button><el-button type="danger" @click="submitAdjustment">确认并生成流水</el-button></span>
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
  yfthCommissionAccrualList, yfthCommissionLedgerList, yfthCommissionAccount,
  yfthCommissionAdjustment, yfthCommissionSettlementReceiver, yfthCommissionSettlementReceiverSave,
  yfthCommissionSettlementBatchList, yfthCommissionSettlementBatchGenerate,
  yfthCommissionSettlementBatchStart, yfthCommissionRetry, yfthCommissionLegacyReport,
} from '@/api/yfth';

export default {
  name: 'YfthCommissionFinance',
  data() {
    return {
      tab: 'rules', loading: false, rules: [], accruals: [], ledgers: [], settlements: [],
      accrualQuery: { status: '', order_id: '', store_id: '', page: 1, limit: 50 },
      settlementQuery: { status: '', store_id: '', page: 1, limit: 50 }, period: [],
      accountType: 'user', accountId: '', accountResult: null,
      ruleVisible: false, adjustVisible: false, receiverVisible: false, receiverStoreId: '',
      receiverForm: { store_id: 0, receiver_type: 'MERCHANT_ID', receiver_account: '', receiver_name: '' },
      ruleForm: { scope_type: 'all', scope_id: 0, c1_ratio_bps: 500, b1_ratio_bps: 500, observation_days: 0, enabled: 1, effective_at: 0, expires_at: 0, note: '' },
      adjustForm: { bucket: 'store_commission', delta_cent: 0, reason: '' },
    };
  },
  computed: {
    accountMetrics() {
      if (!this.accountResult) return [];
      const a = this.accountResult.account || this.accountResult;
      const keys = this.accountType === 'user'
        ? [['应结算佣金', 'available'], ['已申请结算', 'frozen'], ['已完成结算', 'withdrawn']]
        : [['未结算金额', 'unsettled'], ['已结算金额', 'settled']];
      return keys.map(([label, key]) => ({ label, value: a[key] || '0.00' }));
    },
  },
  created() { this.loadRules(); },
  methods: {
    ruleStatus(v) { return ({ draft: '草稿', published: '已发布', retired: '已替代' })[v] || v; },
    refreshCurrent() { ({ rules: this.loadRules, accruals: this.loadAccruals, accounts: this.loadAccount, settlements: this.loadSettlements }[this.tab] || this.loadRules)(); },
    withLoading(promise) { this.loading = true; return promise.finally(() => { this.loading = false; }); },
    loadRules() { return this.withLoading(yfthCommissionRuleList({ limit: 100 }).then((r) => { this.rules = (r.data && r.data.list) || []; })); },
    openRule() { this.ruleVisible = true; },
    saveRule() { yfthCommissionRuleSave(this.ruleForm).then(() => { this.$message.success('规则草稿已保存'); this.ruleVisible = false; this.loadRules(); }); },
    publishRule(row) { this.$confirm('发布后仅影响新订单，历史快照不会重算。', '确认发布').then(() => yfthCommissionRulePublish(row.id)).then(() => { this.$message.success('规则已发布'); this.loadRules(); }); },
    loadLegacyReport() { yfthCommissionLegacyReport().then((r) => { this.$alert(JSON.stringify(r.data || {}, null, 2), '历史候选对账（只读）'); }); },
    loadAccruals() { return this.withLoading(yfthCommissionAccrualList(this.accrualQuery).then((r) => { this.accruals = (r.data && r.data.list) || []; })); },
    retryDue() { yfthCommissionRetry({ limit: 100 }).then((r) => { this.$message.success(`扫描 ${r.data.scanned || 0} 条，入账 ${r.data.credited || 0} 条`); this.loadAccruals(); }); },
    loadAccount() {
      if (!Number(this.accountId)) { this.accountResult = null; this.ledgers = []; return Promise.resolve(); }
      const params = this.accountType === 'user' ? { uid: Number(this.accountId) } : { store_id: Number(this.accountId) };
      return this.withLoading(Promise.all([
        yfthCommissionAccount(params).then((r) => { this.accountResult = r.data || null; }),
        yfthCommissionLedgerList({ account_type: this.accountType, account_id: Number(this.accountId), limit: 100 }).then((r) => { this.ledgers = (r.data && r.data.list) || []; }),
      ]));
    },
    openAdjustment() { if (!Number(this.accountId)) return this.$message.warning('请先查询账户'); this.adjustVisible = true; },
    submitAdjustment() {
      const data = { account_type: this.accountType, account_id: Number(this.accountId), bucket: this.accountType === 'user' ? 'c1_commission' : this.adjustForm.bucket, delta_cent: Number(this.adjustForm.delta_cent), reason: this.adjustForm.reason, request_id: `admin-${Date.now()}` };
      this.$confirm(`确认调整 ${data.delta_cent} 分？该操作会生成不可删除流水。`, '二次确认', { type: 'warning' }).then(() => yfthCommissionAdjustment(data)).then(() => { this.$message.success('调整完成'); this.adjustVisible = false; this.loadAccount(); });
    },
    settlementStatus(v) { return ({ pending: '待结算', processing: '结算中', settled: '已结算', exception: '异常' })[v] || v; },
    dateText(v) { return v ? new Date(Number(v) * 1000).toLocaleDateString() : '-'; },
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
.account-strip { display: grid; grid-template-columns: repeat(5, minmax(120px, 1fr)); border: 1px solid #ebeef5; margin: 16px 0; }
.account-strip div { padding: 18px; border-right: 1px solid #ebeef5; }
.account-strip div:last-child { border-right: 0; }
.account-strip strong, .account-strip span { display: block; }
.account-strip strong { font-size: 22px; margin-bottom: 6px; }
.account-strip span { color: #777; }
</style>
