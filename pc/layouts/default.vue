<template>
  <div class="min-h-screen flex flex-col bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm sticky top-0 z-50">
      <div
        class="max-w-6xl mx-auto px-6 h-16 flex items-center justify-between"
      >
        <NuxtLink to="/" class="flex items-center gap-3">
          <img
            :src="website.pc_logo"
            alt="logo"
            class="h-8 w-8 rounded object-contain"
          />
          <span class="text-lg font-bold text-gray-800">{{
            website.pc_title
          }}</span>
        </NuxtLink>

        <nav class="flex items-center gap-8">
          <NuxtLink
            to="/"
            class="text-gray-600 hover:text-primary transition-colors"
            >首页</NuxtLink
          >
          <NuxtLink
            to="/information"
            class="text-gray-600 hover:text-primary transition-colors"
            >资讯</NuxtLink
          >
          <NuxtLink
            to="/about"
            class="text-gray-600 hover:text-primary transition-colors"
            >关于我们</NuxtLink
          >
          <NuxtLink
            v-if="isLoggedIn"
            to="/recharge"
            class="text-gray-600 hover:text-primary transition-colors"
            >充值</NuxtLink
          >
        </nav>

        <ClientOnly>
          <div class="flex items-center gap-3">
            <template v-if="isLoggedIn">
              <el-dropdown>
                <div class="flex items-center gap-2 cursor-pointer">
                  <el-avatar
                    :size="32"
                    :src="(userInfo?.avatar as string) || ''"
                  />
                  <span class="text-gray-700">{{
                    userInfo?.nickname || '用户'
                  }}</span>
                </div>
                <template #dropdown>
                  <el-dropdown-menu>
                    <el-dropdown-item @click="$router.push('/user/info')"
                      >个人资料</el-dropdown-item
                    >
                    <el-dropdown-item @click="$router.push('/user/collection')"
                      >我的收藏</el-dropdown-item
                    >
                    <el-dropdown-item @click="$router.push('/account/security')"
                      >账户安全</el-dropdown-item
                    >
                    <el-dropdown-item divided @click="handleLogout"
                      >退出登录</el-dropdown-item
                    >
                  </el-dropdown-menu>
                </template>
              </el-dropdown>
            </template>
            <template v-else>
              <NuxtLink to="/login">
                <el-button type="primary" size="small">登录</el-button>
              </NuxtLink>
            </template>
          </div>
        </ClientOnly>
      </div>
    </header>

    <!-- Main -->
    <main class="flex-1">
      <slot />
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-100 py-8 mt-12">
      <div class="max-w-6xl mx-auto px-6 text-center text-gray-400 text-sm">
        <p>© {{ new Date().getFullYear() }} {{ website.copyright }}</p>
        <div class="flex justify-center gap-6 mt-3">
          <NuxtLink
            to="/policy/privacy"
            class="hover:text-primary transition-colors"
            >隐私政策</NuxtLink
          >
          <NuxtLink
            to="/policy/service"
            class="hover:text-primary transition-colors"
            >用户协议</NuxtLink
          >
        </div>
      </div>
    </footer>
  </div>
</template>

<script setup lang="ts">
  const appStore = useAppStore();
  const userStore = import.meta.client ? useUserStore() : null;
  const request = useRequest();

  const website = computed(() => appStore.website);
  const isLoggedIn = computed(() => userStore?.isLoggedIn ?? false);
  const userInfo = computed(() => userStore?.userInfo);

  async function handleLogout() {
    let revoked = true;
    try {
      await request.post('api/login/logout');
    } catch {
      revoked = false;
    } finally {
      userStore?.clearSession();
    }
    await navigateTo('/');
    if (revoked) {
      ElMessage.success('已退出登录');
    } else {
      ElMessage.warning('服务端撤销失败，已清除本地会话');
    }
  }
</script>
