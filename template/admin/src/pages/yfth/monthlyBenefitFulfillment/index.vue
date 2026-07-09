<template>
  <div class="yfth-monthly-benefit">
    <el-card shadow="never">
      <div slot="header" class="header">
        <span>月度权益领取 / 配送履约</span>
        <el-button size="small" type="primary" @click="load">刷新</el-button>
      </div>
      <el-alert
        title="本页只处理 YFTH 产品类月度权益履约，不创建 CRMEB 订单，不修改商品/SKU库存，不接入支付、分账或产品额度抵扣。"
        type="warning"
        :closable="false"
        show-icon
      />
      <el-form :inline="true" :model="where" class="filters">
        <el-form-item label="履约单号"><el-input v-model="where.fulfillment_no" clearable /></el-form-item>
        <el-form-item label="状态">
          <el-select v-model="where.status" clearable>
            <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="方式">
          <el-select v-model="where.fulfillment_method" clearable>
            <el-option label="快递配送" value="express_delivery" />
            <el-option label="到店自提" value="self_pickup" />
          </el-select>
        </el-form-item>
        <el-form-item label="用户ID"><el-input v-model="where.uid" clearable /></el-form-item>
        <el-form-item label="门店ID"><el-input v-model="where.store_id" clearable /></el-form-item>
        <el-button type="primary" @click="load">查询</el-button>
      </el-form>
      <el-table :data="list" v-loading="loading" border>
        <el-table-column prop="fulfillment_no" label="履约单号" min-width="190" />
        <el-table-column prop="uid" label="用户" width="90" />
        <el-table-column prop="benefit_name" label="权益" min-width="180" />
        <el-table-column prop="month_no" label="月份" width="80" />
        <el-table-column prop="fulfillment_method" label="方式" width="120">
          <template slot-scope="{ row }">{{ methodText(row.fulfillment_method) }}</template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="120">
          <template slot-scope="{ row }"><el-tag>{{ statusText(row.status) }}</el-tag></template>
        </el-table-column>
        <el-table-column prop="claim_time" label="领取时间" width="170">
          <template slot-scope="{ row }">{{ timeText(row.claim_time) }}</template>
        </el-table-column>
        <el-table-column label="操作" min-width="300" fixed="right">
          <template slot-scope="{ row }">
            <el-button size="mini" @click="openDetail(row)">详情</el-button>
            <el-button v-if="row.status === 'pending_confirm'" size="mini" type="success" @click="doAction(row, 'confirm')">确认</el-button>
            <el-button v-if="row.status === 'pending_confirm' || row.status === 'confirmed'" size="mini" type="danger" @click="doAction(row, 'reject')">驳回</el-button>
            <el-button v-if="row.status === 'confirmed'" size="mini" @click="doAction(row, 'prepare')">备货</el-button>
            <el-button v-if="canShip(row)" size="mini" @click="openShip(row)">发货</el-button>
            <el-button v-if="canComplete(row)" size="mini" type="primary" @click="doAction(row, 'complete')">完成</el-button>
            <el-button v-if="canCancel(row)" size="mini" @click="doAction(row, 'cancel')">取消</el-button>
            <el-button v-if="canException(row)" size="mini" @click="doAction(row, 'exception')">异常</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        :current-page.sync="where.page"
        :page-size.sync="where.limit"
        :total="count"
        layout="total, prev, pager, next"
        @current-change="load"
      />
    </el-card>

    <el-dialog :visible.sync="detailVisible" title="履约详情" width="760px">
      <div v-if="detail.id">
        <el-row :gutter="12" class="detail-grid">
          <el-col :span="12"><div class="detail-cell"><b>履约单号</b><span>{{ detail.fulfillment_no }}</span></div></el-col>
          <el-col :span="12"><div class="detail-cell"><b>状态</b><span>{{ statusText(detail.status) }}</span></div></el-col>
          <el-col :span="12"><div class="detail-cell"><b>权益</b><span>{{ detail.benefit_name }}</span></div></el-col>
          <el-col :span="12"><div class="detail-cell"><b>方式</b><span>{{ methodText(detail.fulfillment_method) }}</span></div></el-col>
          <el-col :span="12"><div class="detail-cell"><b>收件人</b><span>{{ detail.recipient_name_masked }} {{ detail.recipient_phone_masked }}</span></div></el-col>
          <el-col :span="12"><div class="detail-cell"><b>自提门店</b><span>{{ detail.pickup_store_id || '-' }}</span></div></el-col>
          <el-col :span="12"><div class="detail-cell"><b>物流</b><span>{{ detail.delivery_company }} {{ detail.delivery_no_masked }}</span></div></el-col>
          <el-col :span="12"><div class="detail-cell"><b>原因</b><span>{{ detail.reason }}</span></div></el-col>
        </el-row>
        <el-timeline class="timeline">
          <el-timeline-item v-for="item in events" :key="item.create_time + item.event_type" :timestamp="timeText(item.create_time)">
            {{ item.event_type }}：{{ item.from_status || '-' }} -> {{ item.to_status || '-' }} {{ item.reason }}
          </el-timeline-item>
        </el-timeline>
      </div>
    </el-dialog>

    <el-dialog :visible.sync="shipVisible" title="填写物流" width="460px">
      <el-form :model="shipForm" label-width="90px">
        <el-form-item label="物流公司"><el-input v-model="shipForm.delivery_company" /></el-form-item>
        <el-form-item label="物流单号"><el-input v-model="shipForm.delivery_no" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="shipForm.reason" /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="shipVisible = false">取消</el-button>
        <el-button type="primary" @click="submitShip">确认发货</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthMonthlyBenefitFulfillmentCancel,
  yfthMonthlyBenefitFulfillmentComplete,
  yfthMonthlyBenefitFulfillmentConfirm,
  yfthMonthlyBenefitFulfillmentDetail,
  yfthMonthlyBenefitFulfillmentException,
  yfthMonthlyBenefitFulfillmentList,
  yfthMonthlyBenefitFulfillmentPrepare,
  yfthMonthlyBenefitFulfillmentReject,
  yfthMonthlyBenefitFulfillmentShip,
} from '@/api/yfth';

export default {
  data() {
    return {
      loading: false,
      list: [],
      count: 0,
      where: { page: 1, limit: 15, fulfillment_no: '', status: '', fulfillment_method: '', uid: '', store_id: '' },
      statusOptions: [
        { label: '待确认', value: 'pending_confirm' },
        { label: '已确认', value: 'confirmed' },
        { label: '备货中', value: 'preparing' },
        { label: '已发货', value: 'shipped' },
        { label: '已完成', value: 'completed' },
        { label: '已取消', value: 'cancelled' },
        { label: '已驳回', value: 'rejected' },
        { label: '异常', value: 'exception' },
      ],
      detailVisible: false,
      detail: {},
      events: [],
      shipVisible: false,
      shipRow: {},
      shipForm: { delivery_company: '', delivery_no: '', reason: '' },
    };
  },
  mounted() {
    this.load();
  },
  methods: {
    load() {
      this.loading = true;
      yfthMonthlyBenefitFulfillmentList(this.where)
        .then((res) => {
          this.list = (res.data && res.data.list) || [];
          this.count = (res.data && res.data.count) || 0;
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openDetail(row) {
      yfthMonthlyBenefitFulfillmentDetail(row.id).then((res) => {
        this.detail = (res.data && res.data.fulfillment) || {};
        this.events = (res.data && res.data.events) || [];
        this.detailVisible = true;
      });
    },
    doAction(row, action) {
      const apis = {
        confirm: yfthMonthlyBenefitFulfillmentConfirm,
        reject: yfthMonthlyBenefitFulfillmentReject,
        prepare: yfthMonthlyBenefitFulfillmentPrepare,
        complete: yfthMonthlyBenefitFulfillmentComplete,
        exception: yfthMonthlyBenefitFulfillmentException,
        cancel: yfthMonthlyBenefitFulfillmentCancel,
      };
      this.$confirm('确认执行该操作？', '提示').then(() => {
        apis[action](row.id, { reason: action, client_operation_key: this.operationKey(action, row.id) }).then(() => {
          this.$message.success('操作成功');
          this.load();
        });
      });
    },
    openShip(row) {
      this.shipRow = row;
      this.shipForm = { delivery_company: '', delivery_no: '', reason: 'ship' };
      this.shipVisible = true;
    },
    submitShip() {
      yfthMonthlyBenefitFulfillmentShip(this.shipRow.id, Object.assign({}, this.shipForm, {
        client_operation_key: this.operationKey('ship', this.shipRow.id),
      })).then(() => {
        this.$message.success('发货成功');
        this.shipVisible = false;
        this.load();
      });
    },
    canShip(row) {
      return row.fulfillment_method === 'express_delivery' && ['confirmed', 'preparing'].indexOf(row.status) !== -1;
    },
    canComplete(row) {
      return ['confirmed', 'preparing', 'shipped', 'picked_up'].indexOf(row.status) !== -1;
    },
    canCancel(row) {
      return ['pending_confirm', 'confirmed', 'preparing', 'exception'].indexOf(row.status) !== -1;
    },
    canException(row) {
      return ['pending_confirm', 'confirmed', 'preparing', 'shipped'].indexOf(row.status) !== -1;
    },
    operationKey(action, id) {
      return 'monthly_benefit_' + action + '_' + id + '_' + Date.now();
    },
    statusText(status) {
      const map = {
        pending_confirm: '待确认',
        confirmed: '已确认',
        preparing: '备货中',
        shipped: '已发货',
        picked_up: '已自提',
        completed: '已完成',
        cancelled: '已取消',
        rejected: '已驳回',
        exception: '异常',
      };
      return map[status] || status;
    },
    methodText(method) {
      return method === 'self_pickup' ? '到店自提' : '快递配送';
    },
    timeText(value) {
      if (!value) return '-';
      return new Date(value * 1000).toLocaleString();
    },
  },
};
</script>

<style scoped>
.filters { margin: 16px 0; }
.header { display: flex; justify-content: space-between; align-items: center; }
.timeline { margin-top: 20px; }
.detail-grid { border: 1px solid #ebeef5; border-bottom: 0; border-right: 0; }
.detail-cell { min-height: 42px; display: flex; border-right: 1px solid #ebeef5; border-bottom: 1px solid #ebeef5; }
.detail-cell b { flex: 0 0 88px; padding: 12px; background: #f5f7fa; color: #606266; }
.detail-cell span { flex: 1; padding: 12px; color: #303133; word-break: break-all; }
</style>
