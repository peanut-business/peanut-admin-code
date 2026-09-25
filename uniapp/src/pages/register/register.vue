<template>
  <view class="register-page">
    <view class="title-area">
      <view class="title">创建账号</view>
      <view class="subtitle">欢迎加入我们</view>
    </view>

    <view class="form">
      <view class="input-group">
        <input
          v-model="form.account"
          placeholder="请设置账号（字母或数字）"
          class="input"
          type="text"
        />
      </view>
      <view class="input-group">
        <input
          v-model="form.password"
          placeholder="请设置密码"
          class="input"
          type="password"
        />
      </view>
      <view class="input-group">
        <input
          v-model="form.password_confirm"
          placeholder="请再次输入密码"
          class="input"
          type="password"
        />
      </view>

      <button class="btn-primary" :disabled="loading" @click="handleRegister">
        {{ loading ? '注册中...' : '立即注册' }}
      </button>
    </view>

    <view class="footer">
      <text>已有账号？</text>
      <text class="link" @click="goLogin">立即登录</text>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { register } from '@/api/account';

  const loading = ref(false);
  const form = ref({ account: '', password: '', password_confirm: '' });

  async function handleRegister() {
    if (!form.value.account)
      return uni.showToast({ title: '请输入账号', icon: 'none' });
    if (!form.value.password)
      return uni.showToast({ title: '请输入密码', icon: 'none' });
    if (form.value.password !== form.value.password_confirm)
      return uni.showToast({ title: '两次密码不一致', icon: 'none' });

    loading.value = true;
    try {
      await register(form.value);
      uni.showToast({ title: '注册成功，请登录', icon: 'success' });
      uni.reLaunch({ url: '/pages/login/login' });
    } finally {
      loading.value = false;
    }
  }

  function goLogin() {
    uni.navigateTo({ url: '/pages/login/login' });
  }
</script>

<style scoped>
  .register-page {
    min-height: 100vh;
    background: #fff;
    padding: 0 60rpx;
  }
  .title-area {
    padding: 120rpx 0 80rpx;
  }
  .title {
    font-size: 48rpx;
    font-weight: 700;
    color: #333;
  }
  .subtitle {
    font-size: 28rpx;
    color: #999;
    margin-top: 16rpx;
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
  .footer {
    text-align: center;
    margin-top: 40rpx;
    font-size: 28rpx;
    color: #666;
  }
  .link {
    color: #2979ff;
  }
</style>
