import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { ConfigData } from '@/api/index';
import { getConfig } from '@/api/index';
import { applyDecorationTheme } from '@/utils/decoration';

export const useAppStore = defineStore('app', () => {
  const config = ref<ConfigData | null>(null);

  async function loadConfig() {
    if (config.value) {
      applyDecorationTheme(config.value.theme);
      return config.value;
    }
    try {
      config.value = await getConfig();
      applyDecorationTheme(config.value.theme);
      return config.value;
    } catch (error) {
      console.error('Failed to load config:', error);
      throw error;
    }
  }

  return {
    config,
    loadConfig,
  };
});
