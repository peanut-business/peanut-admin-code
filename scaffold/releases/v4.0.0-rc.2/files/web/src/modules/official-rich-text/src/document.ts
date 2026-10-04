import type { JSONContent } from '@tiptap/core';

export const DOCUMENT_VERSION = 'peanut.richtext/1' as const;

export interface RichTextDocumentValue {
  schemaVersion: typeof DOCUMENT_VERSION;
  editorModel: 'tiptap-prosemirror';
  content: JSONContent;
  annotations: [];
}

export const emptyDocument = (): RichTextDocumentValue => ({
  schemaVersion: DOCUMENT_VERSION,
  editorModel: 'tiptap-prosemirror',
  content: { type: 'doc', content: [{ type: 'paragraph' }] },
  annotations: [],
});
