<template>
  <section v-loading="loading">
    <el-alert
      title="本页只读取源码声明、编译目录、路由/API 目录和打包预览；声明、发现、注册、运行有效与测试证据分别展示。"
      type="info"
      :closable="false"
    />
    <div class="toolbar">
      <el-select
        v-model="selected"
        clearable
        filterable
        placeholder="全部模块"
        @change="load()"
      >
        <el-option
          v-for="item in moduleChoices"
          :key="item.key"
          :label="`${item.key} · ${item.name}`"
          :value="item.key"
        />
      </el-select>
      <el-button :loading="loading" @click="load">刷新目录</el-button>
    </div>
    <el-alert v-if="error" :title="error" type="error" show-icon />
    <template v-if="snapshot">
      <div class="metric-grid">
        <el-card
          ><span>声明模块</span
          ><strong>{{ snapshot.summary.modules }}</strong></el-card
        >
        <el-card
          ><span>严格发现</span
          ><strong>{{ snapshot.summary.discovered }}</strong></el-card
        >
        <el-card
          ><span>已编译注册</span
          ><strong>{{ snapshot.summary.registered }}</strong></el-card
        >
        <el-card
          ><span>生成 API</span
          ><strong>{{
            snapshot.summary.generated_api_operations
          }}</strong></el-card
        >
      </div>
      <el-card class="backup-center-card">
        <template #header>证据边界</template>
        <el-table :data="statusRows">
          <el-table-column prop="label" label="阶段" width="120" />
          <el-table-column label="状态" width="130">
            <template #default="{ row }"
              ><el-tag :type="tagType(row.status)">{{
                row.status
              }}</el-tag></template
            >
          </el-table-column>
          <el-table-column prop="code" label="原因码" min-width="230" />
          <el-table-column prop="reason" label="证据说明" min-width="320" />
        </el-table>
      </el-card>
      <el-collapse>
        <el-collapse-item
          v-for="module in snapshot.modules"
          :key="module.key"
          :name="module.key"
        >
          <template #title
            ><b>{{ module.key }}</b
            >&nbsp;· {{ module.name }}&nbsp;·
            {{ module.version || '版本未声明' }}</template
          >
          <el-descriptions :column="2" border>
            <el-descriptions-item label="职责">{{
              module.description || '未说明'
            }}</el-descriptions-item>
            <el-descriptions-item label="源码">{{
              module.source.backend_root
            }}</el-descriptions-item>
            <el-descriptions-item label="Provider">{{
              module.provider.class || '未声明'
            }}</el-descriptions-item>
            <el-descriptions-item label="前端入口">{{
              frontendEntries(module)
            }}</el-descriptions-item>
            <el-descriptions-item label="路由 / 生成 API"
              >{{ module.routes.length }} /
              {{ module.generated_api.length }}</el-descriptions-item
            >
            <el-descriptions-item label="权限 / 公开服务"
              >{{ module.permissions.length }} /
              {{ module.public_services.length }}</el-descriptions-item
            >
            <el-descriptions-item label="迁移 / 文档"
              >{{ module.migrations.files.length }} /
              {{ module.documentation.files.length }}</el-descriptions-item
            >
            <el-descriptions-item label="打包预览"
              >{{ module.package_preview.status
              }}<template v-if="module.package_preview.file_count">
                · {{ module.package_preview.file_count }} files</template
              ></el-descriptions-item
            >
          </el-descriptions>
          <el-table :data="evidenceRows(module)" size="small">
            <el-table-column prop="label" label="阶段" width="120" />
            <el-table-column prop="status" label="状态" width="130" />
            <el-table-column prop="code" label="原因码" min-width="230" />
            <el-table-column prop="reason" label="说明" min-width="320" />
          </el-table>
          <el-tabs>
            <el-tab-pane label="依赖">
              <pre>{{
                pretty({
                  dependencies: module.dependencies,
                  dependants: module.dependants,
                })
              }}</pre>
            </el-tab-pane>
            <el-tab-pane label="路由与权限">
              <pre>{{
                pretty({
                  routes: module.generated_api.length
                    ? module.generated_api
                    : module.routes,
                  permissions: module.permissions,
                  middleware: module.middleware,
                })
              }}</pre>
            </el-tab-pane>
            <el-tab-pane label="服务与 Provider">
              <pre>{{
                pretty({
                  provider: module.provider,
                  public_services: module.public_services,
                })
              }}</pre>
            </el-tab-pane>
            <el-tab-pane label="生命周期">
              <pre>{{ pretty(module.lifecycle) }}</pre>
            </el-tab-pane>
            <el-tab-pane label="事件任务">
              <pre>{{
                pretty({ events: module.events, tasks: module.tasks })
              }}</pre>
            </el-tab-pane>
            <el-tab-pane label="迁移文档">
              <pre>{{
                pretty({
                  migrations: module.migrations,
                  documentation: module.documentation,
                })
              }}</pre>
            </el-tab-pane>
            <el-tab-pane label="打包预览">
              <pre>{{ pretty(module.package_preview) }}</pre>
            </el-tab-pane>
          </el-tabs>
        </el-collapse-item>
      </el-collapse>
    </template>
  </section>
</template>

<script setup lang="ts">
  import { computed, onMounted, ref } from 'vue';
  import { api } from '../api/platform';
  import type {
    DeveloperCenterCatalogSnapshot,
    DeveloperModuleCatalog,
  } from '../api/platform';

  const loading = ref(false);
  const error = ref('');
  const selected = ref('');
  const snapshot = ref<DeveloperCenterCatalogSnapshot | null>(null);
  const moduleChoices = ref<Array<{ key: string; name: string }>>([]);

  const labels: Record<string, string> = {
    declaration: '声明',
    discovery: '发现',
    registration: '注册',
    runtime_effective: '运行有效',
    tests: '测试',
    api_catalog: 'API 目录',
  };
  const statusRows = computed(() =>
    Object.entries(snapshot.value?.status || {}).map(([key, item]) => ({
      label: labels[key] || key,
      ...item,
    }))
  );

  async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
      const next = await api.developerCatalog(selected.value);
      snapshot.value = next;
      if (!selected.value)
        moduleChoices.value = next.modules.map(({ key, name }) => ({
          key,
          name,
        }));
    } catch (cause) {
      error.value =
        cause instanceof Error ? cause.message : '开发者目录加载失败';
    } finally {
      loading.value = false;
    }
  }

  function evidenceRows(module: DeveloperModuleCatalog) {
    return Object.entries(module.evidence).map(([key, item]) => ({
      label: labels[key] || key,
      ...item,
    }));
  }
  function frontendEntries(module: DeveloperModuleCatalog): string {
    if (module.source.frontend_clients.length === 0) return '后端模块';
    return module.source.frontend_clients
      .map(({ client_key: client, entry }) => `${client}: ${entry}`)
      .join('；');
  }
  function tagType(status: string): '' | 'success' | 'warning' | 'danger' {
    if (
      ['available', 'declared', 'discovered', 'registered', 'ready'].includes(
        status
      )
    )
      return 'success';
    if (status === 'blocked') return 'danger';
    return 'warning';
  }
  function pretty(value: unknown): string {
    return JSON.stringify(value, null, 2);
  }

  onMounted(load);
</script>

<style scoped>
  .backup-center-card {
    margin-bottom: 16px;
  }
  .el-select {
    min-width: 320px;
  }
  pre {
    margin: 0;
    max-height: 420px;
    overflow: auto;
    padding: 14px;
    color: #d9e2f2;
    background: #13213a;
    border-radius: 6px;
    font-size: 12px;
  }
</style>
