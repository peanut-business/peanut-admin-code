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

      <el-steps :active="stage" finish-status="success">
        <el-step v-for="title in ['协议', '环境探针', '配置', '数据库复查', '执行', '完成登录']" :key="title" :title="title" />
      </el-steps>
      <el-alert v-if="errorMessage" :title="errorMessage" type="error" :closable="false" />
      <pre v-if="diagnostic">{{ JSON.stringify(diagnostic, null, 2) }}</pre>
      <section v-if="stage === 0">
        <h2>使用协议</h2>
        <p>项目按 Apache License 2.0 提供，请阅读项目 LICENSE。安装将向已登记的空目标写入数据库结构、初始账号和所选模块，并保存安装完成回执。</p>
        <p>安装不提供自动清库、覆写身份或部分失败后的盲目重试。</p>
        <el-checkbox v-model="agreed">我已阅读协议并确认安装范围</el-checkbox>
        <el-button :disabled="!agreed" :loading="refreshing" @click="inspectEnvironment">同意并检查环境</el-button>
      </section>
      <section v-else-if="stage === 1">
        <h2>环境探针</h2>
        <div v-for="check in environment?.checks" :key="check.id || check.code"><strong>{{ check.status }} · {{ check.reason }}</strong><p>{{ check.remediation }}</p></div>
        <el-button :loading="refreshing" @click="inspectEnvironment">重新检查</el-button>
        <el-button :disabled="environment?.status !== 'ready'" @click="readConfiguration">继续配置</el-button>
      </section>
      <section v-else-if="stage === 2">
        <h2>实例配置</h2>
        <p>已有配置只读保留；连接与凭据来自本实例登记资源。</p>
        <el-input v-model="form.setupToken" type="password" show-password placeholder="Setup Token" autocomplete="off" />
        <template v-if="!configuration?.configured">
          <el-select v-model="configurationInput.deployment_target"><el-option v-for="target in configuration?.deployment_targets || []" :key="target" :label="target" :value="target" /></el-select>
          <el-input v-model="configurationInput.database_name" placeholder="本实例登记的数据库名" />
          <el-input v-model="configurationInput.platform_hosts" placeholder="Platform Hosts（多租户）" />
          <el-input v-model="configurationInput.tenant_admin_hosts" placeholder="Tenant Admin Hosts（多租户）" />
          <el-button :loading="refreshing" :disabled="!form.setupToken.trim()" @click="saveConfiguration">保存配置</el-button>
        </template>
        <p v-if="configuration?.state === 'pending'">配置待实例正常发布，请重新读取确认，不重复提交。</p>
        <el-button :loading="refreshing" @click="readConfiguration">重新读取配置</el-button>
        <el-button :disabled="!configuration?.configured" @click="inspectDatabase">检查数据库与权限</el-button>
      </section>
      <section v-else-if="stage === 3">
        <h2>数据库连接与权限复查</h2>
        <p>{{ databaseReport?.reason }} · {{ databaseReport?.remedy }}</p>
        <p>未确认权限：{{ databaseReport?.permissions_missing_or_unconfirmed?.join(', ') || '无' }}</p>
        <pre>{{ JSON.stringify(databaseReport?.writes, null, 2) }}</pre>
        <el-button :loading="refreshing" @click="inspectDatabase">重新检查</el-button>
        <el-button :disabled="!databaseReady" @click="stage = 4">继续执行</el-button>
      </section>
      <section v-else-if="stage === 5">
        <h2>安装完成</h2>
        <p>完成锁为 server/private/installation/installed.json。正常启动或数据库故障不会删除它。手动删除该锁只重新开放入口，既有数据和 baseline 仍阻止重新初始化。</p>
        <el-button type="primary" @click="goToLogin">前往登录</el-button>
      </section>
      <template v-else-if="loading">
        <el-skeleton :rows="8" animated />
      </template>

      <template v-else-if="blocked">
        <el-result
          icon="error"
          :title="$t('installation.preflight.blockedTitle')"
          :sub-title="blockedReason"
        >
          <template #extra>
            <div
              v-if="preflight?.status !== 'ready' && preflightChecks.length"
              class="preflight-checks"
            >
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

        <p>服务器在执行前重新核对空库、安装身份、完成锁与权限。请求超时后先读取状态，不直接重复提交。</p>

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
                :placeholder="passwordHint"
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
                :placeholder="passwordHint"
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
    checkInstallDatabase, configureInstall, getInstallConfiguration, getInstallEnvironment, InstallRequestError,
    type InstallationConfiguration, type InstallationPreflight,
    executeInstallation,
    type InstallationModuleOption,
    type InstallationPreflightCheck,
    type InstallationStatus,
  } from '@/api/install';
  import {
    bootstrapInstallationStatus,
    installationStatus,
    loadInstallationDiagnostics,
    markInstallationInstalled,
  } from '@/core/install';
  import { useBrandStore } from '@/store';
  import {
    getPasswordPolicy,
    passwordWithinPolicy,
    type PasswordPolicy,
  } from '@/api/password-policy';

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
  const stage = ref(0);
  const agreed = ref(false);
  const diagnostic = ref<Record<string, unknown> | null>(null);
  const environment = ref<InstallationPreflight | null>(null);
  const configuration = ref<InstallationConfiguration | null>(null);
  const databaseReport = ref<InstallationStatus | null>(null);
  const configurationInput = reactive({ deployment_target: 'local-production-preview', database_name: '', platform_hosts: '', tenant_admin_hosts: '' });
  const databaseReady = computed(() => databaseReport.value?.state === 'uninstalled' && databaseReport.value.safe_retry === true && databaseReport.value.permissions_verified === true);
  async function installationStep(name: string, action: () => Promise<void>) {
    if (refreshing.value) return;
    refreshing.value = true; errorMessage.value = ''; diagnostic.value = null;
    try { await action(); } catch (error) {
      errorMessage.value = errorText(error);
      const failure = error as { response?: { data?: { data?: Record<string, unknown> } } };
      diagnostic.value = failure.response?.data?.data || (error instanceof InstallRequestError ? error.diagnostic : { category: 'unknown', stage: name, reason: 'REQUEST_FAILED', remedy: '恢复服务后重新读取状态，不直接重复执行。', writes: { outcome: 'unknown' }, safe_retry: false });
    } finally { refreshing.value = false; }
  }
  async function inspectEnvironment() { stage.value = 1; environment.value = null; await installationStep('environment', async () => { environment.value = await getInstallEnvironment(); }); }
  async function readConfiguration() { await installationStep('configuration', async () => { configuration.value = await getInstallConfiguration(); stage.value = 2; }); }
  async function saveConfiguration() { await installationStep('configuration', async () => { await configureInstall(form.setupToken, configurationInput); configuration.value = await getInstallConfiguration(); }); }
  async function inspectDatabase() {
    stage.value = 3; databaseReport.value = null;
    await installationStep('database_check', async () => {
      databaseReport.value = await checkInstallDatabase(); installationStatus.value = databaseReport.value;
      await loadPasswordPolicy(); loading.value = false;
    });
  }
  const passwordPolicy = ref<PasswordPolicy | null>(null);
  const passwordPolicyError = ref('');
  const passwordHint = computed(() =>
    passwordPolicy.value
      ? t('installation.validation.password', {
          min: passwordPolicy.value.minimum_length,
          max: passwordPolicy.value.maximum_length,
        })
      : passwordPolicyError.value || t('installation.validation.policyLoading')
  );
  async function loadPasswordPolicy() {
    try {
      passwordPolicy.value = await getPasswordPolicy();
      passwordPolicyError.value = '';
    } catch {
      passwordPolicy.value = null;
      passwordPolicyError.value = t(
        'installation.validation.policyUnavailable'
      );
    }
  }
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
  const automatic = computed(() => currentStatus.value?.state === 'installed');
  const preflightChecks = computed<InstallationPreflightCheck[]>(() => {
    const checks = preflight.value?.checks;
    return Array.isArray(checks) ? checks : [];
  });
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
  const moduleOptions = computed(() => {
    const modules =
      currentStatus.value?.official_modules ||
      preflight.value?.official_modules ||
      preflight.value?.modules;
    return normalizeModuleOptions(modules);
  });
  const moduleCatalogMissing = computed(
    () =>
      currentStatus.value?.state === 'uninstalled' &&
      preflight.value?.status === 'ready' &&
      moduleOptions.value.length === 0
  );
  const blocked = computed(() => {
    if (currentStatus.value?.state === 'blocked') return true;
    return (
      currentStatus.value?.state === 'uninstalled' &&
      (!preflight.value ||
        preflight.value.status !== 'ready' ||
        moduleCatalogMissing.value)
    );
  });
  const readyForForm = computed(
    () => currentStatus.value?.state === 'uninstalled' && !blocked.value
  );
  const deploymentModeLabel = computed(() =>
    isMultiTenant.value
      ? t('installation.mode.multiTenant')
      : t('installation.mode.standalone')
  );
  const blockedReason = computed(() => {
    if (currentStatus.value?.state === 'blocked') {
      const { code } = currentStatus.value;
      if (code === 'INSTALL_PREFLIGHT_BLOCKED') {
        return preflight.value?.reason || t('installation.preflight.blocked');
      }
      const reasonKeys: Record<string, string> = {
        INSTALL_STATE_MIGRATION_PENDING:
          'installation.blocked.migrationPending',
        INSTALL_STATE_MIGRATION_REQUIRED:
          'installation.blocked.migrationRequired',
        INSTALL_COMPLETION_LOCK_INVALID: 'installation.blocked.lockInvalid',
        INSTALL_LOCKED_DATABASE_UNAVAILABLE:
          'installation.blocked.lockedDatabaseUnavailable',
        INSTALL_DATABASE_UNAVAILABLE:
          'installation.blocked.databaseUnavailable',
        INSTALL_LOCKED_DATABASE_MISMATCH:
          'installation.blocked.lockedDatabaseMismatch',
        INSTALL_PARTIAL_STATE_REQUIRES_REBUILD:
          'installation.blocked.partialState',
        INSTALL_COMPLETION_LOCK_MISSING: 'installation.blocked.lockMissing',
      };
      if (code && reasonKeys[code]) return t(reasonKeys[code]);
      return code
        ? t('installation.blocked.unknownWithCode', { code })
        : t('installation.blocked.unknown');
    }
    if (moduleCatalogMissing.value)
      return t('installation.modules.catalogMissing');
    return preflight.value?.reason || t('installation.preflight.blocked');
  });

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
          validator: (
            _rule,
            value: string,
            callback: (error?: Error) => void
          ) =>
            callback(
              passwordPolicy.value &&
                passwordWithinPolicy(value, passwordPolicy.value)
                ? undefined
                : new Error(passwordHint.value)
            ),
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
          validator: (
            _rule,
            value: string,
            callback: (error?: Error) => void
          ) =>
            callback(
              passwordPolicy.value &&
                passwordWithinPolicy(value, passwordPolicy.value)
                ? undefined
                : new Error(passwordHint.value)
            ),
          trigger: 'blur',
        },
      ];
    }
    return rules;
  });

  async function redirectIfNotGuided() {
    const status = currentStatus.value;
    if (status?.state === 'installed') {
      stage.value = 5;
    }
  }

  async function refreshStatus() {
    if (refreshing.value) return;
    refreshing.value = true;
    errorMessage.value = '';
    try {
      await loadInstallationDiagnostics(true);
      await bootstrapInstallationStatus(true);
      await redirectIfNotGuided();
    } catch (error) {
      errorMessage.value = errorText(error);
      diagnostic.value = { category: 'unknown', stage: 'status', reason: 'STATUS_UNAVAILABLE', remedy: '恢复服务后重新读取；不能把失败视为尚未安装。', writes: { status_request: 'none', execution: 'unknown' }, safe_retry: false };
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
    if (submitting.value || !readyForForm.value || !databaseReady.value) return;
    if (!passwordPolicy.value) {
      await loadPasswordPolicy();
      if (!passwordPolicy.value) {
        errorMessage.value = passwordPolicyError.value;
        return;
      }
    }
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
      stage.value = 5;
    } catch (error) {
      errorMessage.value = errorText(error);
      databaseReport.value = null;
      const failure = error as { response?: { data?: { data?: Record<string, unknown> } } };
      diagnostic.value = failure.response?.data?.data || { category: 'unknown', stage: 'execution', reason: 'EXECUTION_RESPONSE_UNCONFIRMED', remedy: '重新读取执行状态，不能直接重放。', writes: { outcome: 'unknown' }, safe_retry: false };
    } finally {
      submitting.value = false;
    }
  }

  onMounted(() => {
    loading.value = false;
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
