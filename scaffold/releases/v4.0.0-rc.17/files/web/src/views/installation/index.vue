<template>
  <main class="installation-page">
    <section class="installation-card" aria-live="polite">
      <header class="installation-brand">
        <img
          :src="brandStore.website.web_logo || '/brand/logo.svg'"
          :alt="$t('installation.brand.alt')"
          class="installation-logo"
        />
        <span>{{ brandStore.website.name }}</span>
      </header>

      <template v-if="loading">
        <el-skeleton :rows="8" animated />
      </template>

      <template v-else-if="blocked">
        <el-result
          icon="error"
          :title="$t('installation.preflight.blockedTitle')"
          :sub-title="blockedReason"
        >
          <template #extra>
            <div v-if="preflightChecks.length" class="preflight-checks">
              <div
                v-for="(check, index) in preflightChecks"
                :key="check.id || check.code || check.reason || index"
                class="preflight-check"
              >
                <div class="preflight-check-reason">
                  {{
                    check.reason ||
                    check.code ||
                    $t('installation.preflight.blocked')
                  }}
                </div>
                <div
                  v-if="check.remediation"
                  class="preflight-check-remediation"
                >
                  {{ check.remediation }}
                </div>
              </div>
            </div>
            <el-button
              :loading="refreshing"
              type="primary"
              @click="refreshStatus"
            >
              {{ $t('installation.preflight.retry') }}
            </el-button>
          </template>
        </el-result>
      </template>

      <template v-else-if="automatic">
        <el-result
          icon="info"
          :title="$t('installation.automatic.title')"
          :sub-title="$t('installation.automatic.description')"
        >
          <template #extra>
            <el-button type="primary" @click="goToLogin">
              {{ $t('installation.login') }}
            </el-button>
          </template>
        </el-result>
      </template>

      <template v-else-if="readyForForm">
        <div class="installation-heading">
          <h1>{{ $t('installation.title') }}</h1>
          <p>{{ $t('installation.subtitle') }}</p>
        </div>

        <el-steps
          :active="1"
          finish-status="success"
          class="installation-steps"
        >
          <el-step :title="$t('installation.step.preflight')" />
          <el-step :title="$t('installation.step.identity')" />
          <el-step :title="$t('installation.step.install')" />
        </el-steps>

        <el-alert
          class="preflight-alert"
          :title="$t('installation.preflight.ready')"
          type="success"
          :closable="false"
        />

        <div class="installation-mode">
          <span>{{ $t('installation.mode') }}</span>
          <el-tag type="info">{{ deploymentModeLabel }}</el-tag>
        </div>

        <el-form
          ref="installationForm"
          :model="form"
          :rules="formRules"
          label-position="top"
          @submit.prevent="submit"
        >
          <el-form-item
            prop="setupToken"
            :label="$t('installation.token.label')"
          >
            <el-input
              v-model="form.setupToken"
              type="password"
              autocomplete="off"
              show-password
              :placeholder="$t('installation.token.placeholder')"
            />
            <div class="field-help">{{ $t('installation.token.help') }}</div>
          </el-form-item>

          <div class="form-section">
            <h2>{{ $t('installation.admin.title') }}</h2>
            <el-form-item
              prop="admin_email"
              :label="$t('installation.admin.email')"
            >
              <el-input
                v-model="form.admin_email"
                type="email"
                autocomplete="off"
                :placeholder="$t('installation.admin.emailPlaceholder')"
              />
            </el-form-item>
            <el-form-item
              prop="admin_password"
              :label="$t('installation.admin.password')"
            >
              <el-input
                v-model="form.admin_password"
                type="password"
                autocomplete="new-password"
                show-password
                :placeholder="$t('installation.admin.passwordPlaceholder')"
              />
            </el-form-item>
          </div>

          <div v-if="isMultiTenant" class="form-section">
            <h2>{{ $t('installation.platform.title') }}</h2>
            <el-form-item
              prop="platform_email"
              :label="$t('installation.platform.email')"
            >
              <el-input
                v-model="form.platform_email"
                type="email"
                autocomplete="off"
                :placeholder="$t('installation.platform.emailPlaceholder')"
              />
            </el-form-item>
            <el-form-item
              prop="platform_password"
              :label="$t('installation.platform.password')"
            >
              <el-input
                v-model="form.platform_password"
                type="password"
                autocomplete="new-password"
                show-password
                :placeholder="$t('installation.platform.passwordPlaceholder')"
              />
            </el-form-item>
          </div>

          <div class="form-section">
            <h2>{{ $t('installation.modules.title') }}</h2>
            <p class="section-description">{{
              $t('installation.modules.description')
            }}</p>
            <el-checkbox-group
              v-model="form.official_modules"
              class="module-list"
            >
              <el-checkbox
                v-for="module in moduleOptions"
                :key="module.key"
                :label="module.key"
                :disabled="module.required === true"
              >
                <span>{{ module.label }}</span>
                <small v-if="module.description">{{
                  module.description
                }}</small>
              </el-checkbox>
            </el-checkbox-group>
            <p
              v-if="form.official_modules.length === 0"
              class="section-description"
            >
              {{ $t('installation.modules.empty') }}
            </p>
          </div>

          <el-alert
            v-if="errorMessage"
            class="submit-error"
            :title="$t('installation.error.title')"
            :description="errorMessage"
            type="error"
            :closable="false"
          />

          <el-button
            class="submit-button"
            type="primary"
            native-type="submit"
            :loading="submitting"
          >
            {{
              submitting
                ? $t('installation.submitting')
                : $t('installation.submit')
            }}
          </el-button>
        </el-form>
      </template>
    </section>
  </main>
</template>

<script lang="ts" setup>
  import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
  import { useI18n } from 'vue-i18n';
  import { useRouter } from 'vue-router';
  import { ElMessage, type FormInstance, type FormRules } from 'element-plus';
  import {
    executeInstallation,
    type InstallationModuleOption,
    type InstallationPreflightCheck,
  } from '@/api/installation';
  import {
    bootstrapInstallationStatus,
    installationStatus,
    markInstallationInstalled,
    shouldShowInstallation,
  } from '@/core/installation';
  import { useBrandStore } from '@/store';

  interface ModuleOption {
    key: string;
    label: string;
    description?: string;
    required?: boolean;
    default?: boolean;
  }

  const router = useRouter();
  const { t } = useI18n();
  const brandStore = useBrandStore();
  const installationForm = ref<FormInstance>();
  const loading = ref(true);
  const refreshing = ref(false);
  const submitting = ref(false);
  const errorMessage = ref('');
  const form = reactive({
    setupToken: '',
    admin_email: '',
    admin_password: '',
    platform_email: '',
    platform_password: '',
    official_modules: [] as string[],
  });

  const currentStatus = computed(() => installationStatus.value);
  const preflight = computed(() => currentStatus.value?.preflight || null);
  const isMultiTenant = computed(
    () => currentStatus.value?.deployment_mode === 'multi-tenant'
  );
  const automatic = computed(
    () =>
      currentStatus.value !== null &&
      !shouldShowInstallation(currentStatus.value) &&
      currentStatus.value.state !== 'blocked'
  );
  const preflightChecks = computed<InstallationPreflightCheck[]>(() => {
    const checks = preflight.value?.checks;
    return Array.isArray(checks) ? checks : [];
  });
  const moduleOptions = computed(() => {
    const modules =
      currentStatus.value?.official_modules ||
      preflight.value?.official_modules ||
      preflight.value?.modules;
    return normalizeModuleOptions(modules);
  });
  const moduleCatalogMissing = computed(
    () =>
      shouldShowInstallation(currentStatus.value) &&
      preflight.value?.status === 'ready' &&
      moduleOptions.value.length === 0
  );
  const blocked = computed(() => {
    if (currentStatus.value?.state === 'blocked') return true;
    return (
      shouldShowInstallation(currentStatus.value) &&
      (!preflight.value ||
        preflight.value.status !== 'ready' ||
        moduleCatalogMissing.value)
    );
  });
  const readyForForm = computed(
    () => shouldShowInstallation(currentStatus.value) && !blocked.value
  );
  const deploymentModeLabel = computed(() =>
    isMultiTenant.value
      ? t('installation.mode.multiTenant')
      : t('installation.mode.standalone')
  );
  const blockedReason = computed(
    () =>
      (moduleCatalogMissing.value
        ? t('installation.modules.catalogMissing')
        : preflight.value?.reason) || t('installation.preflight.blocked')
  );

  function normalizeModuleOptions(value: unknown): ModuleOption[] {
    if (!Array.isArray(value) || value.length === 0) return [];
    const options = value.reduce<ModuleOption[]>((result, item) => {
      if (typeof item === 'string' && item.trim()) {
        result.push({ key: item, label: item, default: true });
        return result;
      }
      if (!item || typeof item !== 'object') return result;
      const module = item as InstallationModuleOption;
      if (!module.key?.trim()) return result;
      result.push({
        key: module.key,
        label: module.label || module.name || module.key,
        description: module.description,
        required: module.required,
        default: module.default ?? module.selected ?? true,
      });
      return result;
    }, []);
    return options;
  }

  watch(
    moduleOptions,
    (options) => {
      const available = new Set(options.map((module) => module.key));
      const selected = form.official_modules.filter((key) =>
        available.has(key)
      );
      const defaults = options
        .filter((module) => module.default !== false || module.required)
        .map((module) => module.key);
      form.official_modules = selected.length > 0 ? selected : defaults;
    },
    { immediate: true }
  );

  const formRules = computed<FormRules>(() => {
    const rules: FormRules = {
      setupToken: [
        {
          required: true,
          message: t('installation.validation.required'),
          trigger: 'blur',
        },
      ],
      admin_email: [
        {
          required: true,
          message: t('installation.validation.required'),
          trigger: 'blur',
        },
        {
          type: 'email',
          message: t('installation.validation.email'),
          trigger: 'blur',
        },
      ],
      admin_password: [
        {
          required: true,
          message: t('installation.validation.required'),
          trigger: 'blur',
        },
        {
          min: 12,
          message: t('installation.validation.password'),
          trigger: 'blur',
        },
      ],
    };
    if (isMultiTenant.value) {
      rules.platform_email = [
        {
          required: true,
          message: t('installation.validation.required'),
          trigger: 'blur',
        },
        {
          type: 'email',
          message: t('installation.validation.email'),
          trigger: 'blur',
        },
      ];
      rules.platform_password = [
        {
          required: true,
          message: t('installation.validation.required'),
          trigger: 'blur',
        },
        {
          min: 12,
          message: t('installation.validation.password'),
          trigger: 'blur',
        },
      ];
    }
    return rules;
  });

  async function redirectIfNotGuided() {
    const status = currentStatus.value;
    if (
      status &&
      status.state !== 'blocked' &&
      !shouldShowInstallation(status)
    ) {
      await router.replace({ name: 'login' });
    }
  }

  async function refreshStatus() {
    if (refreshing.value) return;
    refreshing.value = true;
    errorMessage.value = '';
    try {
      await bootstrapInstallationStatus(true);
      await redirectIfNotGuided();
    } finally {
      refreshing.value = false;
    }
  }

  function goToLogin() {
    router.replace({ name: 'login' });
  }

  function errorText(error: unknown) {
    if (error && typeof error === 'object') {
      const candidate = error as {
        message?: string;
        response?: { data?: { msg?: string } };
      };
      return (
        candidate.response?.data?.msg ||
        candidate.message ||
        t('installation.error.title')
      );
    }
    return t('installation.error.title');
  }

  async function submit() {
    if (submitting.value || !readyForForm.value) return;
    const valid = await installationForm.value?.validate().catch(() => false);
    if (!valid) return;
    submitting.value = true;
    errorMessage.value = '';
    const setupToken = form.setupToken.trim();
    const payload = {
      admin_email: form.admin_email.trim(),
      admin_password: form.admin_password,
      platform_email: isMultiTenant.value ? form.platform_email.trim() : '',
      platform_password: isMultiTenant.value ? form.platform_password : '',
      official_modules: [...form.official_modules],
    };
    try {
      await executeInstallation(setupToken, payload);
      // Remove all credentials from the component state before leaving the
      // page. Nothing is written to localStorage/sessionStorage.
      form.setupToken = '';
      form.admin_password = '';
      form.platform_password = '';
      markInstallationInstalled();
      ElMessage.success(t('installation.success'));
      await router.replace({ name: 'login' });
    } catch (error) {
      errorMessage.value = errorText(error);
    } finally {
      submitting.value = false;
    }
  }

  onMounted(async () => {
    await bootstrapInstallationStatus();
    loading.value = false;
    await redirectIfNotGuided();
  });

  onUnmounted(() => {
    form.setupToken = '';
    form.admin_password = '';
    form.platform_password = '';
  });
</script>

<style lang="less" scoped>
  .installation-page {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    min-height: 100vh;
    padding: 48px 24px;
    background: var(--el-fill-color-light);
  }

  .installation-card {
    width: min(100%, 720px);
    padding: 32px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 12px;
    box-shadow: var(--el-box-shadow-light);
  }

  .installation-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 28px;
    color: var(--el-text-color-primary);
    font-weight: 600;
    font-size: 18px;
  }

  .installation-logo {
    width: 36px;
    height: 36px;
    object-fit: contain;
  }

  .installation-heading {
    margin-bottom: 24px;

    h1 {
      margin: 0 0 8px;
      color: var(--el-text-color-primary);
      font-size: 28px;
      line-height: 1.3;
    }

    p {
      margin: 0;
      color: var(--el-text-color-secondary);
    }
  }

  .installation-steps {
    margin-bottom: 24px;
  }

  .preflight-alert {
    margin-bottom: 20px;
  }

  .installation-mode {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    color: var(--el-text-color-secondary);
  }

  .field-help,
  .section-description {
    color: var(--el-text-color-secondary);
    font-size: 12px;
    line-height: 1.5;
  }

  .field-help {
    margin-top: 4px;
  }

  .form-section {
    margin-top: 28px;
    padding-top: 24px;
    border-top: 1px solid var(--el-border-color-lighter);

    h2 {
      margin: 0 0 16px;
      color: var(--el-text-color-primary);
      font-size: 16px;
    }
  }

  .section-description {
    margin: -8px 0 16px;
  }

  .module-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px 16px;

    :deep(.el-checkbox) {
      margin-right: 0;
    }

    small {
      display: block;
      color: var(--el-text-color-secondary);
      font-size: 11px;
    }
  }

  .submit-error {
    margin-top: 24px;
  }

  .submit-button {
    width: 100%;
    margin-top: 28px;
  }

  .preflight-checks {
    width: min(100%, 520px);
    margin-bottom: 20px;
    text-align: left;
  }

  .preflight-check {
    padding: 10px 12px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;

    & + & {
      margin-top: 8px;
    }
  }

  .preflight-check-reason {
    color: var(--el-text-color-primary);
  }

  .preflight-check-remediation {
    margin-top: 4px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }

  @media (max-width: 600px) {
    .installation-page {
      padding: 16px;
    }

    .installation-card {
      padding: 24px 20px;
    }

    .module-list {
      grid-template-columns: 1fr;
    }
  }
</style>
