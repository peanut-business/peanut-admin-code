import { defineAsyncComponent } from 'vue';

export const RichTextEditor = defineAsyncComponent(
  () => import('../components/RichTextEditor.vue')
);

export type { RichTextCollaborationConfig } from '../components/types';
export type { RichTextDocumentValue } from './document';
