<template>
  <div class="container">
    <Breadcrumb :items="['menu.system', 'menu.system.role']" />
    <el-card class="general-card">
      <template #header>{{ $t('menu.system.role') }}</template>
      <el-row style="margin-bottom: 16px">
        <el-col :span="12">
          <el-space>
            <el-button
              v-permission="['role/add']"
              type="primary"
              @click="handleAdd"
            >
              <template #icon><Plus /></template>
              {{ $t('systemRole.operation.create') }}
            </el-button>
            <el-button @click="fetchData(pagination.current)">
              <template #icon><Refresh /></template>
              {{ $t('systemRole.operation.refresh') }}
            </el-button>
          </el-space>
        </el-col>
      </el-row>

      <el-table row-key="id" :loading="loading" :data="renderData" border>
        <el-table-column
          prop="id"
          :label="$t('systemRole.columns.id')"
          width="80"
        />
        <el-table-column
          prop="name"
          :label="$t('systemRole.columns.name')"
          width="150"
        />
        <el-table-column
          prop="desc"
          :label="$t('systemRole.columns.desc')"
          show-overflow-tooltip
        />
        <el-table-column
          prop="sort"
          :label="$t('systemRole.columns.sort')"
          width="90"
        />
        <el-table-column
          prop="num"
          :label="$t('systemRole.columns.num')"
          width="120"
        />
        <el-table-column
          :label="$t('systemRole.columns.createTime')"
          width="180"
        >
          <template #default="{ row }">{{
            formatTime(row.create_time)
          }}</template>
        </el-table-column>
        <el-table-column
          :label="$t('systemRole.columns.operations')"
          width="220"
          fixed="right"
        >
          <template #default="{ row }">
            <el-space>
              <el-button
                v-permission="['role/edit']"
                link
                size="small"
                @click="handleEdit(row)"
                >{{ $t('systemRole.operation.edit') }}</el-button
              >
              <el-button
                v-permission="['role/edit']"
                link
                size="small"
                @click="handleAuth(row)"
                >{{ $t('systemRole.operation.permission') }}</el-button
              >
              <el-popconfirm
                :title="$t('systemRole.delete.confirm')"
                @confirm="handleDelete(row)"
              >
                <template #reference
                  ><el-button
                    v-permission="['role/delete']"
                    link
                    type="danger"
                    size="small"
                    >{{ $t('systemRole.operation.delete') }}</el-button
                  ></template
                >
              </el-popconfirm>
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
      :title="
        isEdit
          ? $t('systemRole.modal.editTitle')
          : $t('systemRole.modal.addTitle')
      "
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top">
        <el-form-item prop="name" :label="$t('systemRole.field.name')">
          <el-input
            v-model="form.name"
            :placeholder="$t('systemRole.field.name.placeholder')"
            clearable
          />
        </el-form-item>
        <el-form-item prop="desc" :label="$t('systemRole.field.desc')">
          <el-input
            type="textarea"
            v-model="form.desc"
            :placeholder="$t('systemRole.field.desc.placeholder')"
            :autosize="{ minRows: 4, maxRows: 6 }"
            maxlength="200"
            show-word-limit
          />
        </el-form-item>
        <el-form-item prop="sort" :label="$t('systemRole.field.sort')">
          <el-input-number
            v-model="form.sort"
            :min="0"
            :max="9999"
            style="width: 160px"
          />
        </el-form-item>
      </el-form>
      <template #footer
        ><el-button @click="modalVisible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="submitLoading"
          @click="handleSubmit"
          >保存</el-button
        ></template
      >
    </el-dialog>

    <el-dialog
      v-model="authVisible"
      :title="$t('systemRole.modal.authTitle')"
      :close-on-click-modal="false"
      width="560px"
    >
      <div v-loading="authLoading" style="width: 100%">
        <div class="auth-toolbar">
          <el-checkbox
            :model-value="expandedKeys.length > 0"
            @change="handleExpandAll"
          >
            {{ $t('systemRole.auth.expand') }}
          </el-checkbox>
          <el-checkbox :model-value="isAllChecked" @change="handleSelectAll">
            {{ $t('systemRole.auth.selectAll') }}
          </el-checkbox>
          <el-checkbox v-model="parentLinked" @change="handleLinkageChange">
            {{ $t('systemRole.auth.parentLinked') }}
          </el-checkbox>
        </div>
        <div class="auth-tree">
          <el-tree
            :key="treeRenderKey"
            :default-expanded-keys="expandedKeys"
            :default-checked-keys="authCheckedKeys"
            :data="treeData"
            node-key="id"
            :props="{ label: 'name', children: 'children' }"
            show-checkbox
            check-strictly
            @node-expand="handleTreeExpand"
            @node-collapse="handleTreeCollapse"
            @check="handleTreeCheck"
          />
        </div>
      </div>
      <template #footer
        ><el-button @click="authVisible = false">取消</el-button
        ><el-button
          type="primary"
          :loading="authSubmitLoading"
          @click="handleAuthSubmit"
          >保存</el-button
        ></template
      >
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
  import { computed, reactive, ref } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { ElMessage } from 'element-plus';
  import type { FormInstance } from 'element-plus';
  import { Plus, Refresh } from '@element-plus/icons-vue';
  import useLoading from '@/hooks/loading';
  import { getMenuAll, type MenuRecord } from '@/api/system/menu';
  import {
    addRole,
    deleteRole,
    editRole,
    getRoleDetail,
    getRoleList,
    type RoleAuthForm,
    type RoleBaseForm,
    type RoleRecord,
  } from '@/api/system/role';

  type AuthCheckState = 0 | 1 | 2;
  type CheckboxValue = boolean | Array<string | number | boolean>;
  type AuthCheckEvent = {
    checked?: boolean;
    node?: MenuRecord & { id?: number };
  };
  type AuthTreeCheckedInfo = {
    checked: boolean;
    checkedKeys: Array<number | string>;
  };

  const { t } = useI18n();
  const { loading, setLoading } = useLoading(true);
  const renderData = ref<RoleRecord[]>([]);

  const pagination = reactive({
    current: 1,
    pageSize: 15,
    total: 0,
    showTotal: true,
    showPageSize: true,
  });

  const fetchData = async (page = 1) => {
    setLoading(true);
    try {
      const { data } = await getRoleList({
        page_no: page,
        page_size: pagination.pageSize,
      });
      renderData.value = data.lists;
      pagination.current = data.pageNo;
      pagination.total = data.count;
    } finally {
      setLoading(false);
    }
  };

  const onPageChange = (current: number) => fetchData(current);
  const onPageSizeChange = (pageSize: number) => {
    pagination.pageSize = pageSize;
    fetchData(1);
  };

  const formatTime = (value?: number | string) => {
    if (!value) return '-';
    if (typeof value === 'number') {
      return new Date(value * 1000).toLocaleString();
    }
    return value;
  };

  fetchData();

  // ---- 新增/编辑基本信息 ----
  const modalVisible = ref(false);
  const isEdit = ref(false);
  const submitLoading = ref(false);
  const formRef = ref<FormInstance>();

  const defaultForm = (): RoleBaseForm => ({
    id: undefined,
    name: '',
    desc: '',
    sort: 0,
  });
  const form = reactive<RoleBaseForm>(defaultForm());

  const rules = {
    name: [{ required: true, message: t('systemRole.field.name.required') }],
  };

  const resetForm = (patch: Partial<RoleBaseForm> = {}) => {
    Object.assign(form, defaultForm(), patch);
  };

  const handleAdd = () => {
    isEdit.value = false;
    resetForm();
    modalVisible.value = true;
  };

  const handleEdit = (record: RoleRecord) => {
    isEdit.value = true;
    resetForm({
      id: record.id,
      name: record.name,
      desc: record.desc,
      sort: record.sort,
    });
    modalVisible.value = true;
  };

  const handleSubmit = async () => {
    const valid = await formRef.value?.validate().catch(() => false);
    if (!valid) return false;

    submitLoading.value = true;
    try {
      const payload: RoleBaseForm = {
        id: form.id,
        name: form.name,
        desc: form.desc,
        sort: form.sort,
      };
      if (isEdit.value) {
        await editRole(payload);
      } else {
        await addRole(payload);
      }
      ElMessage.success(t('systemRole.tip.success'));
      modalVisible.value = false;
      await fetchData(pagination.current);
      return true;
    } finally {
      submitLoading.value = false;
    }
  };

  const handleDelete = async (record: RoleRecord) => {
    await deleteRole(record.id);
    ElMessage.success(t('systemRole.tip.success'));
    await fetchData(pagination.current);
  };

  // ---- 独立分配权限 ----
  const authVisible = ref(false);
  const authLoading = ref(false);
  const authSubmitLoading = ref(false);
  const menuTree = ref<MenuRecord[]>([]);
  const treeData = computed(() => menuTree.value);
  const parentLinked = ref(true);
  const expandedKeys = ref<number[]>([]);
  const authCheckedKeys = ref<number[]>([]);
  const authHalfCheckedKeys = ref<number[]>([]);
  const authForm = reactive<RoleAuthForm>({
    id: 0,
    name: '',
    desc: '',
    sort: 0,
    menu_id: [],
  });

  // Element Plus exposes default tree state as initialization-only props. Re-key
  // the tree whenever our controlled permission state changes so the toolbar
  // actions (expand/select all) and linkage mode stay reflected in the UI.
  const treeRenderKey = computed(
    () =>
      `${parentLinked.value}-${expandedKeys.value.join(
        ','
      )}-${authCheckedKeys.value.join(',')}-${authHalfCheckedKeys.value.join(
        ','
      )}`
  );

  const handleTreeExpand = (data: MenuRecord) => {
    const id = Number(data.id);
    if (!expandedKeys.value.includes(id)) {
      expandedKeys.value = [...expandedKeys.value, id];
    }
  };

  const handleTreeCollapse = (data: MenuRecord) => {
    const id = Number(data.id);
    expandedKeys.value = expandedKeys.value.filter((key) => key !== id);
  };

  const allMenuIds = computed(() => {
    const ids: number[] = [];
    const visit = (nodes: MenuRecord[]) => {
      nodes.forEach((node) => {
        ids.push(node.id);
        if (node.children?.length) visit(node.children);
      });
    };
    visit(menuTree.value);
    return ids;
  });

  const menuNodeMap = computed(() => {
    const nodes = new Map<number, MenuRecord>();
    const visit = (items: MenuRecord[]) => {
      items.forEach((item) => {
        nodes.set(item.id, item);
        if (item.children?.length) visit(item.children);
      });
    };
    visit(menuTree.value);
    return nodes;
  });

  const isAllChecked = computed(
    () =>
      allMenuIds.value.length > 0 &&
      authCheckedKeys.value.length === allMenuIds.value.length
  );

  const orderKeys = (keys: Set<number>) =>
    allMenuIds.value.filter((id) => keys.has(id));

  const normalizeLinkedState = (menuIds: number[]) => {
    const selected = new Set(menuIds);
    const checked = new Set<number>();
    const halfChecked = new Set<number>();

    const visit = (node: MenuRecord): AuthCheckState => {
      const childStates = (node.children ?? []).map(visit);
      const selfSelected = selected.has(node.id);
      if (childStates.length === 0) {
        if (selfSelected) checked.add(node.id);
        return selfSelected ? 2 : 0;
      }

      const allChildrenChecked = childStates.every((state) => state === 2);
      const hasCheckedChild = childStates.some((state) => state !== 0);
      if (allChildrenChecked && (selfSelected || hasCheckedChild)) {
        checked.add(node.id);
        return 2;
      }
      if (hasCheckedChild) {
        halfChecked.add(node.id);
        return 1;
      }
      if (selfSelected) {
        checked.add(node.id);
        return 2;
      }
      return 0;
    };

    menuTree.value.forEach(visit);
    authCheckedKeys.value = orderKeys(checked);
    authHalfCheckedKeys.value = orderKeys(halfChecked);
  };

  const loadMenuTree = async () => {
    if (menuTree.value.length > 0) return;
    const { data } = await getMenuAll();
    menuTree.value = data;
  };

  const handleAuth = async (record: RoleRecord) => {
    authVisible.value = true;
    authLoading.value = true;
    try {
      const [{ data }] = await Promise.all([
        getRoleDetail(record.id),
        loadMenuTree(),
      ]);
      const menuIds = data.menu_id ?? data.menu_ids ?? [];
      Object.assign(authForm, {
        id: data.id,
        name: data.name,
        desc: data.desc,
        sort: data.sort,
        menu_id: [...menuIds],
      });
      parentLinked.value = true;
      expandedKeys.value = [];
      normalizeLinkedState(menuIds);
    } catch (error) {
      authVisible.value = false;
      throw error;
    } finally {
      authLoading.value = false;
    }
  };

  const setSubtreeChecked = (
    node: MenuRecord,
    checked: boolean,
    checkedSet: Set<number>,
    halfCheckedSet: Set<number>
  ) => {
    if (checked) checkedSet.add(node.id);
    else checkedSet.delete(node.id);
    halfCheckedSet.delete(node.id);
    node.children?.forEach((child) =>
      setSubtreeChecked(child, checked, checkedSet, halfCheckedSet)
    );
  };

  const updateAncestors = (
    node: MenuRecord,
    checkedSet: Set<number>,
    halfCheckedSet: Set<number>
  ) => {
    let parent = menuNodeMap.value.get(node.pid);
    while (parent) {
      const children = parent.children ?? [];
      const allChildrenChecked =
        children.length > 0 &&
        children.every((child) => checkedSet.has(child.id));
      const hasCheckedChild = children.some(
        (child) => checkedSet.has(child.id) || halfCheckedSet.has(child.id)
      );

      if (allChildrenChecked) {
        checkedSet.add(parent.id);
        halfCheckedSet.delete(parent.id);
      } else if (hasCheckedChild) {
        checkedSet.delete(parent.id);
        halfCheckedSet.add(parent.id);
      } else {
        checkedSet.delete(parent.id);
        halfCheckedSet.delete(parent.id);
      }
      parent = menuNodeMap.value.get(parent.pid);
    }
  };

  const handleAuthCheck = (
    rawCheckedKeys: Array<number | string>,
    event: AuthCheckEvent
  ) => {
    const keys = rawCheckedKeys.map(Number);
    if (!parentLinked.value) {
      authCheckedKeys.value = keys;
      authHalfCheckedKeys.value = [];
      return;
    }

    const nodeId = Number(event.node?.id);
    const node = menuNodeMap.value.get(nodeId);
    if (!node) return;

    const checkedSet = new Set(authCheckedKeys.value);
    const halfCheckedSet = new Set(authHalfCheckedKeys.value);
    const checked = event.checked ?? keys.includes(nodeId);
    setSubtreeChecked(node, checked, checkedSet, halfCheckedSet);
    updateAncestors(node, checkedSet, halfCheckedSet);
    authCheckedKeys.value = orderKeys(checkedSet);
    authHalfCheckedKeys.value = orderKeys(halfCheckedSet);
  };

  const handleTreeCheck = (data: MenuRecord, checked: AuthTreeCheckedInfo) => {
    handleAuthCheck(checked.checkedKeys, {
      checked: checked.checked,
      node: data,
    });
  };

  const handleLinkageChange = (value: CheckboxValue) => {
    const linked = value === true;
    parentLinked.value = linked;
    if (linked) {
      normalizeLinkedState([
        ...authCheckedKeys.value,
        ...authHalfCheckedKeys.value,
      ]);
      return;
    }
    authCheckedKeys.value = orderKeys(
      new Set([...authCheckedKeys.value, ...authHalfCheckedKeys.value])
    );
    authHalfCheckedKeys.value = [];
  };

  const handleExpandAll = (value: CheckboxValue) => {
    const expanded = value === true;
    expandedKeys.value = expanded ? [...allMenuIds.value] : [];
  };

  const handleSelectAll = (value: CheckboxValue) => {
    const checked = value === true;
    authCheckedKeys.value = checked ? [...allMenuIds.value] : [];
    authHalfCheckedKeys.value = [];
  };

  const handleAuthSubmit = async () => {
    const menuIds = orderKeys(
      new Set([...authCheckedKeys.value, ...authHalfCheckedKeys.value])
    );
    if (menuIds.length === 0) {
      ElMessage.warning(t('systemRole.tip.permissionRequired'));
      return false;
    }

    authSubmitLoading.value = true;
    try {
      await editRole({
        id: authForm.id,
        name: authForm.name,
        desc: authForm.desc,
        sort: authForm.sort,
        menu_id: menuIds,
      });
      ElMessage.success(t('systemRole.tip.success'));
      authVisible.value = false;
      await fetchData(pagination.current);
      return true;
    } finally {
      authSubmitLoading.value = false;
    }
  };
</script>

<script lang="ts">
  export default {
    name: 'SystemRole',
  };
</script>

<style scoped lang="less">
  .container {
    padding: 0 20px 20px 20px;
  }

  .auth-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 12px;
  }

  .auth-tree {
    height: 480px;
    padding: 8px 12px;
    overflow: auto;
    border: 1px solid var(--color-neutral-3);
    border-radius: 4px;
  }
</style>
