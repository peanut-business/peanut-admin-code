import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, mergeConfig, type OutputChunk, type Plugin } from 'vite';
import { createBaseConfig } from './vite.config.base';
import configCompressPlugin from './plugin/compress';
import configVisualizerPlugin from './plugin/visualizer';

const configDir = dirname(fileURLToPath(import.meta.url));

function assertNoInstanceTools(): Plugin {
  const route = resolve(configDir, '../src/router/routes/modules/dev-tools.ts');
  const prefixes = [
    `${resolve(configDir, '../src/views/dev-tools')}/`,
    `${resolve(configDir, '../src/api/dev-tools')}/`,
  ];
  const normalize = (value: string) => value.replace(/\\/g, '/');
  const forbiddenRoute = normalize(route);
  const forbiddenPrefixes = prefixes.map(normalize);
  return {
    name: 'peanut-no-instance-tools-in-production',
    generateBundle(_options, bundle) {
      Object.values(bundle).forEach((output) => {
        if (output.type !== 'chunk') return;
        Object.keys((output as OutputChunk).modules).forEach((moduleId) => {
          const normalized = normalize(moduleId);
          if (
            normalized === forbiddenRoute ||
            forbiddenPrefixes.some((prefix) => normalized.startsWith(prefix))
          ) {
            throw new Error(
              `Production bundle contains instance-tool module: ${normalized}`
            );
          }
        });
      });
    },
  };
}

export default defineConfig((configEnv) =>
  mergeConfig(
    {
      mode: 'production',
      plugins: [
        assertNoInstanceTools(),
        configCompressPlugin('gzip'),
        configVisualizerPlugin(),
      ],
      build: {
        manifest: true,
        chunkSizeWarningLimit: 2000,
      },
    },
    createBaseConfig(configEnv)
  )
);
