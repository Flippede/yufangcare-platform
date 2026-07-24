<template>
  <div class="procurement-products">
    <el-card shadow="never" class="ivu-mt">
      <div slot="header" class="card-header">
        <div>
          <strong>采购商品管理</strong>
          <div class="sub-title">采购目录独立定价，下单、支付、发货、物流及售后统一复用商城订单链路。</div>
        </div>
        <div>
          <el-button @click="createNativeProduct">新建采购专用商品</el-button>
          <el-button type="success" :loading="importing" @click="importRetailProducts">导入商城在售商品</el-button>
          <el-button type="primary" icon="el-icon-plus" @click="openEditor()">添加采购商品</el-button>
        </div>
      </div>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="只要商品列入本目录，店长即可按采购价下单。采购专用商品可以在原商品库创建并保持商城下架，然后加入本目录。"
      />

      <div class="toolbar">
        <el-input v-model="filters.keyword" clearable placeholder="商品名称或ID" class="w240" @keyup.enter.native="load(true)" />
        <el-select v-model="filters.status" clearable placeholder="采购上架状态" class="w160">
          <el-option label="采购商城上架" value="active" />
          <el-option label="采购商城下架" value="disabled" />
        </el-select>
        <el-button type="primary" icon="el-icon-search" @click="load(true)">查询</el-button>
      </div>

      <el-table v-loading="loading" :data="catalog.list" border>
        <el-table-column label="商品" min-width="280">
          <template slot-scope="{ row }">
            <div class="product-cell">
              <el-image :src="row.product_image" fit="cover" class="product-image" />
              <div>
                <div class="product-name">{{ row.product_name || '商品已停用' }}</div>
                <div class="muted">商品ID {{ row.product_id }} · {{ row.skus.length }} 个SKU</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="retail_price" label="商城参考价" width="120" />
        <el-table-column prop="purchase_price" label="默认采购价" width="120" />
        <el-table-column label="采购规格价" min-width="220">
          <template slot-scope="{ row }">
            <div v-for="sku in row.skus.slice(0, 3)" :key="sku.sku_unique" class="sku-line">
              <span>{{ sku.sku_name || '默认' }}</span>
              <strong>¥{{ sku.purchase_price }}</strong>
            </div>
            <span v-if="row.skus.length > 3" class="muted">另有 {{ row.skus.length - 3 }} 个规格</span>
          </template>
        </el-table-column>
        <el-table-column prop="min_purchase_quantity" label="起购量" width="90" />
        <el-table-column label="采购状态" width="120">
          <template slot-scope="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'info'">
              {{ row.status === 'active' ? '采购商城上架' : '采购商城下架' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template slot-scope="{ row }">
            <el-button type="text" @click="openEditor(row)">编辑价格</el-button>
            <el-button v-if="row.status === 'active'" type="text" class="danger" @click="disable(row)">下架</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination
          :current-page.sync="filters.page"
          :page-size="filters.limit"
          :total="catalog.count"
          layout="total, prev, pager, next"
          @current-change="load(false)"
        />
      </div>
    </el-card>

    <el-dialog title="采购商品与SKU价格" :visible.sync="editorVisible" width="720px" append-to-body>
      <el-form label-width="110px">
        <el-form-item label="商品">
          <div v-if="form.product_id" class="selected-product">
            <el-image :src="form.product_image" fit="cover" class="product-image" />
            <div>
              <div>{{ form.product_name }}</div>
              <div class="muted">商品ID {{ form.product_id }}</div>
            </div>
            <el-button v-if="!form.id" type="text" @click="productVisible = true">重新选择</el-button>
          </div>
          <el-button v-else type="primary" plain @click="productVisible = true">选择原商品/SKU</el-button>
        </el-form-item>
        <el-form-item label="默认采购价">
          <el-input-number v-model="form.purchase_price" :min="0.01" :precision="2" :step="1" controls-position="right" />
        </el-form-item>
        <el-form-item label="商城参考价">
          <el-input-number v-model="form.retail_reference_price" :min="0" :precision="2" :step="1" controls-position="right" />
        </el-form-item>
        <el-form-item label="起购量">
          <el-input-number v-model="form.min_purchase_quantity" :min="1" :precision="0" controls-position="right" />
        </el-form-item>
        <el-form-item label="包装倍数">
          <el-input-number v-model="form.package_multiple" :min="1" :precision="0" controls-position="right" />
        </el-form-item>
        <el-form-item label="采购状态">
          <el-radio-group v-model="form.status">
            <el-radio label="active">采购商城上架</el-radio>
            <el-radio label="disabled">采购商城下架</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="SKU采购价">
          <el-table :data="form.skus" border size="small">
            <el-table-column prop="sku_name" label="规格" min-width="180" />
            <el-table-column prop="price" label="商城价" width="110" />
            <el-table-column label="采购价" width="180">
              <template slot-scope="{ row }">
                <el-input-number v-model="row.purchase_price" :min="0.01" :precision="2" :step="1" size="small" controls-position="right" />
              </template>
            </el-table-column>
          </el-table>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="editorVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog title="选择商品" :visible.sync="productVisible" width="760px" append-to-body>
      <div class="toolbar">
        <el-input v-model="productFilters.keyword" clearable placeholder="商品名称或ID" class="w240" @keyup.enter.native="searchProducts(true)" />
        <el-button type="primary" @click="searchProducts(true)">查询</el-button>
      </div>
      <el-table v-loading="productLoading" :data="products.list" border @row-dblclick="selectProduct">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="商品" min-width="260">
          <template slot-scope="{ row }">
            <div class="product-cell">
              <el-image :src="row.image" fit="cover" class="product-image" />
              <div>
                <div>{{ row.store_name }}</div>
                <el-tag size="mini" :type="row.is_show ? 'success' : 'info'">{{ row.is_show ? '商城在售' : '采购专用/商城下架' }}</el-tag>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="price" label="商城价" width="100" />
        <el-table-column prop="stock" label="库存" width="90" />
        <el-table-column label="操作" width="90">
          <template slot-scope="{ row }"><el-button type="text" @click="selectProduct(row)">选择</el-button></template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination
          :current-page.sync="productFilters.page"
          :page-size="productFilters.limit"
          :total="products.count"
          layout="total, prev, pager, next"
          @current-change="searchProducts(false)"
        />
      </div>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthSupplyCatalogDisable,
  yfthSupplyCatalogImportVisible,
  yfthSupplyCatalogList,
  yfthSupplyCatalogSave,
  yfthSupplyProductSearch,
} from '@/api/yfth';

export default {
  name: 'YfthProcurementProducts',
  data() {
    return {
      loading: false,
      importing: false,
      saving: false,
      catalog: { list: [], count: 0 },
      filters: { keyword: '', status: '', page: 1, limit: 20 },
      editorVisible: false,
      productVisible: false,
      productLoading: false,
      products: { list: [], count: 0 },
      productFilters: { keyword: '', page: 1, limit: 15 },
      form: {},
    };
  },
  created() {
    this.load(true);
  },
  methods: {
    load(reset) {
      if (reset) this.filters.page = 1;
      this.loading = true;
      return yfthSupplyCatalogList(this.filters)
        .then((res) => {
          this.catalog = res.data || { list: [], count: 0 };
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openEditor(row) {
      const source = row || {};
      this.form = {
        id: source.id || 0,
        product_id: source.product_id || 0,
        product_name: source.product_name || '',
        product_image: source.product_image || '',
        purchase_price: Number(source.purchase_price || 0.01),
        retail_reference_price: Number(source.retail_reference_price || source.retail_price || 0),
        min_purchase_quantity: Number(source.min_purchase_quantity || 1),
        package_multiple: Number(source.package_multiple || 1),
        status: source.status || 'active',
        skus: (source.skus || []).map((sku) => Object.assign({}, sku, {
          purchase_price: Number(sku.purchase_price || source.purchase_price || sku.price || 0.01),
        })),
      };
      this.editorVisible = true;
      if (!row) {
        this.searchProducts(true);
        this.productVisible = true;
      }
    },
    searchProducts(reset) {
      if (reset) this.productFilters.page = 1;
      this.productLoading = true;
      return yfthSupplyProductSearch(this.productFilters)
        .then((res) => {
          this.products = res.data || { list: [], count: 0 };
        })
        .finally(() => {
          this.productLoading = false;
        });
    },
    selectProduct(row) {
      this.form.product_id = row.id;
      this.form.product_name = row.store_name;
      this.form.product_image = row.image;
      this.form.retail_reference_price = Number(row.price || 0);
      this.form.purchase_price = Number(row.price || 0.01);
      this.form.skus = (row.skus || []).map((sku) => Object.assign({}, sku, {
        purchase_price: Number(sku.price || row.price || 0.01),
      }));
      this.productVisible = false;
    },
    save() {
      if (!this.form.product_id) return this.$message.warning('请选择商品');
      const payload = Object.assign({}, this.form, {
        sku_prices: this.form.skus.map((sku) => ({
          sku_unique: sku.sku_unique,
          purchase_price: Number(sku.purchase_price).toFixed(2),
        })),
      });
      this.saving = true;
      return yfthSupplyCatalogSave(payload)
        .then(() => {
          this.$message.success('采购商品已保存');
          this.editorVisible = false;
          this.load(false);
        })
        .finally(() => {
          this.saving = false;
        });
    },
    disable(row) {
      return this.$confirm('下架后店长不能新建该商品采购订单，历史订单不受影响。', '确认下架', { type: 'warning' })
        .then(() => yfthSupplyCatalogDisable({ id: row.id, reason: 'procurement_product_disabled' }))
        .then(() => {
          this.$message.success('已从采购商城下架');
          this.load(false);
        });
    },
    importRetailProducts() {
      return this.$confirm('导入当前商城在售的全部实体商品，已有采购价不会被覆盖。', '导入商城商品')
        .then(() => {
          this.importing = true;
          return yfthSupplyCatalogImportVisible({ product_ids: [] });
        })
        .then((res) => {
          const data = res.data || {};
          this.$message.success(`导入 ${data.imported_count || 0} 个，跳过 ${data.skipped_count || 0} 个`);
          this.load(true);
        })
        .finally(() => {
          this.importing = false;
        });
    },
    createNativeProduct() {
      this.$router.push({ name: 'product_productAdd' });
    },
  },
};
</script>

<style scoped>
.card-header { display: flex; justify-content: space-between; gap: 16px; align-items: center; }
.sub-title { margin-top: 6px; color: #909399; font-size: 13px; }
.toolbar { display: flex; gap: 10px; margin: 16px 0; }
.w240 { width: 240px; }
.w160 { width: 160px; }
.product-cell, .selected-product { display: flex; align-items: center; gap: 12px; }
.selected-product { min-height: 58px; }
.product-image { width: 54px; height: 54px; border-radius: 4px; background: #f5f7fa; flex: 0 0 auto; }
.product-name { font-weight: 600; color: #303133; }
.muted { color: #909399; font-size: 12px; margin-top: 4px; }
.sku-line { display: flex; justify-content: space-between; gap: 16px; line-height: 24px; }
.pager { display: flex; justify-content: flex-end; margin-top: 16px; }
.danger { color: #f56c6c; }
</style>
