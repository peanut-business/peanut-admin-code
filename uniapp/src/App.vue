<script setup lang="ts">
  import { onLaunch, onShow, onHide } from '@dcloudio/uni-app';
  import { useAppStore } from '@/store/app';

  const appStore = useAppStore();

  onLaunch(async () => {
    try {
      const config = await appStore.loadConfig();
      // #ifdef H5
      if (config?.web_page.status === 0) {
        const redirectUrl = config.web_page.page_url.trim();
        const target =
          config.web_page.page_status === 1 && redirectUrl
            ? redirectUrl
            : 'about:blank';
        window.location.replace(target);
      }
      // #endif
    } catch {
      // A failed public configuration request must not silently bypass the H5 guard.
      // #ifdef H5
      window.location.replace('about:blank');
      // #endif
    }
  });
  onShow(() => {
    console.log('App Show');
  });
  onHide(() => {
    console.log('App Hide');
  });
</script>
<style></style>
