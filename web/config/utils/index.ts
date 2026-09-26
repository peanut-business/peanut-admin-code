/**
 * Whether to generate package preview
 * 是否生成打包报告
 */
export default {};

import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readClientEnvironment } from '../../../scripts/client-environment';

const configDir = dirname(fileURLToPath(import.meta.url));

export function isReportMode(): boolean {
  return (
    readClientEnvironment(resolve(configDir, '../../.env.production'))
      .REPORT === 'true'
  );
}
