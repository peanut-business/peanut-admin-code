<template>
  <div class="container">
    <Breadcrumb :items="['menu.member', 'menu.member.list']" />

    <el-card class="general-card search-card">
      <el-form :model="queryParams" inline>
        <el-form-item prop="keyword" :label="$t('member.filter.userInfo')">
          <el-input
            v-model="queryParams.keyword"
            :placeholder="$t('member.filter.keyword')"
            style="width: 240px"
            clearable
            @keyup.enter="search"
          />
        </el-form-item>
        <el-form-item :label="$t('member.filter.createTime')">
          <el-date-picker
            v-model="queryParams.createTime"
            type="daterange"
            value-format="YYYY-MM-DD"
            style="width: 260px"
            clearable
          />
        </el-form-item>
        <el-form-item prop="channel" :label="$t('member.filter.channel')">
          <el-select
            v-model="queryParams.channel"
            :placeholder="$t('member.filter.channel.all')"
            style="width: 160px"
            clearable
          >
            <el-option
              v-for="option in channelOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-space>
            <el-button type="primary" @click="search">
              <template #icon><Search /></template>
              {{ $t('member.operation.search') }}
            </el-button>
            <el-button @click="resetQuery">
              <template #icon><Refresh /></template>
              {{ $t('member.operation.reset') }}
            </el-button>
            <el-button @click="openExport">
              <template #icon><Download /></template>
              {{ $t('member.operation.export') }}
            </el-button>
          </el-space>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card class="general-card">
      <template #header>{{ $t('menu.member.list') }}</template>
      <el-row style="margin-bottom: 16px">
        <el-button
          v-permission="['official.member.add']"
          type="primary"
          @click="handleAdd"
        >
          <template #icon><Plus /></template>
          {{ $t('member.operation.add') }}
        </el-button>
      </el-row>
      <el-table v-loading="loading" row-key="id" :data="renderData" border>
        <el-table-column :label="$t('member.columns.avatar')" width="90">
          <template #default="{ row }">
            <el-avatar :size="46" :src="row.avatar">
              {{ row.nickname?.slice(0, 1) }}
            </el-avatar>
          </template>
        </el-table-column>
        <el-table-column
          prop="nickname"
          :label="$t('member.columns.nickname')"
          width="140"
        />
        <el-table-column
          prop="account"
          :label="$t('member.columns.account')"
          width="160"
        />
        <el-table-column
          prop="mobile"
          :label="$t('member.columns.mobile')"
          width="140"
        />
        <el-table-column
          prop="channel"
          :label="$t('member.columns.channel')"
          width="130"
        />
        <el-table-column
          prop="create_time"
          :label="$t('member.columns.createTime')"
          width="180"
        />
        <el-table-column :label="$t('member.columns.status')" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'">
              {{
                row.status === 1
                  ? $t('member.status.normal')
                  : $t('member.status.disabled')
              }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          prop="balance"
          :label="$t('member.columns.balance')"
          width="100"
        />
        <el-table-column
          :label="$t('member.columns.operations')"
          width="260"
          fixed="right"
        >
          <template #default="{ row }">
            <el-space>
              <el-button
                v-permission="['official.member.detail']"
                link
                size="small"
                @click="openDetail(row)"
              >
                {{ $t('member.operation.detail') }}
              </el-button>
              <el-button
                v-permission="['official.member.update-status']"
                link
                size="small"
                :type="row.status === 1 ? 'danger' : 'primary'"
                @click="handleToggleStatus(row)"
              >
                {{
                  row.status === 1
                    ? $t('member.operation.disable')
                    : $t('member.operation.enable')
                }}
              </el-button>
            </el-space>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-model:current-page="pagination.current"
        v-model:page-size="pagination.pageSize"
        :total="pagination.total"
        :page-sizes="[10, 15, 30, 50]"
        layout="total, sizes, prev, pager, next"
        style="margin-top: 16px; justify-content: flex-end"
        @current-change="onPageChange"
        @size-change="onPageSizeChange"
      />
    </el-card>

    <el-dialog
      v-model="modalVisible"
      :title="$t('member.modal.addTitle')"
      :close-on-click-modal="false"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="formRules"
        label-position="top"
      >
        <el-form-item prop="nickname" :label="$t('member.field.nickname')">
          <el-input
            v-model="form.nickname"
            :placeholder="$t('member.field.nickname.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="mobile" :label="$t('member.field.mobile')">
          <el-input v-model="form.mobile" />
        </el-form-item>
        <el-form-item prop="email" :label="$t('member.field.email')">
          <el-input v-model="form.email" />
        </el-form-item>
        <el-form-item prop="sex" :label="$t('member.field.sex')">
          <el-radio-group v-model="form.sex">
            <el-radio :value="0">{{ $t('member.sex.unknown') }}</el-radio>
            <el-radio :value="1">{{ $t('member.sex.male') }}</el-radio>
            <el-radio :value="2">{{ $t('member.sex.female') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item prop="tag_ids" :label="$t('member.field.tags')">
          <el-select v-model="form.tag_ids" multiple clearable>
            <el-option
              v-for="tag in tagOptions"
              :key="tag.id"
              :label="tag.name"
              :value="tag.id"
            >
              {{ tag.name }}
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item prop="status" :label="$t('member.field.status')">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">{{ $t('member.status.normal') }}</el-radio>
            <el-radio :value="0">{{ $t('member.status.disabled') }}</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="modalVisible = false">
          {{ $t('userSetting.cancel') }}
        </el-button>
        <el-button
          type="primary"
          :loading="submitLoading"
          @click="handleSubmit"
        >
          {{ $t('userSetting.save') }}
        </el-button>
      </template>
    </el-dialog>

    <el-drawer
      v-model="detailDrawerVisible"
      size="640px"
      :title="$t('member.detail.title')"
      destroy-on-close
    >
      <div v-loading="detailLoading" style="width: 100%">
        <div class="detail-summary">
          <div class="detail-summary-item">
            <span class="detail-label">{{ $t('member.detail.avatar') }}</span>
            <el-avatar :size="58" :src="detail.avatar">
              {{ detail.nickname?.slice(0, 1) }}
            </el-avatar>
          </div>
          <div class="detail-summary-item">
            <span class="detail-label">{{ $t('member.detail.balance') }}</span>
            <div>
              ¥{{ Number(detail.user_money || 0).toFixed(2) }}
              <el-button
                v-permission="['official.member.balance.adjust']"
                link
                size="small"
                @click="openBalanceFromDetail"
              >
                {{ $t('member.operation.adjustBalance') }}
              </el-button>
            </div>
          </div>
        </div>

        <el-descriptions :column="1" border size="large">
          <el-descriptions-item :label="$t('member.detail.nickname')">
            {{ detail.nickname || '-' }}
          </el-descriptions-item>
          <el-descriptions-item :label="$t('member.detail.account')">
            {{ detail.account || '-' }}
            <el-button
              v-permission="['official.member.edit']"
              link
              size="small"
              @click="openFieldEdit('account')"
            >
              {{ $t('member.operation.edit') }}
            </el-button>
          </el-descriptions-item>
          <el-descriptions-item :label="$t('member.detail.realName')">
            {{ detail.real_name || '-' }}
            <el-button
              v-permission="['official.member.edit']"
              link
              size="small"
              @click="openFieldEdit('real_name')"
            >
              {{ $t('member.operation.edit') }}
            </el-button>
          </el-descriptions-item>
          <el-descriptions-item :label="$t('member.detail.sex')">
            {{ detailSexLabel }}
            <el-button
              v-permission="['official.member.edit']"
              link
              size="small"
              @click="openFieldEdit('sex')"
            >
              {{ $t('member.operation.edit') }}
            </el-button>
          </el-descriptions-item>
          <el-descriptions-item :label="$t('member.detail.mobile')">
            {{ detail.mobile || '-' }}
            <el-button
              v-permission="['official.member.edit']"
              link
              size="small"
              @click="openFieldEdit('mobile')"
            >
              {{ $t('member.operation.edit') }}
            </el-button>
          </el-descriptions-item>
          <el-descriptions-item :label="$t('member.detail.channel')">
            {{ detail.channel || '-' }}
          </el-descriptions-item>
          <el-descriptions-item :label="$t('member.detail.createTime')">
            {{ detail.create_time || '-' }}
          </el-descriptions-item>
          <el-descriptions-item :label="$t('member.detail.loginTime')">
            {{ detail.login_time || '-' }}
          </el-descriptions-item>
        </el-descriptions>
      </div>
    </el-drawer>

    <el-dialog
      v-model="fieldModalVisible"
      :title="$t('member.detail.editTitle', { field: fieldLabel })"
      :close-on-click-modal="false"
    >
      <el-form :model="fieldForm" label-position="top">
        <el-form-item :label="fieldLabel" required>
          <el-select v-if="fieldForm.field === 'sex'" v-model="fieldForm.value">
            <el-option :value="0" :label="$t('member.sex.unknown')" />
            <el-option :value="1" :label="$t('member.sex.male')" />
            <el-option :value="2" :label="$t('member.sex.female')" />
          </el-select>
          <el-input
            v-else
            v-model="fieldForm.value"
            :maxlength="fieldForm.field === 'mobile' ? 11 : 32"
            show-word-limit
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="fieldModalVisible = false">
          {{ $t('userSetting.cancel') }}
        </el-button>
        <el-button
          type="primary"
          :loading="fieldLoading"
          @click="submitFieldEdit"
        >
          {{ $t('userSetting.save') }}
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="balanceModalVisible"
      :title="$t('member.balance.modalTitle')"
      :close-on-click-modal="false"
    >
      <el-form
        ref="balanceFormRef"
        :model="balanceForm"
        :rules="balanceRules"
        label-position="top"
      >
        <el-form-item :label="$t('member.balance.current')">
          ¥{{ Number(detail.user_money || 0).toFixed(2) }}
        </el-form-item>
        <el-form-item prop="action" :label="$t('member.balance.action')">
          <el-radio-group v-model="balanceForm.action">
            <el-radio :value="1">{{ $t('member.balance.increase') }}</el-radio>
            <el-radio :value="2">{{ $t('member.balance.decrease') }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item prop="num" :label="$t('member.field.amount')">
          <el-input-number
            v-model="balanceForm.num"
            :placeholder="$t('member.field.amount.placeholder')"
            style="width: 100%"
            :precision="2"
            :min="0.01"
            :step="1"
          />
        </el-form-item>
        <el-form-item :label="$t('member.balance.after')">
          ¥{{ adjustedBalance.toFixed(2) }}
        </el-form-item>
        <el-form-item prop="remark" :label="$t('member.field.remark')">
          <el-input
            type="textarea"
            v-model="balanceForm.remark"
            :placeholder="$t('member.field.remark.placeholder')"
            maxlength="128"
            show-word-limit
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="balanceModalVisible = false">
          {{ $t('userSetting.cancel') }}
        </el-button>
        <el-button
          type="primary"
          :loading="balanceLoading"
          @click="handleBalanceSubmit"
        >
          {{ $t('userSetting.save') }}
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="exportVisible"
      :title="$t('member.export.title')"
      :close-on-click-modal="false"
      width="540px"
    >
      <div v-loading="exportInfoLoading" style="width: 100%">
        <el-form :model="exportForm" label-position="top">
          <el-alert type="info" style="margin-bottom: 16px">
            {{
              $t('member.export.summary', {
                count: exportInfo.count,
                pages: exportInfo.sum_page,
                size: exportInfo.page_size,
              })
            }}
            <br />
            {{
              $t('member.export.limit', {
                pages: exportInfo.max_page,
                count: exportInfo.all_max_size,
              })
            }}
          </el-alert>
          <el-form-item prop="page_type" :label="$t('member.export.range')">
            <el-radio-group v-model="exportForm.page_type">
              <el-radio :value="0">{{ $t('member.export.all') }}</el-radio>
              <el-radio :value="1">{{ $t('member.export.pages') }}</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item
            v-if="exportForm.page_type === 1"
            :label="$t('member.export.pageRange')"
          >
            <el-space>
              <el-input-number
                v-model="exportForm.page_start"
                :min="1"
                :max="exportInfo.sum_page || 1"
              />
              <span>{{ $t('member.export.to') }}</span>
              <el-input-number
                v-model="exportForm.page_end"
                :min="exportForm.page_start"
                :max="exportInfo.sum_page || 1"
              />
            </el-space>
          </el-form-item>
          <el-form-item prop="file_name" :label="$t('member.export.fileName')">
            <el-input v-model="exportForm.file_name" maxlength="100" />
          </el-form-item>
        </el-form>
      </div>
      <template #footer>
        <el-button @click="exportVisible = false">
          {{ $t('userSetting.cancel') }}
        </el-button>
        <el-button
          type="primary"
          :loading="exportLoading"
          @click="handleExport"
        >
          {{ $t('member.export.confirm') }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, onMounted, reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { Download, Plus, Refresh, Search } from '@element-plus/icons-vue';
  import { ElMessage } from 'element-plus';
  import type { FormInstance } from 'element-plus';
  import useLoading from '@/hooks/loading';
  import { hasPermission } from '@/hooks/permission';
  import {
    addMember,
    adjustMemberMoney,
    exportMembers,
    getMemberDetail,
    getMemberExportInfo,
    getMemberList,
    getMemberTagList,
    updateMemberField,
    updateMemberStatus,
    type MemberDetail,
    type MemberEditableField,
    type MemberExportInfo,
    type MemberForm,
    type MemberListParams,
    type MemberRecord,
    type MemberTagRecord,
  } from '@/modules/official-member/api';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<MemberRecord[]>([]);
  const tagOptions = ref<MemberTagRecord[]>([]);

  const queryParams = reactive({
    keyword: '',
    channel: '' as number | '',
    createTime: [] as string[],
  });
  const channelOptions = computed(() => [
    { value: 1, label: t('member.channel.wechatMmp') },
    { value: 2, label: t('member.channel.wechatOa') },
    { value: 3, label: t('member.channel.h5') },
    { value: 4, label: t('member.channel.pc') },
    { value: 5, label: t('member.channel.ios') },
    { value: 6, label: t('member.channel.android') },
  ]);
  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
  });
  const listParams = (page = pagination.current): MemberListParams => ({
    keyword: queryParams.keyword || undefined,
    channel: queryParams.channel === '' ? undefined : queryParams.channel,
    create_time_start: queryParams.createTime[0]
      ? `${queryParams.createTime[0]} 00:00:00`
      : undefined,
    create_time_end: queryParams.createTime[1]
      ? `${queryParams.createTime[1]} 23:59:59`
      : undefined,
    page_no: page,
    page_size: pagination.pageSize,
  });

  const fetchData = async (page = 1) => {
    setLoading(true);
    try {
      const { data } = await getMemberList(listParams(page));
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };
  const search = () => fetchData(1);
  const resetQuery = () => {
    queryParams.keyword = '';
    queryParams.channel = '';
    queryParams.createTime = [];
    fetchData(1);
  };
  const onPageChange = (current: number) => fetchData(current);
  const onPageSizeChange = (pageSize: number) => {
    pagination.pageSize = pageSize;
    fetchData(1);
  };

  const modalVisible = ref(false);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();
  const defaultForm = (): MemberForm => ({
    id: undefined,
    nickname: '',
    mobile: '',
    email: '',
    sex: 0,
    status: 1,
    tag_ids: [],
  });
  const form = reactive<MemberForm>(defaultForm());
  const formRules = {
    nickname: [
      { required: true, message: t('member.field.nickname.required') },
    ],
  };
  const resetForm = (patch: Partial<MemberForm> = {}) =>
    Object.assign(form, defaultForm(), patch);
  const handleAdd = () => {
    resetForm();
    modalVisible.value = true;
  };
  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return false;
    submitLoading.value = true;
    try {
      await addMember({ ...form });
      ElMessage.success(t('member.tip.success'));
      modalVisible.value = false;
      await fetchData(pagination.current);
      return true;
    } finally {
      submitLoading.value = false;
    }
  };
  const handleToggleStatus = async (record: MemberRecord) => {
    const next = record.status === 1 ? 0 : 1;
    await updateMemberStatus(record.id, next);
    record.status = next;
    record.is_disable = next === 1 ? 0 : 1;
    ElMessage.success(t('member.tip.success'));
  };

  const emptyDetail = (): MemberDetail => ({
    id: 0,
    sn: '',
    account: '',
    nickname: '',
    avatar: '',
    real_name: '',
    sex: 0,
    mobile: '',
    create_time: '',
    login_time: '',
    channel: '',
    user_money: 0,
    balance: 0,
  });
  const detailDrawerVisible = ref(false);
  const detailLoading = ref(false);
  const detail = reactive<MemberDetail>(emptyDetail());
  const detailSexLabel = computed(
    () =>
      ({
        0: t('member.sex.unknown'),
        1: t('member.sex.male'),
        2: t('member.sex.female'),
      }[detail.sex] || t('member.sex.unknown'))
  );
  const refreshDetail = async () => {
    if (!detail.id) return;
    const { data } = await getMemberDetail(detail.id);
    Object.assign(detail, data);
  };
  const openDetail = async (record: MemberRecord) => {
    Object.assign(detail, emptyDetail(), { id: record.id });
    detailDrawerVisible.value = true;
    detailLoading.value = true;
    try {
      await refreshDetail();
    } finally {
      detailLoading.value = false;
    }
  };

  const fieldModalVisible = ref(false);
  const fieldLoading = ref(false);
  const fieldForm = reactive<{
    field: MemberEditableField;
    value: MemberDetail[MemberEditableField];
  }>({ field: 'account', value: '' });
  const fieldLabel = computed(
    () =>
      ({
        account: t('member.detail.account'),
        real_name: t('member.detail.realName'),
        sex: t('member.detail.sex'),
        mobile: t('member.detail.mobile'),
      }[fieldForm.field])
  );
  const openFieldEdit = (field: MemberEditableField) => {
    fieldForm.field = field;
    fieldForm.value = detail[field];
    fieldModalVisible.value = true;
  };
  const submitFieldEdit = async () => {
    if (fieldForm.value === '') {
      ElMessage.warning(t('member.detail.valueRequired'));
      return false;
    }
    fieldLoading.value = true;
    try {
      await updateMemberField({
        id: detail.id,
        field: fieldForm.field,
        value: fieldForm.value,
      });
      ElMessage.success(t('member.tip.success'));
      fieldModalVisible.value = false;
      await Promise.all([refreshDetail(), fetchData(pagination.current)]);
      return true;
    } catch {
      return false;
    } finally {
      fieldLoading.value = false;
    }
  };

  const balanceModalVisible = ref(false);
  const balanceLoading = ref(false);
  const balanceFormRef = ref<FormInstance>();
  const balanceForm = reactive<{ action: 1 | 2; num: number; remark: string }>({
    action: 1,
    num: 0,
    remark: '',
  });
  const balanceRules = {
    action: [{ required: true, message: t('member.balance.actionRequired') }],
    num: [{ required: true, message: t('member.field.amount.required') }],
  };
  const adjustedBalance = computed(
    () =>
      Number(detail.user_money || 0) +
      balanceForm.num * (balanceForm.action === 1 ? 1 : -1)
  );
  const openBalanceFromDetail = () => {
    balanceForm.action = 1;
    balanceForm.num = 0;
    balanceForm.remark = '';
    balanceModalVisible.value = true;
  };
  const handleBalanceSubmit = async () => {
    const valid = await balanceFormRef.value?.validate().catch(() => false);
    if (!valid) return false;
    if (balanceForm.num <= 0) {
      ElMessage.warning(t('member.field.amount.positive'));
      return false;
    }
    balanceLoading.value = true;
    try {
      await adjustMemberMoney({
        user_id: detail.id,
        action: balanceForm.action,
        num: balanceForm.num,
        remark: balanceForm.remark,
      });
      ElMessage.success(t('member.tip.success'));
      balanceModalVisible.value = false;
      await Promise.all([refreshDetail(), fetchData(pagination.current)]);
      return true;
    } catch {
      return false;
    } finally {
      balanceLoading.value = false;
    }
  };

  const emptyExportInfo = (): MemberExportInfo => ({
    count: 0,
    page_size: pagination.pageSize,
    sum_page: 0,
    max_page: 0,
    all_max_size: 0,
    page_start: 1,
    page_end: 1,
    file_name: '',
  });
  const exportVisible = ref(false);
  const exportInfoLoading = ref(false);
  const exportLoading = ref(false);
  const exportInfo = reactive<MemberExportInfo>(emptyExportInfo());
  const exportForm = reactive({
    page_type: 0 as 0 | 1,
    page_start: 1,
    page_end: 1,
    file_name: '',
  });
  const openExport = async () => {
    exportVisible.value = true;
    exportInfoLoading.value = true;
    try {
      const { data } = await getMemberExportInfo(listParams(1));
      Object.assign(exportInfo, data);
      exportForm.page_type = 0;
      exportForm.page_start = data.page_start;
      exportForm.page_end = data.page_end;
      exportForm.file_name = data.file_name;
    } finally {
      exportInfoLoading.value = false;
    }
  };
  const handleExport = async () => {
    if (exportInfoLoading.value) return false;
    if (
      exportForm.page_type === 1 &&
      exportForm.page_end < exportForm.page_start
    ) {
      ElMessage.error(t('member.export.invalidRange'));
      return false;
    }
    exportLoading.value = true;
    try {
      const { data } = await exportMembers({
        ...listParams(1),
        ...exportForm,
      });
      const link = document.createElement('a');
      link.href = data.url;
      link.download = data.file_name;
      link.rel = 'noopener';
      document.body.appendChild(link);
      link.click();
      link.remove();
      ElMessage.success(t('member.export.success'));
      exportVisible.value = false;
      return true;
    } finally {
      exportLoading.value = false;
    }
  };

  onMounted(async () => {
    const tagsPromise = hasPermission('official.member.tag.list')
      ? getMemberTagList()
      : Promise.resolve(null);
    const [, tagsRes] = await Promise.all([fetchData(1), tagsPromise]);
    tagOptions.value = tagsRes?.data ?? [];
  });
</script>

<script lang="ts">
  export default { name: 'MemberList' };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px;
  }

  .search-card {
    margin-bottom: 16px;
  }

  .detail-summary {
    display: flex;
    align-items: center;
    justify-content: space-around;
    padding: 24px;
    margin-bottom: 20px;
    background: var(--el-fill-color-light);
    border-radius: 4px;
  }

  .detail-summary-item {
    display: flex;
    flex-direction: column;
    gap: 10px;
    align-items: center;
  }

  .detail-label {
    color: var(--el-text-color-regular);
  }
</style>
