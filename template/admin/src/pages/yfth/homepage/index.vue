<template>
  <div class="yfth-homepage-config">
    <el-alert title="此页面固定使用御方通和首页版式；只替换名称、图标、图片和真实 CRMEB 商品/分类/套餐绑定，不会修改原有商城装修、订单或支付流程。" type="info" :closable="false" />

    <el-card shadow="never" class="config-card">
      <div slot="header" class="card-header"><span>顶部与显示状态</span><el-switch v-model="config.enabled" :active-value="1" :inactive-value="0" active-text="启用定制首页" /></div>
      <el-form label-width="110px" size="small" class="header-form">
        <el-form-item label="首页标题"><el-input v-model="config.header.title" maxlength="32" show-word-limit /></el-form-item>
        <el-form-item label="搜索提示"><el-input v-model="config.header.search_placeholder" maxlength="40" show-word-limit /></el-form-item>
        <el-form-item label="商城商品图">
          <div class="featured-image-field">
            <div class="featured-image-preview">
              <img v-if="featuredImagePreview" :src="featuredImagePreview" alt="商城商品展示图" />
              <span v-else>暂无图片</span>
            </div>
            <div class="featured-image-actions">
              <el-input v-model="config.featured_product_image" placeholder="留空时使用 CRMEB 商品主图" />
              <div>
                <el-button type="primary" plain @click="openImagePicker">从图片库选择</el-button>
                <el-button v-if="config.featured_product_image" type="text" @click="config.featured_product_image = ''">恢复商品主图</el-button>
              </div>
              <div class="field-tip">只替换首页红框区域图片，不修改商品详情、SKU、库存、订单和支付。</div>
            </div>
          </div>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" class="config-card">
      <div slot="header" class="card-header"><span>快捷入口</span><el-button type="primary" size="small" @click="addQuick">新增入口</el-button></div>
      <el-table :data="config.quick_entries" border size="small">
        <el-table-column label="排序" width="78"><template slot-scope="{ row }"><el-input-number v-model="row.sort" :min="0" :controls="false" /></template></el-table-column>
        <el-table-column label="显示" width="72"><template slot-scope="{ row }"><el-switch v-model="row.visible" :active-value="1" :inactive-value="0" /></template></el-table-column>
        <el-table-column label="名称" min-width="130"><template slot-scope="{ row }"><el-input v-model="row.title" /></template></el-table-column>
        <el-table-column label="图标 URL" min-width="180"><template slot-scope="{ row }"><el-input v-model="row.icon_url" placeholder="可使用 OSS 或上传附件 URL" /></template></el-table-column>
        <el-table-column label="跳转" min-width="150"><template slot-scope="{ row }"><target-editor :row="row" :categories="options.categories" :products="options.products" :packages="options.packages" /></template></el-table-column>
        <el-table-column label="操作" width="70"><template slot-scope="{ $index }"><el-button type="text" @click="config.quick_entries.splice($index, 1)">删除</el-button></template></el-table-column>
      </el-table>
    </el-card>

    <el-card shadow="never" class="config-card">
      <div slot="header" class="card-header"><span>双列内容卡片</span><el-button type="primary" size="small" @click="addSection">新增卡片</el-button></div>
      <el-table :data="config.sections" border size="small">
        <el-table-column label="排序" width="78"><template slot-scope="{ row }"><el-input-number v-model="row.sort" :min="0" :controls="false" /></template></el-table-column>
        <el-table-column label="显示" width="72"><template slot-scope="{ row }"><el-switch v-model="row.visible" :active-value="1" :inactive-value="0" /></template></el-table-column>
        <el-table-column label="标题" min-width="130"><template slot-scope="{ row }"><el-input v-model="row.title" /></template></el-table-column>
        <el-table-column label="卡片图片 URL" min-width="190"><template slot-scope="{ row }"><el-input v-model="row.image_url" placeholder="留空时展示绑定商品图片" /></template></el-table-column>
        <el-table-column label="内容" min-width="190"><template slot-scope="{ row }"><content-editor :row="row" :products="options.products" :packages="options.packages" /></template></el-table-column>
        <el-table-column label="点击跳转" min-width="150"><template slot-scope="{ row }"><target-editor :row="row" :categories="options.categories" :products="options.products" :packages="options.packages" /></template></el-table-column>
        <el-table-column label="操作" width="70"><template slot-scope="{ $index }"><el-button type="text" @click="config.sections.splice($index, 1)">删除</el-button></template></el-table-column>
      </el-table>
    </el-card>

    <div class="action-bar"><el-button type="primary" :loading="saving" @click="save">保存首页内容配置</el-button><el-button @click="load">重置</el-button></div>

    <el-dialog :visible.sync="imagePickerVisible" width="950px" title="选择商城商品展示图" :close-on-click-modal="false">
      <upload-pictures v-if="imagePickerVisible" isChoice="单选" @getPic="selectFeaturedImage" />
    </el-dialog>
  </div>
</template>

<script>
import { yfthHomepageConfig, yfthHomepageSave } from '@/api/yfth';
import uploadPictures from '@/components/uploadPictures';

const TargetEditor = {
  props: ['row', 'categories', 'products', 'packages'],
  template: `
    <div class="compact-editor">
      <el-select v-model="row.target_type" size="small"><el-option label="商品分类" value="category" /><el-option label="具体商品" value="product" /><el-option label="套餐列表" value="package_list" /><el-option label="套餐详情" value="package_detail" /><el-option label="自定义页面路径" value="path" /></el-select>
      <el-select v-if="row.target_type === 'category'" v-model="row.category_id" filterable size="small" placeholder="选择分类"><el-option v-for="item in categories" :key="item.id" :label="item.cate_name + ' #' + item.id" :value="item.id" /></el-select>
      <el-select v-if="row.target_type === 'product'" v-model="row.product_ids" multiple filterable size="small" placeholder="选择商品"><el-option v-for="item in products" :key="item.id" :label="item.store_name + ' #' + item.id" :value="item.id" /></el-select>
      <el-select v-if="row.target_type === 'package_detail'" v-model="row.package_id" filterable size="small" placeholder="选择套餐"><el-option v-for="item in packages" :key="item.id" :label="(item.package_title || item.package_name) + ' #' + item.id" :value="item.id" /></el-select>
      <el-input v-if="row.target_type === 'path'" v-model="row.target_path" size="small" placeholder="/pages/..." />
    </div>`,
};

const ContentEditor = {
  props: ['row', 'products', 'packages'],
  template: `
    <div class="compact-editor">
      <el-select v-model="row.content_type" size="small"><el-option label="真实商品" value="product" /><el-option label="真实套餐" value="package" /></el-select>
      <el-select v-if="row.content_type === 'product'" v-model="row.product_ids" multiple filterable size="small" placeholder="留空按绑定分类动态展示"><el-option v-for="item in products" :key="item.id" :label="item.store_name + ' #' + item.id" :value="item.id" /></el-select>
      <el-select v-if="row.content_type === 'package'" v-model="row.package_id" filterable size="small" placeholder="留空展示已发布套餐"><el-option v-for="item in packages" :key="item.id" :label="(item.package_title || item.package_name) + ' #' + item.id" :value="item.id" /></el-select>
      <el-input-number v-model="row.display_limit" :min="1" :max="8" :controls="false" size="small" />
    </div>`,
};

export default {
  name: 'YfthHomepageConfig',
  components: { TargetEditor, ContentEditor, uploadPictures },
  data() {
    return {
      saving: false,
      imagePickerVisible: false,
      config: { enabled: 1, featured_product_image: '', header: {}, quick_entries: [], sections: [] },
      options: { categories: [], products: [], packages: [], featured_product: {} },
    };
  },
  computed: {
    featuredImagePreview() {
      return this.config.featured_product_image || (this.options.featured_product && this.options.featured_product.image) || '';
    },
  },
  mounted() { this.load(); },
  methods: {
    load() {
      yfthHomepageConfig().then((res) => {
        this.config = res.data.config;
        this.options = res.data.options;
      });
    },
    addQuick() {
      this.config.quick_entries.push({ title: '新入口', icon_url: '', target_type: 'category', target_path: '', category_id: 0, product_ids: [], package_id: 0, visible: 1, sort: this.config.quick_entries.length + 1 });
    },
    addSection() {
      this.config.sections.push({ title: '新内容区', image_url: '', content_type: 'product', target_type: 'category', target_path: '', category_id: 0, product_ids: [], package_id: 0, display_limit: 6, visible: 1, sort: this.config.sections.length + 1 });
    },
    openImagePicker() {
      this.imagePickerVisible = true;
    },
    selectFeaturedImage(image) {
      this.config.featured_product_image = image && (image.att_dir || image.satt_dir) ? (image.att_dir || image.satt_dir) : '';
      this.imagePickerVisible = false;
    },
    save() {
      this.saving = true;
      yfthHomepageSave(this.config).then(() => this.$message.success('首页内容配置已保存')).finally(() => { this.saving = false; });
    },
  },
};
</script>

<style scoped>
.config-card { margin-top: 16px; }
.card-header { display: flex; align-items: center; justify-content: space-between; }
.header-form { max-width: 620px; }
.featured-image-field { display: flex; gap: 16px; align-items: flex-start; }
.featured-image-preview { width: 112px; height: 112px; flex: 0 0 112px; border: 1px solid #e4e7ed; border-radius: 6px; background: #f7f8fa; display: flex; align-items: center; justify-content: center; color: #909399; overflow: hidden; }
.featured-image-preview img { width: 100%; height: 100%; object-fit: cover; }
.featured-image-actions { flex: 1; min-width: 0; }
.featured-image-actions > div { margin-top: 8px; }
.field-tip { color: #909399; line-height: 1.5; }
.action-bar { padding: 20px 0 36px; }
.compact-editor > * { width: 100%; margin-bottom: 7px; }
</style>
