<template>
  <div class="yfth-service-appointment">
    <el-card shadow="never" class="ivu-mt" :body-style="{ padding: '16px' }">
      <el-tabs v-model="activeTab" @tab-click="handleTabChange">
        <el-tab-pane label="Projects" name="project">
          <div class="toolbar">
            <el-input v-model="filters.project.service_code" clearable placeholder="Code" class="w160" />
            <el-select v-model="filters.project.status" clearable placeholder="Status" class="w140">
              <el-option label="Active" value="active" />
              <el-option label="Disabled" value="disabled" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="search('project')">Search</el-button>
            <el-button type="success" icon="el-icon-plus" @click="openProject()">Add</el-button>
          </div>
          <el-table v-loading="loading.project" :data="lists.project" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="service_code" label="Code" min-width="150" />
            <el-table-column prop="service_name" label="Name" min-width="180" />
            <el-table-column prop="service_type" label="Type" width="150" />
            <el-table-column prop="suggested_duration_minutes" label="Duration" width="100" />
            <el-table-column prop="required_benefit_type" label="Benefit" width="110" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column label="Actions" width="170" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" @click="openProject(scope.row)">Edit</el-button>
                <el-button type="text" icon="el-icon-close" @click="disableProject(scope.row)">Disable</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Store Services" name="storeService">
          <div class="toolbar">
            <el-input v-model="filters.storeService.store_id" clearable placeholder="Store ID" class="w120" />
            <el-input
              v-model="filters.storeService.service_project_id"
              clearable
              placeholder="Project ID"
              class="w120"
            />
            <el-select v-model="filters.storeService.status" clearable placeholder="Status" class="w140">
              <el-option label="Active" value="active" />
              <el-option label="Disabled" value="disabled" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="search('storeService')">Search</el-button>
            <el-button type="success" icon="el-icon-plus" @click="openStoreService()">Authorize</el-button>
          </div>
          <el-table v-loading="loading.storeService" :data="lists.storeService" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_id" label="Store" width="90" />
            <el-table-column prop="service_project_id" label="Project" width="100" />
            <el-table-column prop="service_name" label="Project Name" min-width="160" />
            <el-table-column prop="service_alias" label="Alias" min-width="160" />
            <el-table-column prop="duration_minutes" label="Duration" width="100" />
            <el-table-column prop="default_capacity" label="Capacity" width="100" />
            <el-table-column prop="appointment_enabled" label="Booking" width="90" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column label="Actions" width="170" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" @click="openStoreService(scope.row)">Edit</el-button>
                <el-button type="text" icon="el-icon-close" @click="disableStoreService(scope.row)">Disable</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Schedules" name="schedule">
          <div class="toolbar">
            <el-input
              v-model="filters.schedule.store_service_id"
              clearable
              placeholder="Store Service ID"
              class="w160"
            />
            <el-input v-model="filters.schedule.store_id" clearable placeholder="Store ID" class="w120" />
            <el-select v-model="filters.schedule.weekday" clearable placeholder="Weekday" class="w140">
              <el-option v-for="item in weekdayOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="search('schedule')">Search</el-button>
            <el-button type="success" icon="el-icon-plus" @click="openSchedule()">Add</el-button>
          </div>
          <el-table v-loading="loading.schedule" :data="lists.schedule" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_service_id" label="Store Service" width="120" />
            <el-table-column prop="weekday" label="Weekday" width="90" />
            <el-table-column prop="start_time_text" label="Start" width="90" />
            <el-table-column prop="end_time_text" label="End" width="90" />
            <el-table-column prop="slot_interval_minutes" label="Interval" width="90" />
            <el-table-column prop="slot_capacity" label="Capacity" width="90" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column label="Actions" width="170" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" @click="openSchedule(scope.row)">Edit</el-button>
                <el-button type="text" icon="el-icon-close" @click="disableSchedule(scope.row)">Disable</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Special Days" name="specialDay">
          <div class="toolbar">
            <el-input
              v-model="filters.specialDay.store_service_id"
              clearable
              placeholder="Store Service ID"
              class="w160"
            />
            <el-date-picker
              v-model="filters.specialDay.service_date"
              value-format="yyyy-MM-dd"
              placeholder="Date"
              class="w160"
            />
            <el-select v-model="filters.specialDay.date_type" clearable placeholder="Type" class="w170">
              <el-option label="Closed" value="closed" />
              <el-option label="Extra" value="extra" />
              <el-option label="Capacity Override" value="capacity_override" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" @click="search('specialDay')">Search</el-button>
            <el-button type="success" icon="el-icon-plus" @click="openSpecialDay()">Add</el-button>
          </div>
          <el-table v-loading="loading.specialDay" :data="lists.specialDay" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_service_id" label="Store Service" width="120" />
            <el-table-column prop="service_date_text" label="Date" width="120" />
            <el-table-column prop="date_type" label="Type" width="150" />
            <el-table-column prop="start_time_text" label="Start" width="90" />
            <el-table-column prop="end_time_text" label="End" width="90" />
            <el-table-column prop="slot_capacity" label="Capacity" width="90" />
            <el-table-column prop="reason" label="Reason" min-width="160" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column label="Actions" width="170" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" @click="openSpecialDay(scope.row)">Edit</el-button>
                <el-button type="text" icon="el-icon-close" @click="disableSpecialDay(scope.row)">Disable</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Slot Preview" name="preview">
          <div class="toolbar">
            <el-input v-model="previewFilters.store_service_id" clearable placeholder="Store Service ID" class="w160" />
            <el-date-picker
              v-model="previewFilters.start_date"
              value-format="yyyy-MM-dd"
              placeholder="Start Date"
              class="w160"
            />
            <el-date-picker
              v-model="previewFilters.end_date"
              value-format="yyyy-MM-dd"
              placeholder="End Date"
              class="w160"
            />
            <el-button type="primary" icon="el-icon-search" :loading="previewLoading" @click="loadPreview"
              >Preview</el-button
            >
          </div>
          <el-table :data="previewRows" border>
            <el-table-column prop="date" label="Date" width="120" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column prop="reason" label="Reason" min-width="160" />
            <el-table-column prop="total_capacity" label="Capacity" width="100" />
            <el-table-column label="Slots" min-width="420">
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

    <el-dialog :visible.sync="dialogs.project" title="Service Project" width="720px">
      <el-form label-width="150px">
        <el-form-item label="Code"><el-input v-model="forms.project.service_code" /></el-form-item>
        <el-form-item label="Name"><el-input v-model="forms.project.service_name" /></el-form-item>
        <el-form-item label="Type"><el-input v-model="forms.project.service_type" /></el-form-item>
        <el-form-item label="Duration">
          <el-input-number v-model="forms.project.suggested_duration_minutes" :min="5" :max="480" />
        </el-form-item>
        <el-form-item label="Benefit Enabled"
          ><el-switch v-model="forms.project.allow_benefit" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="Benefit Type"><el-input v-model="forms.project.required_benefit_type" /></el-form-item>
        <el-form-item label="Benefit Template IDs"
          ><el-input v-model="forms.project.required_benefit_template_ids"
        /></el-form-item>
        <el-form-item label="Paid Extension"
          ><el-switch v-model="forms.project.allow_paid" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="Status">
          <el-select v-model="forms.project.status">
            <el-option label="Active" value="active" />
            <el-option label="Disabled" value="disabled" />
          </el-select>
        </el-form-item>
        <el-form-item label="Sort"><el-input-number v-model="forms.project.sort" /></el-form-item>
        <el-form-item label="Description"
          ><el-input v-model="forms.project.service_desc" type="textarea" :rows="3"
        /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.project = false">Cancel</el-button>
        <el-button type="primary" @click="saveProject">Save</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.storeService" title="Store Service" width="720px">
      <el-form label-width="170px">
        <el-form-item label="Store ID"><el-input-number v-model="forms.storeService.store_id" :min="0" /></el-form-item>
        <el-form-item label="Project ID"
          ><el-input-number v-model="forms.storeService.service_project_id" :min="0"
        /></el-form-item>
        <el-form-item label="Alias"><el-input v-model="forms.storeService.service_alias" /></el-form-item>
        <el-form-item label="Duration"
          ><el-input-number v-model="forms.storeService.duration_minutes" :min="5" :max="480"
        /></el-form-item>
        <el-form-item label="Confirmation"
          ><el-switch v-model="forms.storeService.requires_confirmation" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="Booking Enabled"
          ><el-switch v-model="forms.storeService.appointment_enabled" :active-value="1" :inactive-value="0"
        /></el-form-item>
        <el-form-item label="Advance Min Minutes"
          ><el-input-number v-model="forms.storeService.advance_min_minutes" :min="0"
        /></el-form-item>
        <el-form-item label="Advance Max Days"
          ><el-input-number v-model="forms.storeService.advance_max_days" :min="1"
        /></el-form-item>
        <el-form-item label="Cancel Deadline"
          ><el-input-number v-model="forms.storeService.cancel_deadline_minutes" :min="0"
        /></el-form-item>
        <el-form-item label="Default Capacity"
          ><el-input-number v-model="forms.storeService.default_capacity" :min="1"
        /></el-form-item>
        <el-form-item label="Timezone"><el-input v-model="forms.storeService.timezone" /></el-form-item>
        <el-form-item label="Status">
          <el-select v-model="forms.storeService.status">
            <el-option label="Active" value="active" />
            <el-option label="Disabled" value="disabled" />
          </el-select>
        </el-form-item>
        <el-form-item label="Description"
          ><el-input v-model="forms.storeService.service_description" type="textarea" :rows="3"
        /></el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.storeService = false">Cancel</el-button>
        <el-button type="primary" @click="saveStoreService">Save</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.schedule" title="Schedule Rule" width="640px">
      <el-form label-width="150px">
        <el-form-item label="Store Service ID"
          ><el-input-number v-model="forms.schedule.store_service_id" :min="0"
        /></el-form-item>
        <el-form-item label="Weekday">
          <el-select v-model="forms.schedule.weekday">
            <el-option v-for="item in weekdayOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="Start Minute"
          ><el-input-number v-model="forms.schedule.start_minute" :min="0" :max="1439"
        /></el-form-item>
        <el-form-item label="End Minute"
          ><el-input-number v-model="forms.schedule.end_minute" :min="1" :max="1440"
        /></el-form-item>
        <el-form-item label="Interval Minutes"
          ><el-input-number v-model="forms.schedule.slot_interval_minutes" :min="0"
        /></el-form-item>
        <el-form-item label="Capacity"
          ><el-input-number v-model="forms.schedule.slot_capacity" :min="1"
        /></el-form-item>
        <el-form-item label="Status">
          <el-select v-model="forms.schedule.status">
            <el-option label="Active" value="active" />
            <el-option label="Disabled" value="disabled" />
          </el-select>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.schedule = false">Cancel</el-button>
        <el-button type="primary" @click="saveSchedule">Save</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="dialogs.specialDay" title="Special Day" width="640px">
      <el-form label-width="150px">
        <el-form-item label="Store Service ID"
          ><el-input-number v-model="forms.specialDay.store_service_id" :min="0"
        /></el-form-item>
        <el-form-item label="Date"
          ><el-date-picker v-model="forms.specialDay.service_date" value-format="yyyy-MM-dd"
        /></el-form-item>
        <el-form-item label="Type">
          <el-select v-model="forms.specialDay.date_type">
            <el-option label="Closed" value="closed" />
            <el-option label="Extra" value="extra" />
            <el-option label="Capacity Override" value="capacity_override" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="forms.specialDay.date_type !== 'closed'" label="Start Minute">
          <el-input-number v-model="forms.specialDay.start_minute" :min="0" :max="1439" />
        </el-form-item>
        <el-form-item v-if="forms.specialDay.date_type !== 'closed'" label="End Minute">
          <el-input-number v-model="forms.specialDay.end_minute" :min="1" :max="1440" />
        </el-form-item>
        <el-form-item v-if="forms.specialDay.date_type !== 'closed'" label="Capacity">
          <el-input-number v-model="forms.specialDay.slot_capacity" :min="1" />
        </el-form-item>
        <el-form-item label="Reason"><el-input v-model="forms.specialDay.reason" /></el-form-item>
        <el-form-item label="Status">
          <el-select v-model="forms.specialDay.status">
            <el-option label="Active" value="active" />
            <el-option label="Disabled" value="disabled" />
          </el-select>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="dialogs.specialDay = false">Cancel</el-button>
        <el-button type="primary" @click="saveSpecialDay">Save</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthServiceProjectDisable,
  yfthServiceProjectList,
  yfthServiceProjectSave,
  yfthServiceScheduleRuleDisable,
  yfthServiceScheduleRuleList,
  yfthServiceScheduleRuleSave,
  yfthServiceSlotPreview,
  yfthServiceSpecialDayDisable,
  yfthServiceSpecialDayList,
  yfthServiceSpecialDaySave,
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
      },
      loading: {
        project: false,
        storeService: false,
        schedule: false,
        specialDay: false,
      },
      pages: {
        project: 1,
        storeService: 1,
        schedule: 1,
        specialDay: 1,
      },
      limits: {
        project: 15,
        storeService: 15,
        schedule: 15,
        specialDay: 15,
      },
      totals: {
        project: 0,
        storeService: 0,
        schedule: 0,
        specialDay: 0,
      },
      dialogs: {
        project: false,
        storeService: false,
        schedule: false,
        specialDay: false,
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
      weekdayOptions: [
        { label: 'Mon', value: 1 },
        { label: 'Tue', value: 2 },
        { label: 'Wed', value: 3 },
        { label: 'Thu', value: 4 },
        { label: 'Fri', value: 5 },
        { label: 'Sat', value: 6 },
        { label: 'Sun', value: 7 },
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
        this.$message.success('Saved');
        this.dialogs.project = false;
        this.fetchList('project');
      });
    },
    disableProject(row) {
      this.$confirm('Disable this service project?', 'Confirm').then(() => {
        yfthServiceProjectDisable({ id: row.id, reason: 'admin_disabled' }).then(() => {
          this.$message.success('Disabled');
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
        this.$message.success('Saved');
        this.dialogs.storeService = false;
        this.fetchList('storeService');
      });
    },
    disableStoreService(row) {
      this.$confirm('Disable this store service?', 'Confirm').then(() => {
        yfthStoreServiceDisable({ id: row.id, reason: 'admin_disabled' }).then(() => {
          this.$message.success('Disabled');
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
        this.$message.success('Saved');
        this.dialogs.schedule = false;
        this.fetchList('schedule');
      });
    },
    disableSchedule(row) {
      this.$confirm('Disable this schedule?', 'Confirm').then(() => {
        yfthServiceScheduleRuleDisable({ id: row.id, reason: 'admin_disabled' }).then(() => {
          this.$message.success('Disabled');
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
        this.$message.success('Saved');
        this.dialogs.specialDay = false;
        this.fetchList('specialDay');
      });
    },
    disableSpecialDay(row) {
      this.$confirm('Disable this special day?', 'Confirm').then(() => {
        yfthServiceSpecialDayDisable({ id: row.id, reason: 'admin_disabled' }).then(() => {
          this.$message.success('Disabled');
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
</style>
