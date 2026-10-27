<?php

declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use app\common\value\scaffold\EditionProfile;
use RuntimeException;

final class EditionProjector
{
    /** Final bytes are projected before the canonical Plugin writer and API inventory. */
    public function workflow(string $stage, array $files, string $mode): array
    {
        if (!in_array($mode, ['none', 'runtime', 'full'], true)) {
            throw new RuntimeException('CREATE_APP_WORKFLOW_MODE_INVALID');
        }
        $result = [];
        foreach ($files as $file) {
            $path = $file['path'];
            $workflow = str_starts_with($path, 'server/app/modules/peanut/workflow/') || str_starts_with($path, 'web/src/modules/peanut-workflow/') || str_starts_with($path, 'plugins/peanut.workflow/')
                || str_starts_with($path, 'server/app/modules/official/article/src/Integration/Workflow/')
                || str_starts_with($path, 'server/app/modules/official/article/resources/workflows/') || str_starts_with($path, 'web/src/modules/official-article/workflow/')
                || str_starts_with($path, 'web/src/modules/official-article/views/review/');
            $design = str_starts_with($path, 'web/src/modules/peanut-workflow/designer/')
                || in_array($path, ['server/app/modules/peanut/workflow/route/design.php', 'server/app/modules/peanut/workflow/src/Controller/WorkflowDefinitionController.php', 'server/app/modules/peanut/workflow/src/Application/WorkflowDefinitionService.php', 'server/app/modules/peanut/workflow/src/Http/WorkflowJsonProjection.php'], true);
            $absolute = $stage . '/' . $path;
            if (($mode === 'none' && ($workflow || $path === 'server/app/command/WorkflowInstallManaged.php')) || ($mode === 'runtime' && $design)) {
                if (!unlink($absolute)) {
                    throw new RuntimeException('CREATE_APP_WORKFLOW_REMOVE_FAILED: ' . $path);
                }
                continue;
            }
            $content = (string) file_get_contents($absolute);
            if ($mode === 'runtime' && $path === 'web/src/modules/peanut-workflow/contribution.ts') {
                $content = "import runtimeContribution from './runtime/contribution';\n\nexport default runtimeContribution;\n";
            }
            if ($mode === 'runtime' && $path === 'server/app/modules/peanut/workflow/src/ModuleProvider.php') {
                $start = strpos($content, '            \\PeanutAdmin\\Modules\\Workflow\\Application\\WorkflowDefinitionService::class =>');
                $end = strpos($content, '            \\PeanutAdmin\\Modules\\Workflow\\Application\\WorkflowCommands::class =>');
                if ($start === false || $end === false || $end <= $start) {
                    throw new RuntimeException('CREATE_APP_WORKFLOW_PROVIDER_INVALID');
                }
                $content = substr($content, 0, $start) . substr($content, $end);
            }
            if ($mode === 'runtime' && $path === 'server/app/modules/peanut/workflow/module.json') {
                $document = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
                $document['contracts']['exports'] = array_values(array_filter($document['contracts']['exports'], static fn(string $name): bool => !str_ends_with($name, '\\WorkflowDefinitionService')));
                $content = self::json($document);
            }
            if ($mode === 'runtime' && $path === 'server/app/modules/peanut/workflow/src/Application/WorkflowQueries.php') {
                $content = $this->removeRange($content, '    public function definitions(', '    public function instance(');
                $content = $this->removeRange($content, '    private function definitionSummary(', '    private function taskManagementAllowed(');
                $content = $this->removeRange($content, '    private function definitionContext(', '    private function page(');
            }
            if ($mode === 'runtime' && $path === 'web/src/modules/peanut-workflow/api.ts') {
                foreach ([
                    ['export interface SaveReceipt {', 'export interface StartInput {'],
                    ['  scenario(', '  dispose(): void;'],
                    ['  definition(', '  inbox('],
                    ['export function parseIssues(', 'function parseInboxTask('],
                    ['export function readGraph(', 'export function createWorkflowApi('],
                    ['    async scenario(', '    async inbox('],
                ] as [$start, $end]) {
                    $content = $this->removeRange($content, $start, $end);
                }
                foreach (['EditorDocument', 'ValidationIssue', 'WorkflowDefinition', 'WorkflowNode', 'WorkflowScenario'] as $type) {
                    $content = $this->replaceOnce($content, '  ' . $type . ",\n", '', 'CREATE_APP_WORKFLOW_SDK_IMPORT_INVALID');
                }
            }
            if ($mode === 'runtime' && $path === 'server/app/modules/peanut/workflow/resources/permissions.json') {
                $permissions = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
                $content = self::json(array_values(array_filter($permissions, static fn(array $permission): bool => !str_starts_with($permission['key'], 'peanut.workflow.definition.'))));
            }
            if ($mode === 'runtime' && $path === 'server/app/modules/peanut/workflow/resources/menus.json') {
                $menus = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
                $content = self::json(array_values(array_filter($menus, static fn(array $menu): bool => $menu['key'] !== 'peanut.workflow.designer')));
            }
            if ($path === 'server/composer.json') {
                $composer = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
                unset($composer['autoload']['psr-4']['PeanutAdmin\\Fixtures\\WorkflowBusiness\\']);
                unset($composer['autoload']['psr-4']['PeanutAdmin\\Fixtures\\DeliveryRecord\\']);
                if ($mode === 'none') {
                    unset($composer['autoload']['psr-4']['PeanutAdmin\\Modules\\Workflow\\']);
                }
                $content = self::json($composer);
            }
            if ($mode === 'none' && $path === 'server/config/console.php') {
                $content = preg_replace('/^.*workflow:install-managed.*\n/m', '', $content) ?? $content;
            }
            if ($mode !== 'full' && $path === 'web/package.json') {
                $package = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
                foreach (array_keys($package['dependencies']) as $dependency) {
                    if (str_starts_with($dependency, '@vue-flow/')) {
                        unset($package['dependencies'][$dependency]);
                    }
                }
                $content = self::json($package);
            }
            if ($mode !== 'full' && $path === 'web/pnpm-lock.yaml') {
                // Only importer declarations select installed dependencies. Unused lock
                // resolutions are retained until the consumer's next native lock update.
                $content = preg_replace('/^      \'@vue-flow\/[^\']+\':\n(?:        [^\n]*\n)+/m', '', $content) ?? $content;
            }
            if ($content !== file_get_contents($absolute)) {
                $this->writeFile($absolute, $content, $file['mode']);
                $file['sha256'] = hash('sha256', $content);
            }
            $result[] = $file;
        }
        return $result;
    }

    private static function json(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function removeRange(string $content, string $start, string $end): string
    {
        if (substr_count($content, $start) !== 1 || substr_count($content, $end) !== 1) {
            throw new RuntimeException('CREATE_APP_WORKFLOW_PROJECTION_SOURCE_INVALID');
        }
        $from = strpos($content, $start);
        $to = strpos($content, $end);
        if ($to <= $from) {
            throw new RuntimeException('CREATE_APP_WORKFLOW_PROJECTION_ORDER_INVALID');
        }
        return substr($content, 0, $from) . substr($content, $to);
    }

    private const PATHS = [
        'deploy/docker/nginx-select-admin.sh',
        'deploy/docker/production.Dockerfile',
        'server/.env.example',
        'server/config/app.php',
        'web/.env.example',
    ];

    /** @return list<string> */
    public function paths(EditionProfile $profile): array
    {
        return self::PATHS;
    }

    /** @return array{path:string,sha256:string,mode:int,classification:string,owner:string,source:string} */
    public function project(
        string $stage,
        array $entry,
        string $content,
        EditionProfile $profile,
    ): array {
        $path = (string) $entry['target'];
        $content = match ($path) {
            'server/.env.example' => $this->deploymentEnvironment($content, $profile->edition, 'DEPLOYMENT_MODE'),
            'web/.env.example' => $this->deploymentEnvironment($content, $profile->edition, 'VITE_DEPLOYMENT_MODE'),
            'server/config/app.php' => $this->applicationConfig($content, $profile),
            'deploy/docker/production.Dockerfile' => $this->productionDockerfile($content, $profile),
            'deploy/docker/nginx-select-admin.sh' => $this->adminSelector($content, $profile),
            default => $content,
        };
        $destination = $stage . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $this->writeFile($destination, $content, (int) $entry['mode']);

        return [
            'path' => $path,
            'sha256' => hash('sha256', $content),
            'mode' => $entry['mode'],
            'classification' => $entry['classification'],
            'owner' => $entry['owner'],
            'source' => $entry['path'],
        ];
    }

    private function deploymentEnvironment(string $content, string $edition, string $key): string
    {
        $pattern = '/^' . $key . '=(standalone|multi-tenant)$/m';
        if (preg_match_all($pattern, $content) !== 1) {
            throw new RuntimeException('CREATE_APP_EDITION_ENV_SOURCE_INVALID');
        }
        return preg_replace(
            $pattern,
            $key . '=' . $edition,
            $content,
            1,
        ) ?? $content;
    }

    private function applicationConfig(string $content, EditionProfile $profile): string
    {
        if ($profile->edition !== 'standalone') {
            return $content;
        }
        if (preg_match_all("/^        'platformapi' => 'platform',$/m", $content) !== 1) {
            throw new RuntimeException('CREATE_APP_EDITION_APP_CONFIG_SOURCE_INVALID');
        }
        return preg_replace(
            "/^        'platformapi' => 'platform',\n/m",
            '',
            $content,
            1,
        ) ?? $content;
    }

    private function productionDockerfile(string $content, EditionProfile $profile): string
    {
        $bothAdminBuilds = "    && pnpm exec vite build --mode standalone --outDir dist/standalone \\\n"
            . "    && pnpm exec vite build --mode multi-tenant --outDir dist/multi-tenant";
        if ($profile->edition === 'standalone') {
            $content = $this->replaceOnce(
                $content,
                $bothAdminBuilds,
                '    && pnpm exec vite build --mode standalone --outDir dist/standalone',
                'CREATE_APP_EDITION_DOCKER_ADMIN_BUILD_SOURCE_INVALID',
            );
            $content = preg_replace(
                '/\nFROM client-base AS platform-builder\n.*?\nRUN npm run build\n/s',
                '',
                $content,
                1,
                $platformCount,
            ) ?? $content;
            if ($platformCount !== 1) {
                throw new RuntimeException('CREATE_APP_EDITION_DOCKER_PLATFORM_BUILD_SOURCE_INVALID');
            }
            $content = $this->removeLineOnce(
                $content,
                'COPY --from=platform-builder /build/platform/dist /var/www/peanut-admin/server/public/platform',
                'CREATE_APP_EDITION_DOCKER_PLATFORM_COPY_SOURCE_INVALID',
            );
        } else {
            $content = $this->replaceOnce(
                $content,
                $bothAdminBuilds,
                '    && pnpm exec vite build --mode multi-tenant --outDir dist/multi-tenant',
                'CREATE_APP_EDITION_DOCKER_ADMIN_BUILD_SOURCE_INVALID',
            );
        }

        return $content;
    }

    private function adminSelector(string $content, EditionProfile $profile): string
    {
        $content = $this->replaceOnce(
            $content,
            '    standalone|multi-tenant) ;;',
            '    ' . $profile->edition . ') ;;',
            'CREATE_APP_EDITION_NGINX_SELECTOR_SOURCE_INVALID',
        );
        return $this->replaceOnce(
            $content,
            'nginx-select-admin: DEPLOYMENT_MODE must be standalone or multi-tenant',
            'nginx-select-admin: this artifact requires DEPLOYMENT_MODE=' . $profile->edition,
            'CREATE_APP_EDITION_NGINX_SELECTOR_SOURCE_INVALID',
        );
    }

    private function replaceOnce(
        string $content,
        string $search,
        string $replacement,
        string $error,
    ): string {
        if (substr_count($content, $search) !== 1) {
            throw new RuntimeException($error);
        }
        return str_replace($search, $replacement, $content);
    }

    private function removeLineOnce(string $content, string $line, string $error): string
    {
        return $this->replaceOnce($content, $line . "\n", '', $error);
    }

    private function writeFile(string $path, string $content, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new RuntimeException('CREATE_APP_DIRECTORY_FAILED: ' . $directory);
        }
        if (file_put_contents($path, $content) === false || !chmod($path, $mode)) {
            throw new RuntimeException('CREATE_APP_WRITE_FAILED: ' . $path);
        }
    }
}
