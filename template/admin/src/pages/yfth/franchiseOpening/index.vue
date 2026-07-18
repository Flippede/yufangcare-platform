<template>
  <div class="yfth-franchise-opening">
    <div class="page-heading">
      <div>
        <h1>加盟筹备与开店验收</h1>
        <p>合同、财务、筹备、验收和正式身份授予必须依次完成；正式授权后会同步合伙人档案及下属门店。</p>
      </div>
      <el-tag type="success" effect="plain">总部开店流程</el-tag>
    </div>
    <el-card shadow="never" :body-style="{ padding: '16px' }">
      <el-alert
        title="这里只展示已经创建合同或进入筹备的申请。新申请请先在“总部加盟申请”中逐步推进到待签约，再点击“创建合同”。"
        type="info"
        :closable="false"
        show-icon
        class="workflow-alert"
      />
      <el-tabs v-model="activeTab" @tab-click="load">
        <el-tab-pane label="加盟合同" name="contracts">
          <div class="toolbar">
            <el-input v-model="contractFilters.application_id" clearable placeholder="申请 ID" class="w160" />
            <el-select v-model="contractFilters.status" clearable placeholder="合同状态" class="w180">
              <el-option v-for="item in contractStatuses" :key="item.value" :label="item.label" :value="item.value" />
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
            <el-input v-model="taskFilters.application_id" clearable placeholder="申请 ID" class="w160" />
            <el-select v-model="taskFilters.status" clearable placeholder="筹备状态" class="w180">
              <el-option v-for="item in taskStatuses" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="load">查询</el-button>
          </div>
          <el-table v-loading="loading" :data="tasks" border>
            <el-table-column prop="task_name" label="筹备任务" min-width="180" />
            <el-table-column prop="application_id" label="申请 ID" width="110" />
            <el-table-column prop="status_text" label="状态" width="130" />
            <el-table-column prop="purchase_order_id" label="首批采购单" width="130" />
            <el-table-column prop="reject_reason" label="驳回原因" min-width="160" />
            <el-table-column label="操作" width="190">
              <template slot-scope="scope">
                <el-button type="text" @click="reviewTask(scope.row, 'approve')">通过</el-button>
                <el-button type="text" @click="reviewTask(scope.row, 'reject')">驳回</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="开店验收" name="acceptance">
          <el-table v-loading="loading" :data="acceptances" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="application_id" label="申请 ID" width="110" />
            <el-table-column prop="system_store_id" label="门店 ID" width="100" />
            <el-table-column prop="status_text" label="状态" width="150" />
            <el-table-column prop="reject_reason" label="驳回原因" min-width="160" />
            <el-table-column label="操作" width="260">
              <template slot-scope="scope">
                <el-button type="text" @click="reviewAcceptance(scope.row, 'reviewing')">开始验收</el-button>
                <el-button type="text" @click="reviewAcceptance(scope.row, 'pass')">验收通过</el-button>
                <el-button type="text" @click="reviewAcceptance(scope.row, 'reject')">验收驳回</el-button>
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
          <el-descriptions-item label="加盟申请">{{ detail.application.application_no }}</el-descriptions-item>
          <el-descriptions-item label="申请状态">{{ detail.application.status_text }}</el-descriptions-item>
          <el-descriptions-item label="申请人">{{ detail.application.name }}</el-descriptions-item>
          <el-descriptions-item label="联系电话">{{ detail.application.phone_masked }}</el-descriptions-item>
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
        <el-alert title="验收通过并绑定正式门店后，才可授予加盟商身份。授权会同步创建县级合伙人档案、下属门店和开店业绩；可选择是否兼任店长。" type="info" :closable="false" show-icon />
        <el-button type="success" @click="grantIdentity('county_partner')">授予加盟商身份</el-button>
        <el-button type="success" @click="grantIdentity('all')">授予加盟商并兼任店长</el-button>
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
      contractStatuses: [
        { label: '待申请人确认', value: 'pending_user_confirm' },
        { label: '申请人已确认', value: 'user_confirmed' },
        { label: '总部已确认', value: 'hq_confirmed' },
        { label: '已签署', value: 'signed' },
      ],
      taskStatuses: [
        { label: '待开始', value: 'pending' },
        { label: '进行中', value: 'in_progress' },
        { label: '已提交', value: 'submitted' },
        { label: '已通过', value: 'approved' },
        { label: '已驳回', value: 'rejected' },
      ],
      createVisible: false,
      createForm: { application_id: '', amount_snapshot: '0.00', attachment_ids: '' },
      detailVisible: false,
      detail: {},
      profileForm: {},
    };
  },
  created() {
    if (this.$route.query.application_id) {
      this.contractFilters.application_id = String(this.$route.query.application_id);
    }
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
        this.$message.success('加盟合同已创建');
        this.load();
      });
    },
    confirmContract(row, action) {
      yfthFranchiseOpeningContractConfirm(row.id, { action }).then(() => {
        this.$message.success(action === 'sign' ? '合同已确认签署' : '总部已确认合同');
        this.load();
      });
    },
    confirmPayment(row) {
      yfthFranchiseOpeningPaymentConfirm(row.id).then(() => {
        this.$message.success('财务到账已确认');
        this.load();
      });
    },
    rejectPayment(row) {
      this.$prompt('请输入驳回原因', '驳回付款凭证').then(({ value }) => {
        return yfthFranchiseOpeningPaymentReject(row.id, { reason: value || '' });
      }).then(() => {
        this.$message.success('付款凭证已驳回');
        this.load();
      });
    },
    reviewTask(row, action) {
      const request = action === 'reject'
        ? this.$prompt('请输入驳回原因', '驳回筹备任务').then(({ value }) => yfthFranchiseOpeningTaskReview(row.id, { action, reject_reason: value || '' }))
        : yfthFranchiseOpeningTaskReview(row.id, { action });
      request.then(() => {
        this.$message.success(action === 'approve' ? '筹备任务已通过' : '筹备任务已驳回');
        this.load();
      });
    },
    reviewAcceptance(row, action) {
      const request = action === 'reject'
        ? this.$prompt('请输入驳回原因', '驳回开店验收').then(({ value }) => yfthFranchiseOpeningAcceptanceReview(row.id, { action, reject_reason: value || '' }))
        : yfthFranchiseOpeningAcceptanceReview(row.id, { action });
      request.then(() => {
        this.$message.success(action === 'pass' ? '开店验收已通过' : action === 'reviewing' ? '已开始验收' : '开店验收已驳回');
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
      }).then((res) => {
        const partner = res.data && res.data.partner;
        this.$message.success(partner ? '加盟商身份已授予，并已同步合伙人档案和下属门店' : '加盟商身份已授予');
      });
    },
  },
};
</script>

<style scoped>
.page-heading { display: flex; justify-content: space-between; align-items: flex-start; padding: 18px 20px; margin-bottom: 14px; background: #fff; border: 1px solid #e8ecf1; border-radius: 6px; }
.page-heading h1 { margin: 0; font-size: 20px; line-height: 28px; }
.page-heading p { margin: 6px 0 0; color: #667085; font-size: 13px; line-height: 20px; }
.workflow-alert { margin-bottom: 16px; }
.toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; }
.w160 { width: 160px; }
.w180 { width: 180px; }
.drawer-body { padding: 0 20px 20px; }
h4 { margin: 18px 0 12px; color: #303133; }
</style>
