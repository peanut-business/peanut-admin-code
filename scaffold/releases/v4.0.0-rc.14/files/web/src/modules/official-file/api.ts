import axios from 'axios';
import type { UploadProgressEvent, UploadRequestOptions } from 'element-plus';

export interface ListRes<T> {
  lists: T[];
  count: number;
  pageNo: number;
  pageSize: number;
}

// 文件类型：10 图片 / 20 视频 / 30 文件
export type FileType = 10 | 20 | 30;

// ---- 分类 ----
export interface FileCateRecord {
  id: number;
  pid: number;
  type: FileType;
  name: string;
  create_time?: string;
  children?: FileCateRecord[];
}

export function getFileCateList(type: FileType) {
  return axios.get<FileCateRecord[]>('/adminapi/official.file.category.list', {
    params: { type },
  });
}

export function addFileCate(data: {
  type: FileType;
  pid?: number;
  name: string;
}) {
  return axios.post('/adminapi/official.file.category.add', data);
}

export function editFileCate(data: { id: number; name: string }) {
  return axios.post('/adminapi/official.file.category.edit', data);
}

export function deleteFileCate(id: number) {
  return axios.post('/adminapi/official.file.category.delete', { id });
}

// ---- 文件 ----
export interface FileRecord {
  id: number;
  cid: number;
  type: FileType;
  name: string;
  uri: string;
  url: string;
  create_time?: string;
}

export interface FileListParams {
  type: FileType;
  cid?: number | '';
  name?: string;
  source?: number | '';
  page_no?: number;
  page_size?: number;
}

export function getFileList(params: FileListParams) {
  return axios.get<ListRes<FileRecord>>('/adminapi/official.file.list', {
    params,
  });
}

export function moveFile(ids: number[], cid: number) {
  return axios.post('/adminapi/official.file.move', { ids, cid });
}

export function renameFile(id: number, name: string) {
  return axios.post('/adminapi/official.file.rename', { id, name });
}

export function deleteFile(ids: number[]) {
  return axios.post('/adminapi/official.file.delete', { ids });
}

const uploadUrl: Record<FileType, string> = {
  10: '/adminapi/official.file.upload.image',
  20: '/adminapi/official.file.upload.video',
  30: '/adminapi/official.file.upload.file',
};

export function uploadFile(type: FileType, options: UploadRequestOptions) {
  const form = new FormData();
  form.append(options.filename || 'file', options.file);
  Object.entries(options.data || {}).forEach(([key, value]) =>
    form.append(key, value as string | Blob)
  );
  return axios
    .post<FileRecord>(uploadUrl[type], form, {
      onUploadProgress: (event) =>
        options.onProgress({
          ...event,
          percent: event.total ? (event.loaded / event.total) * 100 : 0,
        } as UploadProgressEvent),
    })
    .then(({ data }) => data);
}
