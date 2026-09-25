import { defineStore } from 'pinia';
import defaultBrand from '@/generated/brand.json';
import {
  getPublicBrandConfig,
  type DemoLoginConfig,
  type WebsiteConfig,
} from '@/api/system/config';

const IMAGE_FIELDS: (keyof WebsiteConfig)[] = [
  'web_favicon',
  'web_logo',
  'login_image',
  'shop_logo',
  'pc_logo',
  'pc_ico',
  'h5_favicon',
];

const fallback = { ...defaultBrand.website } as WebsiteConfig;
IMAGE_FIELDS.forEach((field) => {
  const value = fallback[field];
  if (value && !value.startsWith('/') && !value.startsWith('http')) {
    fallback[field] = `/${value}`;
  }
});

function applyDocumentBrand(website: WebsiteConfig, tenantName: string) {
  document.title = tenantName
    ? `${tenantName} - ${website.name}`
    : website.name;
  let favicon = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
  if (!favicon) {
    favicon = document.createElement('link');
    favicon.rel = 'icon';
    document.head.appendChild(favicon);
  }
  favicon.href = website.web_favicon;
}

const useBrandStore = defineStore('brand', {
  state: () => ({
    website: { ...fallback } as WebsiteConfig,
    entryTenantName: '',
    tenantName: '',
    demo: { enabled: false, email: '', password: '' } as DemoLoginConfig,
    loaded: false,
  }),
  actions: {
    replace(website: WebsiteConfig) {
      this.website = { ...fallback, ...website };
      this.applyTitle();
    },
    applyTitle() {
      applyDocumentBrand(this.website, this.tenantName || this.entryTenantName);
    },
    setEntryTenantName(tenantName?: string) {
      this.entryTenantName = tenantName?.trim() || '';
      this.applyTitle();
    },
    setTenantName(tenantName?: string) {
      this.tenantName = tenantName?.trim() || '';
      this.applyTitle();
    },
    async load() {
      if (this.loaded) return;
      try {
        const { data } = await getPublicBrandConfig();
        this.demo = data.demo || { enabled: false, email: '', password: '' };
        this.setEntryTenantName(data.tenantName);
        this.replace(data.website);
      } catch {
        this.replace(fallback);
      } finally {
        this.loaded = true;
      }
    },
  },
});

export default useBrandStore;
