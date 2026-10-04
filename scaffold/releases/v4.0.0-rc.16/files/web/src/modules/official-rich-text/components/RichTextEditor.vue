<template>
  <div class="rich-text-editor">
    <div class="rich-text-editor__toolbar" role="toolbar" aria-label="正文格式">
      <el-button size="small" @mousedown.prevent="run('bold')"
        ><b>B</b></el-button
      >
      <el-button size="small" @mousedown.prevent="run('italic')"
        ><i>I</i></el-button
      >
      <el-button size="small" @mousedown.prevent="run('heading')">H2</el-button>
      <el-button size="small" @mousedown.prevent="run('bullet')"
        >• 列表</el-button
      >
      <el-button size="small" @mousedown.prevent="run('ordered')"
        >1. 列表</el-button
      >
      <el-button size="small" @mousedown.prevent="run('undo')">撤销</el-button>
      <el-button size="small" @mousedown.prevent="run('redo')">重做</el-button>
    </div>
    <div class="rich-text-editor__controls">
      <el-input
        v-model="link"
        placeholder="https://example.com"
        aria-label="链接地址"
      />
      <el-button @click="setLink">设置链接</el-button>
    </div>
    <div class="rich-text-editor__controls">
      <el-input
        v-model="mediaRef"
        placeholder="asset:image:001"
        aria-label="媒体引用"
      />
      <el-select v-model="mediaKind" aria-label="媒体类型">
        <el-option label="图片" value="image" />
        <el-option label="视频" value="video" />
        <el-option label="音频" value="audio" />
      </el-select>
      <el-button @click="insertMedia">插入媒体引用</el-button>
    </div>
    <div ref="host" class="rich-text-editor__content" />
    <div class="rich-text-editor__status" role="status" aria-live="polite">
      {{ collaborationStatus }}
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
  import { ElMessage } from 'element-plus';
  import { Editor, Node } from '@tiptap/core';
  import Collaboration from '@tiptap/extension-collaboration';
  import StarterKit from '@tiptap/starter-kit';
  import { HocuspocusProvider } from '@hocuspocus/provider';
  import DOMPurify from 'dompurify';
  import * as Y from 'yjs';
  import {
    DOCUMENT_VERSION,
    type RichTextDocumentValue,
  } from '../src/document';
  import type { RichTextCollaborationConfig } from './types';

  const props = defineProps<{
    modelValue: RichTextDocumentValue;
    collaborationState?: string;
    collaboration?: RichTextCollaborationConfig | null;
  }>();
  const emit = defineEmits<{
    (event: 'update:modelValue', value: RichTextDocumentValue): void;
    (event: 'update:collaborationState', value: string): void;
  }>();

  const host = ref<HTMLDivElement>();
  const editor = shallowRef<Editor>();
  const provider = shallowRef<HocuspocusProvider>();
  const collaborationDocument = shallowRef<Y.Doc>();
  const collaborationStatus = ref('单人编辑 · 保存到数据库');
  const link = ref('');
  const mediaRef = ref('');
  const mediaKind = ref<'image' | 'video' | 'audio'>('image');

  const cleanPastedHtml = (html: string): string =>
    DOMPurify.sanitize(html, {
      ALLOWED_TAGS: [
        'a',
        'blockquote',
        'br',
        'code',
        'em',
        'figure',
        'h1',
        'h2',
        'h3',
        'li',
        'ol',
        'p',
        'pre',
        'span',
        'strong',
        'ul',
      ],
      ALLOWED_ATTR: [
        'href',
        'rel',
        'target',
        'data-media-kind',
        'data-media-label',
        'data-media-ref',
      ],
    });

  const MediaPlaceholder = Node.create({
    name: 'mediaPlaceholder',
    group: 'block',
    atom: true,
    addAttributes: () => ({
      ref: { default: '' },
      kind: { default: 'image' },
      label: { default: '' },
    }),
    parseHTML: () => [
      {
        tag: 'figure[data-media-ref]',
        getAttrs: (element) => {
          const node = element as HTMLElement;
          return {
            ref: node.dataset.mediaRef || '',
            kind: node.dataset.mediaKind || 'image',
            label: node.dataset.mediaLabel || '',
          };
        },
      },
    ],
    renderHTML: ({ node }) => [
      'figure',
      {
        'data-media-ref': node.attrs.ref,
        'data-media-kind': node.attrs.kind,
        'data-media-label': node.attrs.label,
        'class': 'rich-text-editor__media',
        'contenteditable': 'false',
      },
      ['span', {}, node.attrs.label || node.attrs.ref],
    ],
  });

  const editorExtensions = (collaborationDocument?: Y.Doc) => [
    StarterKit.configure({
      undoRedo: collaborationDocument ? false : {},
      link: {
        autolink: false,
        openOnClick: false,
        HTMLAttributes: { rel: 'noopener noreferrer' },
      },
    }),
    MediaPlaceholder,
    ...(collaborationDocument
      ? [Collaboration.configure({ document: collaborationDocument })]
      : []),
  ];

  const documentEnvelope = (instance: Editor): RichTextDocumentValue => ({
    schemaVersion: DOCUMENT_VERSION,
    editorModel: 'tiptap-prosemirror',
    content: instance.getJSON(),
    annotations: [],
  });

  const bytesToBase64 = (bytes: Uint8Array) => {
    let binary = '';
    for (let offset = 0; offset < bytes.length; offset += 0x8000) {
      binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
    }
    return btoa(binary);
  };

  const base64ToBytes = (value: string) =>
    Uint8Array.from(atob(value), (character) => character.charCodeAt(0));

  const publish = (instance: Editor) => {
    emit('update:modelValue', documentEnvelope(instance));
    if (collaborationDocument.value) {
      emit(
        'update:collaborationState',
        bytesToBase64(Y.encodeStateAsUpdate(collaborationDocument.value))
      );
    }
  };

  onMounted(() => {
    const config = props.collaboration;
    const yDocument = config?.enabled ? new Y.Doc() : undefined;
    collaborationDocument.value = yDocument;
    if (yDocument && props.collaborationState) {
      Y.applyUpdate(yDocument, base64ToBytes(props.collaborationState));
    }
    const instance = new Editor({
      element: host.value,
      extensions: editorExtensions(yDocument),
      content: yDocument ? undefined : props.modelValue.content,
      editorProps: {
        attributes: {
          'aria-label': '富文本正文',
          'aria-multiline': 'true',
          'role': 'textbox',
          'spellcheck': 'true',
        },
        transformPastedHTML: cleanPastedHtml,
      },
      onUpdate: ({ editor: current }) => publish(current),
    });
    editor.value = instance;

    if (!yDocument || !config?.url || !config.token) return;
    if (!props.collaborationState) {
      instance.commands.setContent(props.modelValue.content);
    }
    collaborationStatus.value = '协同连接中';
    provider.value = new HocuspocusProvider({
      url: config.url,
      name: config.document_name,
      document: yDocument,
      token: config.token,
      onStatus: ({ status }) => {
        collaborationStatus.value =
          status === 'connected' ? '协同已连接' : `协同${status}`;
      },
      onAuthenticationFailed: ({ reason }) => {
        collaborationStatus.value = `协同认证失败：${reason}`;
        instance.setEditable(false);
      },
    });
  });

  onBeforeUnmount(() => {
    provider.value?.destroy();
    editor.value?.destroy();
    collaborationDocument.value?.destroy();
  });

  const run = (command: string) => {
    const chain = editor.value?.chain().focus();
    if (!chain) return;
    const commands: Record<string, () => void> = {
      bold: () => chain.toggleBold().run(),
      italic: () => chain.toggleItalic().run(),
      heading: () => chain.toggleHeading({ level: 2 }).run(),
      bullet: () => chain.toggleBulletList().run(),
      ordered: () => chain.toggleOrderedList().run(),
      undo: () => chain.undo().run(),
      redo: () => chain.redo().run(),
    };
    commands[command]?.();
  };

  const setLink = () => {
    try {
      const value = link.value.trim();
      const url = new URL(value);
      if (!['http:', 'https:', 'mailto:'].includes(url.protocol))
        throw new Error();
      editor.value
        ?.chain()
        .focus()
        .extendMarkRange('link')
        .setLink({ href: value })
        .run();
    } catch {
      ElMessage.error('仅允许 http、https 或 mailto 链接');
    }
  };

  const insertMedia = () => {
    const value = mediaRef.value.trim();
    if (!/^[a-z][a-z0-9._:-]{2,80}$/.test(value)) {
      ElMessage.error('媒体引用格式无效');
      return;
    }
    editor.value
      ?.chain()
      .focus()
      .insertContent({
        type: 'mediaPlaceholder',
        attrs: { ref: value, kind: mediaKind.value, label: value },
      })
      .run();
  };
</script>

<style scoped lang="less">
  .rich-text-editor {
    width: 100%;
  }

  .rich-text-editor__toolbar,
  .rich-text-editor__controls {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
  }

  .rich-text-editor__content {
    min-height: 320px;
    padding: 14px;
    border: 1px solid var(--el-border-color);
    border-radius: 4px;
  }

  .rich-text-editor__content :deep(.ProseMirror) {
    min-height: 290px;
    outline: none;
  }

  .rich-text-editor__content :deep(.rich-text-editor__media) {
    padding: 12px;
    background: var(--el-fill-color-light);
    border: 1px dashed var(--el-border-color);
  }

  .rich-text-editor__status {
    margin-top: 8px;
    color: var(--el-text-color-secondary);
  }
</style>
