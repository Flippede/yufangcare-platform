<template>
  <div class="yfth-franchise-opening">
    <el-card shadow="never" :body-style="{ padding: '16px' }">
      <el-tabs v-model="activeTab" @tab-click="load">
        <el-tab-pane label="Contracts" name="contracts">
          <div class="toolbar">
            <el-input v-model="contractFilters.application_id" clearable placeholder="Application ID" class="w160" />
            <el-select v-model="contractFilters.status" clearable placeholder="Status" class="w180">
              <el-option v-for="item in contractStatuses" :key="item" :label="item" :value="item" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="load">Search</el-button>
            <el-button icon="el-icon-plus" @click="createVisible = true">Create contract</el-button>
          </div>
          <el-table v-loading="loading" :data="contracts" border>
            <el-table-column prop="contract_no" label="Contract No" min-width="190" />
            <el-table-column prop="application_id" label="Application" width="110" />
            <el-table-column prop="applicant_uid" label="UID" width="100" />
            <el-table-column prop="amount_snapshot" label="Amount" width="120" />
            <el-table-column prop="status_text" label="Status" width="150" />
            <el-table-column label="Actions" width="310" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" @click="openDetail(scope.row)">Detail</el-button>
                <el-button type="text" @click="confirmContract(scope.row, 'hq_confirm')">HQ confirm</el-button>
                <el-button type="text" @click="confirmContract(scope.row, 'sign')">Sign</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Payments" name="payments">
          <el-table v-loading="loading" :data="payments" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="application_id" label="Application" width="110" />
            <el-table-column prop="contract_id" label="Contract" width="100" />
            <el-table-column prop="amount_snapshot" label="Amount" width="120" />
            <el-table-column prop="status_text" label="Status" width="150" />
            <el-table-column prop="reject_reason" label="Reject reason" min-width="160" />
            <el-table-column label="Actions" width="220">
              <template slot-scope="scope">
                <el-button type="text" @click="confirmPayment(scope.row)">Confirm</el-button>
                <el-button type="text" @click="rejectPayment(scope.row)">Reject</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Tasks" name="tasks">
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

        <el-tab-pane label="Acceptance" name="acceptance">
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

    <el-dialog title="Create contract" :visible.sync="createVisible" width="460px">
      <el-form label-width="130px">
        <el-form-item label="Application ID">
          <el-input v-model="createForm.application_id" />
        </el-form-item>
        <el-form-item label="Amount">
          <el-input v-model="createForm.amount_snapshot" />
        </el-form-item>
        <el-form-item label="Attachment IDs">
          <el-input v-model="createForm.attachment_ids" placeholder="1,2,3" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="createVisible = false">Cancel</el-button>
        <el-button type="primary" @click="createContract">Save</el-button>
      </span>
    </el-dialog>

    <el-drawer title="Opening detail" :visible.sync="detailVisible" size="48%">
      <div class="drawer-body">
        <el-descriptions v-if="detail.application" :column="2" border>
          <el-descriptions-item label="Application">{{ detail.application.application_no }}</el-descriptions-item>
          <el-descriptions-item label="Status">{{ detail.application.status_text }}</el-descriptions-item>
          <el-descriptions-item label="Applicant">{{ detail.application.name }}</el-descriptions-item>
          <el-descriptions-item label="Phone">{{ detail.application.phone_masked }}</el-descriptions-item>
        </el-descriptions>
        <h4>Store profile</h4>
        <el-form label-width="120px">
          <el-form-item label="Store name"><el-input v-model="profileForm.store_name" /></el-form-item>
          <el-form-item label="Store type"><el-input v-model="profileForm.intended_store_type" /></el-form-item>
          <el-form-item label="City"><el-input v-model="profileForm.city" /></el-form-item>
          <el-form-item label="Address"><el-input v-model="profileForm.address" /></el-form-item>
          <el-form-item label="System store"><el-input v-model="profileForm.system_store_id" /></el-form-item>
          <el-button type="primary" @click="saveProfile">Save profile</el-button>
          <el-button @click="bindStore">Bind store</el-button>
        </el-form>
        <h4>Identity grant</h4>
        <el-button type="success" @click="grantIdentity('all')">Grant franchisee + manager</el-button>
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
      })).then(() => {
        this.$message.success('Saved');
      });
    },
    bindStore() {
      yfthFranchiseOpeningProfileBindStore(this.profileForm.id, {
        system_store_id: Number(this.profileForm.system_store_id || 0),
      }).then(() => {
        this.$message.success('Bound');
      });
    },
    grantIdentity(roleCode) {
      yfthFranchiseOpeningIdentityGrant({
        application_id: this.detail.application.id,
        role_code: roleCode,
        reason: 'final_opening_confirmation',
      }).then(() => {
        this.$message.success('Granted');
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
