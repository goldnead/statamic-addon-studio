<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint;

/**
 * Everything a rule may know about the addon under inspection.
 *
 * All paths handed to and returned from this class are relative to the addon root,
 * so findings stay copy-pasteable regardless of where the studio lives.
 */
final class AddonContext
{
    private ?array $composer = null;

    /** @var string[]|null */
    private ?array $files = null;

    /** @var array<string, string> */
    private array $contents = [];

    public function __construct(public readonly string $root)
    {
    }

    public function name(): string
    {
        return $this->composer()['name'] ?? basename($this->root);
    }

    public function composer(): array
    {
        if ($this->composer !== null) {
            return $this->composer;
        }

        $raw = $this->read('composer.json');

        return $this->composer = $raw === null ? [] : (json_decode($raw, true) ?: []);
    }

    /** Dot-path lookup into composer.json, e.g. `extra.statamic.name`. */
    public function composerValue(string $path, mixed $default = null): mixed
    {
        $value = $this->composer();

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function abs(string $relative): string
    {
        return rtrim($this->root, '/').'/'.ltrim($relative, '/');
    }

    public function has(string $relative): bool
    {
        return file_exists($this->abs($relative));
    }

    public function read(string $relative): ?string
    {
        if (array_key_exists($relative, $this->contents)) {
            return $this->contents[$relative];
        }

        $path = $this->abs($relative);

        if (! is_file($path) || ! is_readable($path)) {
            return $this->contents[$relative] = null;
        }

        $contents = file_get_contents($path);

        return $this->contents[$relative] = $contents === false ? null : $contents;
    }

    /**
     * Tracked files, relative to the addon root. Uses git when available so that
     * vendor/, node_modules/ and build output never leak into the analysis.
     *
     * @return string[]
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $files = $this->gitFiles() ?? $this->scannedFiles();
        sort($files);

        return $this->files = $files;
    }

    /**
     * Tracked files matching an fnmatch pattern, e.g. `src/*.php`, `resources/**\/*.vue`.
     *
     * @return string[]
     */
    public function match(string $pattern): array
    {
        return array_values(array_filter(
            $this->files(),
            fn (string $file) => fnmatch($pattern, $file, FNM_PATHNAME | FNM_CASEFOLD)
                || fnmatch($pattern, $file, FNM_CASEFOLD)
        ));
    }

    /** @return string[] */
    public function phpFiles(): array
    {
        return $this->withExtension('php');
    }

    /** @return string[] */
    public function vueFiles(): array
    {
        return $this->withExtension('vue');
    }

    /** @return string[] */
    public function jsFiles(): array
    {
        return array_merge($this->withExtension('js'), $this->withExtension('ts'));
    }

    /** @return string[] */
    public function bladeFiles(): array
    {
        return array_values(array_filter(
            $this->files(),
            fn (string $f) => str_ends_with(strtolower($f), '.blade.php')
        ));
    }

    /** @return string[] */
    public function antlersFiles(): array
    {
        return array_values(array_filter(
            $this->files(),
            fn (string $f) => str_ends_with(strtolower($f), '.antlers.html')
                || str_ends_with(strtolower($f), '.antlers.php')
        ));
    }

    /** @return string[] */
    public function cssFiles(): array
    {
        return array_merge($this->withExtension('css'), $this->withExtension('scss'), $this->withExtension('postcss'));
    }

    /**
     * Files with this extension, excluding build output.
     *
     * Build artefacts are minified copies of the sources plus everything the bundler pulled in,
     * so linting them produces findings that point at code the addon does not own.
     *
     * @return string[]
     */
    public function withExtension(string $extension): array
    {
        $suffix = '.'.strtolower(ltrim($extension, '.'));

        return array_values(array_filter($this->files(), function (string $file) use ($suffix) {
            $lower = strtolower($file);

            return str_ends_with($lower, $suffix)
                && ! str_ends_with($lower, '.blade.php')
                && ! $this->isBuildOutput($file);
        }));
    }

    public function isBuildOutput(string $file): bool
    {
        return preg_match('#(^|/)(dist|build|public/vendor|node_modules|vendor)/#', $file) === 1;
    }

    /** @return string[] */
    public function distFiles(): array
    {
        return array_values(array_filter($this->files(), fn (string $f) => $this->isBuildOutput($f)));
    }

    /**
     * True when built assets reach the consumer — either committed, or fetched from the
     * GitHub release by pixelfear/composer-dist-plugin (`extra.download-dist`).
     */
    public function shipsBuiltAssets(): bool
    {
        if ($this->composerValue('extra.download-dist') !== null) {
            return true;
        }

        foreach ($this->files() as $file) {
            if (preg_match('#(^|/)dist/.*\.(js|css)$#', $file) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Search tracked files for a PCRE pattern.
     *
     * @param  string[]|null  $files  Restrict to these files; defaults to all tracked files.
     * @return array<int, array{file: string, line: int, text: string, match: array<int|string, string>}>
     */
    public function grep(string $pattern, ?array $files = null): array
    {
        $hits = [];

        foreach ($files ?? $this->files() as $file) {
            $contents = $this->read($file);

            if ($contents === null || $contents === '') {
                continue;
            }

            foreach (explode("\n", $contents) as $index => $text) {
                if (preg_match($pattern, $text, $match) === 1) {
                    $hits[] = [
                        'file' => $file,
                        'line' => $index + 1,
                        'text' => trim($text),
                        'match' => $match,
                    ];
                }
            }
        }

        return $hits;
    }

    /** True when any tracked file matches the pattern. */
    public function contains(string $pattern, ?array $files = null): bool
    {
        return $this->grep($pattern, $files) !== [];
    }

    /** The PSR-4 namespace root declared for src/, e.g. `Goldnead\StatamicToc\`. */
    public function psr4Root(): ?string
    {
        $autoload = $this->composerValue('autoload.psr-4', []);

        if (! is_array($autoload)) {
            return null;
        }

        foreach ($autoload as $namespace => $dir) {
            $dirs = (array) $dir;
            foreach ($dirs as $candidate) {
                if (rtrim($candidate, '/') === 'src') {
                    return $namespace;
                }
            }
        }

        return array_key_first($autoload) ?: null;
    }

    /** Relative paths of classes that look like the addon's service provider(s). */
    public function serviceProviders(): array
    {
        $declared = (array) $this->composerValue('extra.laravel.providers', []);
        $found = [];

        foreach ($this->phpFiles() as $file) {
            if (! str_starts_with($file, 'src/')) {
                continue;
            }

            $contents = $this->read($file) ?? '';

            if (preg_match('/class\s+\w+\s+extends\s+\\\\?(\w+\\\\)*AddonServiceProvider\b/', $contents) === 1) {
                $found[] = $file;
            }
        }

        if ($found === [] && $declared !== []) {
            foreach ($declared as $class) {
                $relative = 'src/'.str_replace('\\', '/', $this->stripNamespace((string) $class)).'.php';
                if ($this->has($relative)) {
                    $found[] = $relative;
                }
            }
        }

        return $found;
    }

    /**
     * Blade/Antlers templates that render inside the Control Panel.
     *
     * @return string[]
     */
    public function cpViews(): array
    {
        $views = array_merge($this->bladeFiles(), $this->antlersFiles());

        return array_values(array_filter($views, function (string $file) {
            if (str_contains($file, '/cp/') || str_contains($file, 'views/cp')) {
                return true;
            }

            $contents = $this->read($file) ?? '';

            return str_contains($contents, 'statamic::layout')
                || str_contains($contents, 'statamic::partials')
                || preg_match('/<ui-[a-z-]+/', $contents) === 1;
        }));
    }

    /**
     * Inertia page components — the modern way to build a CP screen in Statamic 6.
     *
     * @return string[]
     */
    public function inertiaPages(): array
    {
        return array_values(array_filter(
            $this->vueFiles(),
            fn (string $f) => str_contains($f, '/Pages/') || str_contains($f, '/pages/')
        ));
    }

    public function usesInertia(): bool
    {
        return $this->contains('/Inertia::render|inertia\(|@inertiajs\/vue3/')
            || $this->inertiaPages() !== [];
    }

    /**
     * Vue single-file components that implement a fieldtype.
     *
     * @return string[]
     */
    public function fieldtypeComponents(): array
    {
        $registered = [];

        foreach ($this->grep('/\$components\.register\(\s*[\'"]([\w-]*fieldtype)[\'"]\s*,\s*(\w+)/', $this->jsFiles()) as $hit) {
            $registered[] = $hit['match'][2];
        }

        return array_values(array_filter($this->vueFiles(), function (string $file) use ($registered) {
            $basename = pathinfo($file, PATHINFO_FILENAME);

            if (in_array($basename, $registered, true)) {
                return true;
            }

            if (str_contains(strtolower($file), 'fieldtype')) {
                return true;
            }

            $contents = $this->read($file) ?? '';

            return str_contains($contents, 'Fieldtype.props') || str_contains($contents, 'Fieldtype.use');
        }));
    }

    /**
     * PHP controllers serving Control-Panel routes.
     *
     * @return string[]
     */
    public function cpControllers(): array
    {
        return array_values(array_filter($this->phpFiles(), function (string $file) {
            if (! str_contains($file, 'Controllers')) {
                return false;
            }

            if (str_contains($file, '/CP/') || str_contains($file, '/Cp/')) {
                return true;
            }

            $contents = $this->read($file) ?? '';

            return str_contains($contents, 'CpController') || str_contains($contents, 'Inertia::render');
        }));
    }

    /** True when the addon exposes anything inside the Control Panel. */
    public function hasCpSurface(): bool
    {
        return $this->vueFiles() !== []
            || $this->match('resources/views/cp/*') !== []
            || $this->contains('/Nav::extend|Statamic::script|Statamic::vite|Statamic::style|\$fieldtypes|\$widgets|Utility::(register|extend)|cpRoutes|routes\/cp\.php/')
            || $this->has('routes/cp.php');
    }

    private function stripNamespace(string $class): string
    {
        $root = $this->psr4Root();

        if ($root !== null && str_starts_with($class, rtrim($root, '\\'))) {
            return trim(substr($class, strlen(rtrim($root, '\\'))), '\\');
        }

        return $class;
    }

    private function gitFiles(): ?array
    {
        if (! is_dir($this->abs('.git'))) {
            return null;
        }

        $command = sprintf('git -C %s ls-files 2>/dev/null', escapeshellarg($this->root));
        $output = [];
        $status = 0;
        exec($command, $output, $status);

        if ($status !== 0 || $output === []) {
            return null;
        }

        return array_values(array_filter($output, fn (string $f) => is_file($this->abs($f))));
    }

    private function scannedFiles(): array
    {
        $skip = ['vendor', 'node_modules', '.git', '.idea', 'build', 'coverage'];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current) use ($skip) {
                    return ! ($current->isDir() && in_array($current->getFilename(), $skip, true));
                }
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = ltrim(str_replace(rtrim($this->root, '/'), '', $file->getPathname()), '/');
            }
        }

        return $files;
    }
}
