<template>
  <div class="yfth-foundation">
    <el-card shadow="never" class="ivu-mt" :body-style="{ padding: '16px' }">
      <el-tabs v-model="activeTab" @tab-click="handleTabChange">
        <el-tab-pane label="用户身份" name="identity">
          <div class="toolbar">
            <el-input v-model="filters.identity.uid" clearable placeholder="UID" class="w120" />
            <el-select v-model="filters.identity.role_code" clearable placeholder="身份" class="w160">
              <el-option v-for="item in roleOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-select v-model="filters.identity.status" clearable placeholder="状态" class="w140">
              <el-option label="启用" value="active" />
              <el-option label="暂停" value="paused" />
              <el-option label="禁用" value="disabled" />
            </el-select>
            <el-button type="primary" v-db-click @click="search('identity')">搜索</el-button>
            <el-button v-db-click @click="reset('identity')">重置</el-button>
          </div>
          <el-table v-loading="loading.identity" :data="lists.identity" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="uid" label="UID" width="110" />
            <el-table-column prop="role_name" label="身份" min-width="130" />
            <el-table-column prop="role_code" label="编码" min-width="140" />
            <el-table-column prop="source_type" label="来源" min-width="130" />
            <el-table-column prop="status" label="状态" width="110" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="门店角色" name="storeRole">
          <div class="toolbar">
            <el-input v-model="filters.storeRole.uid" clearable placeholder="UID" class="w120" />
            <el-input v-model="filters.storeRole.store_id" clearable placeholder="门店ID" class="w120" />
            <el-select v-model="filters.storeRole.role_code" clearable placeholder="角色" class="w160">
              <el-option label="门店店长" value="store_manager" />
              <el-option label="门店员工" value="store_staff" />
            </el-select>
            <el-button type="primary" v-db-click @click="search('storeRole')">搜索</el-button>
            <el-button v-db-click @click="reset('storeRole')">重置</el-button>
          </div>
          <el-table v-loading="loading.storeRole" :data="lists.storeRole" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="uid" label="UID" width="110" />
            <el-table-column prop="store_id" label="门店ID" width="110" />
            <el-table-column prop="role_name" label="角色" min-width="130" />
            <el-table-column prop="status" label="状态" width="110" />
            <el-table-column label="权限范围" min-width="220">
              <template slot-scope="scope">{{ formatJson(scope.row.permission_scope) }}</template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="经营主体" name="subject">
          <div class="toolbar">
            <el-select v-model="filters.subject.subject_type" clearable placeholder="主体类型" class="w180">
              <el-option v-for="item in subjectTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-select v-model="filters.subject.status" clearable placeholder="状态" class="w140">
              <el-option label="启用" value="active" />
              <el-option label="暂停" value="paused" />
              <el-option label="禁用" value="disabled" />
            </el-select>
            <el-button type="primary" v-db-click @click="search('subject')">搜索</el-button>
            <el-button v-db-click @click="reset('subject')">重置</el-button>
            <el-button type="success" v-db-click @click="openSubject()">新增主体</el-button>
          </div>
          <el-table v-loading="loading.subject" :data="lists.subject" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="subject_type_name" label="类型" min-width="130" />
            <el-table-column prop="subject_name" label="名称" min-width="180" />
            <el-table-column prop="credit_code" label="信用代码" min-width="180" />
            <el-table-column prop="contact_phone_masked" label="联系电话" min-width="130" />
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column label="操作" width="100" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" v-db-click @click="openSubject(scope.row)">编辑</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="门店主体" name="storeSubject">
          <div class="toolbar">
            <el-input v-model="filters.storeSubject.store_id" clearable placeholder="门店ID" class="w120" />
            <el-input v-model="filters.storeSubject.subject_id" clearable placeholder="主体ID" class="w120" />
            <el-select v-model="filters.storeSubject.subject_role" clearable placeholder="主体角色" class="w150">
              <el-option label="销售" value="sales" />
              <el-option label="服务" value="service" />
              <el-option label="开票" value="invoice" />
            </el-select>
            <el-button type="primary" v-db-click @click="search('storeSubject')">搜索</el-button>
            <el-button v-db-click @click="reset('storeSubject')">重置</el-button>
          </div>
          <el-table v-loading="loading.storeSubject" :data="lists.storeSubject" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_id" label="门店ID" width="110" />
            <el-table-column prop="subject_id" label="主体ID" width="110" />
            <el-table-column prop="store_type_name" label="门店类型" min-width="130" />
            <el-table-column prop="subject_role" label="主体角色" min-width="120" />
            <el-table-column prop="status" label="状态" width="100" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="门店资质" name="qualification">
          <div class="toolbar">
            <el-input v-model="filters.qualification.store_id" clearable placeholder="门店ID" class="w120" />
            <el-input v-model="filters.qualification.subject_id" clearable placeholder="主体ID" class="w120" />
            <el-select v-model="filters.qualification.status" clearable placeholder="状态" class="w140">
              <el-option
                v-for="item in qualificationStatusOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
            <el-button type="primary" v-db-click @click="search('qualification')">搜索</el-button>
            <el-button v-db-click @click="reset('qualification')">重置</el-button>
            <el-button type="success" v-db-click @click="openQualification()">提交资质</el-button>
          </div>
          <el-table v-loading="loading.qualification" :data="lists.qualification" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_id" label="门店ID" width="100" />
            <el-table-column prop="subject_id" label="主体ID" width="100" />
            <el-table-column prop="qualification_type" label="资质类型" min-width="150" />
            <el-table-column prop="certificate_no" label="证照编号" min-width="170" />
            <el-table-column prop="status_name" label="状态" width="110" />
            <el-table-column label="过期时间" min-width="150">
              <template slot-scope="scope">{{ formatTime(scope.row.expire_time) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="220" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" v-db-click @click="openQualification(scope.row)">编辑</el-button>
                <el-button type="text" v-db-click @click="openAudit(scope.row, 'active')">通过</el-button>
                <el-button type="text" v-db-click @click="openAudit(scope.row, 'rejected')">驳回</el-button>
                <el-button type="text" v-db-click @click="openAudit(scope.row, 'paused')">暂停</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="门店能力" name="capability">
          <div class="toolbar">
            <el-input v-model="filters.capability.store_id" clearable placeholder="门店ID" class="w120" />
            <el-select v-model="filters.capability.capability_code" clearable placeholder="能力" class="w180">
              <el-option v-for="item in capabilityOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-select v-model="filters.capability.status" clearable placeholder="状态" class="w140">
              <el-option label="启用" value="active" />
              <el-option label="暂停" value="paused" />
              <el-option label="过期" value="expired" />
            </el-select>
            <el-button type="primary" v-db-click @click="search('capability')">搜索</el-button>
            <el-button v-db-click @click="reset('capability')">重置</el-button>
          </div>
          <el-table v-loading="loading.capability" :data="lists.capability" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_id" label="门店ID" width="100" />
            <el-table-column prop="capability_name" label="能力" min-width="140" />
            <el-table-column prop="source_qualification_id" label="来源资质" width="110" />
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column prop="close_reason" label="关闭原因" min-width="180" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="收款路由" name="paymentRoute">
          <div class="toolbar">
            <el-input v-model="filters.paymentRoute.store_id" clearable placeholder="门店ID" class="w120" />
            <el-select v-model="filters.paymentRoute.business_scene" clearable placeholder="场景" class="w180">
              <el-option
                v-for="item in paymentSceneOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
            <el-button type="primary" v-db-click @click="search('paymentRoute')">搜索</el-button>
            <el-button v-db-click @click="reset('paymentRoute')">重置</el-button>
          </div>
          <el-table v-loading="loading.paymentRoute" :data="lists.paymentRoute" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_id" label="门店ID" width="100" />
            <el-table-column prop="business_scene_name" label="场景" min-width="130" />
            <el-table-column prop="route_type" label="路由类型" min-width="130" />
            <el-table-column prop="merchant_ref_masked" label="商户引用" min-width="140" />
            <el-table-column prop="sub_merchant_ref_masked" label="子商户引用" min-width="140" />
            <el-table-column prop="config_status" label="配置状态" min-width="130" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="审计事件" name="auditEvent">
          <div class="toolbar">
            <el-input v-model="filters.auditEvent.store_id" clearable placeholder="门店ID" class="w120" />
            <el-input v-model="filters.auditEvent.object_type" clearable placeholder="对象类型" class="w140" />
            <el-input v-model="filters.auditEvent.object_id" clearable placeholder="对象ID" class="w140" />
            <el-button type="primary" v-db-click @click="search('auditEvent')">搜索</el-button>
            <el-button v-db-click @click="reset('auditEvent')">重置</el-button>
          </div>
          <el-table v-loading="loading.auditEvent" :data="lists.auditEvent" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="business_domain" label="业务域" min-width="130" />
            <el-table-column prop="object_type" label="对象" min-width="130" />
            <el-table-column prop="object_id" label="对象ID" min-width="120" />
            <el-table-column prop="action" label="动作" min-width="140" />
            <el-table-column prop="operator_uid" label="操作人" width="100" />
            <el-table-column prop="store_id" label="门店ID" width="100" />
            <el-table-column label="时间" min-width="150">
              <template slot-scope="scope">{{ formatTime(scope.row.add_time) }}</template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>

      <el-pagination
        class="pager"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :page-sizes="[10, 20, 50, 100]"
        :current-page="pages[activeTab]"
        :page-size="limits[activeTab]"
        :total="totals[activeTab]"
        @size-change="handleSizeChange"
        @current-change="handleCurrentChange"
      />
    </el-card>

    <el-dialog :visible.sync="subjectDialog" title="经营主体" width="620px" :close-on-click-modal="false">
      <el-form :model="subjectForm" label-width="110px">
        <el-form-item label="主体类型">
          <el-select v-model="subjectForm.subject_type" class="full">
            <el-option v-for="item in subjectTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="主体名称">
          <el-input v-model="subjectForm.subject_name" />
        </el-form-item>
        <el-form-item label="信用代码">
          <el-input v-model="subjectForm.credit_code" />
        </el-form-item>
        <el-form-item label="法定代表人">
          <el-input v-model="subjectForm.legal_person" />
        </el-form-item>
        <el-form-item label="联系人">
          <el-input v-model="subjectForm.contact_name" />
        </el-form-item>
        <el-form-item label="联系电话">
          <el-input v-model="subjectForm.contact_phone" />
        </el-form-item>
        <el-form-item label="注册地址">
          <el-input v-model="subjectForm.registered_address" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="subjectForm.status" class="full">
            <el-option label="启用" value="active" />
            <el-option label="暂停" value="paused" />
            <el-option label="禁用" value="disabled" />
          </el-select>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="subjectDialog = false">取消</el-button>
        <el-button type="primary" v-db-click @click="submitSubject">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="qualificationDialog" title="门店资质" width="620px" :close-on-click-modal="false">
      <el-form :model="qualificationForm" label-width="110px">
        <el-form-item label="门店ID">
          <el-input v-model="qualificationForm.store_id" />
        </el-form-item>
        <el-form-item label="主体ID">
          <el-input v-model="qualificationForm.subject_id" />
        </el-form-item>
        <el-form-item label="资质类型">
          <el-input v-model="qualificationForm.qualification_type" />
        </el-form-item>
        <el-form-item label="证照编号">
          <el-input v-model="qualificationForm.certificate_no" />
        </el-form-item>
        <el-form-item label="附件ID">
          <el-input v-model="qualificationForm.attachment_id" />
        </el-form-item>
        <el-form-item label="开始时间">
          <el-date-picker v-model="qualificationForm.start_time" type="date" value-format="yyyy-MM-dd" class="full" />
        </el-form-item>
        <el-form-item label="过期时间">
          <el-date-picker v-model="qualificationForm.expire_time" type="date" value-format="yyyy-MM-dd" class="full" />
        </el-form-item>
        <el-form-item label="适用范围">
          <el-input v-model="qualificationForm.scopeText" type="textarea" :rows="3" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="qualificationDialog = false">取消</el-button>
        <el-button type="primary" v-db-click @click="submitQualification">提交</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="auditDialog" title="资质审核" width="460px" :close-on-click-modal="false">
      <el-form :model="auditForm" label-width="80px">
        <el-form-item label="状态">
          <el-select v-model="auditForm.status" class="full">
            <el-option label="通过" value="active" />
            <el-option label="驳回" value="rejected" />
            <el-option label="暂停" value="paused" />
            <el-option label="过期" value="expired" />
          </el-select>
        </el-form-item>
        <el-form-item label="原因">
          <el-input v-model="auditForm.reason" type="textarea" :rows="3" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="auditDialog = false">取消</el-button>
        <el-button type="primary" v-db-click @click="submitAudit">确认</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthAuditEventList,
  yfthCapabilityList,
  yfthIdentityList,
  yfthPaymentRouteList,
  yfthQualificationAudit,
  yfthQualificationList,
  yfthQualificationSave,
  yfthStoreRoleList,
  yfthStoreSubjectList,
  yfthSubjectList,
  yfthSubjectSave,
} from '@/api/yfth';

const emptyFilters = () => ({
  identity: { uid: '', role_code: '', status: '' },
  storeRole: { uid: '', store_id: '', role_code: '', status: '' },
  subject: { subject_type: '', status: '' },
  storeSubject: { store_id: '', subject_id: '', subject_role: '', status: '' },
  qualification: { store_id: '', subject_id: '', qualification_type: '', status: '' },
  capability: { store_id: '', capability_code: '', status: '' },
  paymentRoute: { store_id: '', business_scene: '', status: '' },
  auditEvent: { business_domain: '', object_type: '', object_id: '', operator_uid: '', store_id: '' },
});

export default {
  name: 'YfthFoundation',
  data() {
    return {
      activeTab: 'identity',
      filters: emptyFilters(),
      lists: {
        identity: [],
        storeRole: [],
        subject: [],
        storeSubject: [],
        qualification: [],
        capability: [],
        paymentRoute: [],
        auditEvent: [],
      },
      loading: {
        identity: false,
        storeRole: false,
        subject: false,
        storeSubject: false,
        qualification: false,
        capability: false,
        paymentRoute: false,
        auditEvent: false,
      },
      pages: {
        identity: 1,
        storeRole: 1,
        subject: 1,
        storeSubject: 1,
        qualification: 1,
        capability: 1,
        paymentRoute: 1,
        auditEvent: 1,
      },
      limits: {
        identity: 20,
        storeRole: 20,
        subject: 20,
        storeSubject: 20,
        qualification: 20,
        capability: 20,
        paymentRoute: 20,
        auditEvent: 20,
      },
      totals: {
        identity: 0,
        storeRole: 0,
        subject: 0,
        storeSubject: 0,
        qualification: 0,
        capability: 0,
        paymentRoute: 0,
        auditEvent: 0,
      },
      roleOptions: [
        { label: '普通用户', value: 'customer' },
        { label: '家庭成员', value: 'family_member' },
        { label: '门店店长', value: 'store_manager' },
        { label: '门店员工', value: 'store_staff' },
        { label: '加盟商', value: 'franchisee' },
        { label: '供应商', value: 'supplier' },
        { label: '总部运营', value: 'headquarter_operator' },
      ],
      subjectTypeOptions: [
        { label: '总部主体', value: 'headquarter' },
        { label: '加盟公司', value: 'franchise_company' },
        { label: '门店公司', value: 'store_company' },
        { label: '个体工商户', value: 'individual' },
        { label: '供应商主体', value: 'supplier' },
      ],
      qualificationStatusOptions: [
        { label: '待审核', value: 'pending' },
        { label: '已通过', value: 'active' },
        { label: '已驳回', value: 'rejected' },
        { label: '已暂停', value: 'paused' },
        { label: '已过期', value: 'expired' },
      ],
      capabilityOptions: [
        { label: '商品销售', value: 'retail_sale' },
        { label: '套餐销售', value: 'package_sale' },
        { label: '预约服务', value: 'reservation_service' },
        { label: '订单核销', value: 'order_writeoff' },
        { label: '门店采购', value: 'store_purchase' },
        { label: '在线收款', value: 'online_payment' },
      ],
      paymentSceneOptions: [
        { label: '零售订单', value: 'retail_order' },
        { label: '套餐订单', value: 'package_order' },
        { label: '加盟采购', value: 'franchise_purchase' },
        { label: '服务退款', value: 'service_refund' },
      ],
      subjectDialog: false,
      subjectForm: {},
      qualificationDialog: false,
      qualificationForm: {},
      auditDialog: false,
      auditForm: { id: 0, status: 'active', reason: '' },
    };
  },
  mounted() {
    this.fetchList(this.activeTab);
  },
  methods: {
    apiMap() {
      return {
        identity: yfthIdentityList,
        storeRole: yfthStoreRoleList,
        subject: yfthSubjectList,
        storeSubject: yfthStoreSubjectList,
        qualification: yfthQualificationList,
        capability: yfthCapabilityList,
        paymentRoute: yfthPaymentRouteList,
        auditEvent: yfthAuditEventList,
      };
    },
    fetchList(tab) {
      const api = this.apiMap()[tab];
      const params = Object.assign({}, this.filters[tab], {
        page: this.pages[tab],
        limit: this.limits[tab],
      });
      this.loading[tab] = true;
      api(params)
        .then((res) => {
          const data = res.data || {};
          this.lists[tab] = data.list || [];
          this.totals[tab] = data.count || 0;
        })
        .finally(() => {
          this.loading[tab] = false;
        });
    },
    handleTabChange() {
      this.fetchList(this.activeTab);
    },
    search(tab) {
      this.pages[tab] = 1;
      this.fetchList(tab);
    },
    reset(tab) {
      this.filters[tab] = emptyFilters()[tab];
      this.search(tab);
    },
    handleSizeChange(size) {
      this.limits[this.activeTab] = size;
      this.pages[this.activeTab] = 1;
      this.fetchList(this.activeTab);
    },
    handleCurrentChange(page) {
      this.pages[this.activeTab] = page;
      this.fetchList(this.activeTab);
    },
    openSubject(row) {
      this.subjectForm = Object.assign(
        {
          id: 0,
          subject_type: 'store_company',
          subject_name: '',
          credit_code: '',
          legal_person: '',
          contact_name: '',
          contact_phone: '',
          registered_address: '',
          status: 'active',
        },
        row || {},
      );
      this.subjectDialog = true;
    },
    submitSubject() {
      yfthSubjectSave(this.subjectForm).then(() => {
        this.$message.success('保存成功');
        this.subjectDialog = false;
        this.fetchList('subject');
      });
    },
    openQualification(row) {
      const form = Object.assign(
        {
          id: 0,
          store_id: '',
          subject_id: '',
          qualification_type: '',
          certificate_no: '',
          attachment_id: '',
          start_time: '',
          expire_time: '',
          scopeText: '{}',
        },
        row || {},
      );
      form.scopeText = this.formatJson(form.scope || form.scopeText || {});
      form.start_time = this.formatDateValue(form.start_time);
      form.expire_time = this.formatDateValue(form.expire_time);
      this.qualificationForm = form;
      this.qualificationDialog = true;
    },
    submitQualification() {
      const data = Object.assign({}, this.qualificationForm, {
        scope: this.parseJson(this.qualificationForm.scopeText),
      });
      yfthQualificationSave(data).then(() => {
        this.$message.success('提交成功');
        this.qualificationDialog = false;
        this.fetchList('qualification');
      });
    },
    openAudit(row, status) {
      this.auditForm = { id: row.id, status, reason: '' };
      this.auditDialog = true;
    },
    submitAudit() {
      yfthQualificationAudit(this.auditForm).then(() => {
        this.$message.success('操作成功');
        this.auditDialog = false;
        this.fetchList('qualification');
        this.fetchList('capability');
      });
    },
    formatJson(value) {
      if (!value) return '';
      if (typeof value === 'string') return value;
      return JSON.stringify(value);
    },
    parseJson(value) {
      if (!value) return {};
      try {
        return JSON.parse(value);
      } catch (e) {
        return { text: value };
      }
    },
    formatTime(value) {
      const timestamp = Number(value || 0);
      if (!timestamp) return '';
      const date = new Date(timestamp * 1000);
      const pad = (n) => String(n).padStart(2, '0');
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(
        date.getMinutes(),
      )}`;
    },
    formatDateValue(value) {
      const timestamp = Number(value || 0);
      if (!timestamp) return '';
      const date = new Date(timestamp * 1000);
      const pad = (n) => String(n).padStart(2, '0');
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    },
  },
};
</script>

<style scoped>
.yfth-foundation .toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}
.yfth-foundation .pager {
  margin-top: 16px;
  text-align: right;
}
.yfth-foundation .w120 {
  width: 120px;
}
.yfth-foundation .w140 {
  width: 140px;
}
.yfth-foundation .w150 {
  width: 150px;
}
.yfth-foundation .w160 {
  width: 160px;
}
.yfth-foundation .w180 {
  width: 180px;
}
.yfth-foundation .full {
  width: 100%;
}
</style>
