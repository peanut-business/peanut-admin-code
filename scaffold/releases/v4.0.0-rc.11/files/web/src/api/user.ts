import axios from 'axios';
import { UserState } from '@/store/modules/user/types';
import type { ServerMenuRecord } from '@/store/modules/app/types';

export interface LoginData {
  username: string;
  password: string;
  tenantId?: number;
  challengeToken?: string;
}

export interface LoginRes {
  token: string;
}
export function login(data: LoginData) {
  return axios.post<LoginRes>('/adminapi/user/login', data);
}

export function logout() {
  return axios.post<LoginRes>('/adminapi/user/logout');
}

export function getUserInfo() {
  return axios.post<UserState>('/adminapi/user/info');
}

export function getMenuList() {
  return axios.post<ServerMenuRecord[]>('/adminapi/user/menu');
}
