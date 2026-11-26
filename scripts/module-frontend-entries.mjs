import { existsSync, lstatSync, readFileSync, readdirSync, realpathSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const clientRoots = {
  'pc-web': 'pc/modules',
  'uniapp': 'uniapp/src/modules',
};

/** Use the same installed lock / development source distinction as admin-web. */
export function moduleFrontendEntries(projectRoot, clientKey, development) {
  const clientRoot = clientRoots[clientKey];
  if (!clientRoot) throw new Error(`Unsupported frontend client: ${clientKey}`);
  const entries = [];
  if (development) {
    const visit = (directory) => {
      for (const item of readdirSync(directory, { withFileTypes: true })) {
        const path = resolve(directory, item.name);
        if (item.isSymbolicLink())
          throw new Error(`Module source is a symlink: ${path}`);
        if (item.isDirectory()) visit(path);
        else if (item.name === 'module.json') {
          const manifest = JSON.parse(readFileSync(path, 'utf8'));
          const entry = manifest.frontend?.clients?.[clientKey]?.entry;
          if (entry !== undefined) entries.push({ key: manifest.key, entry });
        }
      }
    };
    const sourceRoot = resolve(projectRoot, 'server/app/modules');
    const sourceStatus = lstatSync(sourceRoot, { throwIfNoEntry: false });
    if (sourceStatus) {
      if (!sourceStatus.isDirectory() || sourceStatus.isSymbolicLink())
        throw new Error('Module source root must be a real directory: ' + sourceRoot);
      visit(sourceRoot);
    } else {
      // The upstream industrial Scaffold source has an empty modules.lock and
      // intentionally does not vendor Module business source. Do not make
      // the standalone Nuxt or UniApp development server depend on a
      // non-existent directory; still fail closed for a consumer whose lock
      // declares real frontend contributions.
      const lockPath = resolve(projectRoot, 'modules.lock');
      if (!existsSync(lockPath))
        throw new Error('modules.lock is required without a Module source root');
      const lock = JSON.parse(readFileSync(lockPath, 'utf8'));
      if (lock.schema_version !== 1 || !Array.isArray(lock.modules))
        throw new Error('modules.lock is invalid');
      if (lock.modules.some((module) =>
        module.frontend?.some((frontend) => frontend.client_key === clientKey)))
        throw new Error('Module source root is unavailable for ' + clientKey + ': ' + sourceRoot);
    }
  } else {
    const lock = JSON.parse(
      readFileSync(resolve(projectRoot, 'modules.lock'), 'utf8')
    );
    if (lock.schema_version !== 1 || !Array.isArray(lock.modules)) {
      throw new Error('modules.lock is invalid');
    }
    for (const plugin of lock.modules) {
      for (const frontend of plugin.frontend ?? []) {
        if (frontend.client_key === clientKey)
          entries.push({ key: plugin.key, entry: frontend.entry });
      }
    }
  }
  const keys = new Set();
  return entries
    .sort((a, b) => a.key.localeCompare(b.key))
    .map(({ key, entry }) => {
      if (
        typeof key !== 'string' ||
        !/^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*$/.test(key) ||
        keys.has(key)
      ) {
        throw new Error(`Invalid or duplicated Module key: ${key}`);
      }
      keys.add(key);
      const expected = `${clientRoot}/${key.replace(
        /\./g,
        '-'
      )}/contribution.ts`;
      const path = resolve(projectRoot, expected);
      if (
        entry !== expected ||
        !existsSync(path) ||
        realpathSync(path) !== path
      ) {
        throw new Error(
          `Module frontend contribution is unavailable: ${key}/${clientKey}`
        );
      }
      return path;
    });
}
