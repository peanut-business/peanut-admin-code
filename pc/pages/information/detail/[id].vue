<template>
  <div class="max-w-4xl mx-auto px-6 py-10">
    <div v-if="article" class="bg-white rounded-2xl shadow-sm overflow-hidden">
      <img :src="article.image" :alt="article.title" class="w-full h-72 object-cover" />

      <div class="p-8">
        <div class="flex items-center gap-3 mb-4">
          <el-tag size="small">{{ article.cate_name }}</el-tag>
          <span class="text-gray-400 text-sm">{{ article.create_time }}</span>
        </div>

        <h1 class="text-3xl font-bold text-gray-800 mb-4">{{ article.title }}</h1>

        <div class="flex items-center justify-between text-sm text-gray-400 mb-8 pb-6 border-b">
          <span>{{ article.author }}</span>
          <div class="flex items-center gap-4">
            <span>{{ article.click }} 次浏览</span>
            <el-button
              :type="collected ? 'primary' : 'default'"
              size="small"
              :icon="collected ? 'StarFilled' : 'Star'"
              @click="toggleCollect"
            >{{ collected ? '已收藏' : '收藏' }}</el-button>
          </div>
        </div>

        <!-- Article body -->
        <div
          v-if="hydrated"
          class="prose max-w-none text-gray-700 leading-relaxed"
          v-html="safeArticleContent"
        />
        <p v-else class="prose max-w-none whitespace-pre-line text-gray-700 leading-relaxed">
          {{ ssrArticleContent }}
        </p>
      </div>
    </div>
    <el-empty v-else description="文章不存在" />
  </div>
</template>

<script setup lang="ts">
import sanitizeRichText, { richTextToPlainText } from '~/utils/sanitize-rich-text'
import {
  addArticleCollect,
  cancelArticleCollect,
  getArticleDetail,
} from '~/api/article'

definePageMeta({ layout: 'default' })

const route = useRoute()
const id = Number(route.params.id)
const userStore = import.meta.client ? useUserStore() : null
const request = useRequest()
const collected = ref(false)
const article = ref(
  Number.isInteger(id) && id > 0
    ? await getArticleDetail(request, id).catch(() => null)
    : null,
)
if (import.meta.server && !article.value) setResponseStatus(404)
const hydrated = ref(false)
onMounted(() => {
  hydrated.value = true
  if (userStore?.isLoggedIn && article.value) {
    request.get<{ collect?: boolean }>('api/article/detail', { id: article.value.id })
      .then(detail => { collected.value = detail.collect === true })
      .catch(() => {})
  }
})
const safeArticleContent = computed(() => sanitizeRichText(article.value?.content))
const ssrArticleContent = computed(() => richTextToPlainText(article.value?.content))

useSeoMeta({
  title: () => article.value?.title || '文章不存在',
  description: () => article.value?.abstract || article.value?.desc || ssrArticleContent.value.slice(0, 160),
  robots: () => article.value ? 'index,follow' : 'noindex,nofollow',
})

async function toggleCollect() {
  if (!userStore?.isLoggedIn) return navigateTo('/login')
  if (!article.value) return
  const updateCollect = collected.value ? cancelArticleCollect : addArticleCollect
  await updateCollect(request, article.value.id)
  collected.value = !collected.value
}
</script>
