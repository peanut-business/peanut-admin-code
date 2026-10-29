<template>
  <el-card>
    <template #header>独立权限包与 API 关联</template>
    <p
      >权限包由模块声明，可被多个菜单、按钮及客户端复用。此处配置实例级 API
      关联；角色授权单独保存。</p
    >
    <el-space wrap>
      <el-select
        v-model="audience"
        aria-label="权限安全域"
        style="width: 160px"
        :disabled="saving"
      >
        <el-option label="租户业务权限" value="tenant" />
        <el-option label="平台权限" value="platform" />
      </el-select>
      <el-input v-model="search" placeholder="搜索权限包" clearable />
      <el-button :loading="loading" :disabled="saving" @click="load"
        >刷新</el-button
      >
    </el-space>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table
      v-loading="loading"
      :data="visibleBundles"
      row-key="key"
      empty-text="暂无权限包"
    >
      <el-table-column prop="name" label="权限包" />
      <el-table-column prop="key" label="标识" />
      <el-table-column prop="module_key" label="模块" />
      <el-table-column prop="status" label="状态" width="90" />
      <el-table-column label="关联 API" width="100"
        ><template #default="{ row }">{{
          row.api_ids.length
        }}</template></el-table-column
      >
      <el-table-column label="来源" width="130"
        ><template #default="{ row }">{{
          row.configured ? '实例配置' : '模块默认'
        }}</template></el-table-column
      >
      <el-table-column label="操作" width="100"
        ><template #default="{ row }"
          ><el-button link :disabled="saving" @click="open(row)"
            >查看关联</el-button
          ></template
        ></el-table-column
      >
    </el-table>
    <el-dialog
      v-model="visible"
      title="权限包关联 API"
      width="850px"
      :close-on-click-modal="false"
      :close-on-press-escape="!saving"
      :show-close="!saving"
    >
      <div v-loading="detailLoading">
        <p>{{ selected?.name }} · {{ selected?.key }}</p>
        <p>同一 API 可被多个权限包引用。公共与上下文接口仅供查阅，不能绑定。</p>
        <el-input
          v-model="apiSearch"
          placeholder="搜索 API 方法、路径或模块"
          clearable
        />
        <el-checkbox-group
          v-model="selection"
          :disabled="
            !canManage ||
            saving ||
            detailLoading ||
            selected?.status !== 'active'
          "
        >
          <div
            v-for="item in visibleApis"
            :key="item.id"
            class="permission-api-row"
          >
            <el-checkbox :value="item.id" :disabled="!bindable(item)">
              {{ item.method }} {{ item.path }}
              <small
                >{{ item.module_key }} · {{ item.access_mode }} ·
                {{ item.status }}</small
              >
            </el-checkbox>
            <p v-if="item.prerequisite_permission_keys.length">
              前置权限：{{
                item.prerequisite_permission_keys.join('、')
              }}（仍须同时满足）
            </p>
          </div>
        </el-checkbox-group>
        <el-alert
          v-if="detailError"
          :title="detailError"
          type="error"
          :closable="false"
        />
      </div>
      <template #footer>
        <el-button :disabled="saving" @click="visible = false">关闭</el-button>
        <el-button
          v-if="canManage"
          type="primary"
          :disabled="
            detailLoading || !!detailError || selected?.status !== 'active'
          "
          :loading="saving"
          @click="save"
          >保存关联</el-button
        >
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, watch } from 'vue';
  import { ElMessage } from 'element-plus';
  import { api } from '../api/platform';
  import type {
    PermissionApi,
    PermissionAudience,
    PermissionBundle,
  } from '../api/permission-bundles';

  const props = defineProps<{ canManage: boolean }>();
  const audience = ref<PermissionAudience>('tenant');
  const bundles = ref<PermissionBundle[]>([]);
  const apis = ref<PermissionApi[]>([]);
  const loading = ref(false);
  const detailLoading = ref(false);
  const saving = ref(false);
  const error = ref('');
  const detailError = ref('');
  const search = ref('');
  const apiSearch = ref('');
  const visible = ref(false);
  const selected = ref<PermissionBundle | null>(null);
  const selection = ref<string[]>([]);
  let generation = 0;
  let detailGeneration = 0;
  const message = (cause: unknown) =>
    cause instanceof Error ? cause.message : '权限目录加载失败';
  const visibleBundles = computed(() =>
    bundles.value.filter((item) =>
      `${item.name} ${item.key} ${item.module_key}`
        .toLowerCase()
        .includes(search.value.toLowerCase().trim())
    )
  );
  const visibleApis = computed(() =>
    apis.value.filter((item) =>
      `${item.method} ${item.path} ${item.module_key}`
        .toLowerCase()
        .includes(apiSearch.value.toLowerCase().trim())
    )
  );
  const bindable = (item: PermissionApi) =>
    item.access_mode === `${audience.value}_permission` &&
    (item.status === 'active' || selected.value?.api_ids.includes(item.id));

  async function readAll<T>(
    read: (page: number) => Promise<{ items: T[]; total: number }>
  ): Promise<T[]> {
    const items: T[] = [];
    let page = 1;
    while (true) {
      const result = await read(page);
      if (!result.items.length && items.length < result.total)
        throw new Error('权限目录未完整加载');
      items.push(...result.items);
      if (items.length >= result.total) return items;
      page += 1;
    }
  }

  async function load() {
    const request = ++generation;
    detailGeneration += 1;
    visible.value = false;
    selected.value = null;
    bundles.value = [];
    apis.value = [];
    error.value = '';
    loading.value = true;
    const scope = audience.value;
    try {
      const [bundleRows, apiRows] = await Promise.all([
        readAll((page) => api.permissionBundles(scope, page)),
        readAll((page) => api.permissionApis(scope, page)),
      ]);
      if (request !== generation) return;
      if (
        bundleRows.some((row) => row.audience !== scope) ||
        apiRows.some((row) => row.audience !== scope)
      )
        throw new Error('权限目录安全域不一致');
      bundles.value = bundleRows;
      apis.value = apiRows;
    } catch (cause) {
      if (request === generation) error.value = message(cause);
    } finally {
      if (request === generation) loading.value = false;
    }
  }

  async function open(bundle: PermissionBundle) {
    const request = ++detailGeneration;
    visible.value = true;
    detailLoading.value = true;
    detailError.value = '';
    selected.value = null;
    selection.value = [];
    apiSearch.value = '';
    try {
      const row = await api.permissionBindings(bundle.key);
      if (request !== detailGeneration || !visible.value) return;
      if (row.key !== bundle.key || row.audience !== audience.value)
        throw new Error('权限包响应目标不一致');
      if (row.api_ids.some((id) => !apis.value.some((item) => item.id === id)))
        throw new Error('关联 API 未完整包含于目录，不能编辑');
      selected.value = row;
      selection.value = [...row.api_ids];
    } catch (cause) {
      if (request === detailGeneration) detailError.value = message(cause);
    } finally {
      if (request === detailGeneration) detailLoading.value = false;
    }
  }

  async function save() {
    if (
      !props.canManage ||
      selected.value?.status !== 'active' ||
      !selected.value ||
      saving.value ||
      detailLoading.value ||
      detailError.value
    )
      return;
    saving.value = true;
    const bundle = selected.value;
    try {
      const row = await api.replacePermissionBindings(
        bundle.key,
        bundle.revision,
        [...selection.value]
      );
      if (row.key !== bundle.key || row.audience !== audience.value)
        throw new Error('权限包响应目标不一致');
      selected.value = row;
      bundles.value = bundles.value.map((item) =>
        item.key === row.key ? row : item
      );
      ElMessage.success('API 关联已保存');
      visible.value = false;
    } catch (cause) {
      ElMessage.error(message(cause));
    } finally {
      saving.value = false;
    }
  }
  watch(audience, load);
  watch(visible, (value) => {
    if (!value) detailGeneration += 1;
  });
  onBeforeUnmount(() => {
    generation += 1;
    detailGeneration += 1;
  });
  load();
</script>

<style scoped>
  .permission-api-row {
    margin: 8px 0;
  }
  small {
    margin-left: 8px;
  }
</style>
