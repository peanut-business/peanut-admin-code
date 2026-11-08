<template>
  <div class="tab-bar-container">
    <div class="tab-bar-box">
      <div v-if="tagList[0]" class="tab-bar-pinned">
        <tab-item :index="0" :item-data="tagList[0]" />
      </div>
      <div ref="tabScrollRef" class="tab-bar-scroll">
        <div class="tags-wrap">
          <tab-item
            v-for="(tag, index) in tagList.slice(1)"
            :key="getTabKey(tag)"
            :index="index + 1"
            :item-data="tag"
          />
        </div>
      </div>
      <TabBarMenu
        v-if="actionTag"
        class="tab-bar-actions"
        show-tabs
        :index="actionIndex"
        :item-data="actionTag"
      />
    </div>
  </div>
</template>

<script lang="ts" setup>
  import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
  import { useRoute } from 'vue-router';
  import { getTabKey } from '@peanut-admin/ui-vue/tabs';
  import { useTabBarStore } from '@/store';
  import tabItem from './tab-item.vue';
  import TabBarMenu from './TabBarMenu.vue';

  const tabBarStore = useTabBarStore();
  const route = useRoute();

  const tabScrollRef = ref<HTMLElement>();
  const tagList = computed(() => {
    return tabBarStore.getTabList;
  });
  const actionIndex = computed(() => {
    const index = tagList.value.findIndex(
      (tag) => getTabKey(tag) === getTabKey(route)
    );
    return index < 0 ? 0 : index;
  });
  const actionTag = computed(() => tagList.value[actionIndex.value]);

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

  let resizeObserver: ResizeObserver;
  onMounted(() => {
    resizeObserver = new ResizeObserver(scrollActiveTabIntoView);
    if (tabScrollRef.value) resizeObserver.observe(tabScrollRef.value);
  });
  onBeforeUnmount(() => resizeObserver.disconnect());

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
    background: var(--el-bg-color);
    border-bottom: 1px solid var(--el-border-color-lighter);
    .tab-bar-box {
      display: flex;
      height: 44px;
      padding-inline: 16px;
      align-items: center;
      gap: 8px;
      .tab-bar-pinned {
        min-width: 0;
        max-width: 35%;
        flex: 0 1 auto;
        overflow: hidden;
      }
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
          gap: 0;
          white-space: nowrap;

          :deep(.el-dropdown) {
            flex: 0 0 auto;
          }

          :deep(.el-tag) {
            display: inline-flex;
            align-items: center;
            height: 44px;
            flex: 0 0 auto;
            cursor: pointer;
          }
        }
      }
      .tab-bar-actions {
        flex: 0 0 32px;
      }
      .tab-bar-pinned :deep(.el-tag) {
        height: 44px;
        max-width: 100%;
      }
      .tab-bar-pinned :deep(.tag-link) {
        display: block;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }
  }
</style>
