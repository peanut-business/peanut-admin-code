<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.dept']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.system.dept') }}</template>
      <el-form :model="queryParams" inline style="margin-bottom: 16px">
        <el-form-item prop="name" :label="$t('systemDept.search.name')">
          <el-input
            v-model="queryParams.name"
            :placeholder="$t('systemDept.search.name.placeholder')"
            clearable
            @keyup.enter="fetchData"
          />
        </el-form-item>
        <el-form-item prop="status" :label="$t('systemDept.search.status')">
          <el-select
            v-model="queryParams.status"
            :placeholder="$t('systemDept.search.status.all')"
            clearable
            style="width: 180px"
          >
            <el-option :value="1" :label="$t('systemDept.status.normal')">{{
              $t('systemDept.status.normal')
            }}</el-option>
            <el-option :value="0" :label="$t('systemDept.status.disabled')">{{
              $t('systemDept.status.disabled')
            }}</el-option>
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-space>
            <el-button type="primary" @click="fetchData">
              {{ $t('systemDept.operation.search') }}
            </el-button>
            <el-button @click="resetQuery">
              {{ $t('systemDept.operation.reset') }}
            </el-button>
          </el-space>
        </el-form-item>
      </el-form>
      <el-row style="margin-bottom: 16px">
        <el-col :span="12">
          <el-space>
            <el-button
              v-permission="['core.department.create']"
              type="primary"
              @click="handleAdd()"
            >
              <template #icon><Plus /></template>
              {{ $t('systemDept.operation.create') }}
            </el-button>
            <span>按层展开，每次最多20项</span>
          </el-space>
        </el-col>
      </el-row>
      <el-table
        :key="tableRevision"
        row-key="id"
        :loading="loading"
        :data="directory.tree.value"
        border
        :tree-props="{ children: 'children', hasChildren: 'hasChildren' }"
        @expand-change="expandDepartment"
      >
        <el-table-column prop="name" :label="$t('systemDept.columns.name')">
          <template #default="{ row }">
            {{ row.name }} <span v-if="row.structuralOnly">（路径）</span>
            <el-button
              v-if="!isSearch && row.hasChildren"
              link
              @click="loadChildren(row.id)"
              >展开子部门</el-button
            >
            <el-button
              v-if="!isSearch && directory.pages.value[row.id]?.hasMore"
              link
              @click="loadChildren(row.id, true)"
              >加载下一页</el-button
            >
          </template>
        </el-table-column>
        <el-table-column :label="$t('systemDept.columns.status')" width="110">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'">{{
              row.status_desc
            }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column
          prop="sort"
          :label="$t('systemDept.columns.sort')"
          width="100"
        />
        <el-table-column
          :label="$t('systemDept.columns.updateTime')"
          width="180"
        >
          <template #default="{ row }">{{
            formatTime(row.update_time)
          }}</template>
        </el-table-column>
        <el-table-column
          :label="$t('systemDept.columns.operations')"
          width="220"
        >
          <template #default="{ row }">
            <el-space v-if="!row.structuralOnly">
              <el-button
                v-permission="['core.department.create']"
                link
                size="small"
                @click="handleAdd(row)"
                >{{ $t('systemDept.operation.addChild') }}</el-button
              >
              <el-button
                v-permission="['core.department.update']"
                link
                size="small"
                @click="handleEdit(row)"
                >{{ $t('systemDept.operation.edit') }}</el-button
              >
              <el-popconfirm
                :title="$t('systemDept.delete.confirm')"
                @confirm="handleDelete(row)"
              >
                <template #reference>
                  <el-button
                    v-permission="['core.department.archive']"
                    link
                    type="danger"
                    size="small"
                    >{{ $t('systemDept.operation.delete') }}</el-button
                  >
                </template>
              </el-popconfirm>
            </el-space>
          </template>
        </el-table-column>
      </el-table>
      <div
        >匹配 {{ directory.pages.value[0]?.count ?? 0 }} 项；祖先路径不计数</div
      >
      <el-button
        v-if="directory.pages.value[0]?.hasMore"
        :loading="directory.busy.value[0]"
        @click="directory.read(0, true)"
        >加载下一页</el-button
      >
      <el-button v-if="directory.error.value" @click="fetchData">{{
        directory.error.value
      }}</el-button>
    </el-card>

    <el-dialog
      v-model="modalVisible"
      :title="
        isEdit
          ? $t('systemDept.modal.editTitle')
          : $t('systemDept.modal.addTitle')
      "
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="pid" :label="$t('systemDept.field.pid')">
          <DepartmentPicker
            v-model="form.pid"
            :key="pickerRevision"
            :root-label="$t('systemDept.field.pid.root')"
            :disabled="isRootDepartmentEdit || (isEdit && !canMoveDepartment)"
          />
        </el-form-item>
        <el-form-item prop="name" :label="$t('systemDept.field.name')">
          <el-input
            v-model="form.name"
            maxlength="30"
            :placeholder="$t('systemDept.field.name.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="leader" :label="$t('systemDept.field.leader')">
          <el-input
            v-model="form.leader"
            maxlength="30"
            :placeholder="$t('systemDept.field.leader.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="mobile" :label="$t('systemDept.field.mobile')">
          <el-input
            v-model="form.mobile"
            :placeholder="$t('systemDept.field.mobile.placeholder')"
          />
        </el-form-item>
        <el-form-item prop="sort" :label="$t('systemDept.field.sort')">
          <el-input-number
            v-model="form.sort"
            :min="0"
            :max="9999"
            style="width: 160px"
          />
        </el-form-item>
        <el-form-item prop="status" :label="$t('systemDept.field.status')">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="modalVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitLoading" @click="handleSubmit"
          >保存</el-button
        >
      </template>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, ref, reactive } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance } from 'element-plus';
  import { Plus } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import {
    getDeptList,
    getDeptDetail,
    addDept,
    editDept,
    deleteDept,
    type DeptForm,
    type DirectoryDepartment,
  } from '@/api/system/dept';
  import { hasPermission } from '@/hooks/permission';
  import {
    DepartmentPicker,
    useDepartmentDirectory,
    initialParentDepartmentId,
    ROOT_DEPARTMENT_ID,
  } from './parent-options';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const directory = useDepartmentDirectory(getDeptList);
  const tableRevision = ref(0);
  const pickerRevision = ref(0);
  const isSearch = ref(false);
  const queryParams = reactive<{ name: string; status: number | '' }>({
    name: '',
    status: '',
  });

  const fetchData = async () => {
    setLoading(true);
    try {
      tableRevision.value += 1;
      isSearch.value = !!queryParams.name.trim() || queryParams.status !== '';
      await directory.reset({
        name: queryParams.name || undefined,
        status: queryParams.status === '' ? undefined : queryParams.status,
      });
    } finally {
      setLoading(false);
    }
  };

  const resetQuery = () => {
    queryParams.name = '';
    queryParams.status = '';
    fetchData();
  };

  const loadChildren = (id: number, next = false) => directory.read(id, next);
  const expandDepartment = (row: DirectoryDepartment, expanded: boolean) => {
    if (expanded && !isSearch.value && !directory.pages.value[row.id])
      loadChildren(row.id);
  };

  const formatTime = (value?: number | string): string => {
    if (!value) return '-';
    const date = new Date(typeof value === 'number' ? value * 1000 : value);
    return Number.isNaN(date.getTime())
      ? '-'
      : date.toLocaleString('zh-CN', { hour12: false });
  };

  fetchData();

  // ---- 弹窗 & 表单 ----
  const modalVisible = ref(false);
  const isEdit = ref(false);
  const isRootDepartmentEdit = ref(false);
  const canMoveDepartment = computed(() =>
    hasPermission('core.department.move')
  );
  const originalParentDepartmentId = ref<number | undefined>();
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();

  const defaultForm = (): DeptForm => ({
    id: undefined,
    pid: ROOT_DEPARTMENT_ID,
    name: '',
    leader: '',
    mobile: '',
    sort: 0,
    status: 1,
    is_disable: 0,
  });
  const form = reactive<DeptForm>(defaultForm());

  const rules = {
    name: [{ required: true, message: t('systemDept.field.name.required') }],
    pid: [{ required: true, message: t('systemDept.field.pid.required') }],
    mobile: [
      {
        validator: (
          _rule: unknown,
          value: string,
          callback: (error?: string | Error) => void
        ) => {
          if (!value || /^1[3-9]\d{9}$/.test(value)) callback();
          else callback(new Error(t('systemDept.field.mobile.invalid')));
        },
      },
    ],
  };

  const resetForm = (patch: Partial<DeptForm> = {}) => {
    Object.assign(form, defaultForm(), patch);
  };

  const handleAdd = async (parent?: DirectoryDepartment) => {
    isEdit.value = false;
    isRootDepartmentEdit.value = false;
    originalParentDepartmentId.value = undefined;
    pickerRevision.value += 1;
    resetForm({ pid: initialParentDepartmentId(parent?.id) });
    modalVisible.value = true;
  };

  const handleEdit = async (record: DirectoryDepartment) => {
    isEdit.value = true;
    isRootDepartmentEdit.value = record.pid === ROOT_DEPARTMENT_ID;
    const { data } = await getDeptDetail(record.id);
    pickerRevision.value += 1;
    resetForm({ ...data, children: undefined });
    originalParentDepartmentId.value = data.pid;
    modalVisible.value = true;
  };

  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    submitLoading.value = true;
    try {
      if (isEdit.value) {
        if (!canMoveDepartment.value) {
          form.pid = originalParentDepartmentId.value ?? form.pid;
        }
        await editDept(form);
      } else {
        await addDept(form);
      }
      ElMessage.success(t('systemDept.tip.success'));
      modalVisible.value = false;
      await fetchData();
    } catch {
      // The shared Axios interceptor already shows the server error; keep the form open and its values intact.
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: DirectoryDepartment) => {
    await deleteDept(record.id);
    ElMessage.success(t('systemDept.tip.success'));
    await fetchData();
  };
</script>

<script lang="ts">
  export default {
    name: 'tenant.departments.list',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }
</style>
