<template>
  <div class="membership-page">
    <el-alert title="仅登记固定 9800 元线下办理。顾客确认后形成永久会员权威事实；本页不提供强制激活、退款或归属修改。" type="info" :closable="false" />
    <div class="toolbar">
      <el-input v-model="query.store_id" placeholder="门店 ID" size="small" clearable />
      <el-select v-model="query.status" placeholder="办理状态" size="small" clearable>
        <el-option label="草稿" value="draft" /><el-option label="待顾客确认" value="pending_customer_confirmation" /><el-option label="已激活" value="activated" />
      </el-select>
      <el-button type="primary" size="small" icon="el-icon-search" @click="load">查询</el-button>
      <el-button v-auth="['yfth-permanent-membership-enrollment-create']" size="small" icon="el-icon-plus" @click="createEnrollment">新建办理</el-button>
      <el-button size="small" @click="showMembers = !showMembers; load()">{{ showMembers ? '查看办理' : '查看会员' }}</el-button>
    </div>
    <el-table v-loading="loading" :data="rows" border size="small">
      <template v-if="!showMembers">
        <el-table-column prop="enrollment_no" label="办理号" min-width="190" />
        <el-table-column prop="store_id" label="门店" width="90" />
        <el-table-column prop="target_uid" label="顾客 UID" width="100" />
        <el-table-column label="金额" width="100"><template>￥9800.00</template></el-table-column>
        <el-table-column prop="payment_status" label="收款" width="100" />
        <el-table-column prop="status" label="状态" min-width="170" />
        <el-table-column label="操作" width="300" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" @click="bindCustomer(row)">绑定顾客</el-button>
            <el-button type="text" @click="confirmPayment(row)">确认收款</el-button>
            <el-button type="text" @click="generateCode(row)">确认码</el-button>
          </template>
        </el-table-column>
      </template>
      <template v-else>
        <el-table-column prop="membership_no" label="会员号" min-width="190" />
        <el-table-column prop="membership_id" label="ID" width="80" />
        <el-table-column prop="uid" label="顾客 UID" width="100" />
        <el-table-column prop="store_id" label="归属门店" width="100" />
        <el-table-column prop="status" label="状态" width="100" />
        <el-table-column label="有效期"><template>永久</template></el-table-column>
        <el-table-column prop="activated_at" label="激活时间"><template slot-scope="{ row }">{{ formatTime(row.activated_at) }}</template></el-table-column>
      </template>
    </el-table>
    <el-dialog title="会员确认码" :visible.sync="codeVisible" width="520px">
      <el-input v-model="issuedCode" readonly /><p class="hint">明文仅本次返回，请由绑定顾客本人登录后确认。</p>
    </el-dialog>
  </div>
</template>

<script>
import { yfthPermanentMembershipBind, yfthPermanentMembershipConfirmationCode, yfthPermanentMembershipCreate, yfthPermanentMembershipEnrollments, yfthPermanentMembershipMembers, yfthPermanentMembershipPayment } from '@/api/yfth';
export default {
  name: 'YfthPermanentMembership',
  data() { return { loading: false, rows: [], query: { store_id: '', status: '' }, showMembers: false, codeVisible: false, issuedCode: '' }; },
  created() { this.load(); },
  methods: {
    key(action, id) { return `hq_pm_${action}_${id || 0}_${Date.now()}`; },
    load() { this.loading = true; const call = this.showMembers ? yfthPermanentMembershipMembers(this.query) : yfthPermanentMembershipEnrollments(this.query); call.then(res => { this.rows = (res.data && res.data.list) || []; }).finally(() => { this.loading = false; }); },
    createEnrollment() { this.$prompt('请输入真实有效门店 ID', '新建办理', { inputPattern: /^[1-9]\d*$/, inputErrorMessage: '请输入正整数门店 ID' }).then(({ value }) => yfthPermanentMembershipCreate({ store_id: Number(value), idempotency_key: this.key('create', value) })).then(() => { this.$message.success('办理记录已创建'); this.load(); }).catch(() => {}); },
    bindCustomer(row) { this.$prompt('请扫描或粘贴顾客本人生成的身份码', '绑定顾客').then(({ value }) => yfthPermanentMembershipBind(row.id, { identity_token: value, idempotency_key: this.key('bind', row.id) })).then(() => { this.$message.success('顾客已绑定'); this.load(); }).catch(() => {}); },
    confirmPayment(row) { this.$confirm('确认已线下收取固定 9800 元会员费？', '确认收款', { type: 'warning' }).then(() => yfthPermanentMembershipPayment(row.id, { idempotency_key: this.key('payment', row.id) })).then(() => { this.$message.success('线下收款已确认'); this.load(); }).catch(() => {}); },
    generateCode(row) { yfthPermanentMembershipConfirmationCode(row.id).then(res => { this.issuedCode = (res.data && res.data.token) || ''; this.codeVisible = true; }); },
    formatTime(value) { return value ? new Date(Number(value) * 1000).toLocaleString() : '-'; },
  },
};
</script>

<style scoped>
.membership-page { padding: 20px; }.toolbar { display: flex; gap: 10px; margin: 16px 0; }.toolbar .el-input,.toolbar .el-select { width: 180px; }.hint { color: #8a6a4c; font-size: 13px; }
</style>
