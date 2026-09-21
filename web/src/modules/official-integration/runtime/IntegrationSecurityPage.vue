<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useIntegrationSecurityRuntime } from './runtime'

const runtime = useIntegrationSecurityRuntime()
const machineDialog = ref(false); const webhookDialog = ref(false); const attemptDialog = ref(false)
const machineForm = reactive({ name: '', scopes: '', expiresAt: '' })
const webhookForm = reactive({ name: '', url: '', events: '' })
const activeMachines = computed(() => runtime.state.machines.items.filter(item => item.status === 'active').length)
const activeWebhooks = computed(() => runtime.state.webhooks.items.filter(item => item.status === 'active').length)
const csv = (value: string) => [...new Set(value.split(',').map(item => item.trim()).filter(Boolean))]
const createMachine = async () => { await runtime.createMachine({ name: machineForm.name, scopes: csv(machineForm.scopes), expires_at: machineForm.expiresAt || null }); if (runtime.state.machines.error === null) machineDialog.value = false }
const createWebhook = async () => { await runtime.createWebhook({ name: webhookForm.name, url: webhookForm.url, events: csv(webhookForm.events) }); if (runtime.state.webhooks.error === null) webhookDialog.value = false }
const showAttempts = async (deliveryKey: string) => { attemptDialog.value = true; await runtime.loadAttempts(deliveryKey) }
onMounted(runtime.load)
</script>

<template>
  <main class="integration-security-page">
    <header class="page-header">
      <div><h1>Integration security</h1><p>Machine credentials, outbound endpoints, delivery evidence, and signed-in devices</p></div>
      <el-button @click="runtime.load">
        Refresh
      </el-button>
    </header>
    <el-alert
      v-if="runtime.state.disclosure"
      type="warning"
      :closable="false"
      show-icon
    >
      <template #title>
        Store this {{ runtime.state.disclosure.kind === 'machine-token' ? 'token' : 'secret' }} now. It will not be shown again.
      </template>
      <code class="disclosure">{{ runtime.state.disclosure.value }}</code>
      <el-button
        text
        @click="runtime.clearDisclosure"
      >
        Dismiss
      </el-button>
    </el-alert>
    <section
      class="summary"
      aria-label="Security summary"
    >
      <div><strong>{{ activeMachines }}</strong><span>Active machines</span></div>
      <div><strong>{{ activeWebhooks }}</strong><span>Active webhooks</span></div>
      <div><strong>{{ runtime.state.deliveries.total }}</strong><span>Webhook deliveries</span></div>
      <div><strong>{{ runtime.state.sessions.items.length }}</strong><span>Signed-in devices</span></div>
    </section>

    <section>
      <div class="section-header">
        <h2>Machine identities</h2><el-button
          v-if="runtime.can.canManageMachines()"
          @click="machineDialog = true"
        >
          Create
        </el-button>
      </div>
      <el-alert
        v-if="runtime.state.machines.error"
        type="error"
        :title="runtime.state.machines.error.message"
        :closable="false"
      />
      <el-table
        v-loading="runtime.state.machines.loading"
        :data="runtime.state.machines.items"
        empty-text="No machine identities"
      >
        <el-table-column
          prop="name"
          label="Name"
          min-width="180"
        /><el-table-column
          prop="status"
          label="Status"
          width="120"
        />
        <el-table-column
          label="Token"
          min-width="180"
        >
          <template #default="{ row }">
            {{ row.tokenPrefix }}...{{ row.tokenLastFour }}
          </template>
        </el-table-column>
        <el-table-column
          label="Scopes"
          min-width="240"
        >
          <template #default="{ row }">
            {{ row.scopes.join(', ') }}
          </template>
        </el-table-column>
        <el-table-column
          v-if="runtime.can.canManageMachines()"
          label=""
          width="170"
          align="right"
        >
          <template #default="{ row }">
            <el-button
              text
              :disabled="row.status !== 'active' || runtime.state.mutating"
              @click="runtime.rotateMachine(row)"
            >
              Rotate
            </el-button><el-button
              text
              type="danger"
              :disabled="row.status !== 'active' || runtime.state.mutating"
              @click="runtime.revokeMachine(row)"
            >
              Revoke
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section>
      <div class="section-header">
        <h2>Webhook endpoints</h2><el-button
          v-if="runtime.can.canManageWebhooks()"
          @click="webhookDialog = true"
        >
          Create
        </el-button>
      </div>
      <el-alert
        v-if="runtime.state.webhooks.error"
        type="error"
        :title="runtime.state.webhooks.error.message"
        :closable="false"
      />
      <el-table
        v-loading="runtime.state.webhooks.loading"
        :data="runtime.state.webhooks.items"
        empty-text="No webhook endpoints"
      >
        <el-table-column
          prop="name"
          label="Name"
          min-width="160"
        /><el-table-column
          prop="url"
          label="HTTPS destination"
          min-width="280"
          show-overflow-tooltip
        />
        <el-table-column
          prop="status"
          label="Status"
          width="120"
        /><el-table-column
          label="Events"
          min-width="220"
        >
          <template #default="{ row }">
            {{ row.events.join(', ') }}
          </template>
        </el-table-column>
        <el-table-column
          v-if="runtime.can.canManageWebhooks()"
          label=""
          width="180"
          align="right"
        >
          <template #default="{ row }">
            <el-button
              text
              :disabled="row.status !== 'active' || runtime.state.mutating"
              @click="runtime.rotateWebhook(row)"
            >
              Rotate secret
            </el-button><el-button
              text
              type="danger"
              :disabled="row.status !== 'active' || runtime.state.mutating"
              @click="runtime.disableWebhook(row)"
            >
              Disable
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section>
      <h2>Webhook deliveries</h2>
      <el-alert
        v-if="runtime.state.deliveries.error"
        type="error"
        :title="runtime.state.deliveries.error.message"
        :closable="false"
      />
      <el-table
        v-loading="runtime.state.deliveries.loading"
        :data="runtime.state.deliveries.items"
        empty-text="No webhook deliveries"
      >
        <el-table-column
          prop="eventType"
          label="Event"
          min-width="180"
        /><el-table-column
          prop="status"
          label="Status"
          width="150"
        />
        <el-table-column
          prop="attemptCount"
          label="Attempts"
          width="100"
        /><el-table-column
          prop="lastStatusCode"
          label="HTTP"
          width="90"
        />
        <el-table-column
          prop="lastErrorCode"
          label="Result"
          min-width="190"
        /><el-table-column
          label=""
          width="100"
          align="right"
        >
          <template #default="{ row }">
            <el-button
              text
              @click="showAttempts(row.deliveryKey)"
            >
              Attempts
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-if="runtime.state.deliveries.total > runtime.state.deliveries.pageSize"
        layout="prev, pager, next"
        :page-size="runtime.state.deliveries.pageSize"
        :total="runtime.state.deliveries.total"
        :current-page="runtime.state.deliveries.page"
        @current-change="runtime.loadDeliveries"
      />
    </section>

    <section>
      <h2>Signed-in devices</h2>
      <el-alert
        v-if="runtime.state.sessions.error"
        type="error"
        :title="runtime.state.sessions.error.message"
        :closable="false"
      />
      <el-table
        v-loading="runtime.state.sessions.loading"
        :data="runtime.state.sessions.items"
        empty-text="No sessions"
      >
        <el-table-column
          label="Device"
          min-width="180"
        >
          <template #default="{ row }">
            {{ row.clientKey }}<span v-if="row.current"> (current)</span>
          </template>
        </el-table-column>
        <el-table-column
          prop="maskedIp"
          label="Network"
          min-width="140"
        /><el-table-column
          prop="lastSeenAt"
          label="Last seen"
          min-width="190"
        />
        <el-table-column
          v-if="runtime.can.canRevokeSession()"
          label=""
          width="112"
          align="right"
        >
          <template #default="{ row }">
            <el-button
              text
              :disabled="row.status !== 'active' || runtime.state.mutating"
              @click="runtime.revokeSession(row)"
            >
              Revoke
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <el-dialog
      v-model="machineDialog"
      title="Create machine identity"
      width="min(520px, 92vw)"
    >
      <el-form label-position="top">
        <el-form-item label="Name">
          <el-input v-model="machineForm.name" />
        </el-form-item><el-form-item label="Scopes">
          <el-input
            v-model="machineForm.scopes"
            placeholder="webhook.publish, data.export.read"
          />
        </el-form-item><el-form-item label="Expires at">
          <el-input
            v-model="machineForm.expiresAt"
            placeholder="2030-01-01T00:00:00.000Z"
          />
        </el-form-item>
      </el-form><template #footer>
        <el-button @click="machineDialog = false">
          Cancel
        </el-button><el-button
          type="primary"
          :loading="runtime.state.mutating"
          @click="createMachine"
        >
          Create
        </el-button>
      </template>
    </el-dialog>
    <el-dialog
      v-model="webhookDialog"
      title="Create webhook endpoint"
      width="min(520px, 92vw)"
    >
      <el-form label-position="top">
        <el-form-item label="Name">
          <el-input v-model="webhookForm.name" />
        </el-form-item><el-form-item label="HTTPS URL">
          <el-input v-model="webhookForm.url" />
        </el-form-item><el-form-item label="Events">
          <el-input
            v-model="webhookForm.events"
            placeholder="audit.event.created"
          />
        </el-form-item>
      </el-form><template #footer>
        <el-button @click="webhookDialog = false">
          Cancel
        </el-button><el-button
          type="primary"
          :loading="runtime.state.mutating"
          @click="createWebhook"
        >
          Create
        </el-button>
      </template>
    </el-dialog>
    <el-dialog
      v-model="attemptDialog"
      title="Delivery attempts"
      width="min(720px, 94vw)"
    >
      <el-alert
        v-if="runtime.state.attempts.error"
        type="error"
        :title="runtime.state.attempts.error.message"
        :closable="false"
      /><el-table
        v-loading="runtime.state.attempts.loading"
        :data="runtime.state.attempts.items"
      >
        <el-table-column
          prop="attemptNumber"
          label="#"
          width="64"
        /><el-table-column
          prop="outcome"
          label="Outcome"
          min-width="150"
        /><el-table-column
          prop="responseStatus"
          label="HTTP"
          width="90"
        /><el-table-column
          prop="errorCode"
          label="Result"
          min-width="190"
        /><el-table-column
          prop="durationMs"
          label="ms"
          width="90"
        />
      </el-table>
    </el-dialog>
  </main>
</template>

<style scoped>
.integration-security-page{display:grid;gap:24px;max-width:1280px;margin:0 auto;padding:24px}.page-header,.section-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.page-header h1,.section-header h2{margin:0}.page-header h1{font-size:24px}.page-header p{margin:6px 0 0;color:var(--el-text-color-secondary)}.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border:1px solid var(--el-border-color);border-radius:6px}.summary div{display:grid;gap:4px;padding:16px;border-right:1px solid var(--el-border-color)}.summary div:last-child{border-right:0}.summary strong{font-size:20px}.summary span{color:var(--el-text-color-secondary)}section h2{font-size:16px;margin:0 0 12px}.disclosure{display:block;overflow-wrap:anywhere;margin-top:8px}@media(max-width:720px){.integration-security-page{padding:16px}.summary{grid-template-columns:1fr}.summary div{border-right:0;border-bottom:1px solid var(--el-border-color)}.summary div:last-child{border-bottom:0}}
</style>
