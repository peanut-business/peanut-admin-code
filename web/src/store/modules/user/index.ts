import { defineStore } from 'pinia';
import {
  login as userLogin,
  logout as userLogout,
  getUserInfo,
  LoginData,
} from '@/api/user';
import { setToken, clearToken } from '@/utils/auth';
import { selectTenant, tenantLogin, tenantLogout } from '@/api/tenant-session';
import {
  isTenantAccessToken,
} from '@peanut-admin/vue';
import isMultiTenantDeployment from '@/core/tenant-session';
import { removeRouteListener } from '@/utils/route-listener';
import { UserState } from './types';
import useAppStore from '../app';
import useBrandStore from '../brand';

const useUserStore = defineStore('user', {
  state: (): UserState => ({
    name: undefined,
    avatar: undefined,
    job: undefined,
    organization: undefined,
    location: undefined,
    email: undefined,
    introduction: undefined,
    personalWebsite: undefined,
    jobName: undefined,
    organizationName: undefined,
    locationName: undefined,
    phone: undefined,
    registrationDate: undefined,
    accountId: undefined,
    certification: undefined,
    role: '',
    permissions: [],
    menu: [],
    canSwitchTenant: false,
    tenantName: '',
    demoMode: false,
  }),

  getters: {
    userInfo(state: UserState): UserState {
      return { ...state };
    },
  },

  actions: {
    // Set user's information
    setInfo(partial: Partial<UserState>) {
      this.$patch(partial);
    },

    // Reset user's information
    resetInfo() {
      this.$reset();
    },

    // Get user's information
    async info() {
      const res = await getUserInfo();
      const appStore = useAppStore();
      const brandStore = useBrandStore();
      appStore.setServerMenu(res.data.menu || []);
      this.setInfo(res.data);
      brandStore.setTenantName(res.data.tenantName);
    },

    // Login
    async login(loginForm: LoginData) {
      try {
        if (isMultiTenantDeployment()) {
          if (loginForm.challengeToken && loginForm.tenantId) {
            const authenticated = await selectTenant(
              loginForm.challengeToken,
              loginForm.tenantId
            );
            setToken(authenticated.access_token);
            return authenticated;
          }
          const outcome = await tenantLogin(
            loginForm.username,
            loginForm.password
          );
          if (!outcome || typeof outcome !== 'object') {
            throw new Error('Tenant session returned no session data.');
          }
          if (outcome.state === 'tenant_selection_required') {
            if (!loginForm.tenantId) return outcome;
            const authenticated = await selectTenant(
              outcome.challenge_token,
              loginForm.tenantId
            );
            setToken(authenticated.access_token);
            return authenticated;
          }
          setToken(outcome.access_token);
          return outcome;
        }
        const res = await userLogin(loginForm);
        setToken(res.data.token);
        return res.data;
      } catch (err) {
        clearToken();
        throw err;
      }
    },
    logoutCallBack() {
      const appStore = useAppStore();
      const brandStore = useBrandStore();
      this.resetInfo();
      brandStore.setTenantName();
      clearToken();
      removeRouteListener();
      appStore.clearServerMenu();
    },
    // Logout
    async logout() {
      try {
        const token = localStorage.getItem('token');
        if (isTenantAccessToken(token)) {
          await tenantLogout(token as string);
        } else {
          await userLogout();
        }
      } finally {
        this.logoutCallBack();
      }
    },
  },
});

export default useUserStore;
