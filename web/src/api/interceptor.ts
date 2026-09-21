import axios from 'axios';
import type { AxiosRequestConfig, AxiosResponse } from 'axios';
import { ElMessage, ElMessageBox } from 'element-plus';
import { useUserStore } from '@/store';
import { getToken, setToken } from '@/utils/auth';
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

let tenantRefreshRequest: Promise<string> | null = null;

axios.interceptors.request.use(
  (config: AxiosRequestConfig) => {
    // let each request carry token
    // this example using the JWT token
    // Authorization is a custom headers key
    // please modify it according to the actual situation
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
    // 20000 is the normal success envelope; LikeAdmin uses code=2 for a
    // successfully generated export file.
    if (![20000, 2].includes(res.code)) {
      const retryConfig = response.config as AxiosRequestConfig & {
        tenantRefreshRetried?: boolean;
      };
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
          tenantRefreshRequest ||= refreshTenantSession()
            .then((authentication) => {
              setToken(authentication.access_token);
              return authentication.access_token;
            })
            .finally(() => {
              tenantRefreshRequest = null;
            });
          const refreshedToken = await tenantRefreshRequest;
          retryConfig.headers = retryConfig.headers || {};
          retryConfig.headers.Authorization = `Bearer ${refreshedToken}`;
          return axios.request(retryConfig);
        } catch {
          // Continue to the existing re-login flow below.
        }
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
            const userStore = useUserStore();
            await userStore.logout();
            window.location.reload();
          })
          .catch(() => undefined);
      }
      return Promise.reject(new Error(res.msg || 'Error'));
    }
  return res;
};

axios.interceptors.response.use(
  handleResponse,
  (error) => {
    const response = axios.isAxiosError(error)
      ? error.response as AxiosResponse<HttpResponse> | undefined
      : undefined;
    if (response?.data && typeof response.data.code === 'number') {
      return handleResponse(response);
    }
    ElMessage.error({
      message: error.msg || 'Request Error',
      duration: 5 * 1000,
    });
    return Promise.reject(error);
  }
);
