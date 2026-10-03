<script setup lang="ts">
  import {
    EmptyState,
    ForbiddenState,
    ModuleUnavailableState,
    PageContent,
    PageHeader,
    PageToolbar,
    SessionExpiredState,
  } from '@peanut-admin/ui-vue';
  import { ElButton, ElCheckbox, ElTag } from 'element-plus';
  import { computed, onMounted } from 'vue';

  import type { NotificationFilter, NotificationMessage } from './contracts';
  import { useNotificationRuntime } from './runtime';

  const runtime = useNotificationRuntime();
  const state = runtime.state;
  const canManage = computed(runtime.canManage);
  const filters: readonly { label: string; value: NotificationFilter }[] = [
    { label: 'All', value: 'all' },
    { label: 'Unread', value: 'unread' },
    { label: 'Read', value: 'read' },
    { label: 'Archived', value: 'archived' },
  ];

  const run = async (operation: () => Promise<void>): Promise<void> => {
    try {
      await operation();
    } catch {
      return;
    }
  };
  const statusType = (
    message: NotificationMessage
  ): 'primary' | 'success' | 'info' =>
    message.status === 'unread'
      ? 'primary'
      : message.status === 'read'
      ? 'success'
      : 'info';

  onMounted(() => run(runtime.load));
</script>

<template>
  <PageContent class="notification-inbox-page">
    <PageHeader>
      Notifications
      <template #actions>
        <ElButton
          aria-label="Reload notifications"
          :disabled="state.mutating"
          :loading="state.loading"
          @click="run(runtime.load)"
        >
          Reload
        </ElButton>
      </template>
    </PageHeader>

    <PageToolbar v-if="!state.error" label="Notification controls">
      <div class="notification-toolbar">
        <div
          class="notification-filters"
          role="group"
          aria-label="Inbox status"
        >
          <ElButton
            v-for="filter in filters"
            :key="filter.value"
            :aria-pressed="state.status === filter.value"
            :disabled="state.loading || state.mutating"
            :type="state.status === filter.value ? 'primary' : 'default'"
            @click="run(() => runtime.setStatus(filter.value))"
          >
            {{ filter.label }}
          </ElButton>
        </div>
        <div class="notification-actions">
          <span>{{ state.selected.size }} selected</span>
          <ElButton
            :disabled="
              !canManage || state.selected.size === 0 || state.mutating
            "
            @click="run(() => runtime.bulk('read'))"
          >
            Mark read
          </ElButton>
          <ElButton
            :disabled="
              !canManage || state.selected.size === 0 || state.mutating
            "
            @click="run(() => runtime.bulk('archive'))"
          >
            Archive
          </ElButton>
        </div>
      </div>
    </PageToolbar>

    <SessionExpiredState
      v-if="state.error?.status === 401"
      :message="state.error.message"
      v-bind="
        state.error.requestId === null
          ? {}
          : { requestId: state.error.requestId }
      "
    />
    <ForbiddenState
      v-else-if="state.error?.status === 403"
      :message="state.error.message"
      v-bind="
        state.error.requestId === null
          ? {}
          : { requestId: state.error.requestId }
      "
    />
    <ModuleUnavailableState
      v-else-if="state.error?.status === 503"
      :message="state.error.message"
      v-bind="
        state.error.requestId === null
          ? {}
          : { requestId: state.error.requestId }
      "
      @action="run(runtime.load)"
    />
    <section v-else-if="state.error" class="notification-state" role="alert">
      <h2>Unable to load notifications</h2>
      <p>{{ state.error.message }}</p>
      <ElButton @click="run(runtime.load)"> Retry </ElButton>
    </section>
    <div
      v-else-if="state.loading"
      class="notification-state"
      role="status"
      aria-live="polite"
    >
      Loading notifications...
    </div>
    <EmptyState
      v-else-if="state.items.length === 0"
      title="No notifications"
      message="No messages match the selected inbox status."
    />
    <section v-else class="notification-list" aria-label="Notification inbox">
      <article
        v-for="message in state.items"
        :key="message.messageKey"
        class="notification-item"
        :class="{ 'notification-item--unread': message.status === 'unread' }"
      >
        <ElCheckbox
          :aria-label="`Select ${message.subject}`"
          :disabled="state.mutating"
          :model-value="state.selected.has(message.messageKey)"
          @update:model-value="runtime.toggle(message.messageKey)"
        />
        <div class="notification-item__content">
          <div class="notification-item__heading">
            <h2>{{ message.subject }}</h2>
            <ElTag :type="statusType(message)">
              {{ message.status }}
            </ElTag>
          </div>
          <p>{{ message.body }}</p>
          <ul
            v-if="message.attachments.length > 0"
            class="notification-attachments"
            aria-label="Attachments"
          >
            <li
              v-for="attachment in message.attachments"
              :key="attachment.fileKey"
            >
              {{ attachment.originalName }} - {{ attachment.mediaType }}
            </li>
          </ul>
          <div class="notification-item__footer">
            <time :datetime="message.createdAt">{{ message.createdAt }}</time>
            <ElButton
              v-if="message.status === 'unread'"
              text
              :disabled="!canManage || state.mutating"
              @click="run(() => runtime.markRead(message))"
            >
              Mark read
            </ElButton>
          </div>
        </div>
      </article>
    </section>
  </PageContent>
</template>

<style scoped>
  .notification-toolbar,
  .notification-filters,
  .notification-actions,
  .notification-item__heading,
  .notification-item__footer {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .notification-toolbar,
  .notification-item__heading,
  .notification-item__footer {
    justify-content: space-between;
  }

  .notification-toolbar {
    flex-wrap: wrap;
    width: 100%;
  }

  .notification-list {
    border-top: 1px solid var(--el-border-color);
  }

  .notification-item {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr);
    gap: 12px;
    padding: 16px 0;
    border-bottom: 1px solid var(--el-border-color);
  }

  .notification-item--unread {
    border-left: 3px solid var(--el-color-primary);
    padding-left: 12px;
  }

  .notification-item__content,
  .notification-item__heading h2 {
    min-width: 0;
  }

  .notification-item__heading h2 {
    margin: 0;
    overflow-wrap: anywhere;
    font-size: 16px;
    letter-spacing: 0;
  }

  .notification-item__content > p {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  .notification-attachments {
    margin: 8px 0;
    padding-left: 20px;
  }

  .notification-item__footer {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .notification-state {
    padding: 24px 0;
  }

  @media (max-width: 720px) {
    .notification-toolbar,
    .notification-actions {
      align-items: flex-start;
      flex-direction: column;
    }

    .notification-filters {
      flex-wrap: wrap;
    }
  }
</style>
