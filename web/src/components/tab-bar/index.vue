<template>
  <div class="tab-bar-container">
    <div class="tab-bar-box">
      <div ref="tabScrollRef" class="tab-bar-scroll">
        <div class="tags-wrap">
          <tab-item
            v-for="(tag, index) in tagList"
            :key="tag.fullPath"
            :index="index"
            :item-data="tag"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { ref, computed, watch, nextTick } from 'vue';
  import { useRoute } from 'vue-router';
  import { useTabBarStore } from '@/store';
  import tabItem from './tab-item.vue';

  const tabBarStore = useTabBarStore();
  const route = useRoute();

  const tabScrollRef = ref<HTMLElement>();
  const tagList = computed(() => {
    return tabBarStore.getTabList;
  });

  const scrollActiveTabIntoView = async () => {
    await nextTick();
    const container = tabScrollRef.value;
    const activeTab = container?.querySelector<HTMLElement>('.link-activated');
    if (!container || !activeTab) return;

    const containerBounds = container.getBoundingClientRect();
    const tabBounds = activeTab.getBoundingClientRect();
    if (tabBounds.left < containerBounds.left) {
      container.scrollLeft += tabBounds.left - containerBounds.left;
    } else if (tabBounds.right > containerBounds.right) {
      container.scrollLeft += tabBounds.right - containerBounds.right;
    }
  };

  watch(
    () => route.fullPath,
    async () => {
      if (!route.meta.noAffix) tabBarStore.updateTabList(route);
      await scrollActiveTabIntoView();
    },
    { immediate: true, flush: 'post' }
  );
</script>

<style scoped lang="less">
  .tab-bar-container {
    position: relative;
    margin-bottom: 16px;
    .tab-bar-box {
      display: flex;
      height: 40px;
      padding-inline: 0;
      border-bottom: 1px solid var(--el-border-color-lighter);
      .tab-bar-scroll {
        min-width: 0;
        height: 100%;
        flex: 1;
        overflow-x: auto;
        overflow-y: hidden;
        scrollbar-width: none;

        &::-webkit-scrollbar {
          height: 0;
        }

        .tags-wrap {
          display: flex;
          width: max-content;
          min-width: 100%;
          height: 100%;
          align-items: center;
          gap: 4px;
          white-space: nowrap;

          :deep(.el-dropdown) {
            flex: 0 0 auto;
          }

          :deep(.el-tag) {
            display: inline-flex;
            align-items: center;
            height: 40px;
            flex: 0 0 auto;
            cursor: pointer;
          }
        }
      }
    }
  }
</style>
