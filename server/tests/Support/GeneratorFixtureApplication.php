<?php

declare(strict_types=1);

namespace Tests\Support;

/** Generator 资格的独立 APP 输入；只向调用者自有临时根复制声明样本。 */
final class GeneratorFixtureApplication
{
    public readonly string $root;
    private bool $owned;

    public function __construct(?string $temporaryRoot = null)
    {
        $this->owned = $temporaryRoot === null;
        $this->root = $temporaryRoot ?? sys_get_temp_dir() . '/peanut-generator-app-' . bin2hex(random_bytes(8));
        $source = dirname(__DIR__, 3);
        self::copyTree($source . '/server/fixtures/modules/delivery_record', $this->root . '/server/app/modules/fixture/delivery_record');
        self::copyTree($source . '/web/tests/fixtures/modules/fixture-delivery-record', $this->root . '/web/src/modules/fixture-delivery-record');
        $route = $this->root . '/server/app/adminapi/route/app.php';
        if (!is_dir(dirname($route)) && !mkdir(dirname($route), 0775, true)) {
            throw new \RuntimeException('Cannot create Generator fixture APP route directory');
        }
        if (!copy($source . '/server/app/adminapi/route/app.php', $route)) {
            throw new \RuntimeException('Cannot copy Generator fixture APP route input');
        }
    }

    public function __destruct()
    {
        if ($this->owned) {
            self::removeTree($this->root);
        }
    }

    private static function copyTree(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0775, true)) {
            throw new \RuntimeException('Cannot create Generator fixture APP directory');
        }
        foreach (new \DirectoryIterator($source) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink()) {
                throw new \RuntimeException('Generator fixture input must not contain symlinks');
            }
            $destination = $target . '/' . $entry->getFilename();
            if ($entry->isDir()) {
                self::copyTree($entry->getPathname(), $destination);
            } elseif (!copy($entry->getPathname(), $destination)) {
                throw new \RuntimeException('Cannot copy Generator fixture APP input');
            }
        }
    }

    private static function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isDir() && !$entry->isLink()) {
                self::removeTree($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }
}
