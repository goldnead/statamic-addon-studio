<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint;

final class RuleRegistry
{
    /** Instantiate every rule class found under rules/. */
    public static function all(string $rulesDir): Linter
    {
        $linter = new Linter();

        foreach (self::classFiles($rulesDir) as $key => $class) {
            require_once explode('#', $key)[0];

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(Rule::class)) {
                continue;
            }

            $linter->add($reflection->newInstance());
        }

        return $linter;
    }

    /** @return array<string, class-string> */
    private static function classFiles(string $rulesDir): array
    {
        $found = [];

        if (! is_dir($rulesDir)) {
            return $found;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rulesDir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/^namespace\s+([^;]+);/m', $contents, $ns) !== 1) {
                continue;
            }

            if (preg_match_all('/^(?:final\s+)?class\s+(\w+)/m', $contents, $classes) < 1) {
                continue;
            }

            foreach ($classes[1] as $index => $class) {
                $found[$file->getPathname().'#'.$index] = trim($ns[1]).'\\'.$class;
            }
        }

        ksort($found);

        return $found;
    }
}
