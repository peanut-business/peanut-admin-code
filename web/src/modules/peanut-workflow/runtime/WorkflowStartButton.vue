<script setup lang="ts">
  import { ref } from 'vue';
  import type { StartInput } from '../api';
  import { createWorkflowApi } from '../api';
  import type { WorkflowInstance } from '../types';
  import { useWorkflowLifecycle } from './lifecycle';
  const props = defineProps<{
    input: StartInput;
    availableActions: readonly string[];
  }>();
  const emit = defineEmits<{
    (event: 'started', instance: WorkflowInstance): void;
  }>();
  const busy = ref(false);
  const error = ref('');
  const lifecycle = useWorkflowLifecycle(() => {
    error.value = '';
    busy.value = false;
  });
  async function start() {
    if (
      busy.value ||
      !props.availableActions.includes('start') ||
      !lifecycle.valid()
    )
      return;
    busy.value = true;
    error.value = '';
    try {
      const instance = await createWorkflowApi().start(
        props.input,
        crypto.randomUUID()
      );
      if (lifecycle.valid()) emit('started', instance);
    } catch (cause) {
      if (lifecycle.valid())
        error.value = cause instanceof Error ? cause.message : '发起失败';
    } finally {
      if (lifecycle.valid()) busy.value = false;
    }
  }
</script>
<template>
  <div
    ><el-button
      v-if="availableActions.includes('start')"
      type="primary"
      :loading="busy"
      @click="start"
      >送审已确认版本</el-button
    ><el-alert v-if="error" :title="error" type="error" :closable="false"
  /></div>
</template>
