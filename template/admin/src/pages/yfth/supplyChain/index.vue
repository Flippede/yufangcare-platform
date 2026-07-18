<template>
  <div class="yfth-supply-chain">
    <el-card shadow="never" class="ivu-mt" :body-style="{ padding: '16px' }">
      <el-tabs v-model="tab" @tab-click="loadCurrent(true)">
        <el-tab-pane label="总部采购目录" name="catalog">
          <div class="toolbar">
            <el-input v-model="filters.catalog.keyword" clearable placeholder="商品 ID 或名称" class="w220" />
            <el-select v-model="filters.catalog.status" clearable placeholder="状态" class="w140">
              <el-option label="启用" value="active" />
              <el-option label="停用" value="disabled" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="loadCatalog(true)">查询</el-button>
            <el-button icon="el-icon-plus" @click="openCatalog()">新增</el-button>
          </div>
          <el-table v-loading="loading.catalog" :data="catalog.list" border>
            <el-table-column prop="product_id" label="商品 ID" width="100" />
            <el-table-column prop="product_name" label="商品" min-width="220" />
            <el-table-column prop="purchase_price" label="采购价" width="130" />
            <el-table-column prop="retail_reference_price" label="零售参考价" width="120" />
            <el-table-column prop="min_purchase_quantity" label="最低采购量" width="110" />
            <el-table-column prop="package_multiple" label="包装倍数" width="100" />
            <el-table-column label="状态" width="100"><template slot-scope="scope">{{ statusText(scope.row.status) }}</template></el-table-column>
            <el-table-column label="操作" width="180" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" @click="openCatalog(scope.row)">编辑</el-button>
                <el-button type="text" @click="disableCatalog(scope.row)">停用</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div class="pager"><el-pagination :current-page.sync="filters.catalog.page" :page-size="filters.catalog.limit" :total="catalog.count" layout="total, prev, pager, next" @current-change="loadCatalog(false)" /></div>
        </el-tab-pane>

        <el-tab-pane label="门店采购单" name="orders">
          <div class="toolbar">
            <el-input v-model="filters.orders.keyword" clearable placeholder="采购单号" class="w220" />
            <el-input v-model="filters.orders.store_id" clearable placeholder="门店 ID" class="w120" />
            <el-select v-model="filters.orders.status" clearable placeholder="状态" class="w160">
              <el-option v-for="item in orderStatuses" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="loadOrders(true)">查询</el-button>
          </div>
          <el-table v-loading="loading.orders" :data="orders.list" border>
            <el-table-column prop="purchase_no" label="采购单号" min-width="210" />
            <el-table-column prop="store_id" label="门店" width="90" />
            <el-table-column label="状态" width="110"><template slot-scope="scope">{{ statusText(scope.row.status) }}</template></el-table-column>
            <el-table-column label="审核状态" width="110"><template slot-scope="scope">{{ statusText(scope.row.audit_status) }}</template></el-table-column>
            <el-table-column prop="quantity_total" label="数量" width="80" />
            <el-table-column prop="amount_snapshot" label="金额" width="120" />
            <el-table-column label="创建时间" width="160"><template slot-scope="scope">{{ formatTime(scope.row.create_time) }}</template></el-table-column>
            <el-table-column label="操作" width="260" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" @click="openOrder(scope.row)">详情</el-button>
                <el-button v-if="scope.row.status === 'submitted'" type="text" @click="auditOrder(scope.row, 'approve')">通过</el-button>
                <el-button v-if="scope.row.status === 'submitted'" type="text" @click="auditOrder(scope.row, 'reject')">驳回</el-button>
                <el-button v-if="scope.row.status === 'approved'" type="text" @click="openShip(scope.row)">发货</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div class="pager"><el-pagination :current-page.sync="filters.orders.page" :page-size="filters.orders.limit" :total="orders.count" layout="total, prev, pager, next" @current-change="loadOrders(false)" /></div>
        </el-tab-pane>

        <el-tab-pane label="发货记录" name="shipments">
          <div class="toolbar">
            <el-input v-model="filters.shipments.purchase_order_id" clearable placeholder="采购单 ID" class="w120" />
            <el-select v-model="filters.shipments.status" clearable placeholder="状态" class="w160">
              <el-option label="已发货" value="shipped" />
              <el-option label="已收货" value="received" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="loadShipments(true)">查询</el-button>
          </div>
          <el-table v-loading="loading.shipments" :data="shipments.list" border>
            <el-table-column prop="shipment_no" label="发货单号" min-width="210" />
            <el-table-column prop="purchase_order_id" label="采购单 ID" width="100" />
            <el-table-column label="状态" width="110"><template slot-scope="scope">{{ statusText(scope.row.status) }}</template></el-table-column>
            <el-table-column prop="quantity_total" label="数量" width="80" />
            <el-table-column prop="logistics_company" label="物流公司" min-width="140" />
            <el-table-column prop="logistics_no" label="物流单号" min-width="160" />
            <el-table-column label="发货时间" width="160"><template slot-scope="scope">{{ formatTime(scope.row.shipped_time) }}</template></el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="门店库存" name="inventory">
          <div class="toolbar">
            <el-input v-model="filters.inventory.store_id" clearable placeholder="门店 ID" class="w120" />
            <el-input v-model="filters.inventory.sku_unique" clearable placeholder="SKU 标识" class="w180" />
            <el-button type="primary" icon="el-icon-search" @click="loadInventory(true)">查询</el-button>
          </div>
          <el-table v-loading="loading.inventory" :data="inventory.list" border>
            <el-table-column prop="store_id" label="门店" width="90" />
            <el-table-column prop="product_name" label="商品" min-width="200" />
            <el-table-column prop="sku_unique" label="SKU" min-width="130" />
            <el-table-column prop="quantity" label="库存数量" width="100" />
            <el-table-column prop="warning_quantity" label="预警数量" width="100" />
            <el-table-column label="更新时间" width="160"><template slot-scope="scope">{{ formatTime(scope.row.update_time) }}</template></el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="库存流水" name="ledger">
          <div class="toolbar">
            <el-input v-model="filters.ledger.store_id" clearable placeholder="门店 ID" class="w120" />
            <el-input v-model="filters.ledger.sku_unique" clearable placeholder="SKU 标识" class="w180" />
            <el-button type="primary" icon="el-icon-search" @click="loadLedger(true)">查询</el-button>
          </div>
          <el-table v-loading="loading.ledger" :data="ledger.list" border>
            <el-table-column prop="store_id" label="门店" width="90" />
            <el-table-column prop="product_name" label="商品" min-width="200" />
            <el-table-column prop="sku_unique" label="SKU" min-width="130" />
            <el-table-column prop="quantity_change" label="变动数量" width="100" />
            <el-table-column prop="balance_after" label="变动后库存" width="110" />
            <el-table-column prop="business_type" label="业务类型" width="150" />
            <el-table-column label="时间" width="160"><template slot-scope="scope">{{ formatTime(scope.row.add_time) }}</template></el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="库存预警" name="alerts">
          <div class="toolbar">
            <el-input v-model="filters.alerts.store_id" clearable placeholder="门店 ID" class="w120" />
            <el-button type="primary" icon="el-icon-search" @click="loadAlerts(true)">查询</el-button>
            <el-button icon="el-icon-plus" @click="openAlert()">新增</el-button>
          </div>
          <el-table v-loading="loading.alerts" :data="alerts.list" border>
            <el-table-column prop="store_id" label="门店" width="90" />
            <el-table-column prop="product_name" label="商品" min-width="200" />
            <el-table-column prop="sku_unique" label="SKU" min-width="140" />
            <el-table-column prop="threshold_quantity" label="预警阈值" width="110" />
            <el-table-column label="状态" width="100"><template slot-scope="scope">{{ statusText(scope.row.status) }}</template></el-table-column>
            <el-table-column label="操作" width="110"><template slot-scope="scope"><el-button type="text" @click="openAlert(scope.row)">编辑</el-button></template></el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <el-dialog title="采购目录商品" :visible.sync="catalogDialog" width="540px">
      <el-form label-width="150px">
        <el-form-item label="商品 ID"><el-input v-model="catalogForm.product_id" /></el-form-item>
        <el-form-item label="采购价"><el-input v-model="catalogForm.purchase_price" /></el-form-item>
        <el-form-item label="零售参考价"><el-input v-model="catalogForm.retail_reference_price" /></el-form-item>
        <el-form-item label="最低采购量"><el-input v-model="catalogForm.min_purchase_quantity" /></el-form-item>
        <el-form-item label="包装倍数"><el-input v-model="catalogForm.package_multiple" /></el-form-item>
        <el-form-item label="允许门店类型"><el-input v-model="catalogForm.allow_store_types" placeholder="加盟店、直营店" /></el-form-item>
        <el-form-item label="资质要求"><el-input v-model="catalogForm.qualification_requirement" /></el-form-item>
        <el-form-item label="状态">
          <el-select v-model="catalogForm.status" class="full"><el-option label="启用" value="active" /><el-option label="停用" value="disabled" /></el-select>
        </el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="catalogDialog = false">取消</el-button><el-button type="primary" @click="saveCatalog">保存</el-button></span>
    </el-dialog>

    <el-drawer title="采购单详情" :visible.sync="orderDrawer" size="50%">
      <div class="drawer-body" v-if="detail.order">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="采购单号">{{ detail.order.purchase_no }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ detail.order.status }}</el-descriptions-item>
          <el-descriptions-item label="门店">{{ detail.order.store_id }}</el-descriptions-item>
          <el-descriptions-item label="金额">{{ detail.order.amount_snapshot }}</el-descriptions-item>
        </el-descriptions>
        <h4>采购商品</h4>
        <el-table :data="detail.items || []" border size="small">
          <el-table-column prop="product_name_snapshot" label="商品" min-width="180" />
          <el-table-column prop="sku_name_snapshot" label="SKU" min-width="140" />
          <el-table-column prop="quantity" label="数量" width="80" />
          <el-table-column prop="purchase_price_snapshot" label="采购价" width="100" />
          <el-table-column prop="amount_snapshot" label="金额" width="100" />
        </el-table>
      </div>
    </el-drawer>

    <el-dialog title="采购单发货" :visible.sync="shipDialog" width="460px">
      <el-form label-width="120px">
        <el-form-item label="物流公司"><el-input v-model="shipForm.logistics_company" /></el-form-item>
        <el-form-item label="物流单号"><el-input v-model="shipForm.logistics_no" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="shipDialog = false">取消</el-button><el-button type="primary" @click="shipOrder">确认发货</el-button></span>
    </el-dialog>

    <el-dialog title="库存预警规则" :visible.sync="alertDialog" width="460px">
      <el-form label-width="120px">
        <el-form-item label="门店 ID"><el-input v-model="alertForm.store_id" /></el-form-item>
        <el-form-item label="SKU 标识"><el-input v-model="alertForm.sku_unique" /></el-form-item>
        <el-form-item label="预警阈值"><el-input v-model="alertForm.threshold_quantity" /></el-form-item>
        <el-form-item label="状态"><el-select v-model="alertForm.status" class="full"><el-option label="启用" value="active" /><el-option label="停用" value="disabled" /></el-select></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="alertDialog = false">取消</el-button><el-button type="primary" @click="saveAlert">保存</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthInventoryAlertRuleList,
  yfthInventoryAlertRuleSave,
  yfthInventoryBalanceList,
  yfthInventoryLedgerList,
  yfthPurchaseOrderAudit,
  yfthPurchaseOrderDetail,
  yfthPurchaseOrderList,
  yfthPurchaseOrderShip,
  yfthSupplyCatalogDisable,
  yfthSupplyCatalogList,
  yfthSupplyCatalogSave,
  yfthSupplyShipmentList,
} from '@/api/yfth';

export default {
  name: 'YfthSupplyChain',
  data() {
    return {
      tab: 'catalog',
      loading: { catalog: false, orders: false, shipments: false, inventory: false, ledger: false, alerts: false },
      filters: {
        catalog: { keyword: '', status: '', page: 1, limit: 20 },
        orders: { keyword: '', status: '', store_id: '', page: 1, limit: 20 },
        shipments: { purchase_order_id: '', status: '', page: 1, limit: 20 },
        inventory: { store_id: '', sku_unique: '', page: 1, limit: 20 },
        ledger: { store_id: '', sku_unique: '', page: 1, limit: 20 },
        alerts: { store_id: '', page: 1, limit: 20 },
      },
      catalog: { list: [], count: 0 },
      orders: { list: [], count: 0 },
      shipments: { list: [], count: 0 },
      inventory: { list: [], count: 0 },
      ledger: { list: [], count: 0 },
      alerts: { list: [], count: 0 },
      orderStatuses: [
        { label: '待审核', value: 'submitted' },
        { label: '已通过', value: 'approved' },
        { label: '已驳回', value: 'rejected' },
        { label: '已发货', value: 'shipped' },
        { label: '已入库', value: 'stocked' },
        { label: '已取消', value: 'cancelled' },
      ],
      catalogDialog: false,
      catalogForm: {},
      orderDrawer: false,
      detail: {},
      shipDialog: false,
      shipTarget: {},
      shipForm: { logistics_company: '', logistics_no: '' },
      alertDialog: false,
      alertForm: {},
    };
  },
  created() {
    this.loadCatalog(true);
  },
  methods: {
    loadCurrent(reset) {
      const map = { catalog: this.loadCatalog, orders: this.loadOrders, shipments: this.loadShipments, inventory: this.loadInventory, ledger: this.loadLedger, alerts: this.loadAlerts };
      return map[this.tab] ? map[this.tab](reset) : null;
    },
    assignResult(target, res) {
      target.list = (res.data && res.data.list) || [];
      target.count = (res.data && res.data.count) || 0;
    },
    loadCatalog(reset) {
      if (reset) this.filters.catalog.page = 1;
      this.loading.catalog = true;
      yfthSupplyCatalogList(this.filters.catalog).then((res) => this.assignResult(this.catalog, res)).finally(() => { this.loading.catalog = false; });
    },
    loadOrders(reset) {
      if (reset) this.filters.orders.page = 1;
      this.loading.orders = true;
      yfthPurchaseOrderList(this.filters.orders).then((res) => this.assignResult(this.orders, res)).finally(() => { this.loading.orders = false; });
    },
    loadShipments(reset) {
      if (reset) this.filters.shipments.page = 1;
      this.loading.shipments = true;
      yfthSupplyShipmentList(this.filters.shipments).then((res) => this.assignResult(this.shipments, res)).finally(() => { this.loading.shipments = false; });
    },
    loadInventory(reset) {
      if (reset) this.filters.inventory.page = 1;
      this.loading.inventory = true;
      yfthInventoryBalanceList(this.filters.inventory).then((res) => this.assignResult(this.inventory, res)).finally(() => { this.loading.inventory = false; });
    },
    loadLedger(reset) {
      if (reset) this.filters.ledger.page = 1;
      this.loading.ledger = true;
      yfthInventoryLedgerList(this.filters.ledger).then((res) => this.assignResult(this.ledger, res)).finally(() => { this.loading.ledger = false; });
    },
    loadAlerts(reset) {
      if (reset) this.filters.alerts.page = 1;
      this.loading.alerts = true;
      yfthInventoryAlertRuleList(this.filters.alerts).then((res) => this.assignResult(this.alerts, res)).finally(() => { this.loading.alerts = false; });
    },
    openCatalog(row) {
      this.catalogForm = Object.assign({ status: 'active', product_id: '', purchase_price: '0.00', retail_reference_price: '0.00', min_purchase_quantity: 1, package_multiple: 1, allow_store_types: '', qualification_requirement: '' }, row || {});
      this.catalogDialog = true;
    },
    saveCatalog() {
      yfthSupplyCatalogSave(this.catalogForm).then(() => {
        this.catalogDialog = false;
        this.$message.success('已保存');
        this.loadCatalog(false);
      });
    },
    disableCatalog(row) {
      this.$confirm('确认停用该采购目录商品？', '停用确认').then(() => yfthSupplyCatalogDisable({ id: row.id, reason: 'admin_disabled' })).then(() => {
        this.$message.success('已停用');
        this.loadCatalog(false);
      });
    },
    openOrder(row) {
      yfthPurchaseOrderDetail(row.id).then((res) => {
        this.detail = res.data || {};
        this.orderDrawer = true;
      });
    },
    auditOrder(row, action) {
      this.$prompt('请输入审核原因', action === 'approve' ? '通过采购单' : '驳回采购单', { inputValue: action === 'approve' ? '审核通过' : '审核驳回' }).then(({ value }) => {
        return yfthPurchaseOrderAudit(row.id, { action, reason: value || action });
      }).then(() => {
        this.$message.success('审核状态已更新');
        this.loadOrders(false);
      });
    },
    openShip(row) {
      this.shipTarget = row;
      this.shipForm = { logistics_company: '', logistics_no: '' };
      this.shipDialog = true;
    },
    shipOrder() {
      yfthPurchaseOrderShip(this.shipTarget.id, this.shipForm).then(() => {
        this.shipDialog = false;
        this.$message.success('采购单已发货');
        this.loadOrders(false);
        this.loadShipments(true);
      });
    },
    openAlert(row) {
      this.alertForm = Object.assign({ store_id: '', sku_unique: '', threshold_quantity: 0, status: 'active' }, row || {});
      this.alertDialog = true;
    },
    saveAlert() {
      yfthInventoryAlertRuleSave(this.alertForm).then(() => {
        this.alertDialog = false;
        this.$message.success('预警规则已保存');
        this.loadAlerts(false);
      });
    },
    formatTime(value) {
      const ts = Number(value || 0);
      if (!ts) return '-';
      const date = new Date(ts * 1000);
      const pad = (n) => (n < 10 ? '0' + n : '' + n);
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    },
    statusText(value) {
      return {
        active: '启用', disabled: '停用', submitted: '待审核', approved: '已通过', rejected: '已驳回',
        shipped: '已发货', received: '已收货', stocked: '已入库', cancelled: '已取消', pending: '待处理', passed: '已通过',
      }[value] || value || '-';
    },
  },
};
</script>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}
.w120 { width: 120px; }
.w140 { width: 140px; }
.w160 { width: 160px; }
.w180 { width: 180px; }
.w220 { width: 220px; }
.full { width: 100%; }
.pager {
  padding-top: 16px;
  text-align: right;
}
.drawer-body {
  padding: 0 24px 24px;
}
h4 {
  margin: 22px 0 12px;
}
</style>
