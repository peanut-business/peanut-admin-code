import { defineConfig } from 'vitepress';
export default defineConfig({ title: 'Application documentation', themeConfig: { nav: [{ text: '开始', link: '/getting-started' }, { text: '模块', link: '/guide/application-module-lifecycle' }, { text: '参考', link: '/reference' }] } });
