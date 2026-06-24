<template>
  <div class="yfth-package-benefit">
    <el-card shadow="never" class="ivu-mt" :body-style="{ padding: '16px' }">
      <el-tabs v-model="activeTab" @tab-click="loadActive">
        <el-tab-pane label="套餐模板" name="template">
          <div class="toolbar">
            <el-input v-model="filters.template.package_code" clearable placeholder="套餐编码" class="w160" />
            <el-select v-model="filters.template.status" clearable placeholder="状态" class="w140">
              <el-option label="草稿" value="draft" />
              <el-option label="已发布" value="published" />
              <el-option label="停用" value="disabled" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="loadTemplates">查询</el-button>
            <el-button type="success" icon="el-icon-plus" @click="openTemplate()">新增</el-button>
            <el-button icon="el-icon-document-add" @click="openRule()">规则版本</el-button>
            <el-button icon="el-icon-link" @click="openBinding()">商品绑定</el-button>
          </div>
          <el-table v-loading="loading.template" :data="lists.template" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="package_code" label="编码" min-width="150" />
            <el-table-column prop="package_name" label="名称" min-width="180" />
            <el-table-column prop="base_price" label="价格" width="110" />
            <el-table-column prop="benefit_months" label="权益月数" width="100" />
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column label="当前规则" min-width="160">
              <template slot-scope="scope">
                <span v-if="scope.row.current_rule">V{{ scope.row.current_rule.version_no }}</span>
                <span v-else>-</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="190" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" @click="openTemplate(scope.row)">编辑</el-button>
                <el-button
                  v-if="scope.row.current_rule"
                  type="text"
                  icon="el-icon-document-copy"
                  @click="copyCurrentRule(scope.row)"
                  >复制规则</el-button
                >
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="权益规则" name="benefit">
          <div class="toolbar">
            <el-input v-model="filters.monthlyRule.rule_version_id" clearable placeholder="规则版本ID" class="w140" />
            <el-input v-model="filters.monthlyRule.month_no" clearable placeholder="月份" class="w100" />
            <el-button type="primary" icon="el-icon-search" @click="loadMonthlyRules">查询</el-button>
            <el-button type="success" icon="el-icon-plus" @click="openBenefitTemplate()">权益模板</el-button>
            <el-button icon="el-icon-plus" @click="openMonthlyRule()">月份规则</el-button>
          </div>
          <el-table v-loading="loading.monthlyRule" :data="lists.monthlyRule" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="rule_version_id" label="规则版本" width="100" />
            <el-table-column prop="month_no" label="月份" width="80" />
            <el-table-column prop="benefit_code" label="权益编码" min-width="140" />
            <el-table-column prop="benefit_name" label="权益名称" min-width="180" />
            <el-table-column prop="quantity" label="数量" width="100" />
            <el-table-column prop="service_capability" label="能力要求" min-width="140" />
            <el-table-column prop="status" label="状态" width="100" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="购买与实例" name="instance">
          <div class="toolbar">
            <el-input v-model="filters.instance.uid" clearable placeholder="UID" class="w120" />
            <el-input v-model="filters.instance.store_id" clearable placeholder="门店ID" class="w120" />
            <el-select v-model="filters.instance.status" clearable placeholder="实例状态" class="w150">
              <el-option label="生效中" value="active" />
              <el-option label="退款中" value="refunding" />
              <el-option label="已退款" value="refunded" />
              <el-option label="已关闭" value="closed" />
              <el-option label="已过期" value="expired" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="loadInstances">查询</el-button>
            <el-button icon="el-icon-refresh" @click="openDuePeriods">开放到期月份</el-button>
          </div>
          <el-table v-loading="loading.instance" :data="lists.instance" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="instance_no" label="实例号" min-width="180" />
            <el-table-column prop="uid" label="UID" width="100" />
            <el-table-column prop="store_id" label="门店" width="90" />
            <el-table-column prop="order_sn" label="订单号" min-width="150" />
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column prop="refund_status" label="退款状态" min-width="150" />
            <el-table-column label="有效期" min-width="220">
              <template slot-scope="scope"
                >{{ timeText(scope.row.start_time) }} - {{ timeText(scope.row.end_time) }}</template
              >
            </el-table-column>
            <el-table-column label="操作" width="170" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-view" @click="showInstance(scope.row)">详情</el-button>
                <el-button type="text" icon="el-icon-warning-outline" @click="openState(scope.row)">状态</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="购买记录" name="purchase">
          <div class="toolbar">
            <el-input v-model="filters.purchase.uid" clearable placeholder="UID" class="w120" />
            <el-input v-model="filters.purchase.order_sn" clearable placeholder="订单号" class="w180" />
            <el-select v-model="filters.purchase.purchase_status" clearable placeholder="状态" class="w150">
              <el-option label="待支付" value="wait_pay" />
              <el-option label="已激活" value="activated" />
              <el-option label="退款中" value="refunding" />
              <el-option label="已退款" value="refunded" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="loadPurchases">查询</el-button>
            <el-button icon="el-icon-refresh" @click="recoverActivation">扫描补偿</el-button>
          </div>
          <el-table v-loading="loading.purchase" :data="lists.purchase" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="purchase_no" label="购买号" min-width="180" />
            <el-table-column prop="uid" label="UID" width="100" />
            <el-table-column prop="store_id" label="门店" width="90" />
            <el-table-column prop="order_sn" label="订单号" min-width="150" />
            <el-table-column prop="expected_pay_price" label="应付" width="100" />
            <el-table-column prop="purchase_status" label="购买状态" width="120" />
            <el-table-column prop="activation_status" label="激活状态" width="120" />
            <el-table-column prop="last_activation_error" label="最近失败" min-width="180" show-overflow-tooltip />
            <el-table-column label="操作" width="110" fixed="right">
              <template slot-scope="scope">
                <el-button
                  v-if="scope.row.instance_id === 0 && ['pending', 'failed'].includes(scope.row.activation_status)"
                  type="text"
                  icon="el-icon-refresh-right"
                  @click="retryActivation(scope.row)"
                  >重试</el-button
                >
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <el-dialog :visible.sync="dialogs.template" title="套餐模板" width="720px">
      <el-form label-width="110px">
        <el-form-item label="编码"><el-input v-model="forms.template.package_code" /></el-form-item>
        <el-form-item label="名称"><el-input v-model="forms.template.package_name" /></el-form-item>
        <el-form-item label="标题"><el-input v-model="forms.template.package_title" /></el-form-item>
        <el-form-item label="价格"><el-input v-model="forms.template.base_price" /></el-form-item>
        <el-form-item label="权益月数"><el-input v-model="forms.template.benefit_months" /></el-form-item>
        <el-form-item label="状态">
          <el-select v-model="forms.template.status">
            <el-option label="草稿" value="draft" />
            <el-option label="已发布" value="published" />
            <el-option label="停用" value="disabled" />
          </el-select>
        </el-form-item>
        <el-form-item label="协议标题"><el-input v-model="forms.template.agreement_title" /></el-form-item>
        <el-form-item label="协议内容"
          ><el-input v-model="forms.template.agreement_content" type="textarea" :rows="4"
        /></el-form-item>
        <el-form-item label="服务摘要"
          ><el-input v-model="forms.template.service_summary" type="textarea" :rows="3"
        /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.template = false">取消</el-button>
        <el-button type="primary" @click="saveTemplate">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.rule" title="规则版本" width="640px">
      <el-form label-width="120px">
        <el-form-item label="模板ID"><el-input v-model="forms.rule.template_id" /></el-form-item>
        <el-form-item label="版本号"
          ><el-input v-model="forms.rule.version_no" placeholder="留空自动递增"
        /></el-form-item>
        <el-form-item label="价格"><el-input v-model="forms.rule.package_price" /></el-form-item>
        <el-form-item label="权益月数"><el-input v-model="forms.rule.month_count" /></el-form-item>
        <el-form-item label="状态">
          <el-select v-model="forms.rule.status">
            <el-option label="草稿" value="draft" />
            <el-option label="发布" value="published" />
            <el-option label="停用" value="disabled" />
          </el-select>
        </el-form-item>
        <el-form-item label="协议内容"
          ><el-input v-model="forms.rule.agreement_content" type="textarea" :rows="4"
        /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.rule = false">取消</el-button>
        <el-button type="primary" @click="saveRule">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.binding" title="商品/SKU 绑定" width="560px">
      <el-form label-width="130px">
        <el-form-item label="模板ID"><el-input v-model="forms.binding.template_id" /></el-form-item>
        <el-form-item label="规则版本ID"><el-input v-model="forms.binding.rule_version_id" /></el-form-item>
        <el-form-item label="商品ID"><el-input v-model="forms.binding.product_id" /></el-form-item>
        <el-form-item label="SKU unique"><el-input v-model="forms.binding.product_attr_unique" /></el-form-item>
        <el-form-item label="价格快照"><el-input v-model="forms.binding.sku_price_snapshot" /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.binding = false">取消</el-button>
        <el-button type="primary" @click="saveBinding">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.benefitTemplate" title="权益模板" width="560px">
      <el-form label-width="110px">
        <el-form-item label="编码"><el-input v-model="forms.benefitTemplate.benefit_code" /></el-form-item>
        <el-form-item label="名称"><el-input v-model="forms.benefitTemplate.benefit_name" /></el-form-item>
        <el-form-item label="类型"><el-input v-model="forms.benefitTemplate.benefit_type" /></el-form-item>
        <el-form-item label="履约类型"><el-input v-model="forms.benefitTemplate.fulfillment_type" /></el-form-item>
        <el-form-item label="单位"><el-input v-model="forms.benefitTemplate.unit" /></el-form-item>
        <el-form-item label="描述"
          ><el-input v-model="forms.benefitTemplate.description" type="textarea" :rows="3"
        /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.benefitTemplate = false">取消</el-button>
        <el-button type="primary" @click="saveBenefitTemplate">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.monthlyRule" title="月份权益规则" width="620px">
      <el-form label-width="130px">
        <el-form-item label="模板ID"><el-input v-model="forms.monthlyRule.template_id" /></el-form-item>
        <el-form-item label="规则版本ID"><el-input v-model="forms.monthlyRule.rule_version_id" /></el-form-item>
        <el-form-item label="月份"><el-input v-model="forms.monthlyRule.month_no" /></el-form-item>
        <el-form-item label="权益模板ID"><el-input v-model="forms.monthlyRule.benefit_template_id" /></el-form-item>
        <el-form-item label="数量"><el-input v-model="forms.monthlyRule.quantity" /></el-form-item>
        <el-form-item label="开放偏移天数"><el-input v-model="forms.monthlyRule.available_offset_days" /></el-form-item>
        <el-form-item label="过期偏移天数"><el-input v-model="forms.monthlyRule.expire_offset_days" /></el-form-item>
        <el-form-item label="能力要求"><el-input v-model="forms.monthlyRule.service_capability" /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.monthlyRule = false">取消</el-button>
        <el-button type="primary" @click="saveMonthlyRule">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.instance" title="套餐实例详情" width="820px">
      <pre class="json-view">{{ formatJson(instanceDetail) }}</pre>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.state" title="高风险状态变更" width="520px">
      <el-alert title="该操作会影响会员身份和权益状态，请填写原因并输入 CONFIRM。" type="warning" show-icon />
      <el-form label-width="110px" class="state-form">
        <el-form-item label="目标状态">
          <el-select v-model="forms.state.status">
            <el-option label="关闭" value="closed" />
            <el-option label="过期" value="expired" />
            <el-option label="退款中" value="refunding" />
          </el-select>
        </el-form-item>
        <el-form-item label="原因"><el-input v-model="forms.state.reason" type="textarea" :rows="3" /></el-form-item>
        <el-form-item label="确认文本"><el-input v-model="forms.state.confirm_text" /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.state = false">取消</el-button>
        <el-button type="danger" @click="saveState">确认变更</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthBenefitTemplateSave,
  yfthMonthlyRuleList,
  yfthMonthlyRuleSave,
  yfthOpenDuePeriods,
  yfthPackageActivationRecover,
  yfthPackageActivationRetry,
  yfthPackageBindingSave,
  yfthPackageInstanceDetail,
  yfthPackageInstanceLifecycle,
  yfthPackageInstanceList,
  yfthPackagePurchaseList,
  yfthPackageRuleCopy,
  yfthPackageRuleSave,
  yfthPackageTemplateList,
  yfthPackageTemplateSave,
} from '@/api/yfth';

export default {
  name: 'YfthPackageBenefit',
  data() {
    return {
      activeTab: 'template',
      loading: {
        template: false,
        monthlyRule: false,
        instance: false,
        purchase: false,
      },
      filters: {
        template: { package_code: '', status: '' },
        monthlyRule: { rule_version_id: '', month_no: '' },
        instance: { uid: '', store_id: '', status: '' },
        purchase: { uid: '', order_sn: '', purchase_status: '' },
      },
      lists: {
        template: [],
        monthlyRule: [],
        instance: [],
        purchase: [],
      },
      dialogs: {
        template: false,
        rule: false,
        binding: false,
        benefitTemplate: false,
        monthlyRule: false,
        instance: false,
        state: false,
      },
      forms: {
        template: {},
        rule: {},
        binding: {},
        benefitTemplate: {},
        monthlyRule: {},
        state: {},
      },
      stateTarget: null,
      instanceDetail: {},
    };
  },
  mounted() {
    this.loadTemplates();
  },
  methods: {
    loadActive() {
      if (this.activeTab === 'template') this.loadTemplates();
      if (this.activeTab === 'benefit') this.loadMonthlyRules();
      if (this.activeTab === 'instance') this.loadInstances();
      if (this.activeTab === 'purchase') this.loadPurchases();
    },
    loadTemplates() {
      this.loading.template = true;
      yfthPackageTemplateList(this.filters.template)
        .then((res) => {
          this.lists.template = res.data.list || [];
        })
        .finally(() => {
          this.loading.template = false;
        });
    },
    loadMonthlyRules() {
      this.loading.monthlyRule = true;
      yfthMonthlyRuleList(this.filters.monthlyRule)
        .then((res) => {
          this.lists.monthlyRule = res.data.list || [];
        })
        .finally(() => {
          this.loading.monthlyRule = false;
        });
    },
    loadInstances() {
      this.loading.instance = true;
      yfthPackageInstanceList(this.filters.instance)
        .then((res) => {
          this.lists.instance = res.data.list || [];
        })
        .finally(() => {
          this.loading.instance = false;
        });
    },
    loadPurchases() {
      this.loading.purchase = true;
      yfthPackagePurchaseList(this.filters.purchase)
        .then((res) => {
          this.lists.purchase = res.data.list || [];
        })
        .finally(() => {
          this.loading.purchase = false;
        });
    },
    openTemplate(row) {
      this.forms.template = Object.assign(
        {
          package_type: 'health_package',
          currency: 'CNY',
          status: 'draft',
          base_price: '0.00',
          benefit_months: 0,
        },
        row || {},
      );
      this.dialogs.template = true;
    },
    saveTemplate() {
      yfthPackageTemplateSave(this.forms.template).then(() => {
        this.$message.success('Saved');
        this.dialogs.template = false;
        this.loadTemplates();
      });
    },
    openRule() {
      this.forms.rule = { status: 'draft', package_price: '0.00', month_count: 0 };
      this.dialogs.rule = true;
    },
    saveRule() {
      yfthPackageRuleSave(this.forms.rule).then(() => {
        this.$message.success('Saved');
        this.dialogs.rule = false;
        this.loadTemplates();
      });
    },
    copyCurrentRule(row) {
      yfthPackageRuleCopy(row.current_rule.id).then(() => {
        this.$message.success('已复制为新草稿版本');
        this.loadTemplates();
      });
    },
    openBinding() {
      this.forms.binding = { binding_status: 'active', sku_price_snapshot: '0.00' };
      this.dialogs.binding = true;
    },
    saveBinding() {
      yfthPackageBindingSave(this.forms.binding).then(() => {
        this.$message.success('Saved');
        this.dialogs.binding = false;
      });
    },
    openBenefitTemplate() {
      this.forms.benefitTemplate = {
        benefit_type: 'service',
        fulfillment_type: 'manual',
        unit: 'item',
        status: 'active',
      };
      this.dialogs.benefitTemplate = true;
    },
    saveBenefitTemplate() {
      yfthBenefitTemplateSave(this.forms.benefitTemplate).then(() => {
        this.$message.success('Saved');
        this.dialogs.benefitTemplate = false;
      });
    },
    openMonthlyRule() {
      this.forms.monthlyRule = { month_no: 1, quantity: '1.00', available_offset_days: 0, expire_offset_days: 0 };
      this.dialogs.monthlyRule = true;
    },
    saveMonthlyRule() {
      yfthMonthlyRuleSave(this.forms.monthlyRule).then(() => {
        this.$message.success('Saved');
        this.dialogs.monthlyRule = false;
        this.loadMonthlyRules();
      });
    },
    showInstance(row) {
      yfthPackageInstanceDetail(row.id).then((res) => {
        this.instanceDetail = res.data || {};
        this.dialogs.instance = true;
      });
    },
    openState(row) {
      this.stateTarget = row;
      this.forms.state = { status: 'closed', reason: '', confirm_text: '' };
      this.dialogs.state = true;
    },
    saveState() {
      yfthPackageInstanceLifecycle(this.stateTarget.id, this.forms.state).then(() => {
        this.$message.success('Updated');
        this.dialogs.state = false;
        this.loadInstances();
      });
    },
    openDuePeriods() {
      yfthOpenDuePeriods({ limit: 100 }).then((res) => {
        const data = res.data || {};
        this.$message.success(`Opened ${data.opened || 0}, expired ${data.expired || 0}`);
        this.loadInstances();
      });
    },
    recoverActivation() {
      yfthPackageActivationRecover({ limit: 50 }).then((res) => {
        const data = res.data || {};
        this.$message.success(`Activated ${data.activated || 0}, failed ${data.failed || 0}`);
        this.loadPurchases();
      });
    },
    retryActivation(row) {
      this.$prompt('请输入人工重试原因', '重试激活', {
        inputType: 'textarea',
        confirmButtonText: '重试',
        cancelButtonText: '取消',
        inputValidator: (value) => !!String(value || '').trim(),
        inputErrorMessage: '必须填写原因',
      }).then(({ value }) => {
        yfthPackageActivationRetry(row.id, { reason: value }).then(() => {
          this.$message.success('已触发重试');
          this.loadPurchases();
        });
      });
    },
    timeText(value) {
      if (!value) return '-';
      return new Date(value * 1000).toLocaleString();
    },
    formatJson(value) {
      return JSON.stringify(value || {}, null, 2);
    },
  },
};
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}
.w100 {
  width: 100px;
}
.w120 {
  width: 120px;
}
.w140 {
  width: 140px;
}
.w150 {
  width: 150px;
}
.w160 {
  width: 160px;
}
.w180 {
  width: 180px;
}
.json-view {
  max-height: 560px;
  overflow: auto;
  padding: 12px;
  background: #f7f8fa;
  border: 1px solid #e7e8eb;
  border-radius: 4px;
  font-size: 12px;
  line-height: 1.6;
}
.state-form {
  margin-top: 14px;
}
</style>
