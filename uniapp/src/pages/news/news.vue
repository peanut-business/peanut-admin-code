<template>
  <view class="news-page">
    <view class="tabs">
      <view
        v-for="cate in categories"
        :key="cate.id"
        class="tab-item"
        :class="{ active: currentCateId === cate.id }"
        @click="switchCate(cate.id)"
      >
        {{ cate.name }}
      </view>
    </view>

    <scroll-view scroll-y class="article-list">
      <view
        v-for="item in articles"
        :key="item.id"
        class="article-item"
        @click="goDetail(item.id)"
      >
        <image :src="item.image" class="article-img" mode="aspectFill" />
        <view class="article-info">
          <view class="article-title">{{ item.title }}</view>
          <view class="article-desc">{{ item.desc }}</view>
          <view class="article-meta">
            <text>{{ item.author }}</text>
            <text>{{ item.click }}次浏览</text>
          </view>
        </view>
      </view>
    </scroll-view>
    <DecorationTabbar />
  </view>
</template>

<script setup lang="ts">
  import { ref, onMounted } from 'vue';
  import { getArticleCate, getArticleLists } from '@/api/news';
  import type { Article, ArticleCate } from '@/api/news';
  import DecorationTabbar from '@/components/DecorationTabbar.vue';

  const categories = ref<ArticleCate[]>([]);
  const currentCateId = ref<number>(0);
  const articles = ref<Article[]>([]);

  onMounted(async () => {
    await loadCategories();
    await loadArticles();
  });

  async function loadCategories() {
    try {
      const list = await getArticleCate();
      categories.value = [{ id: 0, name: '全部', image: '', sort: 0 }, ...list];
    } catch (error) {
      categories.value = [];
      console.error('Failed to load categories:', error);
    }
  }

  async function loadArticles() {
    try {
      const data = await getArticleLists({
        cid: currentCateId.value || undefined,
      });
      articles.value = data.lists;
    } catch (error) {
      articles.value = [];
      console.error('Failed to load articles:', error);
    }
  }

  function switchCate(id: number) {
    currentCateId.value = id;
    loadArticles();
  }

  function goDetail(id: number) {
    uni.navigateTo({ url: `/pages/news_detail/news_detail?id=${id}` });
  }
</script>

<style scoped>
  .news-page {
    display: flex;
    flex-direction: column;
    height: 100vh;
    padding-bottom: calc(120rpx + env(safe-area-inset-bottom));
    background: #f5f5f5;
    box-sizing: border-box;
  }
  .tabs {
    display: flex;
    background: #fff;
    padding: 0 24rpx;
    border-bottom: 1rpx solid #eee;
    overflow-x: auto;
    white-space: nowrap;
  }
  .tab-item {
    padding: 24rpx 20rpx;
    font-size: 28rpx;
    color: #666;
    display: inline-block;
  }
  .tab-item.active {
    color: #2979ff;
    border-bottom: 4rpx solid #2979ff;
    font-weight: 600;
  }
  .article-list {
    flex: 1;
    padding: 24rpx;
  }
  .article-item {
    display: flex;
    background: #fff;
    border-radius: 12rpx;
    margin-bottom: 20rpx;
    overflow: hidden;
  }
  .article-img {
    width: 200rpx;
    height: 160rpx;
    flex-shrink: 0;
  }
  .article-info {
    flex: 1;
    padding: 16rpx 20rpx;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .article-title {
    font-size: 28rpx;
    color: #333;
    font-weight: 500;
    line-height: 1.4;
  }
  .article-desc {
    font-size: 24rpx;
    color: #999;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }
  .article-meta {
    display: flex;
    justify-content: space-between;
    font-size: 22rpx;
    color: #999;
  }
</style>
