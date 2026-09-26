#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

// Honor an already selected test autoloader; parse, never execute, audited sources.
if (!class_exists(ParserFactory::class)) {
    require_once dirname(__DIR__) . '/tools/quality/vendor/autoload.php';
}

/** @return array<string,mixed> */
function moduleBoundaryInventory(string $root): array
{
    $modules = [];
    $namespaces = [];
    $tables = [];
    $findings = [];
    $root = realpath($root) ?: throw new InvalidArgumentException('MODULE_BOUNDARY_ROOT_MISSING');
    foreach (glob($root . '/server/app/modules/*/*/module.json') ?: [] as $manifestPath) {
        $directory = dirname($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $composer = json_decode((string) file_get_contents($directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $key = $manifest['key'];
        if (isset($modules[$key])) {
            throw new RuntimeException('MODULE_BOUNDARY_DUPLICATE_KEY: ' . $key);
        }
        $sources = [];
        foreach ($composer['autoload']['psr-4'] ?? [] as $prefix => $paths) {
            if (isset($namespaces[$prefix])) {
                throw new RuntimeException('MODULE_BOUNDARY_DUPLICATE_NAMESPACE: ' . $prefix);
            }
            $namespaces[$prefix] = $key;
            foreach ((array) $paths as $path) {
                $source = realpath($directory . '/' . $path);
                if ($source === false || !is_dir($source) || !str_starts_with($source, $directory . '/')) {
                    throw new RuntimeException('MODULE_BOUNDARY_SOURCE_OUTSIDE_MODULE: ' . $key);
                }
                $sources[$source] = true;
            }
        }
        if ($sources === []) {
            throw new RuntimeException('MODULE_BOUNDARY_EMPTY_SOURCE_MAPPING: ' . $key);
        }
        foreach ($manifest['database']['owned_tables'] ?? [] as $table) {
            $logical = str_starts_with($table, 'pa_') ? substr($table, 3) : $table;
            if (isset($tables[$logical])) {
                throw new RuntimeException('MODULE_BOUNDARY_DUPLICATE_TABLE: ' . $table);
            }
            $tables[$logical] = $key;
        }
        $modules[$key] = [
            'sources' => array_keys($sources),
            'exports' => array_fill_keys($manifest['contracts']['exports'] ?? [], true),
        ];
    }
    if ($modules === []) {
        throw new RuntimeException('MODULE_BOUNDARY_NO_MODULES');
    }
    uksort($namespaces, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
    $ownerOf = static function (string $name) use ($namespaces): ?string {
        foreach ($namespaces as $prefix => $owner) {
            if (str_starts_with($name, $prefix)) {
                return $owner;
            }
        }
        return null;
    };
    $parser = (new ParserFactory())->createForHostVersion();
    $files = 0;
    $references = 0;
    $literalTables = 0;
    $seenFiles = [];
    foreach ($modules as $owner => $module) {
        foreach ($module['sources'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                if (isset($seenFiles[$path])) {
                    continue;
                }
                $seenFiles[$path] = true;
                $files++;
                $relative = substr($path, strlen($root) + 1);
                $nodes = $parser->parse((string) file_get_contents($path)) ?? [];
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver());
                $nodes = $traverser->traverse($nodes);
                $visit = function (Node $node) use (&$visit, $owner, $ownerOf, $modules, $tables, $relative, &$findings, &$references, &$literalTables): void {
                    if ($node instanceof Node\Name\FullyQualified) {
                        $target = $node->toString();
                        $targetOwner = $ownerOf($target);
                        if ($targetOwner !== null && $targetOwner !== $owner) {
                            $references++;
                            if (!isset($modules[$targetOwner]['exports'][$target])) {
                                $id = $relative . ':' . $node->getStartFilePos() . ':type:' . $target;
                                $findings[$id] = ['code' => 'PRIVATE_MODULE_TYPE', 'path' => $relative, 'line' => $node->getStartLine(), 'owner' => $owner, 'target_owner' => $targetOwner, 'target' => $target];
                            }
                        }
                    }
                    $tableCall = $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier
                        && in_array(strtolower($node->name->toString()), ['join', 'leftjoin', 'rightjoin', 'fulljoin', 'table'], true);
                    $facadeCall = $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->toString() === 'think\\facade\\Db'
                        && $node->name instanceof Node\Identifier && in_array(strtolower($node->name->toString()), ['name', 'table'], true);
                    if (($tableCall || $facadeCall) && isset($node->args[0]) && $node->args[0]->value instanceof Node\Scalar\String_) {
                        $value = $node->args[0]->value->value;
                        if (preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?(?:\\s|$)/', $value, $match)) {
                            $logical = str_starts_with($match[1], 'pa_') ? substr($match[1], 3) : $match[1];
                            $targetOwner = $tables[$logical] ?? null;
                            $literalTables++;
                            if ($targetOwner !== null && $targetOwner !== $owner) {
                                $id = $relative . ':' . $node->getStartFilePos() . ':table:' . $logical;
                                $findings[$id] = ['code' => 'FOREIGN_MODULE_TABLE', 'path' => $relative, 'line' => $node->getStartLine(), 'owner' => $owner, 'target_owner' => $targetOwner, 'target' => $logical];
                            }
                        }
                    }
                    foreach ($node->getSubNodeNames() as $name) {
                        $child = $node->$name;
                        foreach (is_array($child) ? $child : [$child] as $item) {
                            if ($item instanceof Node) {
                                $visit($item);
                            }
                        }
                    }
                };
                foreach ($nodes as $node) {
                    $visit($node);
                }
            }
        }
    }
    ksort($findings, SORT_STRING);
    return [
        'status' => $findings === [] ? 'passed' : 'failed',
        'scope' => 'Current module Composer sources, resolved static PHP names and literal table calls; not computed class names, arbitrary SQL or runtime authorization.',
        'modules' => count($modules), 'php_files' => $files,
        'cross_module_type_references' => $references, 'literal_table_calls' => $literalTables,
        'declared_tables' => count($tables), 'findings' => array_values($findings),
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (count($argv) > 1) {
            throw new InvalidArgumentException('Usage: php scripts/check-module-boundaries.php');
        }
        $report = moduleBoundaryInventory(dirname(__DIR__));
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
        exit($report['status'] === 'passed' ? 0 : 1);
    } catch (Throwable $error) {
        fwrite(STDERR, 'MODULE_BOUNDARY_CHECK_FAILED: ' . $error->getMessage() . PHP_EOL);
        exit(2);
    }
}
