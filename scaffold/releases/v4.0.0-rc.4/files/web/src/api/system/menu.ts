import axios from 'axios';

/** 菜单类型：M 目录 / C 菜单 / A 按钮 */
export type MenuType = 'M' | 'C' | 'A';

export interface MenuRecord {
  id: number;
  pid: number;
  type: MenuType;
  name: string;
  icon: string;
  sort: number;
  perms: string;
  paths: string;
  component: string;
  is_cache: number;
  is_show: number;
  is_disable: number;
  children?: MenuRecord[];
}

/** 新增/编辑提交体：id 仅编辑时带 */
export type MenuForm = Partial<MenuRecord> & { id?: number };

/** 树形全量列表（含禁用项，供后台管理） */
export function getMenuList() {
  return axios.get<MenuRecord[]>('/adminapi/menu/lists');
}

/** 精简树（id/pid/name），供上级菜单选择器 */
export function getMenuAll() {
  return axios.get<MenuRecord[]>('/adminapi/menu/all');
}

export function getMenuDetail(id: number) {
  return axios.get<MenuRecord>('/adminapi/menu/detail', { params: { id } });
}

export function addMenu(data: MenuForm) {
  return axios.post('/adminapi/menu/add', data);
}

export function editMenu(data: MenuForm) {
  return axios.post('/adminapi/menu/edit', data);
}

export function deleteMenu(id: number) {
  return axios.post('/adminapi/menu/delete', { id });
}

export function updateMenuStatus(id: number, isDisable: number) {
  return axios.post('/adminapi/menu/status', { id, is_disable: isDisable });
}
