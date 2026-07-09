<template>
  <div class="yfth-product-quota">
    <el-alert
      class="notice"
      type="warning"
      :closable="false"
      title="产品额度 / 返货额度仅代表总部线下确认的产品等价额度，不代表系统付款，不可提取为资金，不自动抵扣采购单。"
    />
    <el-tabs v-model="activeTab" @tab-click="loadCurrent">
      <el-tab-pane label="额度账号" name="accounts">
        <div class="toolbar">
          <el-input v-model="accountQuery.store_id" size="small" clearable placeholder="门店ID" />
          <el-select v-model="accountQuery.status" size="small" clearable placeholder="状态">
            <el-option label="active" value="active" />
            <el-option label="frozen" value="frozen" />
            <el-option label="closed" value="closed" />
          </el-select>
          <el-button size="small" type="primary" icon="el-icon-search" @click="loadAccounts">查询</el-button>
          <el-button size="small" icon="el-icon-plus" @click="openGrant()">创建授予单</el-button>
        </div>
        <el-table :data="accounts" size="small" border>
          <el-table-column prop="account_no" label="账号" min-width="170" />
          <el-table-column prop="store_id" label="门店" width="90" />
          <el-table-column prop="quota_type" label="类型" width="130" />
          <el-table-column prop="status" label="状态" width="100" />
          <el-table-column label="可用产品额度" width="150">
            <template slot-scope="{ row }">{{ formatCent(row.available_cent) }}</template>
          </el-table-column>
          <el-table-column label="已授予" width="130">
            <template slot-scope="{ row }">{{ formatCent(row.total_granted_cent) }}</template>
          </el-table-column>
          <el-table-column label="已反冲" width="130">
            <template slot-scope="{ row }">{{ formatCent(row.total_reversed_cent) }}</template>
          </el-table-column>
          <el-table-column label="更新时间" width="150">
            <template slot-scope="{ row }">{{ formatTime(row.update_time) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="310" fixed="right">
            <template slot-scope="{ row }">
              <el-button size="mini" @click="openDetail(row)">详情</el-button>
              <el-button size="mini" @click="openAdjustment(row)" :disabled="row.status !== 'active'">纠偏</el-button>
              <el-button size="mini" @click="statusAction(row, 'freeze')" :disabled="row.status !== 'active'">冻结</el-button>
              <el-button size="mini" @click="statusAction(row, 'unfreeze')" :disabled="row.status !== 'frozen'">解冻</el-button>
              <el-button size="mini" type="danger" @click="statusAction(row, 'close')" :disabled="row.status === 'closed'">关闭</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="授予单" name="grants">
        <div class="toolbar">
          <el-input v-model="grantQuery.store_id" size="small" clearable placeholder="门店ID" />
          <el-select v-model="grantQuery.status" size="small" clearable placeholder="状态">
            <el-option v-for="item in grantStatuses" :key="item" :label="item" :value="item" />
          </el-select>
          <el-button size="small" type="primary" icon="el-icon-search" @click="loadGrants">查询</el-button>
          <el-button size="small" icon="el-icon-plus" @click="openGrant()">创建授予单</el-button>
        </div>
        <el-table :data="grants" size="small" border>
          <el-table-column prop="grant_no" label="授予单号" min-width="170" />
          <el-table-column prop="store_id" label="门店" width="90" />
          <el-table-column prop="source_type" label="来源" min-width="180" />
          <el-table-column label="额度" width="130">
            <template slot-scope="{ row }">{{ formatCent(row.amount_cent) }}</template>
          </el-table-column>
          <el-table-column prop="status" label="状态" width="110" />
          <el-table-column prop="reason" label="原因" min-width="160" />
          <el-table-column label="创建时间" width="150">
            <template slot-scope="{ row }">{{ formatTime(row.create_time) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="240" fixed="right">
            <template slot-scope="{ row }">
              <el-button size="mini" type="success" @click="confirmGrant(row)" :disabled="row.status !== 'draft'">确认</el-button>
              <el-button size="mini" @click="rejectGrant(row)" :disabled="row.status !== 'draft'">驳回</el-button>
              <el-button size="mini" type="warning" @click="reverseGrant(row)" :disabled="row.status !== 'confirmed'">反冲</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="额度流水" name="ledger">
        <div class="toolbar">
          <el-input v-model="ledgerQuery.store_id" size="small" clearable placeholder="门店ID" />
          <el-input v-model="ledgerQuery.account_id" size="small" clearable placeholder="账号ID" />
          <el-select v-model="ledgerQuery.action_type" size="small" clearable placeholder="动作">
            <el-option label="headquarters_manual_grant" value="headquarters_manual_grant" />
            <el-option label="manual_increase" value="manual_increase" />
            <el-option label="manual_decrease" value="manual_decrease" />
            <el-option label="reverse_grant" value="reverse_grant" />
          </el-select>
          <el-button size="small" type="primary" icon="el-icon-search" @click="loadLedger">查询</el-button>
        </div>
        <el-table :data="ledgers" size="small" border>
          <el-table-column prop="ledger_no" label="流水号" min-width="170" />
          <el-table-column prop="store_id" label="门店" width="90" />
          <el-table-column prop="direction" label="方向" width="80" />
          <el-table-column prop="action_type" label="动作" min-width="150" />
          <el-table-column label="变动额度" width="130">
            <template slot-scope="{ row }">{{ formatCent(row.amount_cent) }}</template>
          </el-table-column>
          <el-table-column label="变动前" width="120">
            <template slot-scope="{ row }">{{ formatCent(row.balance_before_cent) }}</template>
          </el-table-column>
          <el-table-column label="变动后" width="120">
            <template slot-scope="{ row }">{{ formatCent(row.balance_after_cent) }}</template>
          </el-table-column>
          <el-table-column prop="source_type" label="来源" min-width="170" />
          <el-table-column label="时间" width="150">
            <template slot-scope="{ row }">{{ formatTime(row.create_time) }}</template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
    </el-tabs>

    <el-drawer title="额度账号详情" :visible.sync="detailVisible" size="50%">
      <div class="drawer-body" v-if="detail.account">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="账号">{{ detail.account.account_no }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ detail.account.status }}</el-descriptions-item>
          <el-descriptions-item label="门店">{{ detail.account.store_id }}</el-descriptions-item>
          <el-descriptions-item label="可用产品额度">{{ formatCent(detail.account.available_cent) }}</el-descriptions-item>
        </el-descriptions>
        <h4>最近流水</h4>
        <el-table :data="detail.recent_ledgers || []" size="mini" border>
          <el-table-column prop="action_type" label="动作" />
          <el-table-column label="额度"><template slot-scope="{ row }">{{ formatCent(row.amount_cent) }}</template></el-table-column>
          <el-table-column label="时间"><template slot-scope="{ row }">{{ formatTime(row.create_time) }}</template></el-table-column>
        </el-table>
      </div>
    </el-drawer>

    <el-dialog title="创建总部人工授予单" :visible.sync="grantVisible" width="520px">
      <el-form label-width="130px" size="small">
        <el-form-item label="门店ID"><el-input v-model="grantForm.store_id" /></el-form-item>
        <el-form-item label="额度类型"><el-input v-model="grantForm.quota_type" /></el-form-item>
        <el-form-item label="额度分值"><el-input v-model="grantForm.amount_cent" placeholder="整数分，不使用小数" /></el-form-item>
        <el-form-item label="来源">
          <el-select v-model="grantForm.source_type" class="full">
            <el-option label="总部人工授予" value="headquarters_manual_grant" />
            <el-option label="开店初始额度（人工）" value="franchise_opening_initial_quota" />
          </el-select>
        </el-form-item>
        <el-form-item label="来源ID"><el-input v-model="grantForm.source_id" placeholder="开店初始额度需填写申请ID" /></el-form-item>
        <el-form-item label="原因"><el-input v-model="grantForm.reason" type="textarea" /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="grantVisible = false">取消</el-button>
        <el-button type="primary" @click="createGrant">保存草稿</el-button>
      </span>
    </el-dialog>

    <el-dialog title="手工纠偏" :visible.sync="adjustVisible" width="460px">
      <el-form label-width="110px" size="small">
        <el-form-item label="动作">
          <el-select v-model="adjustForm.action_type" class="full">
            <el-option label="增加产品额度" value="manual_increase" />
            <el-option label="减少产品额度" value="manual_decrease" />
          </el-select>
        </el-form-item>
        <el-form-item label="额度分值"><el-input v-model="adjustForm.amount_cent" /></el-form-item>
        <el-form-item label="原因"><el-input v-model="adjustForm.reason" type="textarea" /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="adjustVisible = false">取消</el-button>
        <el-button type="primary" @click="createAdjustment">提交</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthProductQuotaAccountClose,
  yfthProductQuotaAccountDetail,
  yfthProductQuotaAccountFreeze,
  yfthProductQuotaAccountList,
  yfthProductQuotaAccountUnfreeze,
  yfthProductQuotaAdjustmentCreate,
  yfthProductQuotaGrantConfirm,
  yfthProductQuotaGrantCreate,
  yfthProductQuotaGrantList,
  yfthProductQuotaGrantReject,
  yfthProductQuotaGrantReverse,
  yfthProductQuotaLedgerList,
} from '@/api/yfth';

export default {
  name: 'YfthProductQuota',
  data() {
    return {
      activeTab: 'accounts',
      accountQuery: { store_id: '', status: '', quota_type: '', page: 1, limit: 20 },
      grantQuery: { store_id: '', status: '', quota_type: '', page: 1, limit: 20 },
      ledgerQuery: { store_id: '', account_id: '', action_type: '', page: 1, limit: 20 },
      accounts: [],
      grants: [],
      ledgers: [],
      grantStatuses: ['draft', 'confirmed', 'rejected', 'reversed'],
      detailVisible: false,
      detail: {},
      grantVisible: false,
      grantSubmitting: false,
      grantForm: {},
      adjustVisible: false,
      adjustSubmitting: false,
      adjustForm: {},
    };
  },
  mounted() {
    this.loadAccounts();
  },
  methods: {
    loadCurrent() {
      const map = { accounts: this.loadAccounts, grants: this.loadGrants, ledger: this.loadLedger };
      if (map[this.activeTab]) map[this.activeTab]();
    },
    loadAccounts() {
      yfthProductQuotaAccountList(this.accountQuery).then((res) => {
        this.accounts = (res.data && res.data.list) || [];
      });
    },
    loadGrants() {
      yfthProductQuotaGrantList(this.grantQuery).then((res) => {
        this.grants = (res.data && res.data.list) || [];
      });
    },
    loadLedger() {
      yfthProductQuotaLedgerList(this.ledgerQuery).then((res) => {
        this.ledgers = (res.data && res.data.list) || [];
      });
    },
    openDetail(row) {
      yfthProductQuotaAccountDetail(row.id).then((res) => {
        this.detail = res.data || {};
        this.detailVisible = true;
      });
    },
    openGrant(row) {
      this.grantForm = {
        store_id: row && row.store_id ? row.store_id : '',
        quota_type: 'return_goods',
        amount_cent: '',
        source_type: 'headquarters_manual_grant',
        source_id: 0,
        reason: '',
        idempotency_key: this.makeOperationKey('grant'),
      };
      this.grantVisible = true;
    },
    createGrant() {
      if (this.grantSubmitting) return;
      if (!this.grantForm.idempotency_key) {
        this.grantForm.idempotency_key = this.makeOperationKey('grant');
      }
      this.grantSubmitting = true;
      yfthProductQuotaGrantCreate(this.grantForm).then(() => {
        this.$message.success('授予单草稿已创建');
        this.grantVisible = false;
        this.loadAccounts();
        this.loadGrants();
      }).finally(() => {
        this.grantSubmitting = false;
      });
    },
    confirmGrant(row) {
      this.$confirm('确认后将增加门店可用产品额度，且仅代表总部线下确认。').then(() => yfthProductQuotaGrantConfirm(row.id)).then(() => {
        this.$message.success('已确认');
        this.loadAccounts();
        this.loadGrants();
        this.loadLedger();
      });
    },
    rejectGrant(row) {
      this.$prompt('驳回原因', '驳回授予单').then(({ value }) => yfthProductQuotaGrantReject(row.id, { reason: value || '总部驳回' })).then(() => {
        this.$message.success('已驳回');
        this.loadGrants();
      });
    },
    reverseGrant(row) {
      this.$prompt('反冲原因', '反冲授予单').then(({ value }) => yfthProductQuotaGrantReverse(row.id, { reason: value || '总部反冲' })).then(() => {
        this.$message.success('已反冲');
        this.loadAccounts();
        this.loadGrants();
        this.loadLedger();
      });
    },
    openAdjustment(row) {
      this.adjustForm = { account_id: row.id, action_type: 'manual_increase', amount_cent: '', reason: '', dedupe_key: this.makeOperationKey('adjust') };
      this.adjustVisible = true;
    },
    createAdjustment() {
      if (this.adjustSubmitting) return;
      if (!this.adjustForm.dedupe_key) {
        this.adjustForm.dedupe_key = this.makeOperationKey('adjust');
      }
      this.adjustSubmitting = true;
      yfthProductQuotaAdjustmentCreate(this.adjustForm).then(() => {
        this.$message.success('已记录纠偏');
        this.adjustVisible = false;
        this.loadAccounts();
        this.loadLedger();
      }).finally(() => {
        this.adjustSubmitting = false;
      });
    },
    statusAction(row, action) {
      const titles = { freeze: '冻结账号', unfreeze: '解冻账号', close: '关闭账号' };
      const apis = { freeze: yfthProductQuotaAccountFreeze, unfreeze: yfthProductQuotaAccountUnfreeze, close: yfthProductQuotaAccountClose };
      this.$prompt('操作原因', titles[action]).then(({ value }) => apis[action](row.id, { reason: value || titles[action] })).then(() => {
        this.$message.success('状态已更新');
        this.loadAccounts();
      });
    },
    formatCent(value) {
      const cents = parseInt(value || 0, 10);
      const sign = cents < 0 ? '-' : '';
      const abs = Math.abs(cents);
      const yuan = Math.floor(abs / 100);
      const cent = String(abs % 100).padStart(2, '0');
      return `${sign}${yuan}.${cent}`;
    },
    formatTime(value) {
      const ts = Number(value || 0);
      if (!ts) return '-';
      const date = new Date(ts * 1000);
      const pad = (n) => (n < 10 ? '0' + n : '' + n);
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    },
    makeOperationKey(prefix) {
      const now = Date.now().toString(36);
      const perf = typeof performance !== 'undefined' && performance.now ? Math.floor(performance.now() * 1000).toString(36) : '0';
      const random = Math.random().toString(36).slice(2, 12);
      return `${prefix}:${now}:${perf}:${random}`;
    },
  },
};
</script>

<style scoped>
.yfth-product-quota {
  padding: 16px;
}
.notice {
  margin-bottom: 12px;
}
.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.toolbar .el-input,
.toolbar .el-select {
  width: 180px;
}
.drawer-body {
  padding: 0 24px 24px;
}
.full {
  width: 100%;
}
h4 {
  margin: 20px 0 10px;
}
</style>
