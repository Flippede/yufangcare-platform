<template>
  <div class="user-role-page">
    <el-alert title="永久会员是独立基础身份；加盟商是独立经营身份，其下可关联多家可管理门店。店长、店员仍按门店授权，所有变更均需原因并写入审计。" type="info" :closable="false" />
    <el-card class="fixture-card" shadow="never">
      <div slot="header" class="fixture-header">
        <div>
          <b>受控验收测试数据</b>
          <span>仅生成带 TEST 标识的隔离门店和虚构账号，密码只保存到服务器私有文件。</span>
        </div>
        <el-tag :type="fixture.enabled ? (fixture.status === 'active' ? 'success' : 'info') : 'danger'">
          {{ fixture.enabled ? fixtureStatusText : '环境开关未启用' }}
        </el-tag>
      </div>
      <div class="fixture-actions">
        <el-button type="primary" :disabled="!fixture.enabled" :loading="fixtureSaving" @click="generateFixture">生成或补齐完整测试门店与账号</el-button>
        <el-button type="danger" plain :disabled="!fixture.enabled || fixture.status !== 'active'" :loading="fixtureSaving" @click="resetFixture">重置测试数据</el-button>
        <el-button type="warning" plain :disabled="!fixture.enabled || fixture.status !== 'active'" :loading="fixtureSaving" @click="resetFixturePasswords">重置临时密码</el-button>
        <el-button icon="el-icon-refresh" @click="loadFixture">刷新状态</el-button>
      </div>
      <el-alert
        v-if="fixture.exists"
        :title="`测试门店：${fixture.store.name || '-'}（ID ${fixture.store.id || '-'}）；账号凭据文件：${fixture.credential_file || '-'}`"
        type="warning"
        :closable="false"
      />
      <el-table v-if="fixture.exists" :data="fixture.accounts || []" border size="mini" class="fixture-table">
        <el-table-column prop="nickname" label="测试身份" min-width="150" />
        <el-table-column prop="account" label="登录账号" min-width="180" />
        <el-table-column prop="phone_masked" label="虚构手机号" width="130" />
        <el-table-column prop="uid" label="UID" width="90" />
        <el-table-column label="登录" width="80"><template slot-scope="{ row }"><el-tag size="mini" :type="row.login_ready ? 'success' : 'danger'">{{ row.login_ready ? '可登录' : '不可用' }}</el-tag></template></el-table-column>
        <el-table-column label="会员/归属" min-width="190"><template slot-scope="{ row }"><div>{{ row.permanent_member ? '永久会员' : '非会员' }}</div><div>{{ row.attribution_status }}<span v-if="row.attribution_store_id"> · 门店 {{ row.attribution_store_id }}</span></div></template></el-table-column>
        <el-table-column label="推荐状态" min-width="130"><template slot-scope="{ row }"><div>{{ row.referral_status }}</div><div v-if="row.invited_count">已邀请 {{ row.invited_count }} 人</div></template></el-table-column>
      </el-table>
    </el-card>
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
      <el-table-column label="操作" width="290" fixed="right">
        <template slot-scope="{ row }">
          <el-button type="text" @click="openDetail(row)">查看</el-button>
          <el-button v-if="!row.permanent_member" type="text" @click="openGrant(row, 'permanent_member')">授权会员</el-button>
          <el-button type="text" @click="openGrant(row, 'franchisee')">加盟商</el-button>
          <el-button type="text" @click="openGrant(row)">店长/店员</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination class="pager" layout="total, prev, pager, next" :total="total" :page-size="query.limit" :current-page.sync="query.page" @current-change="load" />

    <el-dialog title="用户经营身份" :visible.sync="detailVisible" width="720px">
      <div v-if="detail" class="detail-head"><b>{{ detail.nickname || detail.account || '-' }}</b><span>UID {{ detail.uid }}</span><span>{{ detail.phone_masked }}</span></div>
      <div v-if="detail" class="identity-summary">
        <div><b>基础身份</b><el-tag size="mini">顾客</el-tag><el-tag v-if="detail.permanent_member" size="mini" type="success">永久会员</el-tag><span v-else class="muted">未授权永久会员</span></div>
        <div><b>加盟商</b><span v-if="!franchiseeRoles(detail).length" class="muted">未授权</span><el-tag v-for="role in franchiseeRoles(detail)" :key="role.id" size="mini" class="role-tag">{{ role.store_name || ('门店 ' + role.store_id) }}<i v-if="role.status === 'active'" class="el-icon-close revoke-icon" @click="revoke(role)" /></el-tag></div>
      </div>
      <el-table :data="detail ? storeStaffRoles(detail) : []" border size="small">
        <el-table-column prop="store_name" label="门店" min-width="160" />
        <el-table-column prop="role_name" label="身份" width="110" />
        <el-table-column prop="status" label="状态" width="100" />
        <el-table-column label="操作" width="100"><template slot-scope="{ row }"><el-button v-if="row.status === 'active'" type="text" class="danger" @click="revoke(row)">撤销</el-button></template></el-table-column>
      </el-table>
    </el-dialog>

    <el-dialog :title="grantDialogTitle" :visible.sync="grantVisible" width="480px">
      <el-form label-width="90px">
        <el-form-item label="用户"><span>{{ selected ? `${selected.nickname || selected.account}（UID ${selected.uid}）` : '' }}</span></el-form-item>
        <el-form-item label="门店"><el-select v-model="grantForm.store_id" filterable placeholder="选择启用门店"><el-option v-for="store in stores" :key="store.id" :label="store.name" :value="store.id" /></el-select></el-form-item>
        <el-form-item v-if="!grantPresetRole" label="经营身份"><el-select v-model="grantForm.role_code" placeholder="选择身份"><el-option v-for="role in staffRoleOptions" :key="role.value" :label="role.label" :value="role.value" /></el-select></el-form-item>
        <el-form-item v-else label="授权类型"><el-tag>{{ grantRoleLabel }}</el-tag><div v-if="grantPresetRole === 'franchisee'" class="form-tip">加盟商身份独立存在；所选门店只定义该加盟商可管理的门店范围，可重复添加其他门店。</div><div v-if="grantPresetRole === 'permanent_member'" class="form-tip">永久会员一经授权不提供普通撤销；同时建立该会员的永久门店归属。</div></el-form-item>
        <el-form-item label="操作原因"><el-input v-model.trim="grantForm.reason" type="textarea" :rows="3" maxlength="255" show-word-limit /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="grantVisible = false">取消</el-button><el-button type="primary" :loading="saving" @click="grant">确认授予</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthAcceptanceFixture,
  yfthAcceptanceFixtureGenerate,
  yfthAcceptanceFixturePasswordReset,
  yfthAcceptanceFixtureReset,
  yfthUserRoleDetail,
  yfthUserRoleGrant,
  yfthUserRoleRevoke,
  yfthUserRoleUsers,
} from '@/api/yfth';

export default {
  data() {
    return {
      loading: false, saving: false, fixtureSaving: false, list: [], total: 0, stores: [], roleOptions: [],
      query: { keyword: '', page: 1, limit: 20 }, detail: null, selected: null,
      detailVisible: false, grantVisible: false, grantPresetRole: '',
      grantForm: { store_id: '', role_code: '', reason: '' },
      fixture: { enabled: false, exists: false, status: 'not_generated', store: {}, accounts: [] },
    };
  },
  computed: {
    fixtureStatusText() {
      return { active: '已启用', disabled: '已重置', not_generated: '尚未生成' }[this.fixture.status] || this.fixture.status;
    },
    staffRoleOptions() { return this.roleOptions.filter((item) => ['store_manager', 'store_staff'].includes(item.value)); },
    grantRoleLabel() { return { permanent_member: '永久会员', franchisee: '加盟商' }[this.grantPresetRole] || '经营身份'; },
    grantDialogTitle() { return this.grantPresetRole ? `授权${this.grantRoleLabel}` : '授权店长/店员'; },
  },
  created() {
    this.load();
    this.loadFixture();
    const uid = Number(this.$route.query.uid || 0);
    if (uid > 0) this.openDetail({ uid });
  },
  methods: {
    loadFixture() {
      return yfthAcceptanceFixture().then((res) => {
        this.fixture = Object.assign({ enabled: false, exists: false, status: 'not_generated', store: {}, accounts: [] }, res.data || {});
      });
    },
    generateFixture() {
      this.$prompt('请填写本次生成或补齐测试数据的原因', '生成受控验收数据', {
        confirmButtonText: '确认生成', cancelButtonText: '取消',
        inputValidator: (value) => Boolean(String(value || '').trim()) || '必须填写原因',
      }).then(({ value }) => {
        this.fixtureSaving = true;
        return yfthAcceptanceFixtureGenerate({ reason: String(value).trim(), request_id: `acceptance-fixture-generate-${Date.now()}` });
      }).then((res) => {
        this.fixture = res.data || this.fixture;
        this.$message.success('测试门店和账号已生成或补齐，密码请从服务器私有文件读取');
        return this.load(true);
      }).finally(() => { this.fixtureSaving = false; });
    },
    resetFixture() {
      this.$confirm('仅停用本工具标记的测试对象。若 C2 已形成会员等复杂事实，系统会拒绝重置。是否继续？', '二次确认', {
        confirmButtonText: '继续', cancelButtonText: '取消', type: 'warning',
      }).then(() => this.$prompt('请填写重置原因', '重置受控验收数据', {
        confirmButtonText: '确认重置', cancelButtonText: '取消',
        inputValidator: (value) => Boolean(String(value || '').trim()) || '必须填写原因',
      })).then(({ value }) => {
        this.fixtureSaving = true;
        return yfthAcceptanceFixtureReset({ reason: String(value).trim(), request_id: `acceptance-fixture-reset-${Date.now()}` });
      }).then((res) => {
        this.fixture = res.data || this.fixture;
        this.$message.success('测试数据已安全停用');
        return this.load(true);
      }).finally(() => { this.fixtureSaving = false; });
    },
    resetFixturePasswords() {
      this.$prompt('请输入临时密码重置原因。新密码仅在本次响应中显示一次，并同步写入服务器 600 权限私有文件。', '重置测试账号临时密码', {
        confirmButtonText: '确认重置', cancelButtonText: '取消',
        inputValidator: (value) => Boolean(String(value || '').trim()) || '必须填写原因',
      }).then(({ value }) => {
        this.fixtureSaving = true;
        return yfthAcceptanceFixturePasswordReset({ reason: String(value).trim(), request_id: `fixture-password-reset-${Date.now()}` });
      }).then((res) => {
        const data = res.data || {};
        const passwords = data.temporary_passwords_once || [];
        delete data.temporary_passwords_once;
        this.fixture = data;
        const lines = passwords.map((item) => `${item.account}：${item.password}`);
        return this.$alert(lines.join('\n'), '临时密码（仅显示一次）', { confirmButtonText: '我已安全保存' });
      }).finally(() => { this.fixtureSaving = false; });
    },
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
    openGrant(row, presetRole) {
      this.selected = row; this.grantPresetRole = presetRole || ''; this.grantForm = { store_id: '', role_code: presetRole || '', reason: '' }; this.grantVisible = true;
    },
    grant() {
      if (!this.grantForm.store_id || !this.grantForm.role_code || !this.grantForm.reason) return this.$message.warning('请选择门店、身份并填写原因');
      this.saving = true;
      const data = { ...this.grantForm, request_id: `hq-role-grant-${Date.now()}` };
      yfthUserRoleGrant(this.selected.uid, data).then(() => { this.$message.success(`${this.grantRoleLabel}已授予`); this.grantVisible = false; this.load(); })
        .finally(() => { this.saving = false; });
    },
    franchiseeRoles(detail) { return (detail.store_roles || []).filter((item) => item.role_code === 'franchisee' && item.status === 'active'); },
    storeStaffRoles(detail) { return (detail.store_roles || []).filter((item) => item.role_code !== 'franchisee'); },
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
.fixture-card { margin-top: 16px; }
.fixture-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.fixture-header b { display: block; margin-bottom: 6px; font-size: 15px; }
.fixture-header span { color: #909399; font-size: 12px; }
.fixture-actions { margin-bottom: 14px; }
.fixture-table { margin-top: 14px; }
.toolbar { display: flex; gap: 10px; margin: 16px 0; }
.toolbar .el-input { width: 320px; }
.pager { margin-top: 16px; text-align: right; }
.user-cell { display: flex; align-items: center; gap: 10px; }
.user-cell img { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; }
.role-tag { margin: 2px 6px 2px 0; }
.detail-head { display: flex; gap: 18px; margin-bottom: 16px; color: #606266; }
.identity-summary { margin-bottom: 16px; padding: 12px 14px; background: #f7f8fa; }
.identity-summary>div { display: flex; align-items: center; gap: 8px; min-height: 34px; }
.identity-summary b { width: 72px; color: #303133; }
.muted, .form-tip { color: #909399; }
.form-tip { margin-top: 8px; font-size: 12px; line-height: 1.55; }
.revoke-icon { margin-left: 6px; cursor: pointer; }
.danger { color: #f56c6c; }
</style>
