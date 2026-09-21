<template>
  <div>
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>

<script setup lang="ts">
const appStore = useAppStore()
const website = computed(() => appStore.website)

useHead({
  title: computed(() => website.value.pc_title),
  meta: [
    { name: 'description', content: computed(() => website.value.pc_desc) },
    { name: 'keywords', content: computed(() => website.value.pc_keywords) },
  ],
  link: [{ rel: 'icon', href: computed(() => website.value.pc_ico) }],
})

await appStore.loadConfig()
</script>
