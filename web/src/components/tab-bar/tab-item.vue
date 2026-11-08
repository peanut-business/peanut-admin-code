<template>
  <TabBarMenu v-slot="{ closeCurrent }" :item-data="itemData" :index="index">
    <el-tag
      :class="{ 'link-activated': getTabKey(itemData) === getTabKey(route) }"
      :closable="index !== 0"
      @click="router.push(itemData.fullPath)"
      @close="closeCurrent"
    >
      <span class="tag-link">{{ $t(itemData.title) }}</span>
    </el-tag>
  </TabBarMenu>
</template>

<script lang="ts" setup>
  import { useRouter, useRoute } from 'vue-router';
  import { getTabKey, type ShellTab } from '@peanut-admin/ui-vue/tabs';
  import TabBarMenu from './TabBarMenu.vue';

  defineProps<{ itemData: ShellTab; index: number }>();
  const router = useRouter();
  const route = useRoute();
</script>

<style scoped lang="less">
  .el-tag {
    padding-inline: 16px;
    font-size: 13px;
    border: 0;
    border-bottom: 2px solid transparent;
    border-radius: 0;
    background: transparent;
  }
  .tag-link {
    color: var(--el-text-color-regular);
    text-decoration: none;
  }
  .link-activated {
    color: var(--el-color-primary);
    border-bottom-color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
    .tag-link {
      color: var(--el-color-primary);
      font-weight: 500;
    }
    :deep(.el-tag__close) {
      color: var(--el-color-primary);
    }
  }
</style>
