<?php
declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use app\common\value\scaffold\EditionProfile;
use RuntimeException;

final class EditionProjector
{
    private const PATHS = [
        'deploy/docker/nginx-select-admin.sh',
        'deploy/docker/production.Dockerfile',
        'server/.env.example',
        'server/config/app.php',
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
        $path = (string)$entry['target'];
        $content = match ($path) {
            'server/.env.example' => $this->serverEnvironment($content, $profile->edition),
            'server/config/app.php' => $this->applicationConfig($content, $profile),
            'deploy/docker/production.Dockerfile' => $this->productionDockerfile($content, $profile),
            'deploy/docker/nginx-select-admin.sh' => $this->adminSelector($content, $profile),
            default => $content,
        };
        $destination = $stage . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $this->writeFile($destination, $content, (int)$entry['mode']);

        return [
            'path' => $path,
            'sha256' => hash('sha256', $content),
            'mode' => $entry['mode'],
            'classification' => $entry['classification'],
            'owner' => $entry['owner'],
            'source' => $entry['path'],
        ];
    }

    private function serverEnvironment(string $content, string $edition): string
    {
        if (preg_match_all('/^DEPLOYMENT_MODE=(standalone|multi-tenant)$/m', $content) !== 1) {
            throw new RuntimeException('CREATE_APP_EDITION_ENV_SOURCE_INVALID');
        }
        return preg_replace(
            '/^DEPLOYMENT_MODE=(standalone|multi-tenant)$/m',
            'DEPLOYMENT_MODE=' . $edition,
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
        $content = $this->removeLineOnce(
            $content,
            '    && chmod +x server/database/seed-multi-tenant-demo.php \\',
            'CREATE_APP_EDITION_DOCKER_DEMO_SOURCE_INVALID',
        );
        $content = $this->removeLineOnce(
            $content,
            '    && ln -s /var/www/peanut-admin/server/database/seed-multi-tenant-demo.php /usr/local/bin/peanut-seed-multi-tenant-demo \\',
            'CREATE_APP_EDITION_DOCKER_DEMO_SOURCE_INVALID',
        );
        $bothAdminBuilds = "    && PEANUT_CLIENT_ENV_FILE=/build/web/.env.standalone pnpm exec vite build --config ./config/vite.config.prod.ts --outDir dist/standalone \\\n"
            . "    && PEANUT_CLIENT_ENV_FILE=/build/web/.env.multi-tenant pnpm exec vite build --config ./config/vite.config.prod.ts --outDir dist/multi-tenant";
        if ($profile->edition === 'standalone') {
            $content = $this->replaceOnce(
                $content,
                $bothAdminBuilds,
                '    && PEANUT_CLIENT_ENV_FILE=/build/web/.env.standalone pnpm exec vite build --config ./config/vite.config.prod.ts --outDir dist/standalone',
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
                '    && PEANUT_CLIENT_ENV_FILE=/build/web/.env.multi-tenant pnpm exec vite build --config ./config/vite.config.prod.ts --outDir dist/multi-tenant',
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
