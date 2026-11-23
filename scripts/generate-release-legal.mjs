#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { lockedYamlParser } from './release-dependency-locks.mjs';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const sourceDir = resolve(scriptDir, '..');
const applicationArgument = process.argv.slice(2).find((argument) => argument.startsWith('--application-root='));
const application = applicationArgument !== undefined;
const rootDir = application ? resolve(applicationArgument.slice('--application-root='.length)) : sourceDir;
const checkOnly = process.argv.includes('--check');

const readJson = (relativePath) =>
  JSON.parse(readFileSync(resolve(rootDir, relativePath), 'utf8'));
const parseYaml = lockedYamlParser(sourceDir);
const composerLockCount = (relativePath) => {
  const lock = readJson(relativePath);
  return lock.packages.length + lock['packages-dev'].length;
};
const pnpmLockCount = (relativePath) =>
  Object.keys(
    parseYaml(readFileSync(resolve(rootDir, relativePath), 'utf8')).packages
  ).length;
const expectedCounts = {
  'composer': composerLockCount('server/composer.lock'),
  'web': pnpmLockCount('web/pnpm-lock.yaml'),
  'platform': pnpmLockCount('platform/pnpm-lock.yaml'),
  'pc': pnpmLockCount('pc/pnpm-lock.yaml'),
  'uniapp': pnpmLockCount('uniapp/pnpm-lock.yaml'),
  'quality-composer': composerLockCount('tools/quality/composer.lock'),
  'quality-js': pnpmLockCount('tools/quality/pnpm-lock.yaml'),
};
const versionContract = readJson('release-versions.json');
const releaseMetadata = readJson('RELEASE_METADATA.json');
const currentVersionContract = [2, 3].includes(versionContract.schema_version);
const releaseVersion = currentVersionContract
  ? versionContract.instance_version ?? versionContract.source_product_version
  : versionContract.product_release;
const metadataVersion =
  [2, 3].includes(releaseMetadata.schema_version) &&
  releaseMetadata.protocol ===
    `peanut.release-metadata.v${releaseMetadata.schema_version}`
    ? releaseMetadata.instance_version ?? releaseMetadata.source_product_version
    : releaseMetadata.version;
if (
  metadataVersion !== releaseVersion ||
  (currentVersionContract &&
    (releaseMetadata.source_product_version !== versionContract.source_product_version ||
      releaseMetadata.instance_version !== versionContract.instance_version)) ||
  (application ? typeof releaseMetadata.application_identity !== 'string'
    : releaseMetadata.expected_tag !== `v${releaseVersion}`)
) {
  throw new Error(
    `RELEASE_METADATA version contract mismatch: expected ${releaseVersion}`
  );
}
const releaseTag = releaseMetadata.expected_tag;
const releaseDate = releaseMetadata.release_date;
const applicationProduct = application ? releaseMetadata.product : 'Peanut Admin';
const applicationRepository = application ? releaseMetadata.source_repository : 'peanut-business/peanut-admin-code';
const applicationIdentity = application ? releaseMetadata.application_identity : 'peanut-business/peanut-admin-code';
const validRepository = (value) => typeof value === 'string' && /^[A-Za-z\d_.-]+\/[A-Za-z\d_.-]+$/.test(value);
const validDate = (value) => {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(date.valueOf()) && date.toISOString().slice(0, 10) === value;
};
if (application && (typeof applicationProduct !== 'string' || applicationProduct.trim() === '' ||
    typeof applicationIdentity !== 'string' || !/^[A-Za-z\d_.-]+\/[A-Za-z\d_.-]+$/.test(applicationIdentity) ||
    (applicationRepository != null && !validRepository(applicationRepository)) ||
    (releaseDate != null && !validDate(releaseDate)))) {
  throw new Error('application SBOM requires a valid product and application_identity; provided source_repository/release_date must be valid');
}
const repositoryUrl = applicationRepository == null
  ? undefined
  : `https://github.com/${applicationRepository}`;
if (versionContract.schema_version !== 3 || versionContract.protocol !== 'peanut.release-versions.v3') {
  throw new Error('RELEASE_LEGAL_VERSION_CONTRACT_UNSUPPORTED: expected peanut.release-versions.v3');
}
const corePhpIdentity = `${versionContract.core_php.resolved_version} (${versionContract.core_php.source_reference})`;
const coreWebIdentity = Object.entries(versionContract.core_web.packages)
  .map(([name, identity]) => `${name}@${identity.version}`)
  .join(', ');

// Exact registry declarations for locked development entries not installed on
// every host. Each declared version was checked against its lock integrity.
const lockedLicenseDeclarations = new Map([
  ['@emnapi/core@1.10.0', 'MIT'],
  ['@emnapi/runtime@1.10.0', 'MIT'],
  ['@emnapi/wasi-threads@1.2.1', 'MIT'],
  ['@esbuild/aix-ppc64@0.25.12', 'MIT'],
  ['@esbuild/android-arm@0.25.12', 'MIT'],
  ['@esbuild/android-arm64@0.25.12', 'MIT'],
  ['@esbuild/android-x64@0.25.12', 'MIT'],
  ['@esbuild/darwin-x64@0.25.12', 'MIT'],
  ['@esbuild/freebsd-arm64@0.25.12', 'MIT'],
  ['@esbuild/freebsd-x64@0.25.12', 'MIT'],
  ['@esbuild/linux-arm@0.25.12', 'MIT'],
  ['@esbuild/linux-arm64@0.25.12', 'MIT'],
  ['@esbuild/linux-ia32@0.25.12', 'MIT'],
  ['@esbuild/linux-loong64@0.25.12', 'MIT'],
  ['@esbuild/linux-mips64el@0.25.12', 'MIT'],
  ['@esbuild/linux-ppc64@0.25.12', 'MIT'],
  ['@esbuild/linux-riscv64@0.25.12', 'MIT'],
  ['@esbuild/linux-s390x@0.25.12', 'MIT'],
  ['@esbuild/linux-x64@0.25.12', 'MIT'],
  ['@esbuild/netbsd-arm64@0.25.12', 'MIT'],
  ['@esbuild/netbsd-x64@0.25.12', 'MIT'],
  ['@esbuild/openbsd-arm64@0.25.12', 'MIT'],
  ['@esbuild/openbsd-x64@0.25.12', 'MIT'],
  ['@esbuild/openharmony-arm64@0.25.12', 'MIT'],
  ['@esbuild/sunos-x64@0.25.12', 'MIT'],
  ['@esbuild/win32-arm64@0.25.12', 'MIT'],
  ['@esbuild/win32-ia32@0.25.12', 'MIT'],
  ['@esbuild/win32-x64@0.25.12', 'MIT'],
  ['@napi-rs/lzma-linux-x64-gnu@1.5.1', 'MIT'],
  ['@napi-rs/wasm-runtime@1.1.6', 'MIT'],
  ['@rolldown/binding-android-arm-eabi@1.2.11', 'MIT'],
  ['@rolldown/binding-android-arm64@1.2.11', 'MIT'],
  ['@rolldown/binding-darwin-x64@1.2.11', 'MIT'],
  ['@rolldown/binding-freebsd-x64@1.2.11', 'MIT'],
  ['@rolldown/binding-linux-arm-gnueabihf@1.2.11', 'MIT'],
  ['@rolldown/binding-linux-arm64-gnu@1.2.11', 'MIT'],
  ['@rolldown/binding-linux-arm64-musl@1.2.11', 'MIT'],
  ['@rolldown/binding-linux-ppc64-gnu@1.2.11', 'MIT'],
  ['@rolldown/binding-linux-s390x-gnu@1.2.11', 'MIT'],
  ['@rolldown/binding-linux-x64-gnu@1.2.11', 'MIT'],
  ['@rolldown/binding-linux-x64-musl@1.2.11', 'MIT'],
  ['@rolldown/binding-openharmony-arm64@1.2.11', 'MIT'],
  ['@rolldown/binding-win32-arm64-msvc@1.2.11', 'MIT'],
  ['@rolldown/binding-win32-x64-msvc@1.2.11', 'MIT'],
  ['@rollup/rollup-android-arm-eabi@4.63.1', 'MIT'],
  ['@rollup/rollup-android-arm64@4.63.1', 'MIT'],
  ['@rollup/rollup-darwin-x64@4.63.1', 'MIT'],
  ['@rollup/rollup-freebsd-arm64@4.63.1', 'MIT'],
  ['@rollup/rollup-freebsd-x64@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-arm-gnueabihf@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-arm-musleabihf@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-arm64-gnu@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-arm64-musl@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-loong64-gnu@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-loong64-musl@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-ppc64-gnu@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-ppc64-musl@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-riscv64-gnu@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-riscv64-musl@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-s390x-gnu@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-x64-gnu@4.63.1', 'MIT'],
  ['@rollup/rollup-linux-x64-musl@4.63.1', 'MIT'],
  ['@rollup/rollup-openbsd-x64@4.63.1', 'MIT'],
  ['@rollup/rollup-openharmony-arm64@4.63.1', 'MIT'],
  ['@rollup/rollup-win32-arm64-msvc@4.63.1', 'MIT'],
  ['@rollup/rollup-win32-ia32-msvc@4.63.1', 'MIT'],
  ['@rollup/rollup-win32-x64-gnu@4.63.1', 'MIT'],
  ['@rollup/rollup-win32-x64-msvc@4.63.1', 'MIT'],
  ['@tybys/wasm-util@0.10.3', 'MIT'],
  ['@unrs/resolver-binding-android-arm-eabi@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-android-arm64@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-darwin-x64@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-freebsd-x64@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-arm-gnueabihf@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-arm-musleabihf@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-arm64-gnu@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-arm64-musl@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-loong64-gnu@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-loong64-musl@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-ppc64-gnu@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-riscv64-gnu@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-riscv64-musl@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-s390x-gnu@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-x64-gnu@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-linux-x64-musl@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-openharmony-arm64@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-wasm32-wasi@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-win32-arm64-msvc@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-win32-ia32-msvc@1.12.2', 'MIT'],
  ['@unrs/resolver-binding-win32-x64-msvc@1.12.2', 'MIT'],
  ['ansi-regex@6.3.0', 'MIT'],
  ['ansi-styles@6.2.3', 'MIT'],
  ['eastasianwidth@0.2.0', 'MIT'],
  ['emoji-regex@9.2.2', 'MIT'],
  ['lightningcss-android-arm64@1.33.0', 'MPL-2.0'],
  ['lightningcss-darwin-x64@1.33.0', 'MPL-2.0'],
  ['lightningcss-freebsd-x64@1.33.0', 'MPL-2.0'],
  ['lightningcss-linux-arm-gnueabihf@1.33.0', 'MPL-2.0'],
  ['lightningcss-linux-arm64-gnu@1.33.0', 'MPL-2.0'],
  ['lightningcss-linux-arm64-musl@1.33.0', 'MPL-2.0'],
  ['lightningcss-linux-x64-gnu@1.33.0', 'MPL-2.0'],
  ['lightningcss-linux-x64-musl@1.33.0', 'MPL-2.0'],
  ['lightningcss-win32-arm64-msvc@1.33.0', 'MPL-2.0'],
  ['lightningcss-win32-x64-msvc@1.33.0', 'MPL-2.0'],
  ['string-width@5.1.2', 'MIT'],
  ['strip-ansi@7.2.0', 'MIT'],
  ['tslib@2.8.1', '0BSD'],
  ['wrap-ansi@8.1.0', 'MIT'],
  ['@esbuild/aix-ppc64@0.28.1', 'MIT'],
  ['@esbuild/android-arm64@0.28.1', 'MIT'],
  ['@esbuild/android-arm@0.28.1', 'MIT'],
  ['@esbuild/android-x64@0.28.1', 'MIT'],
  ['@esbuild/darwin-arm64@0.25.12', 'MIT'],
  ['@esbuild/darwin-arm64@0.28.1', 'MIT'],
  ['@esbuild/darwin-x64@0.28.1', 'MIT'],
  ['@esbuild/freebsd-arm64@0.28.1', 'MIT'],
  ['@esbuild/freebsd-x64@0.28.1', 'MIT'],
  ['@esbuild/linux-arm64@0.28.1', 'MIT'],
  ['@esbuild/linux-arm@0.28.1', 'MIT'],
  ['@esbuild/linux-ia32@0.28.1', 'MIT'],
  ['@esbuild/linux-loong64@0.28.1', 'MIT'],
  ['@esbuild/linux-mips64el@0.28.1', 'MIT'],
  ['@esbuild/linux-ppc64@0.28.1', 'MIT'],
  ['@esbuild/linux-riscv64@0.28.1', 'MIT'],
  ['@esbuild/linux-s390x@0.28.1', 'MIT'],
  ['@esbuild/linux-x64@0.28.1', 'MIT'],
  ['@esbuild/netbsd-arm64@0.28.1', 'MIT'],
  ['@esbuild/netbsd-x64@0.28.1', 'MIT'],
  ['@esbuild/openbsd-arm64@0.28.1', 'MIT'],
  ['@esbuild/openbsd-x64@0.28.1', 'MIT'],
  ['@esbuild/openharmony-arm64@0.28.1', 'MIT'],
  ['@esbuild/sunos-x64@0.28.1', 'MIT'],
  ['@esbuild/win32-arm64@0.28.1', 'MIT'],
  ['@esbuild/win32-ia32@0.28.1', 'MIT'],
  ['@esbuild/win32-x64@0.28.1', 'MIT'],
  ['@oxc-minify/binding-android-arm-eabi@0.143.0', 'MIT'],
  ['@oxc-minify/binding-android-arm64@0.143.0', 'MIT'],
  ['@oxc-minify/binding-darwin-arm64@0.143.0', 'MIT'],
  ['@oxc-minify/binding-darwin-x64@0.143.0', 'MIT'],
  ['@oxc-minify/binding-freebsd-x64@0.143.0', 'MIT'],
  ['@oxc-minify/binding-linux-arm-gnueabihf@0.143.0', 'MIT'],
  ['@oxc-minify/binding-linux-arm-musleabihf@0.143.0', 'MIT'],
  ['@oxc-minify/binding-linux-arm64-gnu@0.143.0', 'MIT'],
  ['@oxc-minify/binding-linux-arm64-musl@0.143.0', 'MIT'],
  ['@oxc-minify/binding-linux-ppc64-gnu@0.143.0', 'MIT'],
  ['@oxc-minify/binding-linux-riscv64-gnu@0.143.0', 'MIT'],
  ['@oxc-minify/binding-linux-riscv64-musl@0.143.0', 'MIT'],
  ['@oxc-minify/binding-linux-s390x-gnu@0.143.0', 'MIT'],
  ['@oxc-minify/binding-linux-x64-gnu@0.143.0', 'MIT'],
  ['@oxc-minify/binding-linux-x64-musl@0.143.0', 'MIT'],
  ['@oxc-minify/binding-openharmony-arm64@0.143.0', 'MIT'],
  ['@oxc-minify/binding-win32-arm64-msvc@0.143.0', 'MIT'],
  ['@oxc-minify/binding-win32-ia32-msvc@0.143.0', 'MIT'],
  ['@oxc-minify/binding-win32-x64-msvc@0.143.0', 'MIT'],
  ['@oxc-parser/binding-android-arm-eabi@0.143.0', 'MIT'],
  ['@oxc-parser/binding-android-arm64@0.143.0', 'MIT'],
  ['@oxc-parser/binding-darwin-arm64@0.143.0', 'MIT'],
  ['@oxc-parser/binding-darwin-x64@0.143.0', 'MIT'],
  ['@oxc-parser/binding-freebsd-x64@0.143.0', 'MIT'],
  ['@oxc-parser/binding-linux-arm-gnueabihf@0.143.0', 'MIT'],
  ['@oxc-parser/binding-linux-arm-musleabihf@0.143.0', 'MIT'],
  ['@oxc-parser/binding-linux-arm64-gnu@0.143.0', 'MIT'],
  ['@oxc-parser/binding-linux-arm64-musl@0.143.0', 'MIT'],
  ['@oxc-parser/binding-linux-ppc64-gnu@0.143.0', 'MIT'],
  ['@oxc-parser/binding-linux-riscv64-gnu@0.143.0', 'MIT'],
  ['@oxc-parser/binding-linux-riscv64-musl@0.143.0', 'MIT'],
  ['@oxc-parser/binding-linux-s390x-gnu@0.143.0', 'MIT'],
  ['@oxc-parser/binding-linux-x64-gnu@0.143.0', 'MIT'],
  ['@oxc-parser/binding-linux-x64-musl@0.143.0', 'MIT'],
  ['@oxc-parser/binding-openharmony-arm64@0.143.0', 'MIT'],
  ['@oxc-parser/binding-win32-arm64-msvc@0.143.0', 'MIT'],
  ['@oxc-parser/binding-win32-ia32-msvc@0.143.0', 'MIT'],
  ['@oxc-parser/binding-win32-x64-msvc@0.143.0', 'MIT'],
  ['@oxc-transform/binding-android-arm-eabi@0.143.0', 'MIT'],
  ['@oxc-transform/binding-android-arm64@0.143.0', 'MIT'],
  ['@oxc-transform/binding-darwin-arm64@0.143.0', 'MIT'],
  ['@oxc-transform/binding-darwin-x64@0.143.0', 'MIT'],
  ['@oxc-transform/binding-freebsd-x64@0.143.0', 'MIT'],
  ['@oxc-transform/binding-linux-arm-gnueabihf@0.143.0', 'MIT'],
  ['@oxc-transform/binding-linux-arm-musleabihf@0.143.0', 'MIT'],
  ['@oxc-transform/binding-linux-arm64-gnu@0.143.0', 'MIT'],
  ['@oxc-transform/binding-linux-arm64-musl@0.143.0', 'MIT'],
  ['@oxc-transform/binding-linux-ppc64-gnu@0.143.0', 'MIT'],
  ['@oxc-transform/binding-linux-riscv64-gnu@0.143.0', 'MIT'],
  ['@oxc-transform/binding-linux-riscv64-musl@0.143.0', 'MIT'],
  ['@oxc-transform/binding-linux-s390x-gnu@0.143.0', 'MIT'],
  ['@oxc-transform/binding-linux-x64-gnu@0.143.0', 'MIT'],
  ['@oxc-transform/binding-linux-x64-musl@0.143.0', 'MIT'],
  ['@oxc-transform/binding-openharmony-arm64@0.143.0', 'MIT'],
  ['@oxc-transform/binding-win32-arm64-msvc@0.143.0', 'MIT'],
  ['@oxc-transform/binding-win32-ia32-msvc@0.143.0', 'MIT'],
  ['@oxc-transform/binding-win32-x64-msvc@0.143.0', 'MIT'],
  ['@rollup/rollup-android-arm-eabi@4.62.3', 'MIT'],
  ['@rollup/rollup-android-arm-eabi@4.62.4', 'MIT'],
  ['@rollup/rollup-android-arm64@4.62.3', 'MIT'],
  ['@rollup/rollup-android-arm64@4.62.4', 'MIT'],
  ['@rollup/rollup-darwin-arm64@4.62.3', 'MIT'],
  ['@rollup/rollup-darwin-arm64@4.62.4', 'MIT'],
  ['@rollup/rollup-darwin-x64@4.62.3', 'MIT'],
  ['@rollup/rollup-darwin-x64@4.62.4', 'MIT'],
  ['@rollup/rollup-freebsd-arm64@4.62.3', 'MIT'],
  ['@rollup/rollup-freebsd-arm64@4.62.4', 'MIT'],
  ['@rollup/rollup-freebsd-x64@4.62.3', 'MIT'],
  ['@rollup/rollup-freebsd-x64@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-arm-gnueabihf@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-arm-gnueabihf@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-arm-musleabihf@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-arm-musleabihf@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-arm64-gnu@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-arm64-gnu@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-arm64-musl@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-arm64-musl@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-loong64-gnu@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-loong64-gnu@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-loong64-musl@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-loong64-musl@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-ppc64-gnu@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-ppc64-gnu@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-ppc64-musl@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-ppc64-musl@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-riscv64-gnu@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-riscv64-gnu@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-riscv64-musl@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-riscv64-musl@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-s390x-gnu@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-s390x-gnu@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-x64-gnu@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-x64-gnu@4.62.4', 'MIT'],
  ['@rollup/rollup-linux-x64-musl@4.62.3', 'MIT'],
  ['@rollup/rollup-linux-x64-musl@4.62.4', 'MIT'],
  ['@rollup/rollup-openbsd-x64@4.62.3', 'MIT'],
  ['@rollup/rollup-openbsd-x64@4.62.4', 'MIT'],
  ['@rollup/rollup-openharmony-arm64@4.62.3', 'MIT'],
  ['@rollup/rollup-openharmony-arm64@4.62.4', 'MIT'],
  ['@rollup/rollup-win32-arm64-msvc@4.62.3', 'MIT'],
  ['@rollup/rollup-win32-arm64-msvc@4.62.4', 'MIT'],
  ['@rollup/rollup-win32-ia32-msvc@4.62.3', 'MIT'],
  ['@rollup/rollup-win32-ia32-msvc@4.62.4', 'MIT'],
  ['@rollup/rollup-win32-x64-gnu@4.62.3', 'MIT'],
  ['@rollup/rollup-win32-x64-gnu@4.62.4', 'MIT'],
  ['@rollup/rollup-win32-x64-msvc@4.62.3', 'MIT'],
  ['@rollup/rollup-win32-x64-msvc@4.62.4', 'MIT'],
  ['fsevents@2.3.3', 'MIT'],
  // Nuxt 4 locked optional packages: npm name/version/integrity verified.
  ['@emnapi/core@2.0.0-beta.2', 'MIT'],
  ['@emnapi/runtime@2.0.0-beta.2', 'MIT'],
  ['@emnapi/wasi-threads@2.2.0', 'MIT'],
  ['@esbuild/aix-ppc64@0.28.2', 'MIT'],
  ['@esbuild/android-arm@0.28.2', 'MIT'],
  ['@esbuild/android-arm64@0.28.2', 'MIT'],
  ['@esbuild/android-x64@0.28.2', 'MIT'],
  ['@esbuild/darwin-x64@0.28.2', 'MIT'],
  ['@esbuild/freebsd-arm64@0.28.2', 'MIT'],
  ['@esbuild/freebsd-x64@0.28.2', 'MIT'],
  ['@esbuild/linux-arm@0.28.2', 'MIT'],
  ['@esbuild/linux-arm64@0.28.2', 'MIT'],
  ['@esbuild/linux-ia32@0.28.2', 'MIT'],
  ['@esbuild/linux-loong64@0.28.2', 'MIT'],
  ['@esbuild/linux-mips64el@0.28.2', 'MIT'],
  ['@esbuild/linux-ppc64@0.28.2', 'MIT'],
  ['@esbuild/linux-riscv64@0.28.2', 'MIT'],
  ['@esbuild/linux-s390x@0.28.2', 'MIT'],
  ['@esbuild/linux-x64@0.28.2', 'MIT'],
  ['@esbuild/netbsd-arm64@0.28.2', 'MIT'],
  ['@esbuild/netbsd-x64@0.28.2', 'MIT'],
  ['@esbuild/openbsd-arm64@0.28.2', 'MIT'],
  ['@esbuild/openbsd-x64@0.28.2', 'MIT'],
  ['@esbuild/openharmony-arm64@0.28.2', 'MIT'],
  ['@esbuild/sunos-x64@0.28.2', 'MIT'],
  ['@esbuild/win32-arm64@0.28.2', 'MIT'],
  ['@esbuild/win32-ia32@0.28.2', 'MIT'],
  ['@esbuild/win32-x64@0.28.2', 'MIT'],
  ['@napi-rs/wasm-runtime@1.2.6', 'MIT'],
  ['@rolldown/binding-android-arm-eabi@1.2.19', 'MIT'],
  ['@rolldown/binding-android-arm64@1.2.19', 'MIT'],
  ['@rolldown/binding-darwin-x64@1.2.19', 'MIT'],
  ['@rolldown/binding-freebsd-x64@1.2.19', 'MIT'],
  ['@rolldown/binding-linux-arm-gnueabihf@1.2.19', 'MIT'],
  ['@rolldown/binding-linux-arm64-gnu@1.2.19', 'MIT'],
  ['@rolldown/binding-linux-arm64-musl@1.2.19', 'MIT'],
  ['@rolldown/binding-linux-ppc64-gnu@1.2.19', 'MIT'],
  ['@rolldown/binding-linux-s390x-gnu@1.2.19', 'MIT'],
  ['@rolldown/binding-linux-x64-gnu@1.2.19', 'MIT'],
  ['@rolldown/binding-linux-x64-musl@1.2.19', 'MIT'],
  ['@rolldown/binding-openharmony-arm64@1.2.19', 'MIT'],
  ['@rolldown/binding-wasm32-wasi@1.2.19', 'MIT'],
  ['@rolldown/binding-win32-arm64-msvc@1.2.19', 'MIT'],
  ['@rolldown/binding-win32-x64-msvc@1.2.19', 'MIT'],
  ['@rollup/rollup-android-arm-eabi@4.68.8', 'MIT'],
  ['@rollup/rollup-android-arm64@4.68.8', 'MIT'],
  ['@rollup/rollup-android-x64@4.68.8', 'MIT'],
  ['@rollup/rollup-darwin-x64@4.68.8', 'MIT'],
  ['@rollup/rollup-freebsd-arm64@4.68.8', 'MIT'],
  ['@rollup/rollup-freebsd-x64@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-arm-gnueabihf@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-arm-musleabihf@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-arm64-gnu@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-arm64-musl@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-loong64-gnu@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-loong64-musl@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-ppc64-gnu@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-ppc64-musl@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-riscv64-gnu@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-riscv64-musl@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-s390x-gnu@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-x64-gnu@4.68.8', 'MIT'],
  ['@rollup/rollup-linux-x64-musl@4.68.8', 'MIT'],
  ['@rollup/rollup-openbsd-x64@4.68.8', 'MIT'],
  ['@rollup/rollup-openharmony-arm64@4.68.8', 'MIT'],
  ['@rollup/rollup-win32-arm64-msvc@4.68.8', 'MIT'],
  ['@rollup/rollup-win32-ia32-msvc@4.68.8', 'MIT'],
  ['@rollup/rollup-win32-x64-gnu@4.68.8', 'MIT'],
  ['@rollup/rollup-win32-x64-msvc@4.68.8', 'MIT'],
  ['@tybys/wasm-util@0.10.5', 'MIT'],
  ['tslib@2.9.1', '0BSD'],
]);

const normalizeLicense = (license, name, version) => {
  const declared = lockedLicenseDeclarations.get(`${name}@${version}`);
  if (declared) {
    if (license && license !== 'Unknown' && license !== declared) {
      throw new Error(`license declaration changed for ${name}@${version}`);
    }
    return declared;
  }
  if (
    (name === 'trim' && version === '0.0.1') ||
    (name === 'only' && version === '0.0.2') ||
    (name === 'exif-parser' && version === '0.1.12') ||
    (name === 'dom-walk' && version === '0.1.2') ||
    (name === 'exit' && version === '0.1.2')
  ) {
    return 'MIT';
  }
  if (name === 'qrcode-terminal' && version === '0.12.0') return 'Apache-2.0';
  if (!license || license === 'Unknown') return 'NOASSERTION';
  if (license === 'Apache 2.0') return 'Apache-2.0';
  if (license === 'BSD') return 'LicenseRef-BSD-ambiguous';
  return license.replace(/^\((.*)\)$/, '$1');
};

const packageId = (ecosystem, name, version, location) => {
  const digest = createHash('sha256')
    .update(`${ecosystem}\0${name}\0${version}\0${location}`)
    .digest('hex')
    .slice(0, 20);
  return `SPDXRef-Package-${ecosystem.replace(
    /[^A-Za-z0-9.-]/g,
    '-'
  )}-${digest}`;
};

const npmPurl = (name, version) => {
  const encodedName = name.startsWith('@')
    ? `%40${name.slice(1).split('/').map(encodeURIComponent).join('/')}`
    : encodeURIComponent(name);
  return `pkg:npm/${encodedName}@${encodeURIComponent(version)}`;
};

const makePackage = ({
  ecosystem,
  name,
  version,
  location,
  license,
  source,
  scope,
}) => ({
  name,
  SPDXID: packageId(ecosystem, name, version, location),
  versionInfo: version,
  downloadLocation: source || 'NOASSERTION',
  filesAnalyzed: false,
  licenseConcluded: 'NOASSERTION',
  licenseDeclared: normalizeLicense(license, name, version),
  copyrightText: 'NOASSERTION',
  primaryPackagePurpose:
    ecosystem === 'composer' && scope === 'runtime' ? 'LIBRARY' : 'SOURCE',
  packageComment: `Peanut Admin ${ecosystem} locked dependency; release scope: ${scope}.`,
  externalRefs: [
    {
      referenceCategory: 'PACKAGE-MANAGER',
      referenceType: 'purl',
      referenceLocator:
        (ecosystem === 'composer' || ecosystem === 'quality-composer')
          ? `pkg:composer/${name}@${encodeURIComponent(version)}`
          : npmPurl(name, version),
    },
  ],
  _ecosystem: ecosystem,
  _scope: scope,
});

const readComposerLock = (ecosystem, relativePath, developmentOnly = false) => {
const composerLock = readJson(relativePath);
return [
  ...composerLock.packages.map((pkg) => ({ pkg, scope: 'runtime' })),
  ...composerLock['packages-dev'].map((pkg) => ({ pkg, scope: 'development' })),
].map(({ pkg, scope }) =>
  makePackage({
    ecosystem,
    name: pkg.name,
    version: pkg.version,
    location: `${pkg.name}@${pkg.version}`,
    license: Array.isArray(pkg.license)
      ? pkg.license.join(' AND ')
      : pkg.license,
    source: pkg.source?.url || (pkg.dist?.type === 'path' ? undefined : pkg.dist?.url),
    scope: developmentOnly ? 'development-tool-input' : scope,
  })
);
};
const composerPackages = readComposerLock('composer', 'server/composer.lock');

const readPnpmLicenses = (relativeDir) => {
  const cwd = resolve(rootDir, relativeDir);
  const manager = readJson(`${relativeDir}/package.json`).packageManager;
  const toolchain = readJson('tools/toolchain.json');
  const packageManagerFile = toolchain?.tools?.pnpm?.package_manager_file;
  const expected = relativeDir === 'tools/quality'
    ? manager
    : (packageManagerFile ? readJson(packageManagerFile).packageManager : manager);
  if (typeof expected !== 'string' || !/^pnpm@\d+\.\d+\.\d+$/.test(expected) || manager !== expected) {
    throw new Error(`undeclared license package manager for ${relativeDir}`);
  }
  const command = relativeDir === 'tools/quality' ? 'corepack' : 'pnpm';
  const prefix = relativeDir === 'tools/quality' ? ['pnpm'] : [];
  const version = spawnSync(command, [...prefix, '--version'], { cwd, encoding: 'utf8' });
  if (version.status !== 0 || version.stdout.trim() !== expected.slice(5)) {
    throw new Error(`license inventory requires ${expected} for ${relativeDir}`);
  }
  const result = spawnSync(
    command,
    [...prefix, 'licenses', 'list', '--json'],
    {
      cwd,
      encoding: 'utf8',
    }
  );
  if (result.status !== 0) {
    throw new Error(
      `pnpm license inventory failed for ${relativeDir}: ${result.stderr.trim()}`
    );
  }
  const grouped = JSON.parse(result.stdout);
  const licenses = new Map();
  for (const [license, entries] of Object.entries(grouped)) {
    for (const entry of entries) {
      for (const version of entry.versions)
        licenses.set(`${entry.name}@${version}`, license);
    }
  }
  return licenses;
};

const readPnpmLock = (ecosystem, relativeDir) => {
  const lockText = readFileSync(
    resolve(rootDir, relativeDir, 'pnpm-lock.yaml'),
    'utf8'
  );
  const packages = parseYaml(lockText).packages;
  if (!packages)
    throw new Error(
      `packages block not found in ${relativeDir}/pnpm-lock.yaml`
    );
  const licenseMap = readPnpmLicenses(relativeDir);
  return Object.entries(packages).map(([spec, locked]) => {
    const separator = spec.indexOf('@', 1);
    if (separator <= 0)
      throw new Error(`unsupported pnpm package key: ${spec}`);
    const name = spec.slice(0, separator);
    const locator = spec.slice(separator + 1);
    const core = versionContract.core_web.packages[name];
    if (locator.startsWith('file:') && (!core?.archive || !locator.endsWith(`/${core.archive}`))) {
      throw new Error(`undeclared pnpm file dependency: ${spec}`);
    }
    const version = locator.startsWith('file:') ? core.version : locator;
    return makePackage({
      ecosystem,
      name,
      version,
      location: spec,
      license: licenseMap.get(`${name}@${version}`),
      source: locator.startsWith('file:') ? undefined :
        (locked.resolution?.tarball || `https://registry.npmjs.org/${encodeURIComponent(name)}/${encodeURIComponent(version)}`),
      scope:
        ecosystem === 'quality-js'
          ? 'development-tool-input'
          : 'static-build-input',
    });
  });
};

const allPackages = [
  ...composerPackages,
  ...readPnpmLock('web', 'web'),
  ...readPnpmLock('platform', 'platform'),
  ...readPnpmLock('pc', 'pc'),
  ...readPnpmLock('uniapp', 'uniapp'),
  ...readComposerLock('quality-composer', 'tools/quality/composer.lock', true),
  ...readPnpmLock('quality-js', 'tools/quality'),
].sort(
  (a, b) =>
    a._ecosystem.localeCompare(b._ecosystem) ||
    a.name.localeCompare(b.name) ||
    a.versionInfo.localeCompare(b.versionInfo) ||
    a.SPDXID.localeCompare(b.SPDXID)
);

for (const [ecosystem, expected] of Object.entries(expectedCounts)) {
  const actual = allPackages.filter(
    (pkg) => pkg._ecosystem === ecosystem
  ).length;
  if (actual !== expected)
    throw new Error(
      `${ecosystem} lock count changed: expected ${expected}, found ${actual}`
    );
}

const unresolved = allPackages.filter(
  (pkg) => pkg.licenseDeclared === 'NOASSERTION'
);
if (unresolved.length > 0) {
  throw new Error(
    `unresolved license metadata: ${unresolved
      .map((pkg) => `${pkg._ecosystem}:${pkg.name}@${pkg.versionInfo}`)
      .join(', ')}`
  );
}

const rootPackage = {
  name: applicationProduct,
  SPDXID: 'SPDXRef-Package-Peanut-Admin',
  versionInfo: releaseVersion,
  downloadLocation: repositoryUrl || 'NOASSERTION',
  filesAnalyzed: false,
  licenseConcluded: 'Apache-2.0',
  licenseDeclared: 'Apache-2.0',
  copyrightText: 'Copyright 2026 花生科技',
  primaryPackagePurpose: 'APPLICATION',
  externalRefs: [
    {
      referenceCategory: 'PACKAGE-MANAGER',
      referenceType: 'purl',
      referenceLocator: applicationRepository
        ? `pkg:github/${applicationRepository}@${releaseVersion}`
        : `pkg:generic/${applicationIdentity.split('/').map(encodeURIComponent).join('/')}@${encodeURIComponent(releaseVersion)}`,
    },
  ],
};

const sbomPackages = allPackages.map(({ _ecosystem, _scope, ...pkg }) => pkg);
let existingCreated;
if (application && releaseDate == null && checkOnly) {
  try {
    const existing = JSON.parse(readFileSync(resolve(rootDir, 'RELEASE_SBOM.spdx.json'), 'utf8'));
    const created = existing.creationInfo?.created;
    if (typeof created === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/.test(created)) {
      const parsed = new Date(created);
      const normalized = created.replace(/\.(\d{1,3})Z$/, (_, fraction) => `.${fraction.padEnd(3, '0')}Z`);
      if (!Number.isNaN(parsed.valueOf()) &&
          (parsed.toISOString() === normalized || parsed.toISOString().replace('.000Z', 'Z') === normalized)) {
        existingCreated = created;
      }
    }
  } catch {
    // Missing or invalid existing SBOM is reported by the normal --check comparison.
  }
}
const sbomCreated = releaseDate != null
  ? `${releaseDate}T00:00:00Z`
  : existingCreated || new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const sbom = {
  spdxVersion: 'SPDX-2.3',
  dataLicense: 'CC0-1.0',
  SPDXID: 'SPDXRef-DOCUMENT',
  name: `${applicationProduct} ${releaseVersion} dependency SBOM`,
  documentNamespace: application
    ? (repositoryUrl && releaseDate != null
      ? `${repositoryUrl}/sbom/${releaseVersion}`
      : '')
    : `${repositoryUrl}/releases/tag/${releaseTag}/sbom`,
  creationInfo: {
    created: sbomCreated,
    creators: [
      'Organization: 花生科技',
      'Tool: scripts/generate-release-legal.mjs',
    ],
    licenseListVersion: '3.27',
  },
  documentDescribes: [rootPackage.SPDXID],
  hasExtractedLicensingInfos: [
    {
      licenseId: 'LicenseRef-BSD-ambiguous',
      extractedText:
        'The upstream package declares BSD without a more specific SPDX identifier. Consult the package source recorded in this SBOM.',
      name: 'Upstream ambiguous BSD declaration',
    },
  ],
  packages: [rootPackage, ...sbomPackages],
  relationships: allPackages.map((pkg) => ({
    spdxElementId: rootPackage.SPDXID,
    relationshipType: 'DEPENDS_ON',
    relatedSpdxElement: pkg.SPDXID,
    comment: `${pkg._ecosystem}; ${pkg._scope}`,
  })),
};
if (application && (repositoryUrl == null || releaseDate == null)) {
  const { documentNamespace: ignoredNamespace, ...documentContent } = sbom;
  const documentDigest = createHash('sha256').update(JSON.stringify(documentContent)).digest('hex');
  sbom.documentNamespace = `https://spdx.org/spdxdocs/${documentDigest}`;
}

const licenseCounts = new Map();
for (const pkg of allPackages) {
  const key = `${pkg._ecosystem}\0${pkg.licenseDeclared}`;
  licenseCounts.set(key, (licenseCounts.get(key) || 0) + 1);
}

const commonLicenses = new Set([
  'MIT',
  'Apache-2.0',
  'BSD-2-Clause',
  'BSD-3-Clause',
  'ISC',
  '0BSD',
]);
const notablePackages = [
  ...new Map(
    allPackages
      .filter((pkg) => !commonLicenses.has(pkg.licenseDeclared))
      .map((pkg) => [
        `${pkg.name}@${pkg.versionInfo}\0${pkg.licenseDeclared}`,
        pkg,
      ])
  ).values(),
].sort(
  (a, b) =>
    a.licenseDeclared.localeCompare(b.licenseDeclared) ||
    a.name.localeCompare(b.name)
);

const composerRuntime = allPackages.filter(
  (pkg) => pkg._ecosystem === 'composer' && pkg._scope === 'runtime'
);
const licenseRows = [...licenseCounts.entries()]
  .sort(([a], [b]) => a.localeCompare(b))
  .map(([key, count]) => {
    const [ecosystem, license] = key.split('\0');
    return `| ${ecosystem} | \`${license}\` | ${count} |`;
  })
  .join('\n');
const composerRows = composerRuntime
  .map(
    (pkg) =>
      `| \`${pkg.name}\` | \`${pkg.versionInfo}\` | \`${pkg.licenseDeclared}\` | ${pkg.downloadLocation} |`
  )
  .join('\n');
const notableRows = notablePackages
  .map(
    (pkg) =>
      `| ${pkg._ecosystem} | \`${pkg.name}\` | \`${pkg.versionInfo}\` | \`${pkg.licenseDeclared}\` | ${pkg.downloadLocation} |`
  )
  .join('\n');

const applicationLockRows = application
  ? Object.entries(expectedCounts).map(([ecosystem, count]) => `| ${ecosystem} | ${count} |`).join('\n')
  : '';
const notices = `# ${applicationProduct} Third-Party Notices

${application && releaseDate == null
  ? `Prepared for ${applicationProduct} ${releaseVersion}; release date not declared.`
  : `Generated for ${applicationProduct} ${releaseVersion} on ${releaseDate}.`}

${application
  ? (repositoryUrl
    ? `Application source: ${repositoryUrl}. See the application's LICENSE and NOTICE for its own terms and attribution. Third-party components remain governed by their own licenses.`
    : `Application source repository: not declared (local application source). See the application's LICENSE and NOTICE for its own terms and attribution. Third-party components remain governed by their own licenses.`)
  : 'Peanut Admin is licensed under Apache-2.0: Copyright 2026 花生科技. Third-party components remain governed by their own licenses.'}

## Distribution boundary

${application ? `- This inventory covers the application source and components resolved in the locks listed below. It does not describe the contents of a particular deployed image or archive.
- The application Composer lock includes production and development dependencies. The four client locks cover static build inputs. The two quality-tool locks cover development tooling.
- Exact package versions, licenses and sources for all seven dependency graphs are listed in \`RELEASE_SBOM.spdx.json\` (SPDX 2.3).

| Dependency graph | Lock entries |
|---|---:|
${applicationLockRows}` : `- The normative GitHub Release distributes this repository's source. It does not attach prebuilt PHP/Nginx images; the fixed core packages are published separately in their public registries.
- Production Compose builds static management, PC and H5 assets and installs the ${composerRuntime.length} Composer production packages listed below. No \`node_modules\` directory is copied into the final images.
- The exhaustive package/version/license/source inventory for the seven locked dependency graphs is \`RELEASE_SBOM.spdx.json\` (SPDX 2.3). The two quality-tool graphs are development inputs, separate from product runtime. Build-only entries are retained there so source-release recipients can reproduce the build and its notices.`}
- Each installed dependency may include additional license or notice files. Those files remain authoritative for that dependency and must not be removed from redistributed dependency archives.

## Material source and framework attributions

| Source | License | Attribution and use in this release |
|---|---|---|
| Arco Design Pro Vue | MIT | The initial management client used Arco Design Pro Vue material; applicable upstream MIT attribution is retained. Source: https://github.com/arco-design/arco-design-pro-vue |
| LikeAdmin 1.9.4 | MIT | Used as the documented behavioral parity reference. This notice does not claim the application is a clean-room implementation. Source: https://github.com/likeadmin-likeshop/likeadmin_php |
| ThinkPHP 8 | Apache-2.0 | Backend framework. Its upstream notice is also retained at \`server/LICENSE.txt\`. Source: https://github.com/top-think/framework |
| \`peanut-admin/core\` | Apache-2.0 | Composer core package locked at ${corePhpIdentity}. Source: https://github.com/peanut-business/peanut-admin-core-php |
| Peanut Admin Core Web | Apache-2.0 | npm core packages locked as ${coreWebIdentity}. Source: https://github.com/peanut-business/peanut-admin-core-web |

## License handling

- MIT, ISC, BSD, 0BSD, MIT-0, Apache-2.0 and Zlib notices are preserved through this file, the SPDX inventory and the upstream package sources recorded there.
${application ? `- License expressions and attributions in the inventory apply to the locked versions listed there. Preserve applicable attributions and follow upstream license terms for the components included in any redistribution.
- For components offered under multiple licenses, the SPDX inventory preserves the alternatives declared by the upstream package.` : `- MPL-2.0 entries are build inputs in the current Nuxt and development-tool lock graphs; no standalone package or modified MPL source is shipped as a release attachment. If a future release distributes those files, it must add the MPL source/notice obligations for that artifact.
- CC0, CC-BY, BlueOak-1.0.0 and Python-2.0 entries are identified below and in the SPDX inventory; attribution-bearing data must keep its upstream credit when redistributed.
- Compound expressions retain the upstream choice exactly. \`node-forge@1.4.0\` is recorded as \`BSD-3-Clause OR GPL-2.0\`; this release relies on the permissive BSD-3-Clause option and does not claim a GPL grant for Peanut Admin.
- \`@tybys/wasm-util@0.10.3\` and \`@napi-rs/lzma-linux-x64-gnu@1.5.1\` publish MIT metadata but no separate copyright line or NOTICE in the inspected upstream artifact. Their package/version/source is recorded without inventing an attribution.`}

## Locked-license summary

| Ecosystem | Declared license | Lock entries |
|---|---|---:|
${licenseRows}

## Composer production packages

${application ? `The application Composer lock lists ${composerRuntime.length} production packages. Development dependencies are reported separately in the SBOM.`
  : `These ${composerRuntime.length} packages are installed with \`composer install --no-dev\` in the production PHP image.`}

| Package | Version | License | Source |
|---|---|---|---|
${composerRows}

## Non-default and compound license entries

The following deduplicated package/version entries are outside the common MIT, Apache-2.0, BSD-2-Clause, BSD-3-Clause, ISC and 0BSD set. The full inventory, including every common-license entry, is in \`RELEASE_SBOM.spdx.json\`.

| Ecosystem | Package | Version | License | Source |
|---|---|---|---|---|
${notableRows}

## Obtaining license texts

The SPDX identifiers above resolve through https://spdx.org/licenses/. Exact package sources and versions are recorded in \`RELEASE_SBOM.spdx.json\`; Composer-installed packages also retain their upstream license files in \`server/vendor\` after deployment. For a redistributed binary or image, include this file, the SBOM and any additional license files required by the packages actually embedded in that artifact.
`;

const outputs = new Map([
  ['RELEASE_SBOM.spdx.json', `${JSON.stringify(sbom, null, 2)}\n`],
  ['THIRD_PARTY_NOTICES.md', notices],
]);

let different = false;
releaseMetadata.legal_files ??= {};
for (const [relativePath, content] of outputs) {
  const outputPath = resolve(rootDir, relativePath);
  if (checkOnly) {
    let existing = '';
    try {
      existing = readFileSync(outputPath, 'utf8');
    } catch {
      different = true;
      console.error(`${relativePath}: missing`);
      continue;
    }
    if (existing !== content) {
      different = true;
      console.error(`${relativePath}: out of date`);
    }
  } else {
    writeFileSync(outputPath, content);
    console.log(`${relativePath}: generated`);
  }
}

// Bind metadata to the newly generated legal bytes, rather than the previous
// release's inventory that existed when candidate preparation first ran.
for (const [relativePath, content] of outputs) {
  const digest = createHash('sha256').update(content).digest('hex');
  if (checkOnly) {
    if (releaseMetadata.legal_files?.[relativePath] !== digest) {
      different = true;
      console.error(`RELEASE_METADATA.json: ${relativePath} digest is out of date`);
    }
  } else {
    releaseMetadata.legal_files[relativePath] = digest;
  }
}
if (!checkOnly) {
  writeFileSync(
    resolve(rootDir, 'RELEASE_METADATA.json'),
    `${JSON.stringify(releaseMetadata, null, 2)}\n`
  );
}

if (different) process.exitCode = 1;
