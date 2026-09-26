import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = dirname(fileURLToPath(import.meta.url));
const selectedCore = process.env.WEB_CORE_SOURCE_ROOT;
if (!selectedCore) throw new Error('WEB_CORE_SOURCE_ROOT is required for optional runtime tests.');
const coreRoot = resolve(selectedCore);
const coreAliases = ['client', 'vue'].map((name) => {
  const entry = resolve(coreRoot, `packages/${name}/src/index.ts`);
  if (!existsSync(entry)) throw new Error(`Selected Core entry is missing: ${entry}`);
  return { find: `@peanut-admin/${name}`, replacement: entry };
});

export default {
  root,
  resolve: {
    alias: [
      ...coreAliases,
      { find: '@', replacement: resolve(root, 'src') },
      { find: 'vue', replacement: resolve(root, 'node_modules/vue/dist/vue.runtime.esm-bundler.js') },
      { find: 'pinia', replacement: resolve(root, 'node_modules/pinia/dist/pinia.mjs') },
    ],
  },
  test: {
    environment: 'node',
    include: [
      'src/modules/official-file/optional-runtime/tests/**/*.spec.ts',
      'src/modules/official-import-export/optional-runtime/tests/**/*.spec.ts',
      'src/modules/official-notification/optional-runtime/tests/**/*.spec.ts',
      'src/modules/official-task/optional-runtime/tests/**/*.spec.ts',
    ],
    passWithNoTests: false,
  },
};
