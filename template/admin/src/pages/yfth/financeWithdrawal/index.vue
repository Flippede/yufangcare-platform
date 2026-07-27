<template>
  <div class="finance-withdrawal">
    <div class="page-head">
      <div>
        <h2>提现审核</h2>
        <p>统一处理招商合伙人与 B1 门店的提现申请。系统不自动打款，财务线下完成银行转账后再确认。</p>
      </div>
      <el-tag type="warning">资金操作</el-tag>
    </div>

    <el-alert
      title="审核通过只锁定申请，不扣减收益；确认线下打款完成后才写入资金流水并对冲金额。"
      type="warning"
      :closable="false"
      show-icon
    />

    <div class="toolbar">
      <el-select v-model="query.owner_type" clearable placeholder="申请主体">
        <el-option label="招商合伙人" value="partner" />
        <el-option label="B1门店" value="store" />
      </el-select>
      <el-select v-model="query.status" clearable placeholder="状态">
        <el-option label="待审核" value="pending_review" />
        <el-option label="待线下打款" value="approved" />
        <el-option label="已驳回" value="rejected" />
        <el-option label="已打款" value="paid" />
      </el-select>
      <el-select v-model="query.rank_code" clearable placeholder="合伙人职级">
        <el-option v-for="item in ranks" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
      <el-input v-model.trim="query.keyword" clearable placeholder="申请号、用户、手机号或门店" @keyup.enter.native="search" />
      <el-button type="primary" icon="el-icon-search" @click="search">查询</el-button>
      <el-button @click="reset">重置</el-button>
    </div>

    <el-table v-loading="loading" :data="list" border size="small">
      <el-table-column prop="request_no" label="申请号" min-width="210" />
      <el-table-column label="主体" min-width="170">
        <template slot-scope="{ row }">
          <div>{{ ownerName(row) }}</div>
          <small>{{ row.owner_type === 'partner' ? rankName(row.rank_code) : 'B1门店' }}</small>
        </template>
      </el-table-column>
      <el-table-column label="申请金额" width="120">
        <template slot-scope="{ row }"><strong>￥{{ row.amount }}</strong></template>
      </el-table-column>
      <el-table-column label="收款信息" min-width="180">
        <template slot-scope="{ row }">
          <div>{{ row.receiver_name_masked }} · {{ row.bank_name }}</div>
          <small>{{ row.receiver_account_masked }}</small>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="120">
        <template slot-scope="{ row }">
          <el-tag size="mini" :type="statusType(row.status)">{{ statusName(row.status) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="申请时间" width="165">
        <template slot-scope="{ row }">{{ timeText(row.add_time) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="250" fixed="right">
        <template slot-scope="{ row }">
          <el-button type="text" @click="openDetail(row)">详情</el-button>
          <el-button v-if="row.status === 'pending_review'" type="text" @click="openReview(row, 'approve')">审核通过</el-button>
          <el-button v-if="row.status === 'pending_review'" type="text" class="danger-text" @click="openReview(row, 'reject')">驳回</el-button>
          <el-button v-if="row.status === 'approved'" type="text" @click="openPaid(row)">确认已打款</el-button>
        </template>
      </el-table-column>
    </el-table>

    <div class="pagination">
      <el-pagination
        background
        layout="total, prev, pager, next"
        :total="count"
        :page-size="query.limit"
        :current-page="query.page"
        @current-change="changePage"
      />
    </div>

    <el-dialog title="提现申请详情" :visible.sync="detailVisible" width="760px">
      <el-descriptions v-if="detail.id" :column="2" border>
        <el-descriptions-item label="申请号">{{ detail.request_no }}</el-descriptions-item>
        <el-descriptions-item label="状态">{{ statusName(detail.status) }}</el-descriptions-item>
        <el-descriptions-item label="申请主体">{{ ownerName(detail) }}</el-descriptions-item>
        <el-descriptions-item label="金额">￥{{ detail.amount }}</el-descriptions-item>
        <el-descriptions-item label="收款人">{{ detail.receiver_name }}</el-descriptions-item>
        <el-descriptions-item label="开户银行">{{ detail.bank_name }}</el-descriptions-item>
        <el-descriptions-item label="银行卡号" :span="2">{{ detail.receiver_account }}</el-descriptions-item>
        <el-descriptions-item label="申请说明" :span="2">{{ detail.applicant_remark || '-' }}</el-descriptions-item>
        <el-descriptions-item label="审核说明" :span="2">{{ detail.review_reason || '-' }}</el-descriptions-item>
        <el-descriptions-item label="打款流水号">{{ detail.pay_reference || '-' }}</el-descriptions-item>
        <el-descriptions-item label="打款时间">{{ timeText(detail.paid_time) }}</el-descriptions-item>
      </el-descriptions>
      <el-table v-if="detail.allocations && detail.allocations.length" :data="detail.allocations" border size="mini" class="allocations">
        <el-table-column prop="source_type" label="收益来源" />
        <el-table-column prop="source_id" label="来源ID" width="100" />
        <el-table-column label="分配金额" width="120">
          <template slot-scope="{ row }">￥{{ money(row.amount_cent) }}</template>
        </el-table-column>
        <el-table-column prop="status" label="冻结状态" width="100" />
      </el-table>
    </el-dialog>

    <el-dialog :title="reviewForm.action === 'approve' ? '审核通过' : '驳回申请'" :visible.sync="reviewVisible" width="520px">
      <el-alert
        v-if="reviewForm.action === 'approve'"
        title="审核通过后仍需线下银行打款，当前操作不会扣减申请人的收益。"
        type="info"
        :closable="false"
      />
      <el-form label-width="90px" class="dialog-form">
        <el-form-item label="审核原因">
          <el-input v-model.trim="reviewForm.reason" type="textarea" :rows="4" maxlength="255" show-word-limit />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="reviewVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitReview">确认</el-button>
      </span>
    </el-dialog>

    <el-dialog title="确认线下打款完成" :visible.sync="paidVisible" width="540px">
      <el-alert title="请先在银行/U盾完成真实转账。确认后将不可逆地对冲申请金额。" type="error" :closable="false" show-icon />
      <el-form label-width="110px" class="dialog-form">
        <el-form-item label="银行流水号">
          <el-input v-model.trim="paidForm.pay_reference" maxlength="128" />
        </el-form-item>
        <el-form-item label="打款说明">
          <el-input v-model.trim="paidForm.remark" type="textarea" :rows="4" maxlength="255" show-word-limit />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="paidVisible = false">取消</el-button>
        <el-button type="danger" :loading="submitting" @click="submitPaid">确认已打款并对冲</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthFundWithdrawalDetail,
  yfthFundWithdrawalList,
  yfthFundWithdrawalPaid,
  yfthFundWithdrawalReview,
} from '@/api/yfth';

export default {
  name: 'YfthFinanceWithdrawal',
  data() {
    return {
      loading: false,
      submitting: false,
      list: [],
      count: 0,
      query: { owner_type: '', status: '', rank_code: '', keyword: '', page: 1, limit: 20 },
      detail: {},
      detailVisible: false,
      reviewVisible: false,
      paidVisible: false,
      currentId: 0,
      reviewForm: { action: 'approve', reason: '' },
      paidForm: { pay_reference: '', remark: '' },
      ranks: [
        { value: 'county_partner', label: '县级合伙人' },
        { value: 'prefecture_partner', label: '地级合伙人' },
        { value: 'province_partner', label: '省级合伙人' },
        { value: 'regional_director', label: '大区总监' },
        { value: 'platform_director', label: '平台董事' },
      ],
    };
  },
  created() {
    this.load();
  },
  methods: {
    load() {
      this.loading = true;
      return yfthFundWithdrawalList(this.query).then((res) => {
        this.list = (res.data && res.data.list) || [];
        this.count = Number((res.data && res.data.count) || 0);
      }).finally(() => {
        this.loading = false;
      });
    },
    search() {
      this.query.page = 1;
      this.load();
    },
    reset() {
      this.query = { owner_type: '', status: '', rank_code: '', keyword: '', page: 1, limit: 20 };
      this.load();
    },
    changePage(page) {
      this.query.page = page;
      this.load();
    },
    openDetail(row) {
      yfthFundWithdrawalDetail(row.id).then((res) => {
        this.detail = res.data || {};
        this.detailVisible = true;
      });
    },
    openReview(row, action) {
      this.currentId = row.id;
      this.reviewForm = { action, reason: '' };
      this.reviewVisible = true;
    },
    submitReview() {
      if (this.reviewForm.reason.length < 4) return this.$message.warning('审核原因不少于4个字');
      this.submitting = true;
      yfthFundWithdrawalReview(this.currentId, this.reviewForm).then(() => {
        this.$message.success(this.reviewForm.action === 'approve' ? '审核已通过，等待线下打款' : '申请已驳回');
        this.reviewVisible = false;
        this.load();
      }).finally(() => {
        this.submitting = false;
      });
    },
    openPaid(row) {
      this.currentId = row.id;
      this.paidForm = { pay_reference: '', remark: '' };
      this.paidVisible = true;
    },
    submitPaid() {
      if (!this.paidForm.pay_reference) return this.$message.warning('请填写银行流水号');
      if (this.paidForm.remark.length < 4) return this.$message.warning('打款说明不少于4个字');
      this.submitting = true;
      yfthFundWithdrawalPaid(this.currentId, this.paidForm).then(() => {
        this.$message.success('已记录线下打款并完成金额对冲');
        this.paidVisible = false;
        this.load();
      }).finally(() => {
        this.submitting = false;
      });
    },
    ownerName(row) {
      if (row.owner_type === 'store') return row.store_name || `门店 ${row.store_id}`;
      return row.nickname || row.applicant_phone_masked || `合伙人 ${row.owner_id}`;
    },
    rankName(value) {
      const found = this.ranks.find((item) => item.value === value);
      return found ? found.label : value;
    },
    statusName(value) {
      return ({
        pending_review: '待审核',
        approved: '待线下打款',
        rejected: '已驳回',
        paid: '已打款',
      })[value] || value;
    },
    statusType(value) {
      return ({ pending_review: 'warning', approved: 'primary', rejected: 'danger', paid: 'success' })[value] || 'info';
    },
    timeText(value) {
      return value ? new Date(Number(value) * 1000).toLocaleString() : '-';
    },
    money(cent) {
      return (Number(cent || 0) / 100).toFixed(2);
    },
  },
};
</script>

<style scoped lang="scss">
.finance-withdrawal { min-height: calc(100vh - 104px); padding: 20px; background: #fff; }
.page-head { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; }
.page-head h2 { margin: 0 0 8px; font-size: 22px; }
.page-head p { margin: 0; color: #777; }
.toolbar { display: flex; align-items: center; gap: 10px; margin: 18px 0; }
.toolbar .el-select { width: 160px; }
.toolbar .el-input { width: 260px; }
.pagination { display: flex; justify-content: flex-end; margin-top: 18px; }
.dialog-form { margin-top: 18px; }
.allocations { margin-top: 18px; }
.danger-text { color: #f56c6c; }
small { color: #999; }
</style>
