<template>
  <div class="user-role-page">
    <el-alert title="经营身份按门店独立授予，不会覆盖顾客或永久会员身份。所有变更均需填写原因并写入统一审计。" type="info" :closable="false" />
    <div class="toolbar">
      <el-input v-model.trim="query.keyword" clearable placeholder="手机号、昵称、账号或 UID" @keyup.enter.native="load(true)" />
      <el-button type="primary" icon="el-icon-search" @click="load(true)">查询</el-button>
      <el-button icon="el-icon-refresh" @click="load()">刷新</el-button>
    </div>
    <el-table v-loading="loading" :data="list" border size="small">
      <el-table-column prop="uid" label="UID" width="90" />
      <el-table-column label="用户" min-width="190">
        <template slot-scope="{ row }">
          <div class="user-cell"><img v-if="row.avatar" :src="row.avatar"><div><b>{{ row.nickname || row.account || '-' }}</b><div>{{ row.phone_masked || '-' }}</div></div></div>
        </template>
      </el-table-column>
      <el-table-column label="基础身份" width="170">
        <template slot-scope="{ row }"><el-tag size="mini">顾客</el-tag><el-tag v-if="row.permanent_member" size="mini" type="success">永久会员</el-tag></template>
      </el-table-column>
      <el-table-column label="有效经营身份" min-width="260">
        <template slot-scope="{ row }">
          <span v-if="!row.store_roles.length">-</span>
          <el-tag v-for="role in row.store_roles" :key="role.id" size="mini" class="role-tag">{{ role.store_name || ('门店 ' + role.store_id) }} · {{ role.role_name }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="170" fixed="right">
        <template slot-scope="{ row }"><el-button type="text" @click="openDetail(row)">查看</el-button><el-button type="text" @click="openGrant(row)">授予身份</el-button></template>
      </el-table-column>
    </el-table>
    <el-pagination class="pager" layout="total, prev, pager, next" :total="total" :page-size="query.limit" :current-page.sync="query.page" @current-change="load" />

    <el-dialog title="用户经营身份" :visible.sync="detailVisible" width="720px">
      <div v-if="detail" class="detail-head"><b>{{ detail.nickname || detail.account || '-' }}</b><span>UID {{ detail.uid }}</span><span>{{ detail.phone_masked }}</span></div>
      <el-table :data="detail ? detail.store_roles : []" border size="small">
        <el-table-column prop="store_name" label="门店" min-width="160" />
        <el-table-column prop="role_name" label="身份" width="110" />
        <el-table-column prop="status" label="状态" width="100" />
        <el-table-column label="操作" width="100"><template slot-scope="{ row }"><el-button v-if="row.status === 'active'" type="text" class="danger" @click="revoke(row)">撤销</el-button></template></el-table-column>
      </el-table>
    </el-dialog>

    <el-dialog title="授予经营身份" :visible.sync="grantVisible" width="480px">
      <el-form label-width="90px">
        <el-form-item label="用户"><span>{{ selected ? `${selected.nickname || selected.account}（UID ${selected.uid}）` : '' }}</span></el-form-item>
        <el-form-item label="门店"><el-select v-model="grantForm.store_id" filterable placeholder="选择启用门店"><el-option v-for="store in stores" :key="store.id" :label="store.name" :value="store.id" /></el-select></el-form-item>
        <el-form-item label="经营身份"><el-select v-model="grantForm.role_code" placeholder="选择身份"><el-option v-for="role in roleOptions" :key="role.value" :label="role.label" :value="role.value" /></el-select></el-form-item>
        <el-form-item label="操作原因"><el-input v-model.trim="grantForm.reason" type="textarea" :rows="3" maxlength="255" show-word-limit /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="grantVisible = false">取消</el-button><el-button type="primary" :loading="saving" @click="grant">确认授予</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import { yfthUserRoleDetail, yfthUserRoleGrant, yfthUserRoleRevoke, yfthUserRoleUsers } from '@/api/yfth';

export default {
  data() {
    return {
      loading: false, saving: false, list: [], total: 0, stores: [], roleOptions: [],
      query: { keyword: '', page: 1, limit: 20 }, detail: null, selected: null,
      detailVisible: false, grantVisible: false,
      grantForm: { store_id: '', role_code: '', reason: '' },
    };
  },
  created() { this.load(); },
  methods: {
    load(reset) {
      if (reset === true) this.query.page = 1;
      this.loading = true;
      yfthUserRoleUsers(this.query).then((res) => {
        const data = res.data || {}; this.list = data.list || []; this.total = Number(data.count || 0);
        this.stores = data.stores || []; this.roleOptions = data.role_options || [];
      }).finally(() => { this.loading = false; });
    },
    openDetail(row) {
      yfthUserRoleDetail(row.uid).then((res) => { this.detail = res.data || null; this.detailVisible = true; });
    },
    openGrant(row) {
      this.selected = row; this.grantForm = { store_id: '', role_code: '', reason: '' }; this.grantVisible = true;
    },
    grant() {
      if (!this.grantForm.store_id || !this.grantForm.role_code || !this.grantForm.reason) return this.$message.warning('请选择门店、身份并填写原因');
      this.saving = true;
      const data = { ...this.grantForm, request_id: `hq-role-grant-${Date.now()}` };
      yfthUserRoleGrant(this.selected.uid, data).then(() => { this.$message.success('经营身份已授予'); this.grantVisible = false; this.load(); })
        .finally(() => { this.saving = false; });
    },
    revoke(row) {
      this.$prompt('请输入撤销原因', '撤销经营身份', { inputValidator: (value) => Boolean(String(value || '').trim()) || '必须填写原因' })
        .then(({ value }) => yfthUserRoleRevoke(row.id, { reason: String(value).trim(), request_id: `hq-role-revoke-${Date.now()}` }))
        .then(() => { this.$message.success('经营身份已撤销'); return yfthUserRoleDetail(this.detail.uid); })
        .then((res) => { this.detail = res.data || null; this.load(); });
    },
  },
};
</script>

<style scoped>
.user-role-page { padding: 16px; }
.toolbar { display: flex; gap: 10px; margin: 16px 0; }
.toolbar .el-input { width: 320px; }
.pager { margin-top: 16px; text-align: right; }
.user-cell { display: flex; align-items: center; gap: 10px; }
.user-cell img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; }
.role-tag { margin: 2px 6px 2px 0; }
.detail-head { display: flex; gap: 18px; margin-bottom: 16px; color: #606266; }
.danger { color: #f56c6c; }
</style>
