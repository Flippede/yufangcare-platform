<template>
  <div class="yfth-franchise-application">
    <div class="page-heading">
      <div>
        <h1>总部加盟申请</h1>
        <p>查看用户提交的加盟意向，分配招商负责人并记录后续跟进。</p>
      </div>
      <el-tag type="warning" effect="plain">总部招商</el-tag>
    </div>
    <el-card shadow="never" class="ivu-mt" :body-style="{ padding: '16px' }">
      <div class="toolbar">
        <el-input v-model="filters.keyword" clearable placeholder="申请号/姓名/电话/城市" class="w220" />
        <el-select v-model="filters.status" clearable placeholder="状态" class="w170">
          <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </el-select>
        <el-input v-model="filters.applicant_uid" clearable placeholder="用户UID" class="w120" />
        <el-input v-model="filters.assigned_uid" clearable placeholder="负责人ID" class="w120" />
        <el-input v-model="filters.city" clearable placeholder="城市" class="w140" />
        <el-button type="primary" icon="el-icon-search" @click="load(true)">查询</el-button>
        <el-button icon="el-icon-refresh-left" @click="reset">重置</el-button>
      </div>

      <el-table v-loading="loading" :data="list" border>
        <el-table-column prop="application_no" label="申请号" min-width="210" />
        <el-table-column label="申请人" min-width="150">
          <template slot-scope="scope">
            <div>{{ scope.row.name }}</div>
            <div class="muted">UID: {{ scope.row.applicant_uid }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="phone_masked" label="电话" width="130" />
        <el-table-column prop="city" label="城市" width="120" />
        <el-table-column prop="intention_area" label="意向区域" min-width="160" />
        <el-table-column prop="budget" label="预算" width="120" />
        <el-table-column prop="status_text" label="状态" width="130" />
        <el-table-column prop="assigned_name" label="负责人" width="160" />
        <el-table-column label="提交时间" width="160">
          <template slot-scope="scope">{{ formatTime(scope.row.submit_time) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="310" fixed="right">
          <template slot-scope="scope">
            <el-button type="text" icon="el-icon-view" @click="openDetail(scope.row)">详情</el-button>
            <el-button type="text" icon="el-icon-user" @click="openAssign(scope.row)">分配</el-button>
            <el-button type="text" icon="el-icon-right" @click="openStatus(scope.row)">推进</el-button>
            <el-button type="text" icon="el-icon-chat-line-square" @click="openFollow(scope.row)">沟通</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          :current-page.sync="filters.page"
          :page-size="filters.limit"
          :total="count"
          layout="total, prev, pager, next"
          @current-change="load(false)"
        />
      </div>
    </el-card>

    <el-drawer title="加盟申请详情" :visible.sync="detailVisible" size="48%">
      <div v-if="detail.application" class="drawer-body">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="申请号">{{ detail.application.application_no }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ detail.application.status_text }}</el-descriptions-item>
          <el-descriptions-item label="申请人">{{ detail.application.name }}</el-descriptions-item>
          <el-descriptions-item label="用户UID">{{ detail.application.applicant_uid }}</el-descriptions-item>
          <el-descriptions-item label="电话">{{ detail.application.phone }}</el-descriptions-item>
          <el-descriptions-item label="城市">{{ detail.application.city }}</el-descriptions-item>
          <el-descriptions-item label="区域">{{ detail.application.region || '-' }}</el-descriptions-item>
          <el-descriptions-item label="意向区域">{{ detail.application.intention_area }}</el-descriptions-item>
          <el-descriptions-item label="预算">{{ detail.application.budget }}</el-descriptions-item>
          <el-descriptions-item label="负责人">{{ detail.application.assigned_name }}</el-descriptions-item>
          <el-descriptions-item label="备注" :span="2">{{ detail.application.remark || '-' }}</el-descriptions-item>
        </el-descriptions>

        <h4>沟通记录</h4>
        <el-timeline>
          <el-timeline-item
            v-for="item in detail.follow_records"
            :key="item.id"
            :timestamp="formatTime(item.follow_time)"
          >
            <div class="strong">{{ item.type_text }} · {{ item.operator_name || '总部' }}</div>
            <el-tag size="mini" :type="item.visible_type === 'public' ? 'success' : 'info'">{{ visibleText(item.visible_type) }}</el-tag>
            <div>{{ item.content }}</div>
            <div v-if="item.next_time" class="muted">下次跟进：{{ formatTime(item.next_time) }}</div>
          </el-timeline-item>
        </el-timeline>

        <h4>操作历史</h4>
        <el-table :data="detail.audit_events || []" size="small" border>
          <el-table-column prop="action" label="动作" width="150" />
          <el-table-column prop="operator_uid" label="操作人" width="100" />
          <el-table-column prop="reason" label="原因" min-width="180" />
          <el-table-column label="时间" width="160">
            <template slot-scope="scope">{{ formatTime(scope.row.add_time) }}</template>
          </el-table-column>
        </el-table>
      </div>
    </el-drawer>

    <el-dialog title="分配负责人" :visible.sync="assignVisible" width="420px">
      <el-form label-width="100px">
        <el-form-item label="负责人ID">
          <el-input v-model="assignForm.assigned_uid" placeholder="请输入总部管理员ID" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="assignVisible = false">取消</el-button>
        <el-button type="primary" @click="submitAssign">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog title="推进状态" :visible.sync="statusVisible" width="480px">
      <el-form label-width="100px">
        <el-form-item label="当前状态">
          <el-input :value="currentRow.status_text" disabled />
        </el-form-item>
        <el-form-item label="目标状态">
          <el-select v-model="statusForm.status" placeholder="选择下一状态" class="full">
            <el-option v-for="item in nextStatusOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="原因">
          <el-input v-model="statusForm.reason" type="textarea" placeholder="记录状态推进原因" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="statusVisible = false">取消</el-button>
        <el-button type="primary" @click="submitStatus">推进</el-button>
      </span>
    </el-dialog>

    <el-dialog title="新增沟通记录" :visible.sync="followVisible" width="520px">
      <el-form label-width="100px">
        <el-form-item label="沟通方式">
          <el-select v-model="followForm.type" class="full">
            <el-option label="电话沟通" value="phone" />
            <el-option label="微信沟通" value="wechat" />
            <el-option label="面谈" value="meeting" />
            <el-option label="考察" value="inspection" />
            <el-option label="其他" value="other" />
          </el-select>
        </el-form-item>
        <el-form-item label="内容">
          <el-input v-model="followForm.content" type="textarea" :rows="4" placeholder="请输入沟通内容" />
        </el-form-item>
        <el-form-item label="可见范围">
          <el-radio-group v-model="followForm.visible_type">
            <el-radio label="internal">总部内部</el-radio>
            <el-radio label="public">用户可见</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="下次跟进">
          <el-date-picker v-model="followForm.next_time" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" class="full" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="followVisible = false">取消</el-button>
        <el-button type="primary" @click="submitFollow">保存</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthFranchiseApplicationAssign,
  yfthFranchiseApplicationDetail,
  yfthFranchiseApplicationFollow,
  yfthFranchiseApplicationList,
  yfthFranchiseApplicationStatus,
} from '@/api/yfth';

export default {
  name: 'YfthFranchiseApplication',
  data() {
    return {
      loading: false,
      filters: { keyword: '', status: '', applicant_uid: '', assigned_uid: '', city: '', page: 1, limit: 20 },
      list: [],
      count: 0,
      detailVisible: false,
      detail: {},
      assignVisible: false,
      statusVisible: false,
      followVisible: false,
      currentRow: {},
      assignForm: { assigned_uid: '' },
      statusForm: { status: '', reason: '' },
      followForm: { type: 'phone', content: '', visible_type: 'internal', next_time: '' },
      statusOptions: [
        { label: '已提交', value: 'submitted' },
        { label: '联系中', value: 'contacting' },
        { label: '沟通中', value: 'communicating' },
        { label: '考察中', value: 'inspecting' },
        { label: '待进入合同阶段', value: 'pending_contract' },
      ],
      transitions: {
        submitted: ['contacting'],
        contacting: ['communicating'],
        communicating: ['inspecting'],
        inspecting: ['pending_contract'],
      },
    };
  },
  computed: {
    nextStatusOptions() {
      const allowed = this.transitions[this.currentRow.status] || [];
      return this.statusOptions.filter((item) => allowed.indexOf(item.value) !== -1);
    },
  },
  created() {
    this.filters.status = this.$route.query.status || '';
    this.load(true);
  },
  methods: {
    load(reset) {
      if (reset) this.filters.page = 1;
      this.loading = true;
      yfthFranchiseApplicationList(this.filters)
        .then((res) => {
          this.list = (res.data && res.data.list) || [];
          this.count = (res.data && res.data.count) || 0;
        })
        .finally(() => {
          this.loading = false;
        });
    },
    reset() {
      this.filters = { keyword: '', status: '', applicant_uid: '', assigned_uid: '', city: '', page: 1, limit: 20 };
      this.load(true);
    },
    openDetail(row) {
      yfthFranchiseApplicationDetail(row.id).then((res) => {
        this.detail = res.data || {};
        this.detailVisible = true;
      });
    },
    openAssign(row) {
      this.currentRow = row;
      this.assignForm = { assigned_uid: row.assigned_uid || '' };
      this.assignVisible = true;
    },
    submitAssign() {
      yfthFranchiseApplicationAssign(this.currentRow.id, { assigned_uid: Number(this.assignForm.assigned_uid || 0) }).then(() => {
        this.assignVisible = false;
        this.$message.success('已分配');
        this.load(false);
      });
    },
    openStatus(row) {
      this.currentRow = row;
      const next = this.nextStatusOptions[0];
      this.statusForm = { status: next ? next.value : '', reason: '' };
      this.statusVisible = true;
    },
    submitStatus() {
      yfthFranchiseApplicationStatus(this.currentRow.id, this.statusForm).then(() => {
        this.statusVisible = false;
        this.$message.success('已推进');
        this.load(false);
      });
    },
    openFollow(row) {
      this.currentRow = row;
      this.followForm = { type: 'phone', content: '', visible_type: 'internal', next_time: '' };
      this.followVisible = true;
    },
    submitFollow() {
      yfthFranchiseApplicationFollow(this.currentRow.id, this.followForm).then(() => {
        this.followVisible = false;
        this.$message.success('已记录');
        this.load(false);
      });
    },
    formatTime(value) {
      const ts = Number(value || 0);
      if (!ts) return '-';
      const date = new Date(ts * 1000);
      const pad = (n) => (n < 10 ? '0' + n : '' + n);
      return (
        date.getFullYear() +
        '-' +
        pad(date.getMonth() + 1) +
        '-' +
        pad(date.getDate()) +
        ' ' +
        pad(date.getHours()) +
        ':' +
        pad(date.getMinutes())
      );
    },
    visibleText(value) {
      return value === 'public' ? '用户可见' : '总部内部';
    },
  },
};
</script>

<style scoped>
.page-heading {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 18px 20px;
  background: #fff;
  border: 1px solid #e8ecf1;
  border-radius: 6px;
}
.page-heading h1 {
  margin: 0;
  font-size: 20px;
  line-height: 28px;
}
.page-heading p {
  margin: 6px 0 0;
  color: #667085;
  font-size: 13px;
  line-height: 20px;
}
.toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}
.w120 {
  width: 120px;
}
.w140 {
  width: 140px;
}
.w170 {
  width: 170px;
}
.w220 {
  width: 220px;
}
.full {
  width: 100%;
}
.pager {
  padding-top: 16px;
  text-align: right;
}
.drawer-body {
  padding: 0 24px 24px;
}
.muted {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
}
.strong {
  font-weight: 600;
  margin-bottom: 4px;
}
h4 {
  margin: 22px 0 12px;
}
</style>
