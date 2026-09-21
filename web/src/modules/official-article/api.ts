import axios from 'axios';
import type { PageData } from '@/types/global';

// ─── 文章分类 ──────────────────────────────────────────────────────────────
export interface ArticleCateRecord {
  id: number;
  name: string;
  sort: number;
  is_show: number;
  article_count?: number;
  create_time?: string | number;
  update_time?: string | number | null;
  delete_time?: string | number | null;
}

export interface ArticleCateOption {
  id: number;
  name: string;
}

export interface ArticleCateListParams {
  page_no?: number;
  page_size?: number;
  page_type?: 0 | 1;
  field?: 'create_time' | 'id';
  order_by?: 'asc' | 'desc';
  export?: 1 | 2;
}

export type ArticleCateListRes = PageData<ArticleCateRecord> & {
  extend: [];
};

export function getArticleCateList(
  params: ArticleCateListParams = {},
  signal?: AbortSignal
) {
  return axios.get<ArticleCateListRes>(
    '/adminapi/official.article.category.list',
    { params, signal }
  );
}

export function getArticleCateAll() {
  return axios.get<ArticleCateOption[]>('/adminapi/official.article.category.all');
}

export function getArticleCateDetail(id: number, signal?: AbortSignal) {
  return axios.get<ArticleCateRecord>(
    '/adminapi/official.article.category.detail',
    { params: { id }, signal }
  );
}

export function addArticleCate(
  data: Partial<ArticleCateRecord>,
  signal?: AbortSignal
) {
  return axios.post('/adminapi/official.article.category.add', data, { signal });
}

export function editArticleCate(
  data: Partial<ArticleCateRecord>,
  signal?: AbortSignal
) {
  return axios.post('/adminapi/official.article.category.edit', data, {
    signal,
  });
}

export function deleteArticleCate(id: number, signal?: AbortSignal) {
  return axios.post(
    '/adminapi/official.article.category.delete',
    { id },
    { signal }
  );
}

export function updateArticleCateStatus(
  id: number,
  isShow: number,
  signal?: AbortSignal
) {
  return axios.post(
    '/adminapi/official.article.category.update-status',
    {
      id,
      is_show: isShow,
    },
    { signal }
  );
}

// ─── 文章 ─────────────────────────────────────────────────────────────────
export interface ArticleRecord {
  id: number;
  cid: number;
  cate_name?: string;
  title: string;
  desc: string;
  abstract: string;
  image: string;
  author: string;
  content: string;
  click_virtual: number;
  click_actual: number;
  click: number;
  sort: number;
  is_show: number;
  create_time?: string | number;
  update_time?: string | number | null;
}

export interface ArticleListParams {
  page_no?: number;
  page_size?: number;
  page_type?: 0 | 1;
  title?: string;
  cid?: number | string;
  is_show?: number | string;
  field?: 'create_time' | 'id';
  order_by?: 'asc' | 'desc';
}

export type ArticleListRes = PageData<ArticleRecord> & {
  extend: [];
};

export function getArticleList(params: ArticleListParams = {}) {
  return axios.get<ArticleListRes>('/adminapi/official.article.list', {
    params,
  });
}

export function getArticleDetail(id: number) {
  return axios.get<ArticleRecord>('/adminapi/official.article.detail', {
    params: { id },
  });
}

export function addArticle(data: Partial<ArticleRecord>) {
  return axios.post('/adminapi/official.article.add', data);
}

export function editArticle(data: Partial<ArticleRecord>) {
  return axios.post('/adminapi/official.article.edit', data);
}

export function deleteArticle(id: number) {
  return axios.post('/adminapi/official.article.delete', { id });
}

export function updateArticleStatus(id: number, isShow: number) {
  return axios.post('/adminapi/official.article.update-status', {
    id,
    is_show: isShow,
  });
}
