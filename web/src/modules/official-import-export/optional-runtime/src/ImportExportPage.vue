<script setup lang="ts">
import { EmptyState, ForbiddenState, ModuleUnavailableState, PageContent, PageHeader, PageToolbar, SessionExpiredState } from '@peanut-admin/ui-vue'
import { ElButton, ElInput } from 'element-plus'
import { computed, onMounted, ref } from 'vue'
import { useImportExportRuntime } from './runtime'

const runtime = useImportExportRuntime(); const state = runtime.state
const providerKey = ref(''); const inputFileKey = ref(''); const mappingJson = ref('{}')
const canCreate = computed(runtime.canCreate); const canCancel = computed(runtime.canCancel)
const statuses = ['queued', 'running', 'cancel_requested', 'succeeded', 'failed', 'cancelled', 'expired'] as const
const submitImport = async (): Promise<void> => { try { const mapping = JSON.parse(mappingJson.value) as unknown; if (typeof mapping !== 'object' || mapping === null || Array.isArray(mapping)) throw new Error(); await runtime.submitImport(providerKey.value, inputFileKey.value, mapping as Record<string, string>) } catch { /* Runtime validation reports request errors; malformed JSON stays local. */ } }
onMounted(runtime.load)
</script>

<template>
  <PageContent class="import-export-page">
    <PageHeader>
      Import / Export<template #actions>
        <ElButton
          :loading="state.loading"
          :disabled="state.mutating"
          @click="runtime.load"
        >
          Reload
        </ElButton>
      </template>
    </PageHeader>
    <section
      v-if="canCreate"
      class="submission"
      aria-label="Create import or export"
    >
      <ElInput
        v-model="providerKey"
        placeholder="Registered provider key"
        aria-label="Provider key"
      />
      <ElInput
        v-model="inputFileKey"
        placeholder="Private CSV file key"
        aria-label="Input file key"
      />
      <ElInput
        v-model="mappingJson"
        type="textarea"
        placeholder="{&quot;CSV heading&quot;:&quot;column_key&quot;}"
        aria-label="Column mapping JSON"
      />
      <div>
        <ElButton
          type="primary"
          :disabled="state.mutating"
          @click="submitImport"
        >
          Import CSV
        </ElButton><ElButton
          :disabled="state.mutating"
          @click="runtime.submitExport(providerKey)"
        >
          Export CSV
        </ElButton>
      </div>
    </section>
    <PageToolbar label="Operation status">
      <ElButton
        v-for="status in statuses"
        :key="status"
        :type="state.status === status ? 'primary' : 'default'"
        @click="runtime.setStatus(status)"
      >
        {{ status }}
      </ElButton>
    </PageToolbar>
    <SessionExpiredState
      v-if="state.error?.status === 401"
      :message="state.error.message"
    />
    <ForbiddenState
      v-else-if="state.error?.status === 403"
      :message="state.error.message"
    />
    <ModuleUnavailableState
      v-else-if="state.error?.status === 503"
      :message="state.error.message"
      @action="runtime.load"
    />
    <section
      v-else-if="state.error"
      role="alert"
    >
      <h2>Unable to complete the import/export request</h2><p>{{ state.error.message }}</p><p v-if="state.error.requestId">
        Request ID: {{ state.error.requestId }}
      </p>
    </section>
    <div
      v-else-if="state.loading"
      role="status"
    >
      Loading import/export operations...
    </div>
    <EmptyState
      v-else-if="state.items.length === 0"
      title="No operations"
      message="No import/export operations match this status."
    />
    <div
      v-else
      class="table-wrap"
    >
      <table>
        <thead><tr><th>Provider</th><th>Direction</th><th>Status</th><th>Progress</th><th>Rows</th><th>Expires</th><th>Actions</th></tr></thead><tbody>
          <tr
            v-for="operation in state.items"
            :key="operation.operationKey"
          >
            <td>{{ operation.providerKey }}</td><td>{{ operation.direction }}</td><td>{{ operation.status }}</td><td>
              <progress
                :value="operation.processedRows"
                :max="Math.max(operation.totalRows, operation.processedRows, 1)"
              />
            </td><td>{{ operation.acceptedRows }} accepted / {{ operation.rejectedRows }} rejected</td><td>{{ operation.retentionUntil }}</td><td>
              <ElButton
                v-if="['queued','running'].includes(operation.status)"
                text
                :disabled="!canCancel || state.mutating"
                @click="runtime.cancel(operation)"
              >
                Cancel
              </ElButton><ElButton
                v-if="operation.resultFileKey"
                text
                :disabled="state.mutating"
                @click="runtime.download(operation.resultFileKey)"
              >
                Result
              </ElButton><ElButton
                v-if="operation.errorFileKey"
                text
                :disabled="state.mutating"
                @click="runtime.download(operation.errorFileKey)"
              >
                Errors
              </ElButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </PageContent>
</template>

<style scoped>
.submission { display: grid; gap: 8px; max-width: 720px; margin-bottom: 16px; }
.table-wrap { overflow-x: auto; } table { width: 100%; border-collapse: collapse; } th, td { padding: 10px 8px; border-bottom: 1px solid var(--el-border-color); text-align: left; } progress { min-width: 120px; }
</style>
