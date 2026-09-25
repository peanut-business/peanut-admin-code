<template>
  <view class="login-page">
    <view class="logo-area">
      <image :src="appLogo" class="logo" mode="aspectFit" />
      <view class="app-name">{{ appName }}</view>
    </view>

    <view class="form">
      <view class="input-group">
        <input
          v-model="form.account"
          placeholder="请输入账号"
          class="input"
          type="text"
        />
      </view>
      <view class="input-group">
        <input
          v-model="form.password"
          placeholder="请输入密码"
          class="input"
          type="password"
        />
      </view>

      <button class="btn-primary" :disabled="loading" @click="handleLogin">
        {{ loading ? '登录中...' : '登录' }}
      </button>

      <button
        v-if="isMiniProgram"
        class="btn-wechat"
        :disabled="wechatLoading"
        @click="handleMiniProgramLogin"
      >
        {{ wechatLoading ? '微信登录中...' : '微信一键登录' }}
      </button>
      <button
        v-else
        class="btn-wechat"
        :disabled="wechatLoading"
        @click="handleOfficialAccountLogin"
      >
        {{ wechatLoading ? '正在跳转...' : '微信公众号登录' }}
      </button>
    </view>

    <view class="links">
      <text @click="goRegister">注册账号</text>
      <text @click="goForget">忘记密码</text>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { useAppStore } from '@/store/app';
  import { useUserStore } from '@/store/user';
  import { loginByAccount } from '@/api/account';
  import { computed } from 'vue';
  import {
    beginWechatOAuth,
    loginWechatMiniProgram,
    stashOAuthCompletion,
    type OAuthResult,
  } from '@/api/oauth';
  import { resolveBrandLogo, resolveBrandName } from '@/utils/brand';

  const appStore = useAppStore();
  const userStore = useUserStore();
  const appName = computed(() => resolveBrandName(appStore.config?.website));
  const appLogo = computed(() => resolveBrandLogo(appStore.config?.website));

  const loading = ref(false);
  const wechatLoading = ref(false);
  const form = ref({ account: '', password: '' });
  const runtimeInfo = (
    typeof uni !== 'undefined' && typeof uni.getSystemInfoSync === 'function'
      ? uni.getSystemInfoSync()
      : {}
  ) as { uniPlatform?: string };
  const isMiniProgram = computed(() => runtimeInfo.uniPlatform === 'mp-weixin');

  async function handleLogin() {
    if (!form.value.account)
      return uni.showToast({ title: '请输入账号', icon: 'none' });
    if (!form.value.password)
      return uni.showToast({ title: '请输入密码', icon: 'none' });

    loading.value = true;
    try {
      const data = await loginByAccount(form.value);
      userStore.login(data);
      uni.reLaunch({ url: '/pages/user/user' });
    } finally {
      loading.value = false;
    }
  }

  async function handleMiniProgramLogin() {
    wechatLoading.value = true;
    try {
      const code = await new Promise<string>((resolve, reject) => {
        uni.login({
          provider: 'weixin',
          success: (result) => {
            const value = String((result as { code?: string }).code || '');
            value ? resolve(value) : reject(new Error('微信登录凭证缺失'));
          },
          fail: reject,
        });
      });
      await consumeWechatResult(await loginWechatMiniProgram(code));
    } catch (error) {
      uni.showToast({
        title: error instanceof Error ? error.message : '微信登录失败',
        icon: 'none',
      });
    } finally {
      wechatLoading.value = false;
    }
  }

  async function handleOfficialAccountLogin() {
    wechatLoading.value = true;
    try {
      const result = await beginWechatOAuth({
        scene: 'oa',
        return_path: '/pages/user/user',
      });
      const location = (globalThis as { location?: { href: string } }).location;
      if (location) {
        location.href = result.authorization_url;
      } else {
        uni.setClipboardData({ data: result.authorization_url });
        uni.showModal({
          title: '请打开微信授权链接',
          content: result.authorization_url,
          showCancel: false,
        });
      }
    } catch (error) {
      uni.showToast({
        title: error instanceof Error ? error.message : '微信授权失败',
        icon: 'none',
      });
    } finally {
      wechatLoading.value = false;
    }
  }

  async function consumeWechatResult(result: OAuthResult) {
    if (result.completed && result.token) {
      // A completion ticket is not a login token. Only persist a token after the
      // server has returned completed=true.
      userStore.setToken(result.token);
      userStore.setUserInfo(result.member);
      uni.reLaunch({ url: '/pages/user/user' });
      return;
    }
    if (!result.completion_ticket) throw new Error('微信登录补全票据缺失');
    stashOAuthCompletion(result, '/pages/user/user');
    uni.navigateTo({ url: '/pages/oauth/complete' });
  }

  function goRegister() {
    uni.navigateTo({ url: '/pages/register/register' });
  }
  function goForget() {
    uni.navigateTo({ url: '/pages/forget_pwd/forget_pwd' });
  }
</script>

<style scoped>
  .login-page {
    min-height: 100vh;
    background: #fff;
    padding: 0 60rpx;
  }
  .logo-area {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 120rpx 0 80rpx;
  }
  .logo {
    width: 160rpx;
    height: 160rpx;
    border-radius: 32rpx;
  }
  .app-name {
    margin-top: 24rpx;
    font-size: 40rpx;
    font-weight: 700;
    color: #333;
  }
  .form {
    margin-top: 40rpx;
  }
  .input-group {
    border-bottom: 1rpx solid #eee;
    margin-bottom: 40rpx;
  }
  .input {
    width: 100%;
    height: 80rpx;
    font-size: 30rpx;
    color: #333;
  }
  .btn-primary {
    width: 100%;
    height: 90rpx;
    background: #2979ff;
    color: #fff;
    font-size: 32rpx;
    border-radius: 45rpx;
    border: none;
    margin-top: 40rpx;
  }
  .btn-primary[disabled] {
    opacity: 0.6;
  }
  .btn-wechat {
    width: 100%;
    height: 90rpx;
    background: #07c160;
    color: #fff;
    font-size: 32rpx;
    border-radius: 45rpx;
    border: none;
    margin-top: 24rpx;
  }
  .btn-wechat[disabled] {
    opacity: 0.6;
  }
  .links {
    display: flex;
    justify-content: space-between;
    margin-top: 30rpx;
    font-size: 28rpx;
    color: #2979ff;
  }
</style>
