<template>
  <div class="yfth-franchise-application">
    <div class="page-heading">
      <div>
        <h1>总部加盟申请</h1>
        <p>招商合伙人在线下完成沟通与确认，总部这里只记录最终同意或驳回结果。</p>
      </div>
      <el-tag type="warning" effect="plain">总部确认</el-tag>
    </div>

    <el-card shadow="never" class="ivu-mt" :body-style="{ padding: '16px' }">
      <el-alert title="无需在线推进沟通、考察、合同或筹备状态。总部与招商合伙人确认后，直接点击“同意加盟”即可。" type="info" :closable="false" show-icon class="review-alert" />
      <div class="toolbar">
        <el-input v-model="filters.keyword" clearable placeholder="申请号、姓名、电话或城市" class="w220" />
        <el-select v-model="filters.status" clearable placeholder="审核状态" class="w170">
          <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </el-select>
        <el-input v-model="filters.applicant_uid" clearable placeholder="用户 UID" class="w120" />
        <el-input v-model="filters.city" clearable placeholder="城市" class="w140" />
        <el-button type="primary" icon="el-icon-search" @click="load(true)">查询</el-button>
        <el-button icon="el-icon-refresh-left" @click="reset">重置</el-button>
      </div>

      <el-table v-loading="loading" :data="list" border>
        <el-table-column prop="application_no" label="申请号" min-width="210" />
        <el-table-column label="申请人" min-width="150">
          <template slot-scope="scope"><div>{{ scope.row.name }}</div><div class="muted">UID: {{ scope.row.applicant_uid }}</div></template>
        </el-table-column>
        <el-table-column prop="phone_masked" label="电话" width="130" />
        <el-table-column prop="city" label="城市" width="110" />
        <el-table-column prop="intention_area" label="意向区域" min-width="140" />
        <el-table-column prop="budget" label="预算" width="110" />
        <el-table-column label="审核状态" width="120"><template slot-scope="scope">{{ reviewStatusText(scope.row.status) }}</template></el-table-column>
        <el-table-column label="提交时间" width="160"><template slot-scope="scope">{{ formatTime(scope.row.submit_time) }}</template></el-table-column>
        <el-table-column label="操作" width="300" fixed="right">
          <template slot-scope="scope">
            <el-button type="text" icon="el-icon-view" @click="openDetail(scope.row)">详情</el-button>
            <template v-if="isReviewable(scope.row)">
              <el-button type="text" icon="el-icon-check" class="approve-button" @click="openReview(scope.row, 'approve')">同意加盟</el-button>
              <el-button type="text" icon="el-icon-close" class="reject-button" @click="openReview(scope.row, 'reject')">驳回申请</el-button>
            </template>
            <el-button v-else-if="scope.row.status === 'pending_contract'" type="text" icon="el-icon-user" @click="manageIdentity(scope.row)">授予合伙人身份</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager"><el-pagination :current-page.sync="filters.page" :page-size="filters.limit" :total="count" layout="total, prev, pager, next" @current-change="load(false)" /></div>
    </el-card>

    <el-drawer title="加盟申请详情" :visible.sync="detailVisible" size="48%">
      <div v-if="detail.application" class="drawer-body">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="申请号">{{ detail.application.application_no }}</el-descriptions-item>
          <el-descriptions-item label="审核状态">{{ reviewStatusText(detail.application.status) }}</el-descriptions-item>
          <el-descriptions-item label="申请人">{{ detail.application.name }}</el-descriptions-item>
          <el-descriptions-item label="用户 UID">{{ detail.application.applicant_uid }}</el-descriptions-item>
          <el-descriptions-item label="电话">{{ detail.application.phone }}</el-descriptions-item>
          <el-descriptions-item label="城市">{{ detail.application.city }}</el-descriptions-item>
          <el-descriptions-item label="区域">{{ detail.application.region || '-' }}</el-descriptions-item>
          <el-descriptions-item label="意向区域">{{ detail.application.intention_area }}</el-descriptions-item>
          <el-descriptions-item label="预算">{{ detail.application.budget }}</el-descriptions-item>
          <el-descriptions-item label="招商来源">{{ recruitSourceText(detail.recruit_source) }}</el-descriptions-item>
          <el-descriptions-item label="备注" :span="2">{{ detail.application.remark || '-' }}</el-descriptions-item>
        </el-descriptions>
        <h4>审核记录</h4>
        <el-table :data="detail.audit_events || []" size="small" border>
          <el-table-column label="动作" width="160"><template slot-scope="scope">{{ auditActionText(scope.row.action) }}</template></el-table-column>
          <el-table-column prop="operator_uid" label="操作人" width="100" />
          <el-table-column prop="reason" label="原因" min-width="180" />
          <el-table-column label="时间" width="160"><template slot-scope="scope">{{ formatTime(scope.row.add_time) }}</template></el-table-column>
        </el-table>
      </div>
    </el-drawer>

    <el-dialog :title="reviewForm.action === 'approve' ? '同意加盟' : '驳回申请'" :visible.sync="reviewVisible" width="460px">
      <el-alert :title="reviewForm.action === 'approve' ? '确认后申请人即可在“用户经营身份”中被授予相应合伙人职级。' : '驳回后该申请不会进入后续授权。'" :type="reviewForm.action === 'approve' ? 'success' : 'warning'" :closable="false" show-icon class="review-alert" />
      <el-form label-width="90px">
        <el-form-item label="申请人"><el-input :value="currentRow.name + ' / UID ' + currentRow.applicant_uid" disabled /></el-form-item>
        <el-form-item label="确认原因"><el-input v-model.trim="reviewForm.reason" type="textarea" :rows="3" maxlength="255" show-word-limit placeholder="请记录与招商合伙人的线下确认结果" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="reviewVisible = false">取消</el-button><el-button :type="reviewForm.action === 'approve' ? 'success' : 'danger'" :loading="reviewSaving" @click="submitReview">确认</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import { yfthFranchiseApplicationDetail, yfthFranchiseApplicationList, yfthFranchiseApplicationReview } from '@/api/yfth';

export default {
  name: 'YfthFranchiseApplication',
  data() {
    return {
      loading: false, reviewSaving: false,
      filters: { keyword: '', status: '', applicant_uid: '', assigned_uid: '', city: '', page: 1, limit: 20 },
      list: [], count: 0, detailVisible: false, detail: {}, reviewVisible: false, currentRow: {},
      reviewForm: { action: 'approve', reason: '' },
      statusOptions: [
        { label: '待总部确认', value: 'submitted' },
        { label: '总部已同意', value: 'pending_contract' },
        { label: '已驳回', value: 'terminated' },
        { label: '已开业', value: 'opened' },
      ],
    };
  },
  created() { this.filters.status = this.$route.query.status || ''; this.load(true); },
  methods: {
    load(reset) {
      if (reset) this.filters.page = 1;
      this.loading = true;
      yfthFranchiseApplicationList(this.filters).then((res) => {
        this.list = (res.data && res.data.list) || [];
        this.count = (res.data && res.data.count) || 0;
      }).finally(() => { this.loading = false; });
    },
    reset() { this.filters = { keyword: '', status: '', applicant_uid: '', assigned_uid: '', city: '', page: 1, limit: 20 }; this.load(true); },
    openDetail(row) { yfthFranchiseApplicationDetail(row.id).then((res) => { this.detail = res.data || {}; this.detailVisible = true; }); },
    openReview(row, action) { this.currentRow = row; this.reviewForm = { action, reason: '' }; this.reviewVisible = true; },
    submitReview() {
      if (!this.reviewForm.reason) return this.$message.warning('请填写确认原因');
      this.reviewSaving = true;
      yfthFranchiseApplicationReview(this.currentRow.id, this.reviewForm).then(() => {
        this.reviewVisible = false;
        this.$message.success(this.reviewForm.action === 'approve' ? '总部已同意该加盟申请' : '申请已驳回');
        this.load(false);
      }).finally(() => { this.reviewSaving = false; });
    },
    manageIdentity(row) { this.$router.push({ path: '/yfth/user-role', query: { uid: row.applicant_uid } }); },
    isReviewable(row) { return ['submitted', 'contacting', 'communicating', 'inspecting'].includes(row.status); },
    reviewStatusText(status) {
      if (['submitted', 'contacting', 'communicating', 'inspecting'].includes(status)) return '待总部确认';
      return { pending_contract: '总部已同意', terminated: '已驳回', signed: '已同意', preparing: '筹备中', opened: '已开业' }[status] || status;
    },
    recruitSourceText(source) { return source && source.direct_partner_uid ? `合伙人 UID ${source.direct_partner_uid}` : '总部自然申请'; },
    auditActionText(action) { return { submit: '提交申请', offline_review_approved: '总部同意', offline_review_rejected: '总部驳回', assign_owner: '分配负责人' }[action] || action; },
    formatTime(value) {
      const ts = Number(value || 0); if (!ts) return '-';
      const date = new Date(ts * 1000); const pad = (n) => (n < 10 ? '0' + n : '' + n);
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    },
  },
};
</script>

<style scoped>
.page-heading { display: flex; justify-content: space-between; align-items: flex-start; padding: 18px 20px; background: #fff; border: 1px solid #e8ecf1; border-radius: 6px; }
.page-heading h1 { margin: 0; font-size: 20px; line-height: 28px; }
.page-heading p { margin: 6px 0 0; color: #667085; font-size: 13px; line-height: 20px; }
.review-alert { margin-bottom: 16px; }
.toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
.w120 { width: 120px; } .w140 { width: 140px; } .w170 { width: 170px; } .w220 { width: 220px; }
.pager { padding-top: 16px; text-align: right; }
.drawer-body { padding: 0 24px 24px; }
.muted { color: #909399; font-size: 12px; line-height: 1.6; }
.approve-button { color: #67c23a; } .reject-button { color: #f56c6c; }
h4 { margin: 22px 0 12px; }
</style>
