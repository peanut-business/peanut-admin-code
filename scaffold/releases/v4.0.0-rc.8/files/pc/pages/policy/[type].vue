<template>
  <div class="max-w-4xl mx-auto px-6 py-10">
    <h1 class="text-2xl font-bold text-gray-800 mb-8">{{ policy?.title }}</h1>
    <div
      v-if="policy?.content"
      class="bg-white rounded-xl p-8 shadow-sm prose max-w-none text-gray-600 leading-relaxed"
      v-html="safePolicyContent"
    />
    <el-empty v-else description="内容加载中..." />
  </div>
</template>

<script setup lang="ts">
  import sanitizeRichText from '~/utils/sanitize-rich-text';

  definePageMeta({ layout: 'default' });

  const route = useRoute();
  const type = route.params.type as string;
  const apiBase = useRuntimeConfig().public.apiBase;

  const { data } = await useFetch<{
    code: number;
    data: { title: string; content: string };
  }>(`${apiBase}/api/index/policy?type=${type}`);
  const policy = computed(() => data.value?.data || null);
  const safePolicyContent = computed(() =>
    sanitizeRichText(policy.value?.content)
  );

  useHead({ title: computed(() => policy.value?.title || '政策') });
</script>
