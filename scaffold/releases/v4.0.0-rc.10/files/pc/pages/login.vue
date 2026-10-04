<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center">
    <div class="bg-white rounded-2xl shadow-sm p-10 w-full max-w-md">
      <div class="text-center mb-8">
        <h2 class="text-2xl font-bold text-gray-800">登录</h2>
        <p class="text-gray-400 text-sm mt-2">欢迎回来</p>
      </div>

      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        size="large"
        @submit.prevent="handleLogin"
      >
        <el-form-item prop="account">
          <el-input
            v-model="form.account"
            placeholder="账号"
            prefix-icon="User"
          />
        </el-form-item>
        <el-form-item prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="密码"
            prefix-icon="Lock"
            show-password
          />
        </el-form-item>
        <el-form-item>
          <el-button
            type="primary"
            class="w-full"
            :loading="loading"
            native-type="submit"
            >登录</el-button
          >
        </el-form-item>
      </el-form>

      <el-divider content-position="center">或</el-divider>
      <el-button
        class="w-full"
        :loading="wechatLoading"
        @click="handleWechatLogin"
      >
        使用微信登录
      </el-button>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { beginWechatPc } from '~/api/oauth';

  definePageMeta({ layout: false });

  const userStore = useUserStore();
  if (userStore.isLoggedIn) await navigateTo('/');

  const apiBase = useRuntimeConfig().public.apiBase;
  const loading = ref(false);
  const wechatLoading = ref(false);
  const formRef = ref();
  const form = ref({ account: '', password: '' });
  const rules = {
    account: [{ required: true, message: '请输入账号', trigger: 'blur' }],
    password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
  };

  async function handleLogin() {
    await formRef.value?.validate();
    loading.value = true;
    try {
      const res = await $fetch<{
        code: number;
        msg: string;
        data: {
          token: string;
          id: number;
          sn: string;
          nickname: string;
          avatar: string;
          mobile: string;
        };
      }>(`${apiBase}/api/login/account`, { method: 'POST', body: form.value });
      if (res.code !== 20000) return ElMessage.error(res.msg || '登录失败');
      userStore.login(res.data);
      ElMessage.success('登录成功');
      await navigateTo('/');
    } catch (error) {
      ElMessage.error('登录失败，请重试');
    } finally {
      loading.value = false;
    }
  }

  async function handleWechatLogin() {
    wechatLoading.value = true;
    try {
      const result = await beginWechatPc(useRequest(), '/');
      if (!result.authorization_url) {
        ElMessage.error('微信授权地址为空');
        return;
      }
      window.location.assign(result.authorization_url);
    } catch {
      // useRequest already reports the server message.
    } finally {
      wechatLoading.value = false;
    }
  }
</script>
