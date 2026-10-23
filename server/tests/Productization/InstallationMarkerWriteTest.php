<?php

declare(strict_types=1);

namespace app\common\services\installation {
    function file_put_contents(string $path, string $contents, int $flags = 0): int|false
    {
        if (($GLOBALS['installationMarkerShortWrite'] ?? false) === true) {
            return \file_put_contents($path, substr($contents, 0, 1), $flags);
        }
        return \file_put_contents($path, $contents, $flags);
    }
}

namespace think\facade {
    final class Config
    {
        public static function get(string $name, mixed $default = null): mixed
        {
            return $name === 'deployment.mode' ? 'multi-tenant' : $default;
        }
    }
}

namespace {
    use app\common\services\installation\InstallationExecutionHost;

    require dirname(__DIR__, 2) . '/app/common/services/installation/InstallationExecutionHost.php';

    $root = dirname(__DIR__, 3) . '/.local/tmp';
    if (!is_dir($root) && !mkdir($root, 0700, true)) {
        throw new RuntimeException('Installation marker fixture root unavailable');
    }
    $directory = $root . '/peanut-install-marker-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Installation marker fixture unavailable');
    }
    $path = $directory . '/private/installation/executing.json';
    $host = (new ReflectionClass(InstallationExecutionHost::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(InstallationExecutionHost::class, 'serverRoot'))->setValue($host, $directory);
    $write = new ReflectionMethod(InstallationExecutionHost::class, 'writeProgressMarker');
    try {
        $write->invoke($host, []);
        $original = file_get_contents($path);
        $decoded = json_decode((string) $original, true, 512, JSON_THROW_ON_ERROR);
        if (($decoded['state'] ?? null) !== 'executing' || ($decoded['deployment_mode'] ?? null) !== 'multi-tenant') {
            throw new RuntimeException('Installation marker normal write failed');
        }

        $GLOBALS['installationMarkerShortWrite'] = true;
        try {
            $write->invoke($host, []);
            throw new RuntimeException('Installation marker short write was accepted');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'Installation state marker cannot be written.') {
                throw $exception;
            }
        }
        if (file_get_contents($path) !== $original || glob($path . '.tmp-*') !== []) {
            throw new RuntimeException('Installation marker short write changed existing identity');
        }
        echo "INSTALL-MARKER-WRITE-001 passed\n";
    } finally {
        unset($GLOBALS['installationMarkerShortWrite']);
        foreach (glob($path . '.tmp-*') ?: [] as $temporary) {
            unlink($temporary);
        }
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($directory . '/private/installation');
        rmdir($directory . '/private');
        rmdir($directory);
    }
}
