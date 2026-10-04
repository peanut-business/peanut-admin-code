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
              v-permission="['dept/add']"
              type="primary"
              @click="handleAdd()"
            >
              <template #icon><Plus /></template>
              {{ $t('systemDept.operation.create') }}
            </el-button>
            <el-button @click="toggleExpand">
              {{ $t('systemDept.operation.expand') }}
            </el-button>
          </el-space>
        </el-col>
      </el-row>
      <el-table
        :key="isExpanded ? 'expanded' : 'collapsed'"
        row-key="id"
        :loading="loading"
        :data="renderData"
        border
        :default-expand-all="isExpanded"
        :tree-props="{ children: 'children' }"
      >
        <el-table-column prop="name" :label="$t('systemDept.columns.name')" />
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
            <el-space>
              <el-button
                v-permission="['dept/add']"
                link
                size="small"
                @click="handleAdd(row)"
                >{{ $t('systemDept.operation.addChild') }}</el-button
              >
              <el-button
                v-permission="['dept/edit']"
                link
                size="small"
                @click="handleEdit(row)"
                >{{ $t('systemDept.operation.edit') }}</el-button
              >
              <el-popconfirm
                v-if="row.pid !== 0"
                :title="$t('systemDept.delete.confirm')"
                @confirm="handleDelete(row)"
              >
                <template #reference>
                  <el-button
                    v-permission="['dept/delete']"
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
          <el-tree-select
            v-model="form.pid"
            :data="parentTree"
            :props="{ value: 'id', label: 'name', children: 'children' }"
            :placeholder="$t('systemDept.field.pid.placeholder')"
            :disabled="isRootDepartmentEdit"
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
    getDeptAll,
    getDeptDetail,
    addDept,
    editDept,
    deleteDept,
    type DeptRecord,
    type DeptForm,
  } from '@/api/system/dept';
  import {
    buildParentDepartmentOptions,
    initialParentDepartmentId,
    ROOT_DEPARTMENT_ID,
  } from './parent-options';

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<DeptRecord[]>([]);
  const deptOptions = ref<DeptRecord[]>([]);
  const expandedKeys = ref<number[]>([]);
  const isExpanded = ref(true);
  const queryParams = reactive<{ name: string; status: number | '' }>({
    name: '',
    status: '',
  });

  const fetchData = async () => {
    setLoading(true);
    try {
      const { data } = await getDeptList({
        name: queryParams.name || undefined,
        status: queryParams.status === '' ? undefined : queryParams.status,
      });
      renderData.value = data;
      if (isExpanded.value) {
        expandedKeys.value = collectIds(data);
      }
    } finally {
      setLoading(false);
    }
  };

  const resetQuery = () => {
    queryParams.name = '';
    queryParams.status = '';
    fetchData();
  };

  const collectIds = (nodes: DeptRecord[]): number[] =>
    nodes.flatMap((node) => [node.id, ...collectIds(node.children || [])]);

  const toggleExpand = () => {
    isExpanded.value = !isExpanded.value;
    expandedKeys.value = isExpanded.value ? collectIds(renderData.value) : [];
  };

  const formatTime = (value?: number | string): string => {
    if (!value) return '-';
    if (typeof value === 'string') return value;
    return new Date(value * 1000).toLocaleString('zh-CN', { hour12: false });
  };

  fetchData();

  // ---- 上级部门选择树：顶级选项 + 正常部门 ----
  const parentTree = computed(() =>
    buildParentDepartmentOptions(
      deptOptions.value,
      t('systemDept.field.pid.root')
    )
  );

  const loadDeptOptions = async () => {
    const { data } = await getDeptAll();
    deptOptions.value = data;
  };

  // ---- 弹窗 & 表单 ----
  const modalVisible = ref(false);
  const isEdit = ref(false);
  const isRootDepartmentEdit = ref(false);
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

  const handleAdd = async (parent?: DeptRecord) => {
    isEdit.value = false;
    isRootDepartmentEdit.value = false;
    await loadDeptOptions();
    resetForm({ pid: initialParentDepartmentId(parent?.id) });
    modalVisible.value = true;
  };

  const handleEdit = async (record: DeptRecord) => {
    isEdit.value = true;
    isRootDepartmentEdit.value = record.pid === ROOT_DEPARTMENT_ID;
    const [{ data }] = await Promise.all([
      getDeptDetail(record.id),
      loadDeptOptions(),
    ]);
    resetForm({ ...data, children: undefined });
    modalVisible.value = true;
  };

  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return;
    submitLoading.value = true;
    try {
      if (isEdit.value) {
        await editDept(form);
      } else {
        await addDept(form);
      }
      ElMessage.success(t('systemDept.tip.success'));
      modalVisible.value = false;
      await fetchData();
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: DeptRecord) => {
    await deleteDept(record.id);
    ElMessage.success(t('systemDept.tip.success'));
    await fetchData();
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemDept',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }
</style>
