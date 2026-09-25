import { defineStore } from 'pinia';
import { ref, computed, watch } from 'vue';
import type { LoginResult } from '@/api/account';
import type { UserCenter, UserInfo } from '@/api/user';

const STORAGE_KEY = 'user_store';

export const useUserStore = defineStore('user', () => {
  // Rehydrate from uni storage on init
  const saved = uni.getStorageSync(STORAGE_KEY);
  const initial = saved ? JSON.parse(saved) : {};

  const token = ref<string>(initial.token || '');
  const userInfo = ref<Partial<UserCenter & UserInfo>>(initial.userInfo || {});

  const isLoggedIn = computed(() => !!token.value);

  // Persist to uni storage on change
  watch(
    () => ({ token: token.value, userInfo: userInfo.value }),
    (val) => uni.setStorageSync(STORAGE_KEY, JSON.stringify(val)),
    { deep: true }
  );

  function setToken(newToken: string) {
    token.value = newToken;
  }

  function setUserInfo(info: Partial<UserCenter & UserInfo>) {
    userInfo.value = { ...userInfo.value, ...info };
  }

  function login(data: LoginResult) {
    token.value = data.token;
    userInfo.value = {
      id: data.id,
      sn: data.sn,
      nickname: data.nickname,
      avatar: data.avatar,
      mobile: data.mobile,
    };
  }

  function logout() {
    token.value = '';
    userInfo.value = {};
    uni.removeStorageSync(STORAGE_KEY);
  }

  return { token, userInfo, isLoggedIn, setToken, setUserInfo, login, logout };
});
