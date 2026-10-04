<template>
  <view class="page">
    <view class="form">
      <view class="input-group">
        <view class="input-label">原密码</view>
        <input
          v-model="form.old_password"
          class="input"
          type="password"
          placeholder="请输入原密码"
        />
      </view>
      <view class="input-group">
        <view class="input-label">新密码</view>
        <input
          v-model="form.new_password"
          class="input"
          type="password"
          placeholder="请输入新密码"
        />
      </view>
      <view class="input-group">
        <view class="input-label">确认密码</view>
        <input
          v-model="form.new_password_confirm"
          class="input"
          type="password"
          placeholder="请再次输入新密码"
        />
      </view>
    </view>

    <view class="btn-area">
      <button class="btn-primary" :disabled="loading" @click="handleSubmit">
        {{ loading ? '提交中...' : '确认修改' }}
      </button>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import { changePassword } from '@/api/user';

  const loading = ref(false);
  const form = ref({
    old_password: '',
    new_password: '',
    new_password_confirm: '',
  });

  async function handleSubmit() {
    if (!form.value.old_password)
      return uni.showToast({ title: '请输入原密码', icon: 'none' });
    if (!form.value.new_password)
      return uni.showToast({ title: '请输入新密码', icon: 'none' });
    if (form.value.new_password !== form.value.new_password_confirm)
      return uni.showToast({ title: '两次密码不一致', icon: 'none' });

    loading.value = true;
    try {
      await changePassword(form.value);
      uni.showToast({ title: '修改成功' });
      setTimeout(() => uni.navigateBack(), 800);
    } finally {
      loading.value = false;
    }
  }
</script>

<style scoped>
  .page {
    background: #f5f5f5;
    min-height: 100vh;
  }
  .form {
    background: #fff;
    margin-top: 20rpx;
  }
  .input-group {
    padding: 24rpx 32rpx;
    border-bottom: 1rpx solid #f5f5f5;
  }
  .input-label {
    font-size: 26rpx;
    color: #999;
    margin-bottom: 12rpx;
  }
  .input {
    font-size: 30rpx;
    color: #333;
  }
  .btn-area {
    padding: 60rpx 40rpx;
  }
  .btn-primary {
    width: 100%;
    height: 90rpx;
    background: #2979ff;
    color: #fff;
    font-size: 32rpx;
    border-radius: 45rpx;
    border: none;
  }
  .btn-primary[disabled] {
    opacity: 0.6;
  }
</style>
