<?php
declare(strict_types=1);

use app\common\infrastructure\scaffold\EditionProjector;
use app\common\value\scaffold\EditionProfile;
use PHPUnit\Framework\TestCase;

final class A1EditionDockerProjectionTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function testDockerBuildInputsExistBeforeDependencyInstallation(): void
    {
        $docker = $this->read('deploy/docker/production.Dockerfile');
        self::assertStringContainsString('FROM node:22.23.2-bookworm-slim AS client-base', $docker);
        self::assertStringContainsString("require('./package.json').packageManager", $docker);
        $web = json_decode($this->read('web/package.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/^pnpm@[0-9]+\\.[0-9]+\\.[0-9]+$/D', $web['packageManager']);
        $firstInstall = $this->position($docker, 'pnpm install --frozen-lockfile');
        self::assertLessThan($firstInstall, $this->position($docker, 'COPY web/package.json web/pnpm-lock.yaml ./'));
        self::assertLessThan($firstInstall, $this->position($docker, 'COPY packages/core-web/*.tgz'));
        $versions = json_decode($this->read('release-versions.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(6, $versions['core_web']['packages']);
        foreach ($versions['core_web']['packages'] as $identity) {
            self::assertMatchesRegularExpression('~^packages/core-web/peanut-admin-[a-z-]+-[0-9A-Za-z.+-]+\\.tgz$~D', $identity['archive']);
            $archive = $this->repositoryRoot . '/' . $identity['archive'];
            self::assertFileExists($archive);
            self::assertSame($identity['sha256'], hash_file('sha256', $archive));
        }

        $composerInstall = $this->position($docker, 'RUN composer install');
        self::assertLessThan($composerInstall, $this->position($docker, 'COPY server/app app'));
        self::assertLessThan($composerInstall, $this->position($docker, 'COPY server/database/schema database/schema'));
    }

    public function testRuntimeRetainsCanonicalModuleSourcesButExcludesPrivateLocalInputs(): void
    {
        $docker = $this->read('deploy/docker/production.Dockerfile');
        self::assertSame(1, preg_match('/FROM php:8\\.3-fpm-bookworm AS php\\n(?<stage>.*?)\\nFROM nginx:/s', $docker, $matches));
        self::assertSame(1, preg_match_all('/^COPY \\. \\.$/m', $matches['stage']));

        $ignore = $this->read('.dockerignore');
        self::assertStringContainsString('**/node_modules', $ignore);
        self::assertStringContainsString('**/.local', $ignore);
        self::assertStringContainsString('**/.env.*', $ignore);
        self::assertStringContainsString('server/tests', $ignore);
        self::assertDoesNotMatchRegularExpression('~^(?:web|platform)/src/modules(?:/|$)~m', $ignore);
        // 模块自带 fixtures 已被当前 Plugin source 摘要覆盖，不能在镜像里单方面丢弃。
        self::assertStringNotContainsString('**/tests/**', $ignore);
        self::assertStringNotContainsString('**/*.spec.ts', $ignore);
        self::assertStringNotContainsString('**/*.test.ts', $ignore);
    }

    public function testRealDockerfileProjectsEachEditionWithTheCurrentEnvironmentSelector(): void
    {
        $docker = $this->read('deploy/docker/production.Dockerfile');
        self::assertStringContainsString('PEANUT_CLIENT_ENV_FILE=', $docker);
        self::assertStringNotContainsString('&& VITE_DEPLOYMENT_MODE=', $docker);

        $standalone = $this->projectDocker($docker, 'standalone');
        self::assertStringContainsString('PEANUT_CLIENT_ENV_FILE=/build/web/.env.standalone', $standalone);
        self::assertStringNotContainsString('PEANUT_CLIENT_ENV_FILE=/build/web/.env.multi-tenant', $standalone);
        self::assertStringNotContainsString('AS platform-builder', $standalone);
        self::assertStringNotContainsString('COPY --from=platform-builder', $standalone);

        $saas = $this->projectDocker($docker, 'multi-tenant');
        self::assertStringNotContainsString('PEANUT_CLIENT_ENV_FILE=/build/web/.env.standalone pnpm exec vite build', $saas);
        self::assertStringContainsString('PEANUT_CLIENT_ENV_FILE=/build/web/.env.multi-tenant', $saas);
        self::assertStringContainsString('AS platform-builder', $saas);
        self::assertStringContainsString('COPY --from=platform-builder', $saas);
    }

    private function projectDocker(string $docker, string $edition): string
    {
        $stage = sys_get_temp_dir() . '/peanut-a1-edition-' . $edition . '-' . bin2hex(random_bytes(6));
        $target = $stage . '/deploy/docker/production.Dockerfile';
        try {
            (new EditionProjector())->project(
                $stage,
                [
                    'target' => 'deploy/docker/production.Dockerfile',
                    'path' => 'deploy/docker/production.Dockerfile',
                    'mode' => 0644,
                    'classification' => 'runtime',
                    'owner' => 'application',
                ],
                $docker,
                EditionProfile::load($this->repositoryRoot . '/scaffold/edition-profiles.json', $edition),
            );
            $projected = file_get_contents($target);
            self::assertIsString($projected);
            return $projected;
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
            $directory = dirname($target);
            while ($directory !== $stage && str_starts_with($directory, $stage)) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
                $directory = dirname($directory);
            }
            if (is_dir($stage)) {
                rmdir($stage);
            }
        }
    }

    private function read(string $path): string
    {
        $content = file_get_contents($this->repositoryRoot . '/' . $path);
        self::assertIsString($content);
        return $content;
    }

    private function position(string $content, string $needle): int
    {
        $position = strpos($content, $needle);
        self::assertNotFalse($position, $needle);
        return $position;
    }
}
