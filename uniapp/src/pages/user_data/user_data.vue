<template>
  <view class="page">
    <view class="avatar-row" @click="chooseAvatar">
      <text class="label">头像</text>
      <view class="right">
        <image :src="form.avatar || '/static/avatar.png'" class="avatar" />
        <text class="arrow">›</text>
      </view>
    </view>

    <view class="row">
      <text class="label">昵称</text>
      <input
        v-model="form.nickname"
        class="row-input"
        placeholder="请输入昵称"
      />
    </view>

    <view class="row">
      <text class="label">性别</text>
      <picker :value="sexIndex" :range="sexOptions" @change="onSexChange">
        <view class="right">
          <text>{{ sexOptions[sexIndex] }}</text>
          <text class="arrow">›</text>
        </view>
      </picker>
    </view>

    <view class="row">
      <text class="label">生日</text>
      <picker mode="date" :value="form.birthday" @change="onBirthdayChange">
        <view class="right">
          <text>{{ form.birthday || '请选择' }}</text>
          <text class="arrow">›</text>
        </view>
      </picker>
    </view>

    <view class="row">
      <text class="label">邮箱</text>
      <input
        v-model="form.email"
        class="row-input"
        placeholder="请输入邮箱"
        type="email"
      />
    </view>

    <view class="btn-area">
      <button class="btn-primary" :disabled="loading" @click="handleSave">
        {{ loading ? '保存中...' : '保存' }}
      </button>
    </view>
  </view>
</template>

<script setup lang="ts">
  import { ref, onMounted } from 'vue';
  import { useUserStore } from '@/store/user';
  import { getUserInfo, setUserInfo } from '@/api/user';

  const userStore = useUserStore();
  const loading = ref(false);
  const sexOptions = ['未知', '男', '女'];
  const sexIndex = ref(0);

  const form = ref({ nickname: '', avatar: '', birthday: '', email: '' });

  onMounted(async () => {
    try {
      const info = await getUserInfo();
      form.value = {
        nickname: info.nickname,
        avatar: info.avatar,
        birthday: info.birthday,
        email: info.email,
      };
      sexIndex.value = info.sex || 0;
    } catch (error) {
      console.error('Failed to load user info:', error);
    }
  });

  function onSexChange(e: any) {
    sexIndex.value = Number(e.detail.value);
  }
  function onBirthdayChange(e: any) {
    form.value.birthday = e.detail.value;
  }

  function chooseAvatar() {
    uni.chooseImage({
      count: 1,
      success(res) {
        // avatar upload would normally call upload API — store locally for now
        form.value.avatar = res.tempFilePaths[0];
      },
    });
  }

  async function handleSave() {
    loading.value = true;
    try {
      await setUserInfo({ ...form.value, sex: sexIndex.value });
      userStore.setUserInfo({
        nickname: form.value.nickname,
        avatar: form.value.avatar,
      });
      uni.showToast({ title: '保存成功' });
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
  .avatar-row,
  .row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
    padding: 28rpx 32rpx;
    border-bottom: 1rpx solid #f5f5f5;
  }
  .label {
    font-size: 28rpx;
    color: #333;
  }
  .right {
    display: flex;
    align-items: center;
    gap: 16rpx;
  }
  .avatar {
    width: 80rpx;
    height: 80rpx;
    border-radius: 50%;
  }
  .arrow {
    font-size: 36rpx;
    color: #ccc;
  }
  .row-input {
    text-align: right;
    font-size: 28rpx;
    color: #666;
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
