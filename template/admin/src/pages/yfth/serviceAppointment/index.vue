<template>
  <div class="yfth-service-appointment">
    <el-card shadow="never" class="ivu-mt" :body-style="{ padding: '16px' }">
      <el-tabs v-model="activeTab" @tab-click="handleTabChange">
        <el-tab-pane label="服务项目" name="project">
          <div class="toolbar">
            <el-input v-model="filters.project.service_code" clearable placeholder="项目编码" class="w160" />
            <el-select v-model="filters.project.status" clearable placeholder="状态" class="w140">
              <el-option label="启用" value="active" />
              <el-option label="停用" value="disabled" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="search('project')">查询</el-button>
            <el-button type="success" icon="el-icon-plus" @click="openProject()">新增</el-button>
          </div>
          <el-table v-loading="loading.project" :data="lists.project" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="service_code" label="编码" min-width="150" />
            <el-table-column prop="service_name" label="名称" min-width="180" />
            <el-table-column prop="service_type" label="类型" width="150" />
            <el-table-column prop="suggested_duration_minutes" label="时长" width="100" />
            <el-table-column prop="required_benefit_type" label="权益" width="110" />
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column label="操作" width="170" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" @click="openProject(scope.row)">编辑</el-button>
                <el-button type="text" icon="el-icon-close" @click="disableProject(scope.row)">停用</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="门店授权" name="storeService">
          <div class="toolbar">
            <el-input v-model="filters.storeService.store_id" clearable placeholder="门店ID" class="w120" />
            <el-input
              v-model="filters.storeService.service_project_id"
              clearable
              placeholder="项目ID"
              class="w120"
            />
            <el-select v-model="filters.storeService.status" clearable placeholder="状态" class="w140">
              <el-option label="启用" value="active" />
              <el-option label="停用" value="disabled" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="search('storeService')">查询</el-button>
            <el-button type="success" icon="el-icon-plus" @click="openStoreService()">授权</el-button>
          </div>
          <el-table v-loading="loading.storeService" :data="lists.storeService" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_id" label="门店" width="90" />
            <el-table-column prop="service_project_id" label="项目" width="100" />
            <el-table-column prop="service_name" label="项目名称" min-width="160" />
            <el-table-column prop="service_alias" label="门店别名" min-width="160" />
            <el-table-column prop="duration_minutes" label="时长" width="100" />
            <el-table-column prop="default_capacity" label="容量" width="100" />
            <el-table-column prop="appointment_enabled" label="可预约" width="90" />
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column label="操作" width="170" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" @click="openStoreService(scope.row)">编辑</el-button>
                <el-button type="text" icon="el-icon-close" @click="disableStoreService(scope.row)">停用</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="排班规则" name="schedule">
          <div class="toolbar">
            <el-input
              v-model="filters.schedule.store_service_id"
              clearable
              placeholder="门店服务ID"
              class="w160"
            />
            <el-input v-model="filters.schedule.store_id" clearable placeholder="门店ID" class="w120" />
            <el-select v-model="filters.schedule.weekday" clearable placeholder="星期" class="w140">
              <el-option v-for="item in weekdayOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="search('schedule')">查询</el-button>
            <el-button type="success" icon="el-icon-plus" @click="openSchedule()">新增</el-button>
          </div>
          <el-table v-loading="loading.schedule" :data="lists.schedule" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_service_id" label="门店服务" width="120" />
            <el-table-column prop="weekday" label="星期" width="90" />
            <el-table-column prop="start_time_text" label="开始" width="90" />
            <el-table-column prop="end_time_text" label="结束" width="90" />
            <el-table-column prop="slot_interval_minutes" label="间隔" width="90" />
            <el-table-column prop="slot_capacity" label="容量" width="90" />
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column label="操作" width="170" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" @click="openSchedule(scope.row)">编辑</el-button>
                <el-button type="text" icon="el-icon-close" @click="disableSchedule(scope.row)">停用</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="特殊日期" name="specialDay">
          <div class="toolbar">
            <el-input
              v-model="filters.specialDay.store_service_id"
              clearable
              placeholder="门店服务ID"
              class="w160"
            />
            <el-date-picker
              v-model="filters.specialDay.service_date"
              value-format="yyyy-MM-dd"
              placeholder="日期"
              class="w160"
            />
            <el-select v-model="filters.specialDay.date_type" clearable placeholder="类型" class="w170">
              <el-option label="关闭" value="closed" />
              <el-option label="加开" value="extra" />
              <el-option label="容量覆盖" value="capacity_override" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="search('specialDay')">查询</el-button>
            <el-button type="success" icon="el-icon-plus" @click="openSpecialDay()">新增</el-button>
          </div>
          <el-table v-loading="loading.specialDay" :data="lists.specialDay" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_service_id" label="门店服务" width="120" />
            <el-table-column prop="service_date_text" label="日期" width="120" />
            <el-table-column prop="date_type" label="类型" width="150" />
            <el-table-column prop="start_time_text" label="开始" width="90" />
            <el-table-column prop="end_time_text" label="结束" width="90" />
            <el-table-column prop="slot_capacity" label="容量" width="90" />
            <el-table-column prop="reason" label="原因" min-width="160" />
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column label="操作" width="170" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" @click="openSpecialDay(scope.row)">编辑</el-button>
                <el-button type="text" icon="el-icon-close" @click="disableSpecialDay(scope.row)">停用</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="预约管理" name="appointment">
          <div class="toolbar">
            <el-input v-model="filters.appointment.store_id" clearable placeholder="门店ID" class="w120" />
            <el-input v-model="filters.appointment.uid" clearable placeholder="用户ID" class="w120" />
            <el-input
              v-model="filters.appointment.service_project_id"
              clearable
              placeholder="项目ID"
              class="w120"
            />
            <el-date-picker
              v-model="filters.appointment.service_date"
              value-format="yyyy-MM-dd"
              placeholder="日期"
              class="w160"
            />
            <el-select v-model="filters.appointment.status" clearable placeholder="状态" class="w170">
              <el-option label="待确认" value="pending_confirm" />
              <el-option label="已确认" value="confirmed" />
              <el-option label="已拒绝" value="rejected" />
              <el-option label="已取消" value="cancelled" />
              <el-option label="已完成" value="completed" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="search('appointment')">查询</el-button>
          </div>
          <el-table v-loading="loading.appointment" :data="lists.appointment" border>
            <el-table-column prop="appointment_no" label="预约号" min-width="170" />
            <el-table-column prop="uid" label="用户" width="90" />
            <el-table-column prop="store_id" label="门店" width="90" />
            <el-table-column prop="service_project_id" label="项目" width="100" />
            <el-table-column prop="date_text" label="日期" width="120" />
            <el-table-column label="时段" width="120">
              <template slot-scope="scope">{{ scope.row.start_time_text }}-{{ scope.row.end_time_text }}</template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="130" />
            <el-table-column prop="confirm_mode" label="确认方式" width="110" />
            <el-table-column prop="writeoff_method" label="核销方式" width="120" />
            <el-table-column prop="writeoff_at" label="核销时间" width="130">
              <template slot-scope="scope">{{ scope.row.writeoff_at || '-' }}</template>
            </el-table-column>
            <el-table-column label="操作" width="330" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-view" @click="openAppointment(scope.row)">详情</el-button>
                <el-button
                  v-if="scope.row.status === 'pending_confirm'"
                  type="text"
                  icon="el-icon-check"
                  @click="operateAppointment(scope.row, 'confirm')"
                  >确认</el-button
                >
                <el-button
                  v-if="scope.row.status === 'pending_confirm'"
                  type="text"
                  icon="el-icon-close"
                  @click="operateAppointment(scope.row, 'reject')"
                  >拒绝</el-button
                >
                <el-button
                  v-if="['pending_confirm', 'confirmed'].includes(scope.row.status)"
                  type="text"
                  icon="el-icon-remove-outline"
                  @click="operateAppointment(scope.row, 'cancel')"
                  >取消</el-button
                >
                <el-button
                  v-if="scope.row.status === 'confirmed'"
                  type="text"
                  icon="el-icon-finished"
                  @click="exceptionWriteoff(scope.row)"
                  >例外核销</el-button
                >
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="核销记录" name="writeoff">
          <div class="toolbar">
            <el-input v-model="filters.writeoff.store_id" clearable placeholder="门店ID" class="w120" />
            <el-input v-model="filters.writeoff.appointment_id" clearable placeholder="预约ID" class="w160" />
            <el-input v-model="filters.writeoff.uid" clearable placeholder="用户ID" class="w120" />
            <el-select v-model="filters.writeoff.writeoff_method" clearable placeholder="方式" class="w170">
              <el-option label="动态二维码" value="qr_code" />
              <el-option label="数字码" value="digital_code" />
              <el-option label="总部例外" value="headquarter_exception" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="search('writeoff')">查询</el-button>
          </div>
          <el-table v-loading="loading.writeoff" :data="lists.writeoff" border>
            <el-table-column prop="writeoff_no" label="核销号" min-width="170" />
            <el-table-column prop="appointment_id" label="预约" width="110" />
            <el-table-column prop="uid" label="用户" width="90" />
            <el-table-column prop="store_id" label="门店" width="90" />
            <el-table-column prop="writeoff_method" label="方式" width="150" />
            <el-table-column prop="operator_role_code" label="角色" width="150" />
            <el-table-column prop="writeoff_time" label="时间" width="130" />
            <el-table-column prop="status" label="状态" width="110" />
            <el-table-column label="操作" width="100" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-view" @click="openWriteoff(scope.row)">详情</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="时段预览" name="preview">
          <div class="toolbar">
            <el-input v-model="previewFilters.store_service_id" clearable placeholder="门店服务ID" class="w160" />
            <el-date-picker
              v-model="previewFilters.start_date"
              value-format="yyyy-MM-dd"
              placeholder="开始日期"
              class="w160"
            />
            <el-date-picker
              v-model="previewFilters.end_date"
              value-format="yyyy-MM-dd"
              placeholder="结束日期"
              class="w160"
            />
            <el-button type="primary" icon="el-icon-search" :loading="previewLoading" @click="loadPreview"
              >预览</el-button
            >
          </div>
          <el-table :data="previewRows" border>
            <el-table-column prop="date" label="日期" width="120" />
            <el-table-column prop="status" label="状态" width="100" />
            <el-table-column prop="reason" label="原因" min-width="160" />
            <el-table-column prop="total_capacity" label="容量" width="100" />
            <el-table-column label="时段" min-width="420">
              <template slot-scope="scope">
                <el-tag v-for="slot in scope.row.slots" :key="slot.slot_key" size="mini" class="slot-tag">
                  {{ slot.start_time }}-{{ slot.end_time }} / {{ slot.remaining_capacity }}
                </el-tag>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>

      <el-pagination
        v-if="activeTab !== 'preview'"
        class="pager"
        :current-page="pages[activeTab]"
        :page-size="limits[activeTab]"
        :total="totals[activeTab]"
        layout="total, prev, pager, next"
        @current-change="handleCurrentChange"
      />
    </el-card>

    <el-dialog :visible.sync="dialogs.project" title="服务项目" width="720px">
      <el-form label-width="150px">
        <el-form-item label="编码"><el-input v-model="forms.project.service_code" /></el-form-item>
        <el-form-item label="名称"><el-input v-model="forms.project.service_name" /></el-form-item>
        <el-form-item label="类型"><el-input v-model="forms.project.service_type" /></el-form-item>
        <el-form-item label="建议时长">
          <el-input-number v-model="forms.project.suggested_duration_minutes" :min="5" :max="480" />
        </el-form-item>
        <el-form-item label="允许权益"
          ><el-switch v-model="forms.project.allow_benefit" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="权益类型"><el-input v-model="forms.project.required_benefit_type" /></el-form-item>
        <el-form-item label="权益模板ID"
          ><el-input v-model="forms.project.required_benefit_template_ids"
        /></el-form-item>
        <el-form-item label="付费扩展"
          ><el-switch v-model="forms.project.allow_paid" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="状态">
          <el-select v-model="forms.project.status">
            <el-option label="启用" value="active" />
            <el-option label="停用" value="disabled" />
          </el-select>
        </el-form-item>
        <el-form-item label="排序"><el-input-number v-model="forms.project.sort" /></el-form-item>
        <el-form-item label="说明"
          ><el-input v-model="forms.project.service_desc" type="textarea" :rows="3"
        /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.project = false">取消</el-button>
        <el-button type="primary" @click="saveProject">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.storeService" title="门店服务授权" width="720px">
      <el-form label-width="170px">
        <el-form-item label="门店ID"><el-input-number v-model="forms.storeService.store_id" :min="0" /></el-form-item>
        <el-form-item label="项目ID"
          ><el-input-number v-model="forms.storeService.service_project_id" :min="0"
        /></el-form-item>
        <el-form-item label="门店别名"><el-input v-model="forms.storeService.service_alias" /></el-form-item>
        <el-form-item label="服务时长"
          ><el-input-number v-model="forms.storeService.duration_minutes" :min="5" :max="480"
        /></el-form-item>
        <el-form-item label="需要确认"
          ><el-switch v-model="forms.storeService.requires_confirmation" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="启用预约"
          ><el-switch v-model="forms.storeService.appointment_enabled" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="最短提前分钟"
          ><el-input-number v-model="forms.storeService.advance_min_minutes" :min="0"
        /></el-form-item>
        <el-form-item label="最长提前天数"
          ><el-input-number v-model="forms.storeService.advance_max_days" :min="1"
        /></el-form-item>
        <el-form-item label="取消截止分钟"
          ><el-input-number v-model="forms.storeService.cancel_deadline_minutes" :min="0"
        /></el-form-item>
        <el-form-item label="默认容量"
          ><el-input-number v-model="forms.storeService.default_capacity" :min="1"
        /></el-form-item>
        <el-form-item label="时区"><el-input v-model="forms.storeService.timezone" /></el-form-item>
        <el-form-item label="状态">
          <el-select v-model="forms.storeService.status">
            <el-option label="启用" value="active" />
            <el-option label="停用" value="disabled" />
          </el-select>
        </el-form-item>
        <el-form-item label="说明"
          ><el-input v-model="forms.storeService.service_description" type="textarea" :rows="3"
        /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.storeService = false">取消</el-button>
        <el-button type="primary" @click="saveStoreService">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.schedule" title="排班规则" width="640px">
      <el-form label-width="150px">
        <el-form-item label="门店服务ID"
          ><el-input-number v-model="forms.schedule.store_service_id" :min="0"
        /></el-form-item>
        <el-form-item label="星期">
          <el-select v-model="forms.schedule.weekday">
            <el-option v-for="item in weekdayOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="开始分钟"
          ><el-input-number v-model="forms.schedule.start_minute" :min="0" :max="1439"
        /></el-form-item>
        <el-form-item label="结束分钟"
          ><el-input-number v-model="forms.schedule.end_minute" :min="1" :max="1440"
        /></el-form-item>
        <el-form-item label="间隔分钟"
          ><el-input-number v-model="forms.schedule.slot_interval_minutes" :min="0"
        /></el-form-item>
        <el-form-item label="容量"
          ><el-input-number v-model="forms.schedule.slot_capacity" :min="1"
        /></el-form-item>
        <el-form-item label="状态">
          <el-select v-model="forms.schedule.status">
            <el-option label="启用" value="active" />
            <el-option label="停用" value="disabled" />
          </el-select>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.schedule = false">取消</el-button>
        <el-button type="primary" @click="saveSchedule">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.specialDay" title="特殊日期" width="640px">
      <el-form label-width="150px">
        <el-form-item label="门店服务ID"
          ><el-input-number v-model="forms.specialDay.store_service_id" :min="0"
        /></el-form-item>
        <el-form-item label="日期"
          ><el-date-picker v-model="forms.specialDay.service_date" value-format="yyyy-MM-dd"
        /></el-form-item>
        <el-form-item label="类型">
          <el-select v-model="forms.specialDay.date_type">
            <el-option label="关闭" value="closed" />
            <el-option label="加开" value="extra" />
            <el-option label="容量覆盖" value="capacity_override" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="forms.specialDay.date_type !== 'closed'" label="开始分钟">
          <el-input-number v-model="forms.specialDay.start_minute" :min="0" :max="1439" />
        </el-form-item>
        <el-form-item v-if="forms.specialDay.date_type !== 'closed'" label="结束分钟">
          <el-input-number v-model="forms.specialDay.end_minute" :min="1" :max="1440" />
        </el-form-item>
        <el-form-item v-if="forms.specialDay.date_type !== 'closed'" label="容量">
          <el-input-number v-model="forms.specialDay.slot_capacity" :min="1" />
        </el-form-item>
        <el-form-item label="原因"><el-input v-model="forms.specialDay.reason" /></el-form-item>
        <el-form-item label="状态">
          <el-select v-model="forms.specialDay.status">
            <el-option label="启用" value="active" />
            <el-option label="停用" value="disabled" />
          </el-select>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.specialDay = false">取消</el-button>
        <el-button type="primary" @click="saveSpecialDay">保存</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.appointment" title="预约详情" width="760px">
      <el-form v-if="appointmentDetail.id" label-width="130px" class="detail-form">
        <el-row>
          <el-col :span="12"><el-form-item label="预约号">{{ appointmentDetail.appointment_no }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="状态">{{ appointmentDetail.status }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="用户">{{ appointmentDetail.uid }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="门店">{{ appointmentDetail.store_id }}</el-form-item></el-col>
          <el-col :span="12"
            ><el-form-item label="项目">{{ appointmentDetail.service_project_id }}</el-form-item></el-col
          >
          <el-col :span="12"><el-form-item label="权益">{{ appointmentDetail.benefit_item_id }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="日期">{{ appointmentDetail.date_text }}</el-form-item></el-col>
          <el-col :span="12"
            ><el-form-item label="时段"
              >{{ appointmentDetail.start_time_text }}-{{ appointmentDetail.end_time_text }}</el-form-item
            ></el-col
          >
          <el-col :span="12"><el-form-item label="确认方式">{{ appointmentDetail.confirm_mode }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="改期次数">{{ appointmentDetail.reschedule_count }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="核销方式">{{ appointmentDetail.writeoff_method }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="核销时间">{{ appointmentDetail.writeoff_at }}</el-form-item></el-col>
          <el-col :span="24"><el-form-item label="备注">{{ appointmentDetail.user_note }}</el-form-item></el-col>
          <el-col :span="24"><el-form-item label="取消原因">{{ appointmentDetail.cancel_reason }}</el-form-item></el-col>
          <el-col :span="24"><el-form-item label="拒绝原因">{{ appointmentDetail.reject_reason }}</el-form-item></el-col>
          <el-col :span="24"
            ><el-form-item label="核销结果">{{ formatWriteoffResult(appointmentDetail.writeoff_result) }}</el-form-item></el-col
          >
        </el-row>
      </el-form>
      <el-table :data="appointmentDetail.events || []" border class="event-table">
        <el-table-column prop="event_type" label="事件" width="130" />
        <el-table-column prop="from_status" label="原状态" width="130" />
        <el-table-column prop="to_status" label="新状态" width="130" />
        <el-table-column prop="operator_type" label="操作人" width="110" />
        <el-table-column prop="reason" label="原因" min-width="180" />
      </el-table>
      <span slot="footer">
        <el-button @click="dialogs.appointment = false">关闭</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.writeoff" title="核销详情" width="720px">
      <el-form v-if="writeoffDetail.id" label-width="150px" class="detail-form">
        <el-row>
          <el-col :span="12"><el-form-item label="核销号">{{ writeoffDetail.writeoff_no }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="状态">{{ writeoffDetail.status }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="预约">{{ writeoffDetail.appointment_id }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="方式">{{ writeoffDetail.writeoff_method }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="操作人">{{ writeoffDetail.operator_id }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="角色">{{ writeoffDetail.operator_role_code }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="门店">{{ writeoffDetail.store_id }}</el-form-item></el-col>
          <el-col :span="12"><el-form-item label="时间">{{ writeoffDetail.writeoff_time }}</el-form-item></el-col>
        </el-row>
      </el-form>
      <pre class="json-preview">{{ writeoffDetail.snapshot }}</pre>
      <span slot="footer">
        <el-button @click="dialogs.writeoff = false">关闭</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthServiceProjectDisable,
  yfthServiceProjectList,
  yfthServiceProjectSave,
  yfthServiceAppointmentCancel,
  yfthServiceAppointmentConfirm,
  yfthServiceAppointmentDetail,
  yfthServiceAppointmentExceptionWriteoff,
  yfthServiceAppointmentList,
  yfthServiceAppointmentReject,
  yfthServiceScheduleRuleDisable,
  yfthServiceScheduleRuleList,
  yfthServiceScheduleRuleSave,
  yfthServiceSlotPreview,
  yfthServiceSpecialDayDisable,
  yfthServiceSpecialDayList,
  yfthServiceSpecialDaySave,
  yfthServiceWriteoffDetail,
  yfthServiceWriteoffList,
  yfthStoreServiceDisable,
  yfthStoreServiceList,
  yfthStoreServiceSave,
} from '@/api/yfth';

const emptyFilters = () => ({
  project: { service_code: '', service_type: '', status: '' },
  storeService: { store_id: '', service_project_id: '', status: '' },
  schedule: { store_id: '', service_project_id: '', store_service_id: '', weekday: '', status: '' },
  specialDay: {
    store_id: '',
    service_project_id: '',
    store_service_id: '',
    service_date: '',
    date_type: '',
    status: '',
  },
  appointment: { store_id: '', service_project_id: '', uid: '', service_date: '', status: '' },
  writeoff: { store_id: '', appointment_id: '', uid: '', status: '', writeoff_method: '' },
});

export default {
  name: 'YfthServiceAppointment',
  data() {
    return {
      activeTab: 'project',
      filters: emptyFilters(),
      lists: {
        project: [],
        storeService: [],
        schedule: [],
        specialDay: [],
        appointment: [],
        writeoff: [],
      },
      loading: {
        project: false,
        storeService: false,
        schedule: false,
        specialDay: false,
        appointment: false,
        writeoff: false,
      },
      pages: {
        project: 1,
        storeService: 1,
        schedule: 1,
        specialDay: 1,
        appointment: 1,
        writeoff: 1,
      },
      limits: {
        project: 15,
        storeService: 15,
        schedule: 15,
        specialDay: 15,
        appointment: 15,
        writeoff: 15,
      },
      totals: {
        project: 0,
        storeService: 0,
        schedule: 0,
        specialDay: 0,
        appointment: 0,
        writeoff: 0,
      },
      dialogs: {
        project: false,
        storeService: false,
        schedule: false,
        specialDay: false,
        appointment: false,
        writeoff: false,
      },
      forms: {
        project: {},
        storeService: {},
        schedule: {},
        specialDay: {},
      },
      previewFilters: {
        store_service_id: '',
        start_date: '',
        end_date: '',
      },
      previewLoading: false,
      previewRows: [],
      appointmentDetail: {},
      writeoffDetail: {},
      weekdayOptions: [
        { label: '周一', value: 1 },
        { label: '周二', value: 2 },
        { label: '周三', value: 3 },
        { label: '周四', value: 4 },
        { label: '周五', value: 5 },
        { label: '周六', value: 6 },
        { label: '周日', value: 7 },
      ],
    };
  },
  mounted() {
    this.fetchList('project');
  },
  methods: {
    apiMap() {
      return {
        project: yfthServiceProjectList,
        storeService: yfthStoreServiceList,
        schedule: yfthServiceScheduleRuleList,
        specialDay: yfthServiceSpecialDayList,
        appointment: yfthServiceAppointmentList,
        writeoff: yfthServiceWriteoffList,
      };
    },
    fetchList(tab) {
      if (tab === 'preview') {
        return;
      }
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
    handleCurrentChange(page) {
      this.pages[this.activeTab] = page;
      this.fetchList(this.activeTab);
    },
    openProject(row) {
      this.forms.project = Object.assign(
        {
          id: 0,
          service_code: '',
          service_name: '',
          service_type: 'health_service',
          service_desc: '',
          suggested_duration_minutes: 30,
          allow_benefit: 1,
          required_benefit_type: 'service',
          required_benefit_template_ids: '',
          allow_paid: 0,
          status: 'active',
          sort: 0,
        },
        row || {},
      );
      this.dialogs.project = true;
    },
    saveProject() {
      yfthServiceProjectSave(this.forms.project).then(() => {
        this.$message.success('已保存');
        this.dialogs.project = false;
        this.fetchList('project');
      });
    },
    disableProject(row) {
      this.$confirm('确认停用该服务项目？', '确认操作').then(() => {
        yfthServiceProjectDisable({ id: row.id, reason: 'admin_disabled' }).then(() => {
          this.$message.success('已停用');
          this.fetchList('project');
        });
      });
    },
    openStoreService(row) {
      this.forms.storeService = Object.assign(
        {
          id: 0,
          store_id: 0,
          service_project_id: 0,
          service_alias: '',
          service_description: '',
          duration_minutes: 30,
          requires_confirmation: 0,
          appointment_enabled: 1,
          advance_min_minutes: 120,
          advance_max_days: 30,
          cancel_deadline_minutes: 1440,
          default_capacity: 1,
          timezone: 'Asia/Shanghai',
          status: 'active',
        },
        row || {},
      );
      this.dialogs.storeService = true;
    },
    saveStoreService() {
      yfthStoreServiceSave(this.forms.storeService).then(() => {
        this.$message.success('已保存');
        this.dialogs.storeService = false;
        this.fetchList('storeService');
      });
    },
    disableStoreService(row) {
      this.$confirm('确认停用该门店服务授权？', '确认操作').then(() => {
        yfthStoreServiceDisable({ id: row.id, reason: 'admin_disabled' }).then(() => {
          this.$message.success('已停用');
          this.fetchList('storeService');
        });
      });
    },
    openSchedule(row) {
      this.forms.schedule = Object.assign(
        {
          id: 0,
          store_service_id: 0,
          weekday: 1,
          start_minute: 540,
          end_minute: 720,
          slot_interval_minutes: 0,
          slot_capacity: 1,
          status: 'active',
        },
        row || {},
      );
      this.dialogs.schedule = true;
    },
    saveSchedule() {
      yfthServiceScheduleRuleSave(this.forms.schedule).then(() => {
        this.$message.success('已保存');
        this.dialogs.schedule = false;
        this.fetchList('schedule');
      });
    },
    disableSchedule(row) {
      this.$confirm('确认停用该排班规则？', '确认操作').then(() => {
        yfthServiceScheduleRuleDisable({ id: row.id, reason: 'admin_disabled' }).then(() => {
          this.$message.success('已停用');
          this.fetchList('schedule');
        });
      });
    },
    openSpecialDay(row) {
      this.forms.specialDay = Object.assign(
        {
          id: 0,
          store_service_id: 0,
          service_date: '',
          date_type: 'closed',
          start_minute: 0,
          end_minute: 1440,
          slot_capacity: 1,
          reason: '',
          status: 'active',
        },
        row || {},
      );
      if (row && row.service_date_text) {
        this.forms.specialDay.service_date = row.service_date_text;
      }
      this.dialogs.specialDay = true;
    },
    saveSpecialDay() {
      yfthServiceSpecialDaySave(this.forms.specialDay).then(() => {
        this.$message.success('已保存');
        this.dialogs.specialDay = false;
        this.fetchList('specialDay');
      });
    },
    disableSpecialDay(row) {
      this.$confirm('确认停用该特殊日期配置？', '确认操作').then(() => {
        yfthServiceSpecialDayDisable({ id: row.id, reason: 'admin_disabled' }).then(() => {
          this.$message.success('已停用');
          this.fetchList('specialDay');
        });
      });
    },
    loadPreview() {
      this.previewLoading = true;
      yfthServiceSlotPreview(this.previewFilters)
        .then((res) => {
          const data = res.data || {};
          this.previewRows = data.days || [];
        })
        .finally(() => {
          this.previewLoading = false;
        });
    },
    openAppointment(row) {
      yfthServiceAppointmentDetail(row.id).then((res) => {
        this.appointmentDetail = res.data || {};
        this.dialogs.appointment = true;
      });
    },
    openWriteoff(row) {
      yfthServiceWriteoffDetail(row.id).then((res) => {
        this.writeoffDetail = (res.data && res.data.record) || {};
        this.dialogs.writeoff = true;
      });
    },
    formatWriteoffResult(result) {
      if (!result || !result.status || result.status === 'none') return '-';
      return result.record ? `${result.status} / ${result.record.writeoff_no || ''}` : result.status;
    },
    exceptionWriteoff(row) {
      this.$prompt('请输入原因', '总部例外核销', {
        confirmButtonText: '提交',
        cancelButtonText: '取消',
        inputValue: 'headquarter_exception_writeoff',
      }).then(({ value }) => {
        yfthServiceAppointmentExceptionWriteoff(row.id, { reason: value || 'headquarter_exception_writeoff' }).then(() => {
          this.$message.success('已核销');
          this.fetchList('appointment');
          this.fetchList('writeoff');
        });
      });
    },
    operateAppointment(row, action) {
      const actionMap = {
        confirm: { api: yfthServiceAppointmentConfirm, text: '确认该预约？' },
        reject: { api: yfthServiceAppointmentReject, text: '拒绝该预约？' },
        cancel: { api: yfthServiceAppointmentCancel, text: '取消该预约？' },
      };
      const config = actionMap[action];
      if (!config) return;
      this.$prompt('请输入原因', '确认操作', {
        confirmButtonText: '提交',
        cancelButtonText: '取消',
        inputValue: action,
        inputPlaceholder: config.text,
      }).then(({ value }) => {
        config.api(row.id, { reason: value || action }).then(() => {
          this.$message.success('已更新');
          this.fetchList('appointment');
        });
      });
    },
  },
};
</script>

<style scoped>
.yfth-service-appointment .toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.yfth-service-appointment .w120 {
  width: 120px;
}

.yfth-service-appointment .w140 {
  width: 140px;
}

.yfth-service-appointment .w160 {
  width: 160px;
}

.yfth-service-appointment .w170 {
  width: 170px;
}

.yfth-service-appointment .pager {
  margin-top: 16px;
  text-align: right;
}

.yfth-service-appointment .slot-tag {
  margin: 2px 4px 2px 0;
}

.yfth-service-appointment .event-table {
  margin-top: 12px;
}
</style>
