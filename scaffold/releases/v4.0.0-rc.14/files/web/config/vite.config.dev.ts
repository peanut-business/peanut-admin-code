import { existsSync, readFileSync, readdirSync, realpathSync } from 'fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, mergeConfig } from 'vite';
import eslint from 'vite-plugin-eslint';
import { createBaseConfig } from './vite.config.base';
import { readClientEnvironment } from '../../scripts/client-environment';

const configDir = dirname(fileURLToPath(import.meta.url));

interface ModuleManifest {
  key?: unknown;
  frontend?: {
    entry?: unknown;
    clients?: { 'admin-web'?: { entry?: unknown } };
  };
}

const moduleKeyPattern =
  /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/;

function moduleManifestPaths(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true })
    .flatMap((entry) => {
      const path = resolve(directory, entry.name);
      if (entry.isSymbolicLink()) {
        throw new Error(
          `Development Module source cannot be a symlink: ${path}`
        );
      }
      if (entry.isDirectory()) return moduleManifestPaths(path);
      return entry.isFile() && entry.name === 'module.json' ? [path] : [];
    })
    .sort();
}

export function discoverAdminContributions(
  projectRoot = resolve(configDir, '../..')
): string[] {
  const modulesRoot = resolve(projectRoot, 'server/app/modules');
  if (!existsSync(modulesRoot)) {
    throw new Error('Development Module source root is unavailable');
  }
  const contributions = new Map<string, string>();
  moduleManifestPaths(modulesRoot).forEach((manifestPath) => {
    const manifest = JSON.parse(
      readFileSync(manifestPath, 'utf8')
    ) as ModuleManifest;
    if (
      typeof manifest.key !== 'string' ||
      !moduleKeyPattern.test(manifest.key)
    ) {
      throw new Error(`Development Module key is invalid: ${manifestPath}`);
    }
    if (contributions.has(manifest.key)) {
      throw new Error(`Development Module key is duplicated: ${manifest.key}`);
    }
    const expectedBackend = resolve(
      modulesRoot,
      ...manifest.key.split('.').map((segment) => segment.replace(/-/g, '_'))
    );
    if (
      realpathSync(resolve(manifestPath, '..')) !==
      realpathSync(expectedBackend)
    ) {
      throw new Error(
        `Development Module path is not key-derived: ${manifest.key}`
      );
    }
    const legacyEntry = manifest.frontend?.entry;
    const clientEntry = manifest.frontend?.clients?.['admin-web']?.entry;
    if (legacyEntry !== undefined && clientEntry !== undefined) {
      throw new Error(
        `Development Module admin-web contribution is duplicated: ${manifest.key}`
      );
    }
    const entry = legacyEntry ?? clientEntry;
    if (entry === undefined || entry === null) return;
    const expectedEntry = `web/src/modules/${manifest.key.replace(
      /\./g,
      '-'
    )}/contribution.ts`;
    if (entry !== expectedEntry) {
      throw new Error(
        `Development Module frontend.entry differs from key: ${manifest.key}`
      );
    }
    const entryPath = resolve(projectRoot, expectedEntry);
    if (!existsSync(entryPath) || realpathSync(entryPath) !== entryPath) {
      throw new Error(
        `Development Module frontend entry is unavailable: ${manifest.key}`
      );
    }
    contributions.set(manifest.key, expectedEntry);
  });
  return [...contributions.entries()]
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([, entry]) => entry);
}

export default defineConfig((configEnv) => {
  const environment = readClientEnvironment(
    resolve(configDir, `../.env.${configEnv.mode}`)
  );
  const apiProxyTarget =
    environment.VITE_API_PROXY_TARGET ||
    (environment.PHP_PORT
      ? `http://127.0.0.1:${environment.PHP_PORT}`
      : 'http://127.0.0.1');
  const allowedHosts = (environment.VITE_ALLOWED_HOSTS || '')
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean);
  const tenantEntryHost = environment.VITE_TENANT_ENTRY_HOST || '';
  const proxyHeaders = tenantEntryHost ? { host: tenantEntryHost } : undefined;
  return mergeConfig(
    {
      mode: 'development',
      server: {
        open: environment.VITE_OPEN_BROWSER !== 'false',
        allowedHosts,
        fs: {
          strict: true,
        },
        proxy: {
          '/adminapi': {
            target: apiProxyTarget,
            changeOrigin: false,
            headers: proxyHeaders,
          },
          '/platformapi': {
            target: apiProxyTarget,
            changeOrigin: false,
            headers: proxyHeaders,
          },
          '/installapi': {
            target: apiProxyTarget,
            changeOrigin: false,
            headers: proxyHeaders,
          },
          '/api': {
            target: apiProxyTarget,
            changeOrigin: false,
            headers: proxyHeaders,
          },
          '/brand': {
            target: apiProxyTarget,
            changeOrigin: false,
            headers: proxyHeaders,
          },
          '/storage': {
            target: apiProxyTarget,
            changeOrigin: false,
            headers: proxyHeaders,
          },
        },
      },
      plugins: [
        eslint({
          cache: false,
          include: ['src/**/*.ts', 'src/**/*.tsx', 'src/**/*.vue'],
          exclude: ['node_modules'],
        }),
      ],
    },
    createBaseConfig(configEnv, discoverAdminContributions)
  );
});
