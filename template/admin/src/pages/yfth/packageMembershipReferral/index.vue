<template>
  <div class="package-membership-page">
    <el-alert
      title="本模块仅记录套餐激活后的永久会员事实与一级推荐奖励候选，不提供结算、提现或打款。"
      type="info"
      :closable="false"
    />
    <el-tabs v-model="tab" @tab-click="loadTab">
      <el-tab-pane label="永久会员" name="members">
        <div class="toolbar">
          <el-input v-model="memberQuery.uid" clearable placeholder="用户 UID" />
          <el-input v-model="memberQuery.store_id" clearable placeholder="门店 ID" />
          <el-select v-model="memberQuery.status" clearable placeholder="状态">
            <el-option label="有效" value="active" />
          </el-select>
          <el-button type="primary" icon="el-icon-search" @click="loadMembers(true)">查询</el-button>
          <el-button icon="el-icon-refresh" @click="loadMembers()">刷新</el-button>
        </div>
        <el-table v-loading="loading" :data="members" border size="small">
          <el-table-column prop="membership_no" label="会员编号" min-width="180" />
          <el-table-column prop="uid" label="UID" width="90" />
          <el-table-column prop="store_id" label="归属门店" width="100" />
          <el-table-column prop="source_package_instance_id" label="套餐实例" width="110" />
          <el-table-column prop="source_rule_version_id" label="规则版本" width="100" />
          <el-table-column label="实际成交金额" width="130">
            <template slot-scope="{ row }">{{ money(row.actual_paid_amount_cent) }}</template>
          </el-table-column>
          <el-table-column prop="status" label="状态" width="90" />
          <el-table-column label="首次激活" width="170">
            <template slot-scope="{ row }">{{ time(row.activated_at) }}</template>
          </el-table-column>
        </el-table>
        <el-pagination class="pager" layout="total, prev, pager, next" :total="memberTotal" :page-size="memberQuery.limit" :current-page.sync="memberQuery.page" @current-change="loadMembers" />
      </el-tab-pane>

      <el-tab-pane label="奖励候选" name="candidates">
        <div class="toolbar">
          <el-input v-model="candidateQuery.referrer_uid" clearable placeholder="推荐人 UID" />
          <el-input v-model="candidateQuery.referred_uid" clearable placeholder="被推荐人 UID" />
          <el-input v-model="candidateQuery.store_id" clearable placeholder="责任门店 ID" />
          <el-select v-model="candidateQuery.candidate_type" clearable placeholder="类型">
            <el-option label="套餐激活" value="package_activation" />
            <el-option label="商城消费" value="mall_consumption" />
          </el-select>
          <el-button type="primary" icon="el-icon-search" @click="loadCandidates(true)">查询</el-button>
        </div>
        <el-table v-loading="loading" :data="candidates" border size="small">
          <el-table-column prop="candidate_no" label="候选编号" min-width="180" />
          <el-table-column prop="candidate_type" label="类型" width="130" />
          <el-table-column prop="referrer_uid" label="推荐人" width="90" />
          <el-table-column prop="referred_uid" label="被推荐人" width="100" />
          <el-table-column prop="store_id" label="责任门店" width="100" />
          <el-table-column prop="reward_sequence_no" label="成交序号" width="100" />
          <el-table-column label="比例" width="90"><template slot-scope="{ row }">{{ (row.ratio_bps / 100).toFixed(2) }}%</template></el-table-column>
          <el-table-column label="实际成交" width="120"><template slot-scope="{ row }">{{ money(row.actual_paid_amount_cent) }}</template></el-table-column>
          <el-table-column label="候选金额" width="120"><template slot-scope="{ row }">{{ money(row.reward_amount_cent) }}</template></el-table-column>
          <el-table-column prop="status" label="状态" width="90" />
        </el-table>
        <el-pagination class="pager" layout="total, prev, pager, next" :total="candidateTotal" :page-size="candidateQuery.limit" :current-page.sync="candidateQuery.page" @current-change="loadCandidates" />
      </el-tab-pane>

      <el-tab-pane label="规则版本" name="rules">
        <div class="toolbar">
          <el-select v-model="ruleQuery.status" clearable placeholder="状态">
            <el-option label="草稿" value="draft" />
            <el-option label="已发布" value="published" />
            <el-option label="已替代" value="superseded" />
          </el-select>
          <el-button type="primary" icon="el-icon-plus" @click="openRule()">新建规则</el-button>
          <el-button icon="el-icon-refresh" @click="loadRules()">刷新</el-button>
        </div>
        <el-table v-loading="loading" :data="rules" border size="small">
          <el-table-column prop="version_no" label="版本" width="90" />
          <el-table-column prop="status" label="状态" width="100" />
          <el-table-column label="套餐循环比例" min-width="190">
            <template slot-scope="{ row }">{{ row.package_ratio_first_bps / 100 }}% / {{ row.package_ratio_second_bps / 100 }}% / {{ row.package_ratio_third_bps / 100 }}%</template>
          </el-table-column>
          <el-table-column label="普通商城比例" min-width="160">
            <template slot-scope="{ row }">{{ row.mall_consumption_enabled ? `${row.mall_consumption_ratio_bps / 100}%` : '未启用' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="150">
            <template slot-scope="{ row }">
              <el-button v-if="row.status === 'draft'" type="text" @click="openRule(row)">编辑</el-button>
              <el-button v-if="row.status === 'draft'" type="text" @click="publish(row)">发布</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="backfill-band">
          <strong>历史套餐会员识别</strong>
          <span>先执行 dry-run，确认结果后再使用受控 execute。</span>
          <el-input v-model="backfill.reason" placeholder="execute 必填原因" />
          <el-button @click="runBackfill('dry_run')">Dry-run</el-button>
          <el-button type="warning" @click="runBackfill('execute')">执行回填</el-button>
        </div>
      </el-tab-pane>
    </el-tabs>

    <el-dialog title="一级推荐规则" :visible.sync="ruleVisible" width="560px">
      <el-form label-width="150px">
        <el-form-item label="版本号"><el-input v-model="ruleForm.version_no" placeholder="留空自动递增" /></el-form-item>
        <el-form-item label="套餐循环比例"><el-input value="15% / 25% / 60%" disabled /></el-form-item>
        <el-form-item label="普通商城收益">
          <el-switch v-model="ruleForm.mall_consumption_enabled" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="普通商城比例(BPS)"><el-input-number v-model="ruleForm.mall_consumption_ratio_bps" :min="0" :max="10000" /></el-form-item>
        <el-form-item label="生效时间"><el-date-picker v-model="ruleForm.effective_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" /></el-form-item>
        <el-form-item label="失效时间"><el-date-picker v-model="ruleForm.expires_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="ruleVisible = false">取消</el-button>
        <el-button type="primary" @click="saveRule">保存草稿</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthPackageMembershipCandidateList,
  yfthPackageMembershipLegacyBackfill,
  yfthPackageMembershipMemberList,
  yfthPackageMembershipRuleList,
  yfthPackageMembershipRulePublish,
  yfthPackageMembershipRuleSave,
} from '@/api/yfth';

export default {
  name: 'YfthPackageMembershipReferral',
  data() {
    return {
      tab: 'members', loading: false,
      members: [], memberTotal: 0, memberQuery: { uid: '', store_id: '', status: '', page: 1, limit: 20 },
      candidates: [], candidateTotal: 0,
      candidateQuery: { referrer_uid: '', referred_uid: '', store_id: '', candidate_type: '', status: '', page: 1, limit: 20 },
      rules: [], ruleQuery: { status: '', page: 1, limit: 50 },
      ruleVisible: false, ruleForm: {}, backfill: { reason: '' },
    };
  },
  mounted() { this.loadMembers(); },
  methods: {
    loadTab() {
      if (this.tab === 'members') this.loadMembers();
      if (this.tab === 'candidates') this.loadCandidates();
      if (this.tab === 'rules') this.loadRules();
    },
    loadMembers(reset) {
      if (reset) this.memberQuery.page = 1;
      this.loading = true;
      yfthPackageMembershipMemberList(this.memberQuery).then((res) => {
        this.members = (res.data && res.data.list) || [];
        this.memberTotal = Number((res.data && res.data.count) || 0);
      }).finally(() => { this.loading = false; });
    },
    loadCandidates(reset) {
      if (reset) this.candidateQuery.page = 1;
      this.loading = true;
      yfthPackageMembershipCandidateList(this.candidateQuery).then((res) => {
        this.candidates = (res.data && res.data.list) || [];
        this.candidateTotal = Number((res.data && res.data.count) || 0);
      }).finally(() => { this.loading = false; });
    },
    loadRules() {
      this.loading = true;
      yfthPackageMembershipRuleList(this.ruleQuery).then((res) => { this.rules = (res.data && res.data.list) || []; })
        .finally(() => { this.loading = false; });
    },
    openRule(row) {
      this.ruleForm = Object.assign({
        id: 0, version_no: 0,
        package_ratio_first_bps: 1500, package_ratio_second_bps: 2500, package_ratio_third_bps: 6000,
        mall_consumption_enabled: 0, mall_consumption_ratio_bps: 0, effective_at: '', expires_at: '',
      }, row || {});
      this.ruleVisible = true;
    },
    saveRule() {
      yfthPackageMembershipRuleSave(this.ruleForm).then(() => {
        this.$message.success('规则草稿已保存'); this.ruleVisible = false; this.loadRules();
      });
    },
    publish(row) {
      this.$confirm('发布后该版本不可修改，并替代当前生效版本。', '发布规则').then(() => {
        yfthPackageMembershipRulePublish(row.id).then(() => { this.$message.success('规则已发布'); this.loadRules(); });
      });
    },
    runBackfill(mode) {
      if (mode === 'execute' && !this.backfill.reason.trim()) {
        this.$message.warning('执行回填必须填写原因'); return;
      }
      const action = () => yfthPackageMembershipLegacyBackfill({
        mode, limit: 100, reason: this.backfill.reason, request_id: `admin-${mode}-${Date.now()}`,
      }).then((res) => {
        const data = res.data || {};
        this.$message.success(`符合 ${data.eligible || 0}，新增 ${data.created || 0}，失败 ${data.failed || 0}`);
        if (mode === 'execute') this.loadMembers(true);
      });
      if (mode === 'execute') this.$confirm('确认执行历史会员回填？', '高风险操作').then(action);
      else action();
    },
    money(value) { return `¥${(Number(value || 0) / 100).toFixed(2)}`; },
    time(value) {
      if (!value) return '-';
      const date = new Date(Number(value) * 1000); const pad = (item) => String(item).padStart(2, '0');
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    },
  },
};
</script>

<style scoped>
.package-membership-page { padding: 16px; }
.toolbar { display: flex; gap: 10px; align-items: center; margin: 16px 0; }
.toolbar .el-input, .toolbar .el-select { width: 170px; }
.pager { margin-top: 16px; text-align: right; }
.backfill-band { display: flex; gap: 12px; align-items: center; margin-top: 24px; padding: 16px 0; border-top: 1px solid #ebeef5; }
.backfill-band .el-input { width: 280px; }
</style>
