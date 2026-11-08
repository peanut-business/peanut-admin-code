<template>
  <footer
    v-if="sidebar"
    class="footer sidebar-footer"
    :class="{ 'is-compact': compact }"
  >
    <el-tooltip :content="$t('footer.about')" placement="right">
      <el-button
        class="about-button"
        :aria-label="$t('footer.about')"
        text
        @click="aboutVisible = true"
      >
        <el-icon><InfoFilled /></el-icon>
        <span v-if="!compact">{{ $t('footer.about') }}</span>
      </el-button>
    </el-tooltip>
    <el-dialog
      v-model="aboutVisible"
      :title="$t('footer.about')"
      width="min(360px, calc(100vw - 24px))"
      append-to-body
    >
      <dl class="about-details">
        <dt>{{ $t('footer.brand') }}</dt>
        <dd>{{ brandStore.website.name }}</dd>
        <dt>{{ $t('footer.version') }}</dt>
        <dd>
          {{ version }}
          <span v-if="isDevelopment" class="environment">
            DEV · {{ $t('footer.development') }}
          </span>
        </dd>
        <dt>{{ $t('footer.copyright') }}</dt>
        <dd>
          © {{ new Date().getFullYear() }} {{ brandStore.website.copyright }}
        </dd>
      </dl>
    </el-dialog>
  </footer>
  <footer v-else class="footer">
    © {{ new Date().getFullYear() }} {{ brandStore.website.copyright }}
  </footer>
</template>

<script lang="ts" setup>
  import { ref } from 'vue';
  import { InfoFilled } from '@element-plus/icons-vue';
  import { useBrandStore } from '@/store';
  import packageJson from '../../../package.json';

  withDefaults(defineProps<{ sidebar?: boolean; compact?: boolean }>(), {
    sidebar: false,
    compact: false,
  });

  const brandStore = useBrandStore();
  const aboutVisible = ref(false);
  const isDevelopment = import.meta.env.DEV;
  const { version } = packageJson;
</script>

<style lang="less" scoped>
  .footer {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 40px;
    color: var(--el-text-color-secondary);
    text-align: center;
  }
  .sidebar-footer {
    flex: 0 0 auto;
    height: auto;
    min-height: 40px;
    padding: 4px 8px;
    border-top: 1px solid var(--el-border-color-lighter);
    .about-button {
      width: 100%;
      min-height: 32px;
      justify-content: flex-start;
      color: var(--el-text-color-secondary);
    }
    &.is-compact .about-button {
      justify-content: center;
    }
  }
  .about-details {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 12px;
    margin: 0;
    dt {
      color: var(--el-text-color-secondary);
    }
    dd {
      min-width: 0;
      margin: 0;
      overflow-wrap: anywhere;
    }
  }
  .environment {
    display: block;
    margin-top: 4px;
    color: var(--el-color-warning);
    font-size: 12px;
  }
</style>
