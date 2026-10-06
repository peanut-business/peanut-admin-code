import axios from 'axios';
import type { AxiosRequestConfig, AxiosResponse } from 'axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { useUserStore } from '@/store';
import {
  getSessionSnapshot,
  getToken,
  setToken,
} from '@/utils/auth';
import { isTenantAccessToken } from '@peanut-admin/vue';
import { refreshTenantSession } from '@/api/tenant-session';

export interface HttpResponse<T = unknown> {
  status: number;
  msg: string;
  code: number;
  data: T;
}

if (import.meta.env.VITE_API_BASE_URL) {
  axios.defaults.baseURL = import.meta.env.VITE_API_BASE_URL;
}

type TenantSessionSnapshot = { generation: number; token: string | null };
type SessionRequestConfig = AxiosRequestConfig & {
  tenantRefreshRetried?: boolean;
  tenantSession?: TenantSessionSnapshot;
};

const tenantRefreshRequests = new Map<string, Promise<string>>();

axios.interceptors.request.use(
  (config: AxiosRequestConfig) => {
    // let each request carry token
    // this example using the JWT token
    // Authorization is a custom headers key
    // please modify it according to the actual situation
    const requestConfig = config as SessionRequestConfig;
    const snapshot = requestConfig.tenantSession ?? getSessionSnapshot();
    if (snapshot.generation !== getSessionSnapshot().generation) {
      return Promise.reject(new Error('The request session has changed.'));
    }
    requestConfig.tenantSession = snapshot;
    const token = getToken();
    if (token) {
      if (!config.headers) {
        config.headers = {};
      }
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    // do something
    return Promise.reject(error);
  }
);
// add response interceptors
const handleResponse = async (response: AxiosResponse<HttpResponse>) => {
  const res = response.data;
  const retryConfig = response.config as SessionRequestConfig;
  const snapshot = retryConfig.tenantSession ?? getSessionSnapshot();
  retryConfig.tenantSession = snapshot;
  if (snapshot.generation !== getSessionSnapshot().generation) {
    return Promise.reject(new Error(res.msg || 'The request session has changed.'));
  }
  // 20000 is the normal success envelope; LikeAdmin uses code=2 for a
  // successfully generated export file.
  if (![20000, 2].includes(res.code)) {
    const accessToken = getToken();
    if (
      res.code === 40100 &&
      isTenantAccessToken(accessToken) &&
      !retryConfig.tenantRefreshRetried &&
      retryConfig.url !== '/adminapi/tenant/session/refresh' &&
      retryConfig.url !== '/adminapi/tenant/session/logout'
    ) {
      retryConfig.tenantRefreshRetried = true;
      try {
        let refreshedToken = accessToken;
        const requestToken = snapshot.token;
        if (requestToken !== null && requestToken === accessToken) {
          const refreshKey = JSON.stringify([snapshot.generation, requestToken]);
          let refreshRequest = tenantRefreshRequests.get(refreshKey);
          if (!refreshRequest) {
            refreshRequest = refreshTenantSession(
              requestToken,
              snapshot.generation
            )
              .then((authentication) => authentication.access_token)
              .finally(() => tenantRefreshRequests.delete(refreshKey));
            tenantRefreshRequests.set(refreshKey, refreshRequest);
          }
          const candidate = await refreshRequest;
          if (snapshot.generation !== getSessionSnapshot().generation) {
            throw new Error('The request session has changed.');
          }
          const currentToken = getToken();
          if (currentToken === requestToken) {
            setToken(candidate);
            refreshedToken = candidate;
          } else if (currentToken) {
            refreshedToken = currentToken;
          } else {
            throw new Error('The request session has changed.');
          }
        }
        if (snapshot.generation !== getSessionSnapshot().generation) {
          throw new Error('The request session has changed.');
        }
        snapshot.token = refreshedToken;
        retryConfig.headers = retryConfig.headers || {};
        retryConfig.headers.Authorization = `Bearer ${refreshedToken}`;
        return axios.request(retryConfig);
      } catch {
        // Continue to the existing re-login flow below.
      }
    }
    if (snapshot.generation !== getSessionSnapshot().generation) {
      return Promise.reject(new Error(res.msg || 'The request session has changed.'));
    }
    ElMessage.error({
      message: res.msg || 'Error',
      duration: 5 * 1000,
    });
    // 50008: Illegal token; 50012: Other clients logged in; 50014: Token expired;
    if (
      [40100].includes(res.code) &&
      response.config.url !== '/adminapi/user/info'
    ) {
      ElMessageBox.confirm(
        'You have been logged out, you can cancel to stay on this page, or log in again',
        'Confirm logout',
        {
          confirmButtonText: 'Re-Login',
          cancelButtonText: 'Cancel',
          type: 'error',
        }
      )
        .then(async () => {
          if (
            snapshot.generation !== getSessionSnapshot().generation ||
            snapshot.token !== getToken()
          ) return;
          const userStore = useUserStore();
          const cleared = await userStore.logout(snapshot);
          if (
            cleared &&
            getSessionSnapshot().generation === snapshot.generation + 2 &&
            getToken() === null
          ) window.location.reload();
        })
        .catch(() => undefined);
    }
    return Promise.reject(new Error(res.msg || 'Error'));
  }
  return res;
};

axios.interceptors.response.use(handleResponse, (error) => {
  if (axios.isCancel(error)) return Promise.reject(error);
  const response = axios.isAxiosError(error)
    ? (error.response as AxiosResponse<HttpResponse> | undefined)
    : undefined;
  if (response?.data && typeof response.data.code === 'number') {
    return handleResponse(response);
  }
  ElMessage.error({
    message: error.msg || 'Request Error',
    duration: 5 * 1000,
  });
  return Promise.reject(error);
});
