<?php
declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/** 执行镜像共用的实际 Shell 读取器；配置内容始终是数据，不执行其中的表达式。 */
final class BackendEnumReaderTest extends TestCase
{
    public function testLegalRawQuotedAndCommentedValuesUseOneCanonicalValue(): void
    {
        foreach ([
            ['DEPLOYMENT_MODE=multi-tenant\n', 'DEPLOYMENT_MODE', 'multi-tenant'],
            ['DEPLOYMENT_MODE="multi-tenant"\n', 'DEPLOYMENT_MODE', 'multi-tenant'],
            ["  DEPLOYMENT_MODE = 'standalone' ; comment\r\n", 'DEPLOYMENT_MODE', 'standalone'],
            ['PEANUT_INSTALLATION_MODE=automatic\n', 'PEANUT_INSTALLATION_MODE', 'automatic'],
            ['PEANUT_INSTALLATION_MODE="guided" # comment\n', 'PEANUT_INSTALLATION_MODE', 'guided'],
        ] as [$input, $key, $expected]) {
            [$code, $output] = $this->runReader(str_replace('\\n', "\n", $input), $key);
            self::assertSame(0, $code);
            self::assertSame($expected . "\n", $output);
        }
    }

    public function testMissingDuplicateInvalidAndExecutableValuesAreRejected(): void
    {
        foreach ([
            '', "DEPLOYMENT_MODE=\n", "DEPLOYMENT_MODE=invalid\n",
            "DEPLOYMENT_MODE=multi-tenant\nDEPLOYMENT_MODE=multi-tenant\n",
            "DEPLOYMENT_MODE=multi-tenant\n DEPLOYMENT_MODE = standalone\n",
            "DEPLOYMENT_MODE=\"multi-tenant\n",
            'DEPLOYMENT_MODE=$(printf unexpected)',
            "DEPLOYMENT_MODE=multi-tenant extra\n",
        ] as $input) {
            [$code, $output] = $this->runReader($input, 'DEPLOYMENT_MODE');
            self::assertNotSame(0, $code);
            self::assertSame('', $output);
        }
    }

    public function testArbitraryConfigurationKeysAndSymlinksAreNotReadable(): void
    {
        [$code, $output] = $this->runReader("DB_PASS=never-output-this-value\n", 'DB_PASS');
        self::assertNotSame(0, $code);
        self::assertSame('', $output);
        [$code, $output] = $this->runReader("DEPLOYMENT_MODE=multi-tenant\n", 'DEPLOYMENT_MODE', true);
        self::assertNotSame(0, $code);
        self::assertSame('', $output);
    }

    private function runReader(string $input, string $key, bool $link = false): array
    {
        $directory = sys_get_temp_dir() . '/peanut-enum-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create enum fixture');
        $file = $directory . '/backend.env';
        file_put_contents($file, $input);
        chmod($file, 0600);
        $selected = $file;
        try {
            if ($link) {
                $selected = $directory . '/linked.env';
                if (!symlink($file, $selected)) throw new RuntimeException('Cannot create test symlink');
            }
            $process = proc_open(['/bin/sh', dirname(__DIR__, 3) . '/deploy/docker/read-backend-enum.sh', $selected, $key],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start enum reader');
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $output, $error];
        } finally {
            if ($link && is_link($selected)) unlink($selected);
            unlink($file);
            rmdir($directory);
        }
    }
}
