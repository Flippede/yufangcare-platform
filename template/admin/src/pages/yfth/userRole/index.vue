<template>
  <div class="user-role-page">
    <el-alert title="永久会员、门店岗位和招商合伙人是三套独立资格。总部可授予五级合伙人；平台董事无需上级，其余职级必须且只能绑定一名相邻上级。所有变更均写入审计。" type="info" :closable="false" />
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
      <el-table-column label="基础身份" width="190">
        <template slot-scope="{ row }"><el-tag size="mini">顾客</el-tag><el-tag v-if="row.permanent_member" size="mini" type="success">永久会员</el-tag><el-tag v-else size="mini" type="info">非会员</el-tag></template>
      </el-table-column>
      <el-table-column label="有效经营身份" min-width="260">
        <template slot-scope="{ row }">
          <el-tag v-if="row.partner_identity && row.partner_identity.active" size="mini" type="warning" class="role-tag">{{ row.partner_identity.rank_name }}</el-tag>
          <span v-if="!row.store_roles.length && !(row.partner_identity && row.partner_identity.active)">-</span>
          <el-tag v-for="role in row.store_roles" :key="role.id" size="mini" class="role-tag">{{ role.store_name || ('门店 ' + role.store_id) }} · {{ role.role_name }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="总部授权与账号" width="430" fixed="right">
        <template slot-scope="{ row }">
          <el-button type="text" @click="openDetail(row)">查看</el-button>
          <el-button v-if="!row.permanent_member" type="text" @click="openGrant(row, 'permanent_member')">授权会员</el-button>
          <el-button v-else type="text" class="danger" @click="openMembershipRevoke(row)">解除会员</el-button>
          <el-button v-if="!(row.partner_identity && row.partner_identity.active)" type="text" @click="openPartnerGrant(row)">授予合伙人</el-button>
          <el-button type="text" @click="openGrant(row)">店长/店员</el-button>
          <el-button type="text" class="danger" @click="openClosure(row)">账号销户</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination class="pager" layout="total, prev, pager, next" :total="total" :page-size="query.limit" :current-page.sync="query.page" @current-change="load" />

    <el-dialog title="用户经营身份" :visible.sync="detailVisible" width="720px">
      <div v-if="detail" class="detail-head"><b>{{ detail.nickname || detail.account || '-' }}</b><span>UID {{ detail.uid }}</span><span>{{ detail.phone_masked }}</span></div>
      <div v-if="detail" class="identity-summary">
        <div><b>基础身份</b><el-tag size="mini">顾客</el-tag><el-tag v-if="detail.permanent_member" size="mini" type="success">永久会员</el-tag><span v-else class="muted">未授权永久会员</span><el-button v-if="!detail.permanent_member" type="primary" size="mini" @click="openGrant(detail, 'permanent_member')">总部授权永久会员</el-button><el-button v-else type="danger" plain size="mini" @click="openMembershipRevoke(detail)">解除永久会员</el-button></div>
        <div><b>招商职级</b><span v-if="!(detail.partner_identity && detail.partner_identity.active)" class="muted">尚未授予招商合伙人身份</span><template v-else><el-tag size="mini" type="warning">{{ detail.partner_identity.rank_name }}</el-tag><span v-if="detail.partner_identity.parent_uid">直属上级：{{ detail.partner_identity.parent_name || ('UID ' + detail.partner_identity.parent_uid) }} · {{ detail.partner_identity.parent_rank_name }}</span><span v-else>总部直属（无上级）</span></template><el-button v-if="!(detail.partner_identity && detail.partner_identity.active)" type="warning" size="mini" @click="openPartnerGrant(detail)">总部授予合伙人</el-button></div>
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
        <el-form-item v-else label="授权类型"><el-tag>{{ grantRoleLabel }}</el-tag><div v-if="grantPresetRole === 'permanent_member'" class="form-tip">永久会员一经授权不提供普通撤销；同时建立该会员的永久门店归属。</div></el-form-item>
        <el-form-item label="操作原因"><el-input v-model.trim="grantForm.reason" type="textarea" :rows="3" maxlength="255" show-word-limit /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="grantVisible = false">取消</el-button><el-button type="primary" :loading="saving" @click="grant">确认授予</el-button></span>
    </el-dialog>

    <el-dialog title="解除用户永久会员" :visible.sync="membershipRevokeVisible" width="560px" :close-on-click-modal="false">
      <div class="membership-revoke-warning">
        <i class="el-icon-warning" aria-hidden="true" />
        <div><b>重要：解除后该用户立即失去永久会员和推广资格。</b><p>商城订单、套餐购买、已产生权益、门店归属、奖励和财务历史不会被删除或改写。</p></div>
      </div>
      <el-form label-width="110px" class="membership-revoke-form">
        <el-form-item label="目标用户"><span>{{ selected ? `${selected.nickname || selected.account || '-'}（UID ${selected.uid}）` : '' }}</span></el-form-item>
        <el-form-item label="操作原因"><el-input v-model.trim="membershipRevokeForm.reason" type="textarea" :rows="3" maxlength="255" show-word-limit placeholder="请填写不少于4个字的解除原因" /></el-form-item>
        <el-form-item label="二次确认"><el-input v-model.trim="membershipRevokeForm.confirmation" placeholder="请输入：确认解除会员" @keyup.enter.native="revokeMembership" /><div class="form-tip danger">必须完整输入“确认解除会员”后才能执行。</div></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="membershipRevokeVisible = false">取消</el-button><el-button type="danger" :disabled="!membershipRevokeReady" :loading="membershipRevokeSaving" @click="revokeMembership">确认解除会员</el-button></span>
    </el-dialog>

    <el-dialog title="授予招商合伙人" :visible.sync="partnerGrantVisible" width="540px" :close-on-click-modal="false">
      <el-alert title="合伙人身份不绑定门店。平台董事可直接授予；大区总监至县级合伙人必须选择唯一的相邻上级。" type="warning" :closable="false" />
      <el-form label-width="110px" class="partner-grant-form">
        <el-form-item label="用户"><span>{{ selected ? `${selected.nickname || selected.account}（UID ${selected.uid}）` : '' }}</span></el-form-item>
        <el-form-item label="合伙人职级">
          <el-select v-model="partnerGrantForm.rank_code" placeholder="选择五级合伙人身份" @change="loadPartnerParents">
            <el-option v-for="rank in partnerRankOptions" :key="rank.value" :label="rank.label" :value="rank.value" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="partnerParentRequired" :label="`直属${partnerParentRankName}`">
          <el-select v-model="partnerGrantForm.parent_uid" filterable placeholder="必须选择一名直属上级" :loading="partnerOptionsLoading">
            <el-option v-for="parent in partnerParentOptions" :key="parent.uid" :label="partnerParentLabel(parent)" :value="parent.uid" />
          </el-select>
          <div v-if="!partnerOptionsLoading && !partnerParentOptions.length" class="form-tip danger">当前没有可用的{{ partnerParentRankName }}，请先授予上一级身份。</div>
        </el-form-item>
        <el-form-item v-else-if="partnerGrantForm.rank_code === 'platform_director'" label="直属上级"><el-tag type="success">平台董事由总部直接设置，无需上级</el-tag></el-form-item>
        <el-form-item label="操作原因"><el-input v-model.trim="partnerGrantForm.reason" type="textarea" :rows="3" maxlength="255" show-word-limit /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="partnerGrantVisible = false">取消</el-button><el-button type="warning" :loading="saving" @click="grantPartner">确认授予</el-button></span>
    </el-dialog>

    <el-dialog title="总部代办用户销户" :visible.sync="closureVisible" width="680px" :close-on-click-modal="false">
      <div class="closure-danger-header">
        <i class="el-icon-warning" aria-hidden="true" />
        <span>重要：销户成功后无法恢复。重新注册是全新 UID；必要交易和财务记录只按随机销户主体匿名保留，不能重新挂回用户。</span>
      </div>
      <div v-if="closurePreflight" class="closure-summary">
        <p><b>目标：</b>{{ closurePreflight.nickname || '-' }} / {{ closurePreflight.account }} / UID {{ closurePreflight.uid }}</p>
        <p><b>预检：</b><el-tag :type="closurePreflight.can_close ? 'success' : 'danger'">{{ closurePreflight.can_close ? '业务门禁已通过' : '当前不能销户' }}</el-tag> {{ closurePreflight.safety_note }}</p>
        <el-table :data="closurePreflight.blockers || []" border size="mini" empty-text="无业务阻塞项">
          <el-table-column prop="label" label="必须先处理的事项" min-width="420" />
          <el-table-column prop="count" label="行数" width="80" />
        </el-table>
        <el-alert v-if="closurePreflight.forfeitures && closurePreflight.forfeitures.length" class="closure-forfeit" title="以下无现金价值权益将在销户时放弃" type="warning" :closable="false">
          <div v-for="item in closurePreflight.forfeitures" :key="item.code">{{ item.label }}：{{ item.amount }}</div>
        </el-alert>
        <div class="closure-policy">
          <p><b>删除：</b>登录身份与会话、个人资料与微信绑定、地址收藏购物车、当前会员/归属/推荐与可撤销角色。</p>
          <p><b>匿名保留：</b>订单支付退款、套餐履约、奖励结算、加盟开店及审计事件。</p>
        </div>
        <el-form v-if="closurePreflight.can_close" label-width="110px" class="closure-form">
          <el-form-item label="代办原因">
            <el-input v-model.trim="closureForm.reason" type="textarea" :rows="3" maxlength="255" show-word-limit placeholder="请填写总部代办销户原因" />
          </el-form-item>
          <el-form-item label="确认销户">
            <el-input v-model.trim="closureForm.confirmation" placeholder="请输入：确认注销" @keyup.enter.native="closeAccount" />
            <div class="closure-confirm-tip">请输入“确认注销”四个字后执行。</div>
          </el-form-item>
        </el-form>
      </div>
      <span slot="footer"><el-button @click="closureVisible = false">取消</el-button><el-button type="danger" :disabled="!closureReady" :loading="closureSaving" @click="closeAccount">我已核对，执行永久销户</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthAcceptanceFixture,
  yfthAcceptanceFixtureGenerate,
  yfthAcceptanceFixturePasswordReset,
  yfthAcceptanceFixtureReset,
  yfthPartnerGrantOptions,
  yfthUserMembershipGrant,
  yfthUserMembershipRevoke,
  yfthUserPartnerGrant,
  yfthUserAccountClosure,
  yfthUserAccountClosurePreflight,
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
      detailVisible: false, grantVisible: false, grantPresetRole: '', partnerGrantVisible: false, closureVisible: false, closureSaving: false,
      membershipRevokeVisible: false, membershipRevokeSaving: false, membershipRevokeForm: { confirmation: '', reason: '' },
      grantForm: { store_id: '', role_code: '', reason: '' },
      partnerGrantForm: { rank_code: '', parent_uid: '', reason: '' },
      partnerRankOptions: [], partnerParentOptions: [], partnerParentRequired: false, partnerParentRankName: '', partnerOptionsLoading: false,
      closurePreflight: null, closureForm: { confirmation: '', reason: '' },
      fixture: { enabled: false, exists: false, status: 'not_generated', store: {}, accounts: [] },
    };
  },
  computed: {
    fixtureStatusText() {
      return { active: '已启用', disabled: '已重置', not_generated: '尚未生成' }[this.fixture.status] || this.fixture.status;
    },
    staffRoleOptions() { return this.roleOptions.filter((item) => ['store_manager', 'store_staff'].includes(item.value)); },
    grantRoleLabel() { return { permanent_member: '永久会员' }[this.grantPresetRole] || '经营身份'; },
    grantDialogTitle() { return this.grantPresetRole ? `授权${this.grantRoleLabel}` : '授权店长/店员'; },
    membershipRevokeReady() {
      return this.membershipRevokeForm.confirmation === '确认解除会员'
        && String(this.membershipRevokeForm.reason || '').trim().length >= 4;
    },
    closureReady() {
      return Boolean(this.closurePreflight && this.closurePreflight.can_close
        && this.closureForm.confirmation === this.closurePreflight.confirmation_phrase
        && String(this.closureForm.reason || '').trim().length >= 4);
    },
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
    openMembershipRevoke(row) {
      this.selected = row;
      this.membershipRevokeForm = { confirmation: '', reason: '' };
      this.membershipRevokeVisible = true;
    },
    revokeMembership() {
      if (!this.membershipRevokeReady || !this.selected) return;
      this.membershipRevokeSaving = true;
      const data = {
        confirmation: this.membershipRevokeForm.confirmation,
        reason: this.membershipRevokeForm.reason,
        request_id: `hq-membership-revoke-${Date.now()}`,
      };
      yfthUserMembershipRevoke(this.selected.uid, data).then(() => {
        this.$message.success('该用户的永久会员资格已解除');
        this.membershipRevokeVisible = false;
        this.detailVisible = false;
        return this.load();
      }).finally(() => { this.membershipRevokeSaving = false; });
    },
    openPartnerGrant(row) {
      this.selected = row;
      this.partnerGrantForm = { rank_code: '', parent_uid: '', reason: '' };
      this.partnerParentOptions = [];
      this.partnerParentRequired = false;
      this.partnerParentRankName = '';
      this.partnerGrantVisible = true;
      this.partnerOptionsLoading = true;
      yfthPartnerGrantOptions({ rank_code: '' }).then((res) => {
        this.partnerRankOptions = (res.data || {}).rank_options || [];
      }).finally(() => { this.partnerOptionsLoading = false; });
    },
    loadPartnerParents(rankCode) {
      this.partnerGrantForm.parent_uid = '';
      this.partnerParentOptions = [];
      this.partnerOptionsLoading = true;
      yfthPartnerGrantOptions({ rank_code: rankCode }).then((res) => {
        const data = res.data || {};
        this.partnerRankOptions = data.rank_options || this.partnerRankOptions;
        this.partnerParentRequired = Boolean(data.parent_required);
        this.partnerParentRankName = data.required_parent_rank_name || '';
        this.partnerParentOptions = data.parent_options || [];
      }).finally(() => { this.partnerOptionsLoading = false; });
    },
    partnerParentLabel(parent) {
      const name = parent.nickname || parent.account || `UID ${parent.uid}`;
      const account = parent.account && parent.account !== name ? ` · ${parent.account}` : '';
      return `${name}${account} · UID ${parent.uid}`;
    },
    grantPartner() {
      if (!this.partnerGrantForm.rank_code || !this.partnerGrantForm.reason) return this.$message.warning('请选择合伙人职级并填写原因');
      if (this.partnerParentRequired && !this.partnerGrantForm.parent_uid) return this.$message.warning(`必须选择一名${this.partnerParentRankName}`);
      this.saving = true;
      const data = {
        rank_code: this.partnerGrantForm.rank_code,
        parent_uid: this.partnerParentRequired ? Number(this.partnerGrantForm.parent_uid) : 0,
        reason: this.partnerGrantForm.reason,
        request_id: `hq-partner-grant-${Date.now()}`,
      };
      yfthUserPartnerGrant(this.selected.uid, data).then(() => {
        this.$message.success('招商合伙人身份已授予');
        this.partnerGrantVisible = false;
        this.detailVisible = false;
        return this.load();
      }).finally(() => { this.saving = false; });
    },
    openClosure(row) {
      this.selected = row;
      this.closurePreflight = null;
      this.closureForm = { confirmation: '', reason: '' };
      this.closureVisible = true;
      yfthUserAccountClosurePreflight(row.uid).then((res) => { this.closurePreflight = res.data || null; });
    },
    closeAccount() {
      if (!this.closurePreflight || !this.closurePreflight.can_close) return;
      if (this.closureForm.confirmation !== this.closurePreflight.confirmation_phrase) {
        return this.$message.warning('请输入“确认注销”四个字');
      }
      if (String(this.closureForm.reason || '').trim().length < 4) return this.$message.warning('请填写不少于4个字的代办原因');
      this.closureSaving = true;
      yfthUserAccountClosure(this.closurePreflight.uid, this.closureForm).then(() => {
        this.$message.success('账号已注销，必要业务历史已完成不可逆匿名化');
        this.closureVisible = false;
        this.detailVisible = false;
        return this.load(true);
      }).finally(() => { this.closureSaving = false; });
    },
    grant() {
      if (!this.grantForm.store_id || !this.grantForm.role_code || !this.grantForm.reason) return this.$message.warning('请选择门店、身份并填写原因');
      this.saving = true;
      const data = { ...this.grantForm, request_id: `hq-role-grant-${Date.now()}` };
      const request = this.grantPresetRole === 'permanent_member'
        ? yfthUserMembershipGrant(this.selected.uid, data)
        : yfthUserRoleGrant(this.selected.uid, data);
      request.then(() => { this.$message.success(`${this.grantRoleLabel}已授予`); this.grantVisible = false; this.load(); })
        .finally(() => { this.saving = false; });
    },
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
.partner-grant-form { margin-top: 18px; }
.partner-grant-form .el-select { width: 100%; }
.revoke-icon { margin-left: 6px; cursor: pointer; }
.danger { color: #f56c6c; }
.membership-revoke-warning { display: flex; gap: 12px; padding: 14px 16px; color: #c45656; background: #fef0f0; border: 1px solid #fbc4c4; }
.membership-revoke-warning .el-icon-warning { flex: 0 0 auto; margin-top: 2px; font-size: 28px; }
.membership-revoke-warning b { display: block; line-height: 1.5; }
.membership-revoke-warning p { margin: 6px 0 0; color: #606266; line-height: 1.6; }
.membership-revoke-form { margin-top: 18px; }
.closure-summary { margin-top: 16px; }
.closure-summary p { line-height: 1.7; }
.closure-form { margin-top: 18px; }
.closure-danger-header { display: flex; align-items: center; gap: 10px; padding: 13px 16px; color: #f56c6c; background: #fef0f0; border: 1px solid #fbc4c4; }
.closure-danger-header .el-icon-warning { flex: 0 0 auto; font-size: 26px; }
.closure-danger-header span { color: #c45656; font-weight: 600; line-height: 1.5; }
.closure-confirm-tip { margin-top: 6px; color: #f56c6c; line-height: 1.5; }
.closure-forfeit { margin-top: 14px; }
.closure-policy { margin-top: 14px; padding: 10px 14px; color: #606266; background: #f7f8fa; }
.closure-policy p { margin: 6px 0; }
</style>
