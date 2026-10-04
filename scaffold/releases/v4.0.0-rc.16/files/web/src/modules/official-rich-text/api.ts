import axios from 'axios';
import type { PageData } from '@/types/global';
import type { RichTextDocumentValue } from './src/document';
import type { RichTextCollaborationConfig } from './components/types';

export interface RichTextDocumentRecord {
  id: number;
  title: string;
  revision: number;
  document?: RichTextDocumentValue;
  collaboration_state?: string;
  created_by_member_id: number;
  updated_by_member_id: number;
  create_time: number;
  update_time: number;
}

export type RichTextDocumentList = PageData<RichTextDocumentRecord>;

export const getRichTextDocuments = (params: {
  title?: string;
  page_no?: number;
  page_size?: number;
}) =>
  axios.get<RichTextDocumentList>(
    '/adminapi/official.rich-text.document.list',
    { params }
  );

export const getRichTextDocument = (id: number) =>
  axios.get<RichTextDocumentRecord>(
    '/adminapi/official.rich-text.document.detail',
    { params: { id } }
  );

export const addRichTextDocument = (data: {
  title: string;
  document: RichTextDocumentValue;
  collaboration_state: string;
}) => axios.post('/adminapi/official.rich-text.document.add', data);

export const editRichTextDocument = (data: {
  id: number;
  title: string;
  document: RichTextDocumentValue;
  collaboration_state: string;
  revision: number;
}) => axios.post('/adminapi/official.rich-text.document.edit', data);

export const deleteRichTextDocument = (id: number) =>
  axios.post('/adminapi/official.rich-text.document.delete', { id });

export const getRichTextCollaboration = (id: number) =>
  axios.get<RichTextCollaborationConfig>(
    '/adminapi/official.rich-text.document.collaboration',
    { params: { id } }
  );
