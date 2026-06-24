<template>
  <div class="yfth-foundation">
    <el-card shadow="never" class="ivu-mt" :body-style="{ padding: '16px' }">
      <el-tabs v-model="activeTab" @tab-click="handleTabChange">
        <el-tab-pane label="Identities" name="identity">
          <div class="toolbar">
            <el-input v-model="filters.identity.uid" clearable placeholder="UID" class="w120" />
            <el-select v-model="filters.identity.role_code" clearable placeholder="Role" class="w180">
              <el-option v-for="item in roleOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-select v-model="filters.identity.status" clearable placeholder="Status" class="w140">
              <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" v-db-click @click="search('identity')">Search</el-button>
            <el-button icon="el-icon-refresh-left" v-db-click @click="reset('identity')">Reset</el-button>
          </div>
          <el-table v-loading="loading.identity" :data="lists.identity" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="uid" label="UID" width="110" />
            <el-table-column prop="role_name" label="Role" min-width="150" />
            <el-table-column prop="role_code" label="Code" min-width="150" />
            <el-table-column prop="source_type" label="Source" min-width="130" />
            <el-table-column prop="status" label="Status" width="110" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Store Roles" name="storeRole">
          <div class="toolbar">
            <el-input v-model="filters.storeRole.uid" clearable placeholder="UID" class="w120" />
            <el-input v-model="filters.storeRole.store_id" clearable placeholder="Store ID" class="w120" />
            <el-select v-model="filters.storeRole.role_code" clearable placeholder="Role" class="w180">
              <el-option v-for="item in storeRoleOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" v-db-click @click="search('storeRole')">Search</el-button>
            <el-button icon="el-icon-refresh-left" v-db-click @click="reset('storeRole')">Reset</el-button>
          </div>
          <el-table v-loading="loading.storeRole" :data="lists.storeRole" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="uid" label="UID" width="110" />
            <el-table-column prop="store_id" label="Store ID" width="110" />
            <el-table-column prop="role_name" label="Role" min-width="150" />
            <el-table-column prop="status" label="Status" width="110" />
            <el-table-column label="Scope" min-width="220">
              <template slot-scope="scope">{{ formatJson(scope.row.permission_scope) }}</template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Subjects" name="subject">
          <div class="toolbar">
            <el-select v-model="filters.subject.subject_type" clearable placeholder="Type" class="w180">
              <el-option v-for="item in subjectTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-select v-model="filters.subject.status" clearable placeholder="Status" class="w140">
              <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" v-db-click @click="search('subject')">Search</el-button>
            <el-button icon="el-icon-refresh-left" v-db-click @click="reset('subject')">Reset</el-button>
            <el-button type="success" icon="el-icon-plus" v-db-click @click="openSubject()">Add</el-button>
          </div>
          <el-table v-loading="loading.subject" :data="lists.subject" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="subject_type_name" label="Type" min-width="150" />
            <el-table-column prop="subject_name" label="Name" min-width="200" />
            <el-table-column prop="credit_code_masked" label="Credit Code" min-width="180" />
            <el-table-column prop="contact_phone_masked" label="Phone" min-width="140" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column label="Actions" width="110" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" v-db-click @click="openSubject(scope.row)">Edit</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Store Subjects" name="storeSubject">
          <div class="toolbar">
            <el-input v-model="filters.storeSubject.store_id" clearable placeholder="Store ID" class="w120" />
            <el-input v-model="filters.storeSubject.subject_id" clearable placeholder="Subject ID" class="w120" />
            <el-select v-model="filters.storeSubject.subject_role" clearable placeholder="Role" class="w180">
              <el-option v-for="item in subjectRoleOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-button type="primary" icon="el-icon-search" v-db-click @click="search('storeSubject')"
              >Search</el-button
            >
            <el-button icon="el-icon-refresh-left" v-db-click @click="reset('storeSubject')">Reset</el-button>
            <el-button type="success" icon="el-icon-plus" v-db-click @click="openStoreSubject()">Add</el-button>
          </div>
          <el-table v-loading="loading.storeSubject" :data="lists.storeSubject" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_id" label="Store ID" width="110" />
            <el-table-column prop="subject_id" label="Subject ID" width="110" />
            <el-table-column prop="store_type_name" label="Store Type" min-width="140" />
            <el-table-column prop="subject_role_name" label="Subject Role" min-width="150" />
            <el-table-column prop="active_key" label="Active Key" min-width="180" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column label="Actions" width="170" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" v-db-click @click="openStoreSubject(scope.row)"
                  >Edit</el-button
                >
                <el-button type="text" icon="el-icon-close" v-db-click @click="disableStoreSubject(scope.row)"
                  >Disable</el-button
                >
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Qualifications" name="qualification">
          <div class="toolbar">
            <el-input v-model="filters.qualification.store_id" clearable placeholder="Store ID" class="w120" />
            <el-input v-model="filters.qualification.subject_id" clearable placeholder="Subject ID" class="w120" />
            <el-select v-model="filters.qualification.status" clearable placeholder="Status" class="w140">
              <el-option
                v-for="item in qualificationStatusOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
            <el-button type="primary" icon="el-icon-search" v-db-click @click="search('qualification')"
              >Search</el-button
            >
            <el-button icon="el-icon-refresh-left" v-db-click @click="reset('qualification')">Reset</el-button>
            <el-button type="success" icon="el-icon-plus" v-db-click @click="openQualification()">Submit</el-button>
          </div>
          <el-table v-loading="loading.qualification" :data="lists.qualification" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_id" label="Store ID" width="100" />
            <el-table-column prop="subject_id" label="Subject ID" width="100" />
            <el-table-column prop="qualification_type" label="Type" min-width="160" />
            <el-table-column prop="certificate_no" label="Certificate" min-width="170" />
            <el-table-column prop="status_name" label="Status" width="120" />
            <el-table-column label="Expire Time" min-width="150">
              <template slot-scope="scope">{{ formatTime(scope.row.expire_time) }}</template>
            </el-table-column>
            <el-table-column label="Actions" width="220" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" v-db-click @click="openQualification(scope.row)"
                  >Edit</el-button
                >
                <el-button type="text" icon="el-icon-check" v-db-click @click="openAudit(scope.row, 'active')"
                  >Pass</el-button
                >
                <el-button type="text" icon="el-icon-close" v-db-click @click="openAudit(scope.row, 'rejected')"
                  >Reject</el-button
                >
                <el-button type="text" icon="el-icon-video-pause" v-db-click @click="openAudit(scope.row, 'paused')"
                  >Pause</el-button
                >
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Capabilities" name="capability">
          <div class="toolbar">
            <el-input v-model="filters.capability.store_id" clearable placeholder="Store ID" class="w120" />
            <el-select v-model="filters.capability.capability_code" clearable placeholder="Capability" class="w180">
              <el-option v-for="item in capabilityOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
            <el-select v-model="filters.capability.status" clearable placeholder="Status" class="w140">
              <el-option
                v-for="item in qualificationStatusOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
            <el-button type="primary" icon="el-icon-search" v-db-click @click="search('capability')">Search</el-button>
            <el-button icon="el-icon-refresh-left" v-db-click @click="reset('capability')">Reset</el-button>
          </div>
          <el-table v-loading="loading.capability" :data="lists.capability" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_id" label="Store ID" width="100" />
            <el-table-column prop="capability_name" label="Capability" min-width="150" />
            <el-table-column prop="source_qualification_id" label="Qualification" width="120" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column prop="close_reason" label="Reason" min-width="180" />
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Payment Routes" name="paymentRoute">
          <div class="toolbar">
            <el-input v-model="filters.paymentRoute.store_id" clearable placeholder="Store ID" class="w120" />
            <el-select v-model="filters.paymentRoute.business_scene" clearable placeholder="Scene" class="w200">
              <el-option
                v-for="item in paymentSceneOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
            <el-button type="primary" icon="el-icon-search" v-db-click @click="search('paymentRoute')"
              >Search</el-button
            >
            <el-button icon="el-icon-refresh-left" v-db-click @click="reset('paymentRoute')">Reset</el-button>
            <el-button type="success" icon="el-icon-plus" v-db-click @click="openPaymentRoute()">Add</el-button>
          </div>
          <el-table v-loading="loading.paymentRoute" :data="lists.paymentRoute" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="store_id" label="Store ID" width="100" />
            <el-table-column prop="business_scene_name" label="Scene" min-width="160" />
            <el-table-column prop="route_type" label="Route Type" min-width="130" />
            <el-table-column prop="merchant_ref_masked" label="Merchant Ref" min-width="140" />
            <el-table-column prop="sub_merchant_ref_masked" label="Sub Merchant" min-width="140" />
            <el-table-column prop="version_no" label="Version" width="90" />
            <el-table-column prop="priority" label="Priority" width="90" />
            <el-table-column prop="status" label="Status" width="100" />
            <el-table-column label="Actions" width="220" fixed="right">
              <template slot-scope="scope">
                <el-button type="text" icon="el-icon-edit" v-db-click @click="openPaymentRoute(scope.row)"
                  >Edit</el-button
                >
                <el-button type="text" icon="el-icon-view" v-db-click @click="resolvePaymentRoute(scope.row)"
                  >Resolve</el-button
                >
                <el-button type="text" icon="el-icon-close" v-db-click @click="disablePaymentRoute(scope.row)"
                  >Disable</el-button
                >
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="Audit Events" name="auditEvent">
          <div class="toolbar">
            <el-input v-model="filters.auditEvent.store_id" clearable placeholder="Store ID" class="w120" />
            <el-input v-model="filters.auditEvent.object_type" clearable placeholder="Object Type" class="w140" />
            <el-input v-model="filters.auditEvent.object_id" clearable placeholder="Object ID" class="w140" />
            <el-button type="primary" icon="el-icon-search" v-db-click @click="search('auditEvent')">Search</el-button>
            <el-button icon="el-icon-refresh-left" v-db-click @click="reset('auditEvent')">Reset</el-button>
          </div>
          <el-table v-loading="loading.auditEvent" :data="lists.auditEvent" border>
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="business_domain" label="Domain" min-width="130" />
            <el-table-column prop="object_type" label="Object" min-width="130" />
            <el-table-column prop="object_id" label="Object ID" min-width="120" />
            <el-table-column prop="action" label="Action" min-width="140" />
            <el-table-column prop="operator_uid" label="Operator" width="100" />
            <el-table-column prop="store_id" label="Store ID" width="100" />
            <el-table-column label="Time" min-width="150">
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

    <el-dialog :visible.sync="subjectDialog" title="Business Subject" width="620px" :close-on-click-modal="false">
      <el-form :model="subjectForm" label-width="120px">
        <el-form-item label="Type">
          <el-select v-model="subjectForm.subject_type" class="full">
            <el-option v-for="item in subjectTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="Name">
          <el-input v-model="subjectForm.subject_name" />
        </el-form-item>
        <el-form-item label="Credit Code">
          <el-input
            v-model="subjectForm.credit_code"
            :disabled="subjectForm.id > 0 && !subjectForm.can_edit_credit_code"
          />
        </el-form-item>
        <el-form-item label="Legal Person">
          <el-input v-model="subjectForm.legal_person" />
        </el-form-item>
        <el-form-item label="Contact">
          <el-input v-model="subjectForm.contact_name" />
        </el-form-item>
        <el-form-item label="Phone">
          <el-input v-model="subjectForm.contact_phone" />
        </el-form-item>
        <el-form-item label="Address">
          <el-input v-model="subjectForm.registered_address" />
        </el-form-item>
        <el-form-item label="Status">
          <el-select v-model="subjectForm.status" class="full">
            <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="subjectDialog = false">Cancel</el-button>
        <el-button type="primary" icon="el-icon-check" v-db-click @click="submitSubject">Save</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="storeSubjectDialog" title="Store Subject" width="640px" :close-on-click-modal="false">
      <el-form :model="storeSubjectForm" label-width="130px">
        <el-form-item label="Store ID">
          <el-input v-model="storeSubjectForm.store_id" />
        </el-form-item>
        <el-form-item label="Subject ID">
          <el-input v-model="storeSubjectForm.subject_id" />
        </el-form-item>
        <el-form-item label="Store Type">
          <el-select v-model="storeSubjectForm.store_type" class="full">
            <el-option v-for="item in storeTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="Subject Role">
          <el-select v-model="storeSubjectForm.subject_role" class="full">
            <el-option v-for="item in subjectRoleOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="Flags">
          <el-checkbox v-model="storeSubjectForm.is_sales_subject">Sales</el-checkbox>
          <el-checkbox v-model="storeSubjectForm.is_payment_subject">Payment</el-checkbox>
          <el-checkbox v-model="storeSubjectForm.is_fulfillment_subject">Fulfillment</el-checkbox>
          <el-checkbox v-model="storeSubjectForm.is_invoice_subject">Invoice</el-checkbox>
          <el-checkbox v-model="storeSubjectForm.is_refund_subject">Refund</el-checkbox>
          <el-checkbox v-model="storeSubjectForm.is_host_subject">Host</el-checkbox>
        </el-form-item>
        <el-form-item label="Effective">
          <el-date-picker
            v-model="storeSubjectForm.effective_time"
            type="date"
            value-format="yyyy-MM-dd"
            class="full"
          />
        </el-form-item>
        <el-form-item label="Expire">
          <el-date-picker v-model="storeSubjectForm.expire_time" type="date" value-format="yyyy-MM-dd" class="full" />
        </el-form-item>
        <el-form-item label="Status">
          <el-select v-model="storeSubjectForm.status" class="full">
            <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="storeSubjectDialog = false">Cancel</el-button>
        <el-button type="primary" icon="el-icon-check" v-db-click @click="submitStoreSubject">Save</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="qualificationDialog" title="Qualification" width="620px" :close-on-click-modal="false">
      <el-form :model="qualificationForm" label-width="120px">
        <el-form-item label="Store ID">
          <el-input v-model="qualificationForm.store_id" />
        </el-form-item>
        <el-form-item label="Subject ID">
          <el-input v-model="qualificationForm.subject_id" />
        </el-form-item>
        <el-form-item label="Type">
          <el-input v-model="qualificationForm.qualification_type" />
        </el-form-item>
        <el-form-item label="Certificate">
          <el-input v-model="qualificationForm.certificate_no" />
        </el-form-item>
        <el-form-item label="Attachment ID">
          <el-input v-model="qualificationForm.attachment_id" />
        </el-form-item>
        <el-form-item label="Start">
          <el-date-picker v-model="qualificationForm.start_time" type="date" value-format="yyyy-MM-dd" class="full" />
        </el-form-item>
        <el-form-item label="Expire">
          <el-date-picker v-model="qualificationForm.expire_time" type="date" value-format="yyyy-MM-dd" class="full" />
        </el-form-item>
        <el-form-item label="Scope">
          <el-input v-model="qualificationForm.scopeText" type="textarea" :rows="3" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="qualificationDialog = false">Cancel</el-button>
        <el-button type="primary" icon="el-icon-check" v-db-click @click="submitQualification">Submit</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="auditDialog" title="Qualification Audit" width="460px" :close-on-click-modal="false">
      <el-form :model="auditForm" label-width="90px">
        <el-form-item label="Status">
          <el-select v-model="auditForm.status" class="full">
            <el-option label="Pass" value="active" />
            <el-option label="Reject" value="rejected" />
            <el-option label="Pause" value="paused" />
            <el-option label="Expire" value="expired" />
          </el-select>
        </el-form-item>
        <el-form-item label="Reason">
          <el-input v-model="auditForm.reason" type="textarea" :rows="3" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="auditDialog = false">Cancel</el-button>
        <el-button type="primary" icon="el-icon-check" v-db-click @click="submitAudit">Confirm</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="paymentRouteDialog" title="Payment Route" width="680px" :close-on-click-modal="false">
      <el-form :model="paymentRouteForm" label-width="150px">
        <el-form-item label="Store ID">
          <el-input v-model="paymentRouteForm.store_id" />
        </el-form-item>
        <el-form-item label="Business Scene">
          <el-select v-model="paymentRouteForm.business_scene" class="full">
            <el-option v-for="item in paymentSceneOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="Route Type">
          <el-input v-model="paymentRouteForm.route_type" />
        </el-form-item>
        <el-form-item label="Payment Subject ID">
          <el-input v-model="paymentRouteForm.subject_id" />
        </el-form-item>
        <el-form-item label="Merchant Ref">
          <el-input v-model="paymentRouteForm.merchant_ref" :placeholder="paymentRouteForm.merchant_ref_masked || ''" />
        </el-form-item>
        <el-form-item label="Sub Merchant Ref">
          <el-input
            v-model="paymentRouteForm.sub_merchant_ref"
            :placeholder="paymentRouteForm.sub_merchant_ref_masked || ''"
          />
        </el-form-item>
        <el-form-item label="Receiver Subject">
          <el-input v-model="paymentRouteForm.receiver_subject_id" />
        </el-form-item>
        <el-form-item label="Invoice Subject">
          <el-input v-model="paymentRouteForm.invoice_subject_id" />
        </el-form-item>
        <el-form-item label="Refund Subject">
          <el-input v-model="paymentRouteForm.refund_subject_id" />
        </el-form-item>
        <el-form-item label="Version / Priority">
          <el-input-number v-model="paymentRouteForm.version_no" :min="0" controls-position="right" />
          <el-input-number v-model="paymentRouteForm.priority" class="ml10" controls-position="right" />
        </el-form-item>
        <el-form-item label="Effective">
          <el-date-picker
            v-model="paymentRouteForm.effective_time"
            type="date"
            value-format="yyyy-MM-dd"
            class="full"
          />
        </el-form-item>
        <el-form-item label="Expire">
          <el-date-picker v-model="paymentRouteForm.expire_time" type="date" value-format="yyyy-MM-dd" class="full" />
        </el-form-item>
        <el-form-item label="Status">
          <el-select v-model="paymentRouteForm.status" class="full">
            <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button @click="paymentRouteDialog = false">Cancel</el-button>
        <el-button type="primary" icon="el-icon-check" v-db-click @click="submitPaymentRoute">Save</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  yfthAuditEventList,
  yfthCapabilityList,
  yfthIdentityList,
  yfthPaymentRouteDisable,
  yfthPaymentRouteList,
  yfthPaymentRouteResolve,
  yfthPaymentRouteSave,
  yfthQualificationAudit,
  yfthQualificationList,
  yfthQualificationSave,
  yfthStoreRoleList,
  yfthStoreSubjectDisable,
  yfthStoreSubjectList,
  yfthStoreSubjectSave,
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
      statusOptions: [
        { label: 'Active', value: 'active' },
        { label: 'Pending', value: 'pending' },
        { label: 'Paused', value: 'paused' },
        { label: 'Disabled', value: 'disabled' },
        { label: 'Expired', value: 'expired' },
      ],
      roleOptions: [
        { label: 'Customer', value: 'customer' },
        { label: 'Family member', value: 'family_member' },
        { label: '5980 member', value: 'member_5980' },
        { label: 'Franchise applicant', value: 'franchise_applicant' },
        { label: 'Franchisee', value: 'franchisee' },
        { label: 'Store manager', value: 'store_manager' },
        { label: 'Store staff', value: 'store_staff' },
        { label: 'Service mentor', value: 'service_mentor' },
        { label: 'Supplier', value: 'supplier' },
        { label: 'Headquarter operator', value: 'headquarter_operator' },
      ],
      storeRoleOptions: [
        { label: 'Franchisee', value: 'franchisee' },
        { label: 'Store manager', value: 'store_manager' },
        { label: 'Store staff', value: 'store_staff' },
      ],
      subjectTypeOptions: [
        { label: 'Headquarter', value: 'headquarter' },
        { label: 'Franchise company', value: 'franchise_company' },
        { label: 'Store company', value: 'store_company' },
        { label: 'Individual', value: 'individual' },
        { label: 'Supplier', value: 'supplier' },
      ],
      storeTypeOptions: [
        { label: 'Direct', value: 'direct' },
        { label: 'Franchise', value: 'franchise' },
        { label: 'Store in store', value: 'store_in_store' },
        { label: 'Partner', value: 'partner' },
      ],
      subjectRoleOptions: [
        { label: 'Sales', value: 'sales' },
        { label: 'Payment', value: 'payment' },
        { label: 'Fulfillment', value: 'fulfillment' },
        { label: 'Invoice', value: 'invoice' },
        { label: 'Refund', value: 'refund' },
        { label: 'Host', value: 'host' },
      ],
      qualificationStatusOptions: [
        { label: 'Pending', value: 'pending' },
        { label: 'Active', value: 'active' },
        { label: 'Rejected', value: 'rejected' },
        { label: 'Paused', value: 'paused' },
        { label: 'Expired', value: 'expired' },
      ],
      capabilityOptions: [
        { label: 'Retail sale', value: 'retail_sale' },
        { label: 'Package sale', value: 'package_sale' },
        { label: 'Reservation service', value: 'reservation_service' },
        { label: 'Order writeoff', value: 'order_writeoff' },
        { label: 'Store purchase', value: 'store_purchase' },
        { label: 'Online payment', value: 'online_payment' },
      ],
      paymentSceneOptions: [
        { label: 'Store retail', value: 'store_retail' },
        { label: 'Retail order', value: 'retail_order' },
        { label: '5980 package', value: 'package_5980' },
        { label: 'Package order', value: 'package_order' },
        { label: 'Paid service', value: 'paid_service' },
        { label: 'Headquarter purchase', value: 'headquarter_purchase' },
        { label: 'Franchise purchase', value: 'franchise_purchase' },
        { label: 'Service refund', value: 'service_refund' },
      ],
      subjectDialog: false,
      subjectForm: {},
      storeSubjectDialog: false,
      storeSubjectForm: {},
      qualificationDialog: false,
      qualificationForm: {},
      auditDialog: false,
      auditForm: { id: 0, status: 'active', reason: '' },
      paymentRouteDialog: false,
      paymentRouteForm: {},
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
          can_edit_credit_code: true,
        },
        row || {},
      );
      if (row && row.id) {
        this.subjectForm.credit_code = row.credit_code || row.credit_code_masked || '';
        this.subjectForm.can_edit_credit_code = Boolean(row.credit_code);
      }
      this.subjectDialog = true;
    },
    submitSubject() {
      const data = Object.assign({}, this.subjectForm);
      if (data.id && !data.can_edit_credit_code) {
        data.credit_code = '';
      }
      yfthSubjectSave(data).then(() => {
        this.$message.success('Saved');
        this.subjectDialog = false;
        this.fetchList('subject');
      });
    },
    openStoreSubject(row) {
      const form = Object.assign(
        {
          id: 0,
          store_id: '',
          subject_id: '',
          store_type: 'franchise',
          subject_role: 'sales',
          is_sales_subject: false,
          is_service_subject: false,
          is_payment_subject: false,
          is_fulfillment_subject: false,
          is_invoice_subject: false,
          is_refund_subject: false,
          is_host_subject: false,
          effective_time: '',
          expire_time: '',
          status: 'active',
        },
        row || {},
      );
      this.normalizeCheckboxFields(form, [
        'is_sales_subject',
        'is_service_subject',
        'is_payment_subject',
        'is_fulfillment_subject',
        'is_invoice_subject',
        'is_refund_subject',
        'is_host_subject',
      ]);
      form.effective_time = this.formatDateValue(form.effective_time);
      form.expire_time = this.formatDateValue(form.expire_time);
      this.storeSubjectForm = form;
      this.storeSubjectDialog = true;
    },
    submitStoreSubject() {
      yfthStoreSubjectSave(this.booleanPayload(this.storeSubjectForm)).then(() => {
        this.$message.success('Saved');
        this.storeSubjectDialog = false;
        this.fetchList('storeSubject');
      });
    },
    disableStoreSubject(row) {
      this.$confirm('Disable this store subject relation?', 'Confirm', { type: 'warning' }).then(() => {
        yfthStoreSubjectDisable({ id: row.id }).then(() => {
          this.$message.success('Disabled');
          this.fetchList('storeSubject');
        });
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
        this.$message.success('Submitted');
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
        this.$message.success('Done');
        this.auditDialog = false;
        this.fetchList('qualification');
        this.fetchList('capability');
      });
    },
    openPaymentRoute(row) {
      const form = Object.assign(
        {
          id: 0,
          store_id: '',
          subject_id: '',
          business_scene: 'store_retail',
          route_type: 'wechat_sub_merchant',
          merchant_ref: '',
          sub_merchant_ref: '',
          receiver_subject_id: '',
          invoice_subject_id: '',
          refund_subject_id: '',
          status: 'active',
          config_status: 'metadata_only',
          version_no: 0,
          priority: 0,
          effective_time: '',
          expire_time: '',
        },
        row || {},
      );
      form.merchant_ref = '';
      form.sub_merchant_ref = '';
      form.effective_time = this.formatDateValue(form.effective_time);
      form.expire_time = this.formatDateValue(form.expire_time);
      this.paymentRouteForm = form;
      this.paymentRouteDialog = true;
    },
    submitPaymentRoute() {
      yfthPaymentRouteSave(this.paymentRouteForm).then(() => {
        this.$message.success('Saved');
        this.paymentRouteDialog = false;
        this.fetchList('paymentRoute');
      });
    },
    disablePaymentRoute(row) {
      this.$confirm('Disable this payment route?', 'Confirm', { type: 'warning' }).then(() => {
        yfthPaymentRouteDisable({ id: row.id }).then(() => {
          this.$message.success('Disabled');
          this.fetchList('paymentRoute');
        });
      });
    },
    resolvePaymentRoute(row) {
      yfthPaymentRouteResolve({ store_id: row.store_id, business_scene: row.business_scene }).then((res) => {
        const route = res.data || {};
        this.$message.success(`Route #${route.id || row.id} resolved`);
      });
    },
    normalizeCheckboxFields(form, fields) {
      fields.forEach((field) => {
        form[field] = Boolean(Number(form[field] || 0));
      });
    },
    booleanPayload(form) {
      const data = Object.assign({}, form);
      Object.keys(data).forEach((key) => {
        if (typeof data[key] === 'boolean') {
          data[key] = data[key] ? 1 : 0;
        }
      });
      return data;
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
.yfth-foundation .w180 {
  width: 180px;
}
.yfth-foundation .w200 {
  width: 200px;
}
.yfth-foundation .full {
  width: 100%;
}
.yfth-foundation .ml10 {
  margin-left: 10px;
}
</style>
