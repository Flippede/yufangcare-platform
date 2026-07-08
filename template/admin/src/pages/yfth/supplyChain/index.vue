<template>
  <div class="yfth-supply-chain">
    <el-card shadow="never" class="ivu-mt" :body-style="{ padding: '16px' }">
      <el-tabs v-model="tab" @tab-click="loadCurrent(true)">
        <el-tab-pane label="Catalog" name="catalog">
          <div class="toolbar">
            <el-input v-model="filters.catalog.keyword" clearable placeholder="Product id/name" class="w220" />
            <el-select v-model="filters.catalog.status" clearable placeholder="Status" class="w140">
              <el-option label="Active" value="active" />
              <el-option label="Disabled" value="disabled" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="loadCatalog(true)">Search</el-button>
            <el-button icon="el-icon-plus" @click="openCatalog()">Add</el-button>
          </div>
          <el-table v-loading="loading.catalog" :data="catalog.list" border>
            <el-table-column prop="product_id" label="Product ID" width="100" />
            <el-table-column prop="product_name" label="Product" min-width="220" />
            <el-table-column prop="purchase_price" label="Purchase Price" width="130" />
            <el-table-column prop="retail_reference_price" label="Retail Ref" width="120" />
            <el-table-column prop="min_purchase_quantity" label="Min Qty" width="100" />
            <el-table-column prop="package_multiple" label="Multiple" width="100" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column label="Actions" width="180" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" @click="openCatalog(scope.row)">Edit</el-button>
                <el-button type="text" @click="disableCatalog(scope.row)">Disable</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div class="pager"><el-pagination :current-page.sync="filters.catalog.page" :page-size="filters.catalog.limit" :total="catalog.count" layout="total, prev, pager, next" @current-change="loadCatalog(false)" /></div>
        </el-tab-pane>

        <el-tab-pane label="Purchase Orders" name="orders">
          <div class="toolbar">
            <el-input v-model="filters.orders.keyword" clearable placeholder="Purchase no" class="w220" />
            <el-input v-model="filters.orders.store_id" clearable placeholder="Store ID" class="w120" />
            <el-select v-model="filters.orders.status" clearable placeholder="Status" class="w160">
              <el-option v-for="item in orderStatuses" :key="item" :label="item" :value="item" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="loadOrders(true)">Search</el-button>
          </div>
          <el-table v-loading="loading.orders" :data="orders.list" border>
            <el-table-column prop="purchase_no" label="Purchase No" min-width="210" />
            <el-table-column prop="store_id" label="Store" width="90" />
            <el-table-column prop="status" label="Status" width="110" />
            <el-table-column prop="audit_status" label="Audit" width="110" />
            <el-table-column prop="quantity_total" label="Qty" width="80" />
            <el-table-column prop="amount_snapshot" label="Amount" width="120" />
            <el-table-column label="Created" width="160"><template slot-scope="scope">{{ formatTime(scope.row.create_time) }}</template></el-table-column>
            <el-table-column label="Actions" width="260" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" @click="openOrder(scope.row)">Detail</el-button>
                <el-button v-if="scope.row.status === 'submitted'" type="text" @click="auditOrder(scope.row, 'approve')">Approve</el-button>
                <el-button v-if="scope.row.status === 'submitted'" type="text" @click="auditOrder(scope.row, 'reject')">Reject</el-button>
                <el-button v-if="scope.row.status === 'approved'" type="text" @click="openShip(scope.row)">Ship</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div class="pager"><el-pagination :current-page.sync="filters.orders.page" :page-size="filters.orders.limit" :total="orders.count" layout="total, prev, pager, next" @current-change="loadOrders(false)" /></div>
        </el-tab-pane>

        <el-tab-pane label="Shipments" name="shipments">
          <div class="toolbar">
            <el-input v-model="filters.shipments.purchase_order_id" clearable placeholder="Order ID" class="w120" />
            <el-select v-model="filters.shipments.status" clearable placeholder="Status" class="w160">
              <el-option label="Shipped" value="shipped" />
              <el-option label="Received" value="received" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="loadShipments(true)">Search</el-button>
          </div>
          <el-table v-loading="loading.shipments" :data="shipments.list" border>
            <el-table-column prop="shipment_no" label="Shipment No" min-width="210" />
            <el-table-column prop="purchase_order_id" label="Order ID" width="100" />
            <el-table-column prop="status" label="Status" width="110" />
            <el-table-column prop="quantity_total" label="Qty" width="80" />
            <el-table-column prop="logistics_company" label="Company" min-width="140" />
            <el-table-column prop="logistics_no" label="Logistics No" min-width="160" />
            <el-table-column label="Shipped" width="160"><template slot-scope="scope">{{ formatTime(scope.row.shipped_time) }}</template></el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Inventory" name="inventory">
          <div class="toolbar">
            <el-input v-model="filters.inventory.store_id" clearable placeholder="Store ID" class="w120" />
            <el-input v-model="filters.inventory.sku_unique" clearable placeholder="SKU unique" class="w180" />
            <el-button type="primary" icon="el-icon-search" @click="loadInventory(true)">Search</el-button>
          </div>
          <el-table v-loading="loading.inventory" :data="inventory.list" border>
            <el-table-column prop="store_id" label="Store" width="90" />
            <el-table-column prop="product_name" label="Product" min-width="200" />
            <el-table-column prop="sku_unique" label="SKU" min-width="130" />
            <el-table-column prop="quantity" label="Quantity" width="100" />
            <el-table-column prop="warning_quantity" label="Warning" width="100" />
            <el-table-column label="Updated" width="160"><template slot-scope="scope">{{ formatTime(scope.row.update_time) }}</template></el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Ledger" name="ledger">
          <div class="toolbar">
            <el-input v-model="filters.ledger.store_id" clearable placeholder="Store ID" class="w120" />
            <el-input v-model="filters.ledger.sku_unique" clearable placeholder="SKU unique" class="w180" />
            <el-button type="primary" icon="el-icon-search" @click="loadLedger(true)">Search</el-button>
          </div>
          <el-table v-loading="loading.ledger" :data="ledger.list" border>
            <el-table-column prop="store_id" label="Store" width="90" />
            <el-table-column prop="product_name" label="Product" min-width="200" />
            <el-table-column prop="sku_unique" label="SKU" min-width="130" />
            <el-table-column prop="quantity_change" label="Change" width="100" />
            <el-table-column prop="balance_after" label="After" width="100" />
            <el-table-column prop="business_type" label="Business" width="150" />
            <el-table-column label="Time" width="160"><template slot-scope="scope">{{ formatTime(scope.row.add_time) }}</template></el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Alert Rules" name="alerts">
          <div class="toolbar">
            <el-input v-model="filters.alerts.store_id" clearable placeholder="Store ID" class="w120" />
            <el-button type="primary" icon="el-icon-search" @click="loadAlerts(true)">Search</el-button>
            <el-button icon="el-icon-plus" @click="openAlert()">Add</el-button>
          </div>
          <el-table v-loading="loading.alerts" :data="alerts.list" border>
            <el-table-column prop="store_id" label="Store" width="90" />
            <el-table-column prop="product_name" label="Product" min-width="200" />
            <el-table-column prop="sku_unique" label="SKU" min-width="140" />
            <el-table-column prop="threshold_quantity" label="Threshold" width="110" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column label="Actions" width="110"><template slot-scope="scope"><el-button type="text" @click="openAlert(scope.row)">Edit</el-button></template></el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <el-dialog title="Catalog Item" :visible.sync="catalogDialog" width="540px">
      <el-form label-width="150px">
        <el-form-item label="Product ID"><el-input v-model="catalogForm.product_id" /></el-form-item>
        <el-form-item label="Purchase Price"><el-input v-model="catalogForm.purchase_price" /></el-form-item>
        <el-form-item label="Retail Reference"><el-input v-model="catalogForm.retail_reference_price" /></el-form-item>
        <el-form-item label="Min Quantity"><el-input v-model="catalogForm.min_purchase_quantity" /></el-form-item>
        <el-form-item label="Package Multiple"><el-input v-model="catalogForm.package_multiple" /></el-form-item>
        <el-form-item label="Allowed Store Types"><el-input v-model="catalogForm.allow_store_types" placeholder="franchise,direct" /></el-form-item>
        <el-form-item label="Qualification"><el-input v-model="catalogForm.qualification_requirement" /></el-form-item>
        <el-form-item label="Status">
          <el-select v-model="catalogForm.status" class="full"><el-option label="Active" value="active" /><el-option label="Disabled" value="disabled" /></el-select>
        </el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="catalogDialog = false">Cancel</el-button><el-button type="primary" @click="saveCatalog">Save</el-button></span>
    </el-dialog>

    <el-drawer title="Purchase Order Detail" :visible.sync="orderDrawer" size="50%">
      <div class="drawer-body" v-if="detail.order">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="Purchase No">{{ detail.order.purchase_no }}</el-descriptions-item>
          <el-descriptions-item label="Status">{{ detail.order.status }}</el-descriptions-item>
          <el-descriptions-item label="Store">{{ detail.order.store_id }}</el-descriptions-item>
          <el-descriptions-item label="Amount">{{ detail.order.amount_snapshot }}</el-descriptions-item>
        </el-descriptions>
        <h4>Items</h4>
        <el-table :data="detail.items || []" border size="small">
          <el-table-column prop="product_name_snapshot" label="Product" min-width="180" />
          <el-table-column prop="sku_name_snapshot" label="SKU" min-width="140" />
          <el-table-column prop="quantity" label="Qty" width="80" />
          <el-table-column prop="purchase_price_snapshot" label="Price" width="100" />
          <el-table-column prop="amount_snapshot" label="Amount" width="100" />
        </el-table>
      </div>
    </el-drawer>

    <el-dialog title="Ship Purchase Order" :visible.sync="shipDialog" width="460px">
      <el-form label-width="120px">
        <el-form-item label="Company"><el-input v-model="shipForm.logistics_company" /></el-form-item>
        <el-form-item label="Logistics No"><el-input v-model="shipForm.logistics_no" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="shipDialog = false">Cancel</el-button><el-button type="primary" @click="shipOrder">Ship</el-button></span>
    </el-dialog>

    <el-dialog title="Alert Rule" :visible.sync="alertDialog" width="460px">
      <el-form label-width="120px">
        <el-form-item label="Store ID"><el-input v-model="alertForm.store_id" /></el-form-item>
        <el-form-item label="SKU Unique"><el-input v-model="alertForm.sku_unique" /></el-form-item>
        <el-form-item label="Threshold"><el-input v-model="alertForm.threshold_quantity" /></el-form-item>
        <el-form-item label="Status"><el-select v-model="alertForm.status" class="full"><el-option label="Active" value="active" /><el-option label="Disabled" value="disabled" /></el-select></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="alertDialog = false">Cancel</el-button><el-button type="primary" @click="saveAlert">Save</el-button></span>
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
      orderStatuses: ['submitted', 'approved', 'rejected', 'shipped', 'stocked', 'cancelled'],
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
        this.$message.success('Saved');
        this.loadCatalog(false);
      });
    },
    disableCatalog(row) {
      this.$confirm('Disable this catalog item?').then(() => yfthSupplyCatalogDisable({ id: row.id, reason: 'admin_disabled' })).then(() => {
        this.$message.success('Disabled');
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
      this.$prompt('Reason', action === 'approve' ? 'Approve' : 'Reject', { inputValue: action }).then(({ value }) => {
        return yfthPurchaseOrderAudit(row.id, { action, reason: value || action });
      }).then(() => {
        this.$message.success('Updated');
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
        this.$message.success('Shipped');
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
        this.$message.success('Saved');
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
