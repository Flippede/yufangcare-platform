<template>
  <div class="yfth-referral-reward">
    <el-tabs v-model="activeTab" @tab-click="loadCurrent">
      <el-tab-pane label="规则版本" name="rules">
        <div class="toolbar">
          <el-select v-model="ruleQuery.scene" size="small" placeholder="场景" clearable>
            <el-option label="5980套餐" value="package_5980" />
            <el-option label="加盟开店" value="franchise_opening" />
          </el-select>
          <el-button size="small" type="primary" @click="loadRules">查询</el-button>
          <el-button size="small" @click="newRule">新建草稿</el-button>
        </div>
        <el-table :data="rules" size="small" border>
          <el-table-column prop="rule_no" label="规则号" min-width="150" />
          <el-table-column prop="scene" label="场景" width="140" />
          <el-table-column prop="name" label="名称" min-width="160" />
          <el-table-column prop="version_no" label="版本" width="80" />
          <el-table-column prop="status" label="状态" width="110" />
          <el-table-column label="规则项" min-width="220">
            <template slot-scope="{ row }">
              <div v-for="item in row.items || []" :key="item.id">
                {{ item.title }} / {{ item.amount_cent }}分 / 观察{{ item.observe_days }}天
              </div>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="190" fixed="right">
            <template slot-scope="{ row }">
              <el-button size="mini" @click="editRule(row)" :disabled="row.status === 'published'">编辑</el-button>
              <el-button size="mini" type="success" @click="publishRule(row)" :disabled="row.status !== 'draft'">发布</el-button>
              <el-button size="mini" @click="copyRule(row)">复制</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="候选关系" name="candidates">
        <simple-filter :query="listQuery" @search="loadCandidates" />
        <el-table :data="candidates" size="small" border>
          <el-table-column prop="id" label="ID" width="80" />
          <el-table-column prop="scene" label="场景" width="140" />
          <el-table-column prop="referrer_uid" label="推荐人UID" width="110" />
          <el-table-column prop="referrer_store_id" label="门店" width="90" />
          <el-table-column prop="referred_uid" label="被推荐UID" width="110" />
          <el-table-column prop="status" label="状态" width="120" />
          <el-table-column prop="source" label="来源" width="120" />
          <el-table-column prop="bind_time" label="绑定时间" min-width="130" />
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="推荐事件" name="events">
        <simple-filter :query="listQuery" @search="loadEvents" />
        <el-table :data="events" size="small" border>
          <el-table-column prop="id" label="ID" width="80" />
          <el-table-column prop="scene" label="场景" width="140" />
          <el-table-column prop="event_type" label="事件" min-width="160" />
          <el-table-column prop="source_type" label="来源类型" width="150" />
          <el-table-column prop="source_id" label="来源ID" width="100" />
          <el-table-column prop="status" label="状态" width="110" />
          <el-table-column prop="error_code" label="错误码" min-width="140" />
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="归因记录" name="attribution">
        <simple-filter :query="listQuery" @search="loadAttribution" />
        <el-table :data="attributions" size="small" border>
          <el-table-column prop="id" label="ID" width="80" />
          <el-table-column prop="scene" label="场景" width="140" />
          <el-table-column prop="referrer_uid" label="推荐人" width="110" />
          <el-table-column prop="referred_uid" label="被推荐人" width="110" />
          <el-table-column prop="business_type" label="业务类型" min-width="160" />
          <el-table-column prop="business_id" label="业务ID" width="100" />
          <el-table-column prop="status" label="状态" width="110" />
        </el-table>
      </el-tab-pane>

      <el-tab-pane label="奖励台账" name="ledger">
        <div class="toolbar">
          <simple-filter :query="listQuery" @search="loadLedger" />
          <el-button size="small" @click="runScan(true)">扫描预览</el-button>
          <el-button size="small" type="warning" @click="runScan(false)">执行扫描</el-button>
        </div>
        <el-table :data="ledgers" size="small" border>
          <el-table-column prop="ledger_no" label="台账号" min-width="150" />
          <el-table-column prop="scene" label="场景" width="140" />
          <el-table-column prop="referrer_uid" label="推荐人" width="110" />
          <el-table-column prop="business_type" label="业务类型" width="150" />
          <el-table-column prop="amount_cent" label="金额(分)" width="100" />
          <el-table-column prop="status" label="状态" width="130" />
          <el-table-column prop="observe_end_time" label="观察结束" width="120" />
          <el-table-column label="操作" width="260" fixed="right">
            <template slot-scope="{ row }">
              <el-button size="mini" @click="openLedger(row)">详情</el-button>
              <el-button size="mini" type="success" @click="settle(row)" :disabled="!['valid','pending_settlement'].includes(row.status)">线下结算</el-button>
              <el-button size="mini" type="warning" @click="cancelSettlement(row)" :disabled="row.status !== 'settled'">取消标记</el-button>
              <el-button size="mini" type="danger" @click="reverse(row)" :disabled="['invalid','reversed'].includes(row.status)">冲正</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
    </el-tabs>

    <el-dialog :visible.sync="ruleDialog" title="奖励规则草稿" width="620px">
      <el-form label-width="100px" size="small">
        <el-form-item label="场景">
          <el-select v-model="ruleForm.scene">
            <el-option label="5980套餐" value="package_5980" />
            <el-option label="加盟开店" value="franchise_opening" />
          </el-select>
        </el-form-item>
        <el-form-item label="名称"><el-input v-model="ruleForm.name" /></el-form-item>
        <el-form-item label="版本"><el-input-number v-model="ruleForm.version_no" :min="1" /></el-form-item>
        <el-form-item label="规则项">
          <div v-for="(item, index) in ruleForm.items" :key="index" class="rule-item">
            <el-input v-model="item.title" placeholder="标题" />
            <el-input-number v-model="item.amount_cent" :min="0" placeholder="金额分" />
            <el-input-number v-model="item.observe_days" :min="0" placeholder="观察天数" />
            <el-button size="mini" @click="ruleForm.items.splice(index, 1)">删除</el-button>
          </div>
          <el-button size="mini" @click="ruleForm.items.push(defaultItem())">添加规则项</el-button>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="ruleDialog = false">取消</el-button>
        <el-button type="primary" @click="saveRule">保存</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthReferralRewardRuleList,
  yfthReferralRewardRuleSave,
  yfthReferralRewardRulePublish,
  yfthReferralRewardRuleCopy,
  yfthReferralCandidateList,
  yfthReferralEventList,
  yfthReferralAttributionList,
  yfthRewardLedgerList,
  yfthRewardLedgerSettle,
  yfthRewardLedgerCancelSettlement,
  yfthRewardLedgerReverse,
  yfthReferralRewardScan,
} from '@/api/yfth';

const SimpleFilter = {
  props: { query: Object },
  template: `
    <div class="toolbar">
      <el-select v-model="query.scene" size="small" placeholder="场景" clearable>
        <el-option label="5980套餐" value="package_5980" />
        <el-option label="加盟开店" value="franchise_opening" />
      </el-select>
      <el-input v-model="query.status" size="small" placeholder="状态" clearable />
      <el-button size="small" type="primary" @click="$emit('search')">查询</el-button>
    </div>
  `,
};

export default {
  name: 'YfthReferralReward',
  components: { SimpleFilter },
  data() {
    return {
      activeTab: 'rules',
      ruleQuery: { scene: '' },
      listQuery: { scene: '', status: '' },
      rules: [],
      candidates: [],
      events: [],
      attributions: [],
      ledgers: [],
      ruleDialog: false,
      ruleForm: {},
    };
  },
  mounted() {
    this.loadRules();
  },
  methods: {
    loadCurrent() {
      const map = {
        rules: this.loadRules,
        candidates: this.loadCandidates,
        events: this.loadEvents,
        attribution: this.loadAttribution,
        ledger: this.loadLedger,
      };
      map[this.activeTab] && map[this.activeTab]();
    },
    loadRules() {
      yfthReferralRewardRuleList(this.ruleQuery).then((res) => {
        this.rules = res.data.list || [];
      });
    },
    loadCandidates() {
      yfthReferralCandidateList(this.listQuery).then((res) => {
        this.candidates = res.data.list || [];
      });
    },
    loadEvents() {
      yfthReferralEventList(this.listQuery).then((res) => {
        this.events = res.data.list || [];
      });
    },
    loadAttribution() {
      yfthReferralAttributionList(this.listQuery).then((res) => {
        this.attributions = res.data.list || [];
      });
    },
    loadLedger() {
      yfthRewardLedgerList(this.listQuery).then((res) => {
        this.ledgers = res.data.list || [];
      });
    },
    defaultItem() {
      return { reward_scene: 'default', reward_type: 'offline_reward', title: '奖励项', amount_cent: 0, observe_days: 0, condition_snapshot: {}, status: 'active' };
    },
    newRule() {
      this.ruleForm = { scene: 'package_5980', name: '', version_no: 1, status: 'draft', items: [this.defaultItem()] };
      this.ruleDialog = true;
    },
    editRule(row) {
      this.ruleForm = JSON.parse(JSON.stringify(row));
      this.ruleDialog = true;
    },
    saveRule() {
      yfthReferralRewardRuleSave(this.ruleForm).then(() => {
        this.ruleDialog = false;
        this.loadRules();
      });
    },
    publishRule(row) {
      yfthReferralRewardRulePublish(row.id).then(this.loadRules);
    },
    copyRule(row) {
      yfthReferralRewardRuleCopy(row.id).then(this.loadRules);
    },
    settle(row) {
      this.$prompt('线下凭证号或备注', '线下结算标记').then(({ value }) => {
        return yfthRewardLedgerSettle(row.id, { offline_ref_no: value || '', remark: value || '' }).then(this.loadLedger);
      });
    },
    cancelSettlement(row) {
      this.$prompt('取消原因', '取消线下结算标记').then(({ value }) => {
        return yfthRewardLedgerCancelSettlement(row.id, { reason: value || '' }).then(this.loadLedger);
      });
    },
    reverse(row) {
      this.$prompt('冲正原因', '奖励台账冲正').then(({ value }) => {
        return yfthRewardLedgerReverse(row.id, { reason: value || '' }).then(this.loadLedger);
      });
    },
    openLedger(row) {
      this.$alert(`台账号：${row.ledger_no}\n状态：${row.status}\n金额：${row.amount_cent}分`, '台账详情');
    },
    runScan(dryRun) {
      yfthReferralRewardScan({ dry_run: dryRun ? 1 : 0, limit: 50 }).then((res) => {
        this.$message.success(`匹配 ${res.data.matched || 0} 条，处理 ${res.data.changed || 0} 条`);
        this.loadLedger();
      });
    },
  },
};
</script>

<style scoped>
.yfth-referral-reward {
  padding: 16px;
}
.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.toolbar .el-input,
.toolbar .el-select {
  width: 180px;
}
.rule-item {
  display: grid;
  grid-template-columns: 1fr 120px 120px 64px;
  gap: 8px;
  margin-bottom: 8px;
}
</style>
