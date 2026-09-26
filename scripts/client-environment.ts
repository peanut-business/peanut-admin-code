import { existsSync, lstatSync, readFileSync, realpathSync } from 'node:fs';
import { isAbsolute, resolve } from 'node:path';

export const clientEnvironmentKeys = [
  'PHP_PORT',
  'VITE_PORT',
  'PLATFORM_PORT',
  'MOBILE_PORT',
  'PC_PORT',
  'DOCS_PORT',
  'DEV_HTTP_PORT',
  'HTTP_PORT',
  'REDIS_PORT',
  'VITE_API_PROXY_TARGET',
  'VITE_ALLOWED_HOSTS',
  'VITE_TENANT_ENTRY_HOST',
  'VITE_OPEN_BROWSER',
  'VITE_DEPLOYMENT_MODE',
  'VITE_API_BASE_URL',
  'VITE_APP_BASE_URL',
  'NUXT_DEV_PROXY_TARGET',
  'NUXT_DEV_PROXY_ORIGIN',
  'NUXT_PC_RENDER_MODE',
  'PEANUT_DOCS_SITE_URL',
  'VITEPRESS_DISABLE_GIT',
  'REPORT',
] as const;

type ClientEnvironmentKey = (typeof clientEnvironmentKeys)[number];
export type ClientEnvironment = Partial<Record<ClientEnvironmentKey, string>>;

export function readClientEnvironment(defaultPath: string): ClientEnvironment {
  for (const key of clientEnvironmentKeys) {
    if (process.env[key] !== undefined) {
      throw new Error(`CLIENT_ENVIRONMENT_AMBIENT_VALUE_FORBIDDEN:${key}`);
    }
  }
  const selected = process.env.PEANUT_CLIENT_ENV_FILE?.trim();
  const path = selected || resolve(defaultPath);
  if (selected && !isAbsolute(path)) {
    throw new Error('CLIENT_ENVIRONMENT_PATH_NOT_ABSOLUTE');
  }
  if (!existsSync(path)) return {};
  const stat = lstatSync(path);
  if (!stat.isFile() || stat.isSymbolicLink() || realpathSync(path) !== path) {
    throw new Error('CLIENT_ENVIRONMENT_FILE_INVALID');
  }
  const allowed = new Set<string>(clientEnvironmentKeys);
  const values: ClientEnvironment = {};
  readFileSync(path, 'utf8')
    .split(/\r?\n/u)
    .forEach((line, index) => {
      const trimmed = line.trim();
      if (trimmed === '' || trimmed.startsWith('#')) return;
      const separator = line.indexOf('=');
      if (separator < 1)
        throw new Error(`CLIENT_ENVIRONMENT_LINE_INVALID:${index + 1}`);
      const key = line.slice(0, separator).trim();
      const value = line.slice(separator + 1).trim();
      if (!allowed.has(key))
        throw new Error(`CLIENT_ENVIRONMENT_UNKNOWN_KEY:${key}`);
      if (Object.hasOwn(values, key))
        throw new Error(`CLIENT_ENVIRONMENT_DUPLICATE_KEY:${key}`);
      values[key as ClientEnvironmentKey] = value;
    });
  return values;
}
