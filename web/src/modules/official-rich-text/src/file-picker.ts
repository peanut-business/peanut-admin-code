import { defineAsyncComponent } from 'vue';

export const RichTextFilePicker = defineAsyncComponent(
  () => import('../components/RichTextFilePicker.vue')
);
export type { StableMediaReference } from '@/modules/official-content-tools/src/contracts';
