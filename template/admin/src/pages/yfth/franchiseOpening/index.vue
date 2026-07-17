<template>
  <div class="yfth-franchise-opening">
    <el-card shadow="never" :body-style="{ padding: '16px' }">
      <el-tabs v-model="activeTab" @tab-click="load">
        <el-tab-pane label="加盟合同" name="contracts">
          <div class="toolbar">
            <el-input v-model="contractFilters.application_id" clearable placeholder="申请 ID" class="w160" />
            <el-select v-model="contractFilters.status" clearable placeholder="合同状态" class="w180">
              <el-option v-for="item in contractStatuses" :key="item" :label="item" :value="item" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="load">查询</el-button>
            <el-button icon="el-icon-plus" @click="createVisible = true">新建合同</el-button>
          </div>
          <el-table v-loading="loading" :data="contracts" border>
            <el-table-column prop="contract_no" label="合同编号" min-width="190" />
            <el-table-column prop="application_id" label="申请 ID" width="110" />
            <el-table-column prop="applicant_uid" label="用户 UID" width="100" />
            <el-table-column prop="amount_snapshot" label="合同金额" width="120" />
            <el-table-column prop="status_text" label="状态" width="150" />
            <el-table-column label="操作" width="310" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" @click="openDetail(scope.row)">开店详情</el-button>
                <el-button type="text" @click="confirmContract(scope.row, 'hq_confirm')">总部确认</el-button>
                <el-button type="text" @click="confirmContract(scope.row, 'sign')">确认签署</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="财务到账" name="payments">
          <el-table v-loading="loading" :data="payments" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="application_id" label="申请 ID" width="110" />
            <el-table-column prop="contract_id" label="合同 ID" width="100" />
            <el-table-column prop="amount_snapshot" label="到账金额" width="120" />
            <el-table-column prop="status_text" label="状态" width="150" />
            <el-table-column prop="reject_reason" label="驳回原因" min-width="160" />
            <el-table-column label="操作" width="220">
              <template slot-scope="scope">
                <el-button type="text" @click="confirmPayment(scope.row)">确认总部公户到账</el-button>
                <el-button type="text" @click="rejectPayment(scope.row)">驳回</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="筹备资料" name="tasks">
          <div class="toolbar">
            <el-input v-model="taskFilters.application_id" clearable placeholder="Application ID" class="w160" />
            <el-select v-model="taskFilters.status" clearable placeholder="Status" class="w180">
              <el-option v-for="item in taskStatuses" :key="item" :label="item" :value="item" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="load">Search</el-button>
          </div>
          <el-table v-loading="loading" :data="tasks" border>
            <el-table-column prop="task_name" label="Task" min-width="180" />
            <el-table-column prop="application_id" label="Application" width="110" />
            <el-table-column prop="status_text" label="Status" width="130" />
            <el-table-column prop="purchase_order_id" label="Purchase Order" width="130" />
            <el-table-column prop="reject_reason" label="Reject reason" min-width="160" />
            <el-table-column label="Actions" width="190">
              <template slot-scope="scope">
                <el-button type="text" @click="reviewTask(scope.row, 'approve')">Approve</el-button>
                <el-button type="text" @click="reviewTask(scope.row, 'reject')">Reject</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="开店验收" name="acceptance">
          <el-table v-loading="loading" :data="acceptances" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="application_id" label="Application" width="110" />
            <el-table-column prop="system_store_id" label="Store" width="100" />
            <el-table-column prop="status_text" label="Status" width="150" />
            <el-table-column prop="reject_reason" label="Reject reason" min-width="160" />
            <el-table-column label="Actions" width="260">
              <template slot-scope="scope">
                <el-button type="text" @click="reviewAcceptance(scope.row, 'reviewing')">Reviewing</el-button>
                <el-button type="text" @click="reviewAcceptance(scope.row, 'pass')">Pass</el-button>
                <el-button type="text" @click="reviewAcceptance(scope.row, 'reject')">Reject</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <el-dialog title="新建加盟合同" :visible.sync="createVisible" width="460px">
      <el-form label-width="130px">
        <el-form-item label="申请 ID">
          <el-input v-model="createForm.application_id" />
        </el-form-item>
        <el-form-item label="合同金额">
          <el-input v-model="createForm.amount_snapshot" />
        </el-form-item>
        <el-form-item label="附件 ID">
          <el-input v-model="createForm.attachment_ids" placeholder="1,2,3" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="createVisible = false">取消</el-button>
        <el-button type="primary" @click="createContract">保存</el-button>
      </span>
    </el-dialog>

    <el-drawer title="加盟开店详情" :visible.sync="detailVisible" size="48%">
      <div class="drawer-body">
        <el-descriptions v-if="detail.application" :column="2" border>
          <el-descriptions-item label="Application">{{ detail.application.application_no }}</el-descriptions-item>
          <el-descriptions-item label="Status">{{ detail.application.status_text }}</el-descriptions-item>
          <el-descriptions-item label="Applicant">{{ detail.application.name }}</el-descriptions-item>
          <el-descriptions-item label="Phone">{{ detail.application.phone_masked }}</el-descriptions-item>
        </el-descriptions>
        <h4>正式门店档案</h4>
        <el-form label-width="120px">
          <el-form-item label="门店名称"><el-input v-model="profileForm.store_name" /></el-form-item>
          <el-form-item label="门店类型"><el-input v-model="profileForm.intended_store_type" /></el-form-item>
          <el-form-item label="城市"><el-input v-model="profileForm.city" /></el-form-item>
          <el-form-item label="详细地址"><el-input v-model="profileForm.address" /></el-form-item>
          <el-form-item label="资料状态"><el-select v-model="profileForm.status"><el-option label="已提交" value="submitted" /><el-option label="总部已核验" value="verified" /><el-option label="已绑定正式门店" value="bound" /></el-select></el-form-item>
          <el-form-item label="已有门店 ID"><el-input v-model="profileForm.system_store_id" /></el-form-item>
          <el-button type="primary" @click="saveProfile">保存门店档案</el-button>
          <el-button @click="bindStore">绑定已有门店</el-button>
          <el-button type="warning" @click="createFormalStore">验收通过后创建正式门店</el-button>
        </el-form>
        <h4>正式开店授权</h4>
        <el-alert title="正式开店固定授予县级合伙人；可按总部设置同时兼任店长。历史 franchisee 仅作为兼容门店权限保留。" type="info" :closable="false" show-icon />
        <el-button type="success" @click="grantIdentity('county_partner')">开通县级合伙人</el-button>
        <el-button type="success" @click="grantIdentity('all')">开通县级合伙人并兼任店长</el-button>
      </div>
    </el-drawer>
  </div>
</template>

<script>
import {
  yfthFranchiseOpeningAcceptanceList,
  yfthFranchiseOpeningAcceptanceReview,
  yfthFranchiseOpeningContractConfirm,
  yfthFranchiseOpeningContractCreate,
  yfthFranchiseOpeningContractDetail,
  yfthFranchiseOpeningContractList,
  yfthFranchiseOpeningIdentityGrant,
  yfthFranchiseOpeningPaymentConfirm,
  yfthFranchiseOpeningPaymentList,
  yfthFranchiseOpeningPaymentReject,
  yfthFranchiseOpeningProfileBindStore,
  yfthFranchiseOpeningProfileCreateStore,
  yfthFranchiseOpeningProfileSave,
  yfthFranchiseOpeningTaskList,
  yfthFranchiseOpeningTaskReview,
} from '@/api/yfth';

export default {
  name: 'YfthFranchiseOpening',
  data() {
    return {
      activeTab: 'contracts',
      loading: false,
      contracts: [],
      payments: [],
      tasks: [],
      acceptances: [],
      contractFilters: { status: '', application_id: '' },
      taskFilters: { status: '', application_id: '' },
      contractStatuses: ['pending_user_confirm', 'user_confirmed', 'hq_confirmed', 'signed'],
      taskStatuses: ['pending', 'in_progress', 'submitted', 'approved', 'rejected'],
      createVisible: false,
      createForm: { application_id: '', amount_snapshot: '0.00', attachment_ids: '' },
      detailVisible: false,
      detail: {},
      profileForm: {},
    };
  },
  created() {
    this.load();
  },
  methods: {
    load() {
      this.loading = true;
      const done = () => { this.loading = false; };
      if (this.activeTab === 'payments') {
        yfthFranchiseOpeningPaymentList({}).then((res) => { this.payments = (res.data && res.data.list) || []; }).finally(done);
      } else if (this.activeTab === 'tasks') {
        yfthFranchiseOpeningTaskList(this.taskFilters).then((res) => { this.tasks = (res.data && res.data.list) || []; }).finally(done);
      } else if (this.activeTab === 'acceptance') {
        yfthFranchiseOpeningAcceptanceList({}).then((res) => { this.acceptances = (res.data && res.data.list) || []; }).finally(done);
      } else {
        yfthFranchiseOpeningContractList(this.contractFilters).then((res) => { this.contracts = (res.data && res.data.list) || []; }).finally(done);
      }
    },
    createContract() {
      yfthFranchiseOpeningContractCreate({
        application_id: Number(this.createForm.application_id || 0),
        amount_snapshot: this.createForm.amount_snapshot,
        attachment_ids: this.createForm.attachment_ids,
      }).then(() => {
        this.createVisible = false;
        this.$message.success('Saved');
        this.load();
      });
    },
    confirmContract(row, action) {
      yfthFranchiseOpeningContractConfirm(row.id, { action }).then(() => {
        this.$message.success('Done');
        this.load();
      });
    },
    confirmPayment(row) {
      yfthFranchiseOpeningPaymentConfirm(row.id).then(() => {
        this.$message.success('Confirmed');
        this.load();
      });
    },
    rejectPayment(row) {
      this.$prompt('Reject reason', 'Reject payment').then(({ value }) => {
        return yfthFranchiseOpeningPaymentReject(row.id, { reason: value || '' });
      }).then(() => {
        this.$message.success('Rejected');
        this.load();
      });
    },
    reviewTask(row, action) {
      const request = action === 'reject'
        ? this.$prompt('Reject reason', 'Reject task').then(({ value }) => yfthFranchiseOpeningTaskReview(row.id, { action, reject_reason: value || '' }))
        : yfthFranchiseOpeningTaskReview(row.id, { action });
      request.then(() => {
        this.$message.success('Done');
        this.load();
      });
    },
    reviewAcceptance(row, action) {
      const request = action === 'reject'
        ? this.$prompt('Reject reason', 'Reject acceptance').then(({ value }) => yfthFranchiseOpeningAcceptanceReview(row.id, { action, reject_reason: value || '' }))
        : yfthFranchiseOpeningAcceptanceReview(row.id, { action });
      request.then(() => {
        this.$message.success('Done');
        this.load();
      });
    },
    openDetail(row) {
      yfthFranchiseOpeningContractDetail(row.id).then((res) => {
        this.detail = res.data || {};
        this.profileForm = Object.assign({ application_id: row.application_id, system_store_id: '' }, this.detail.store_profile || {});
        this.detailVisible = true;
      });
    },
    saveProfile() {
      yfthFranchiseOpeningProfileSave(Object.assign({}, this.profileForm, {
        application_id: this.detail.application.id,
      })).then((res) => {
        this.profileForm = Object.assign({}, this.profileForm, (res.data && res.data.store_profile) || {});
        this.$message.success('门店档案已保存');
      });
    },
    bindStore() {
      yfthFranchiseOpeningProfileBindStore(this.profileForm.id, {
        system_store_id: Number(this.profileForm.system_store_id || 0),
      }).then(() => {
        this.$message.success('已有门店已绑定');
      });
    },
    createFormalStore() {
      this.$confirm('仅在合同已签署、财务确认到账、筹备完成且验收通过后创建正式门店。是否继续？', '创建正式门店', { type: 'warning' })
        .then(() => yfthFranchiseOpeningProfileCreateStore(this.profileForm.id, { reason: 'headquarters_formal_store_opening' }))
        .then((res) => {
          this.profileForm = Object.assign({}, this.profileForm, (res.data && res.data.store_profile) || {});
          this.$message.success((res.data && res.data.created) ? '正式门店已创建并绑定' : '正式门店已存在');
        });
    },
    grantIdentity(roleCode) {
      yfthFranchiseOpeningIdentityGrant({
        application_id: this.detail.application.id,
        role_code: roleCode,
        reason: 'final_opening_confirmation',
      }).then(() => {
        this.$message.success('正式开店身份已授予');
      });
    },
  },
};
</script>

<style scoped>
.toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; }
.w160 { width: 160px; }
.w180 { width: 180px; }
.drawer-body { padding: 0 20px 20px; }
h4 { margin: 18px 0 12px; color: #303133; }
</style>
