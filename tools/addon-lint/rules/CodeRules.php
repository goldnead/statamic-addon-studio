<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint\Rules;

use StatamicAddonStudio\Lint\AbstractRule;
use StatamicAddonStudio\Lint\AddonContext;
use StatamicAddonStudio\Lint\Severity;

final class PintRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.pint';
    }

    public function title(): string
    {
        return 'Format with Laravel Pint';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'Every official Statamic addon ships a pint.json. Matching core formatting means a diff '
            .'against core code reads as a real difference, not as whitespace.';
    }

    public function check(AddonContext $addon): array
    {
        $dev = (array) $addon->composerValue('require-dev', []);

        if ($addon->has('pint.json') || isset($dev['laravel/pint'])) {
            return [];
        }

        if ($addon->has('.php-cs-fixer.php') || $addon->has('.php-cs-fixer.dist.php') || $addon->has('.styleci.yaml')) {
            return [$this->failWith(
                Severity::INFO,
                'Formatting is configured, but not with Pint.',
                null,
                null,
                'Statamic core and all official addons use Pint; matching it lowers review friction.'
            )];
        }

        return [$this->fail('No code formatter configured.', null, null, 'composer require --dev laravel/pint and add pint.json.')];
    }
}

final class NoComposerLockRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.no-composer-lock';
    }

    public function title(): string
    {
        return 'Do not commit composer.lock in a library package';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'A lock file in a library pins nothing for consumers and only makes the CI matrix lie about '
            .'which dependency versions were actually tested.';
    }

    public function check(AddonContext $addon): array
    {
        if (! in_array('composer.lock', $addon->files(), true)) {
            return [];
        }

        return [$this->fail('composer.lock is committed.', 'composer.lock', null, 'Git-ignore it; CI should resolve fresh.')];
    }
}

final class StabilityRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.stability';
    }

    public function title(): string
    {
        return 'Do not ship `minimum-stability: dev`';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::INFO;
    }

    public function rationale(): string
    {
        return 'Composer only honours `minimum-stability` from the root package, so this does not leak into '
            .'a consuming site. It does mean the addon\'s own CI resolves against unstable dependencies, which '
            .'is why Runway and SEO Pro set it deliberately. Worth a conscious decision, not an automatic fix.';
    }

    public function check(AddonContext $addon): array
    {
        $stability = $addon->composerValue('minimum-stability');

        if ($stability === null || $stability === 'stable') {
            return [];
        }

        $hasFlag = $addon->composerValue('prefer-stable') === true;

        return [$this->fail(
            sprintf(
                'composer.json sets minimum-stability to `%s`%s.',
                (string) $stability,
                $hasFlag ? ' with prefer-stable' : ' without prefer-stable'
            ),
            'composer.json',
            null,
            $hasFlag ? 'Deliberate and safe.' : 'Add "prefer-stable": true so CI still favours tagged releases.'
        )];
    }
}

final class DebugLeftoversRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.debug-leftovers';
    }

    public function title(): string
    {
        return 'Ship no debug statements';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'dd()/dump()/ray()/console.log in shipped code leaks into a customer site, sometimes into a '
            .'production response body.';
    }

    public function check(AddonContext $addon): array
    {
        $php = array_values(array_filter($addon->phpFiles(), fn (string $f) => ! str_starts_with($f, 'tests/')));
        $js = array_values(array_filter(
            array_merge($addon->vueFiles(), $addon->jsFiles()),
            fn (string $f) => ! str_contains($f, 'dist/') && ! str_contains($f, 'tests/')
        ));

        $findings = [];

        // The lookbehind must also exclude `::` and `->` so that YAML::dump(), Str::dump()
        // and $collection->dump() are not mistaken for debug calls.
        foreach ($addon->grep('/(?<!function )(?<![\w>:])(dd|dump|ray|var_dump|print_r)\s*\(/', $php) as $hit) {
            $findings[] = $this->fail('Debug call: '.trim($hit['text']), $hit['file'], $hit['line']);
        }

        foreach ($addon->grep('/console\.(log|debug|warn)\s*\(/', $js) as $hit) {
            $findings[] = $this->failWith(Severity::MINOR, 'Console statement: '.trim($hit['text']), $hit['file'], $hit['line']);
        }

        return $findings;
    }
}

final class ConfigPublishingRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.config-publishing';
    }

    public function title(): string
    {
        return 'Merge and publish the addon config through the documented mechanism';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'A config file that is never merged returns null on a site that has not published it, so the '
            .'addon breaks precisely for the users who did nothing wrong.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->match('config/*.php') !== [];
    }

    public function check(AddonContext $addon): array
    {
        $providers = $addon->serviceProviders();

        if ($providers === []) {
            return [];
        }

        // Core auto-merges and auto-publishes anything in the addon's config/ directory.
        // A manual mergeConfigFrom is fine; a manual publish that forgets the merge is not.
        $merges = $addon->contains('/mergeConfigFrom|autoPublishesConfig|\$publishables/', $providers);
        $publishes = $addon->contains('/publishes\(/', $providers);

        if ($publishes && ! $merges) {
            return [$this->fail(
                'The config is published but never merged; unpublished installs read null.',
                $providers[0],
                null,
                'Add $this->mergeConfigFrom(__DIR__.\'/../config/x.php\', \'x\') or rely on core auto-merging.'
            )];
        }

        return [];
    }
}

final class TranslationFilesRule extends AbstractRule
{
    public function id(): string
    {
        return 'code.translation-files';
    }

    public function title(): string
    {
        return 'Ship the translation files the `__()` calls resolve against';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MINOR;
    }

    public function rationale(): string
    {
        return 'Namespaced translation keys without a lang/ directory render the raw key in the CP.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->contains('/__\(\s*[\'"][\w-]+::/', array_merge($addon->phpFiles(), $addon->bladeFiles()));
    }

    public function check(AddonContext $addon): array
    {
        if ($addon->match('lang/*') !== [] || $addon->match('resources/lang/*') !== []) {
            return [];
        }

        return [$this->fail(
            'Namespaced translation keys are used but no lang/ directory is shipped.',
            null,
            null,
            'Add lang/en/*.php and register the namespace, or drop the namespace from the keys.'
        )];
    }
}

final class SilentQueryExceptionRule extends AbstractRule
{
    /**
     * Anything in the block that shows the code looked at *which* database error
     * it caught, or made sure somebody hears about it.
     */
    private const EVIDENCE = [
        'Log::', 'logger(', 'report(', 'throw', 'getCode()', 'errorInfo',
        'SQLSTATE', '23000', '23505', 'wasRecentlyCreated',
    ];

    public function id(): string
    {
        return 'code.silent-query-exception';
    }

    public function title(): string
    {
        return 'Do not treat every database error as the one you expected';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'A catch (QueryException) whose block neither inspects the SQLSTATE nor logs anything reads '
            .'every database failure as the happy-path one. statamic-funnels/src/Support/MailTrigger.php '
            .'(02.09.2026) caught QueryException around an insert and returned as if the row already existed: '
            .'a full disk, a lost connection and a truncated column all looked like "already sent", and the '
            .'mail was silently never sent. The fix was firstOrCreate() + wasRecentlyCreated, which needs no '
            .'catch at all.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $this->sourceFiles($addon) !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($this->sourceFiles($addon) as $file) {
            $contents = $addon->read($file);

            if ($contents === null || ! str_contains($contents, 'QueryException')) {
                continue;
            }

            foreach ($this->queryExceptionCatches($contents) as [$line, $block]) {
                if ($this->speaks($block)) {
                    continue;
                }

                $findings[] = $this->fail(
                    'catch (QueryException) swallows every database error without distinguishing it.',
                    $file,
                    $line,
                    'Check the SQLSTATE (e.g. $e->getCode() === \'23000\'), or drop the catch for '
                    .'firstOrCreate() + wasRecentlyCreated. A failure nobody hears about is not handled.'
                );
            }
        }

        return $findings;
    }

    /** Shipped PHP, tests excluded — a test may catch a QueryException on purpose. */
    private function sourceFiles(AddonContext $addon): array
    {
        return array_values(array_filter(
            $addon->phpFiles(),
            fn (string $f) => str_starts_with($f, 'src/')
        ));
    }

    /**
     * Every `catch (…QueryException…) { … }` in the file, as [line, block body].
     *
     * Tokenised rather than brace-counted: a `{` inside a string or a comment in
     * the block would otherwise end it early and hide the rest from the check.
     *
     * @return array<int, array{0:int,1:string}>
     */
    private function queryExceptionCatches(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $catches = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) {
                continue;
            }

            $line = $tokens[$i][2];

            // The type list, up to the closing parenthesis.
            $types = '';
            $depth = 0;
            $j = $i + 1;

            for (; $j < $count; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

                if ($text === '(') {
                    $depth++;

                    continue;
                }

                if ($text === ')') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }

                    continue;
                }

                if ($depth > 0) {
                    $types .= $text;
                }
            }

            if (! str_contains($types, 'QueryException')) {
                continue;
            }

            // The block itself.
            $block = '';
            $depth = 0;

            for (; $j < $count; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

                if ($text === '{') {
                    $depth++;

                    if ($depth === 1) {
                        continue;
                    }
                }

                if ($text === '}') {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                }

                if ($depth > 0) {
                    $block .= $text;
                }
            }

            $catches[] = [$line, $block];
        }

        return $catches;
    }

    /** Does the block show any sign of having looked at the error? */
    private function speaks(string $block): bool
    {
        foreach (self::EVIDENCE as $needle) {
            if (str_contains($block, $needle)) {
                return true;
            }
        }

        // A block that hands the error on — to a callback, a queue, an event —
        // is not swallowing it, even without the words above.
        return (bool) preg_match('/\b(rethrow|abort|fail\(|dispatch\(|event\()/', $block);
    }
}

final class UnescapedTemplateVariablesRule extends AbstractRule
{
    /** A class doing its own `{{ … }}` replacement. */
    private const SUBSTITUTION = [
        '/str_replace\s*\(\s*[\'"]\{\{/s',
        '/(str_replace|strtr)\s*\(\s*\[[^\]]{0,400}[\'"]\{\{/s',
        '/preg_replace(_callback)?\s*\(\s*[\'"][^\'"\n]{0,200}\\\\\{\\\\\{/s',
        '/strtr\s*\([^)]{0,200}[\'"]\{\{/s',
    ];

    /**
     * The substitution must actually insert *supplied* data — a value looked up
     * from the caller's array — rather than something the class computed itself.
     *
     * This is what separates the incident from its neighbours. `AbandonedReminder`
     * put `$flat[$m[1]]` (the name from the checkout) into the output; an
     * automations token resolver hands its matches to a method and may legitimately
     * emit JSON or a URL, where e() would be wrong rather than missing.
     */
    private const DATA_INSERTION = [
        // A data array read by the regex match: `$flat[$m[1]]`. Not `self::TAGS[$m[1]]`
        // (a constant lookup, not the caller's data) and not `$params[$match[1]] = …`
        // (an assignment into a parse result, not an insertion into output).
        '/\$\w+\s*\[\s*\$(?:m|match|matches)\w*\s*\[\s*\d+\s*\]\s*\](?!\s*=[^=])/',
        // A callback handing back an array value keyed by the token name.
        '/return\s+\$\w+\s*\[\s*\$\w+/',
        // `foreach ($variables as $key => $value) { … str_replace('{{ '.$key … , $value …`
        '/foreach\s*\(\s*\$\w+\s+as\s+\$\w+\s*=>\s*\$\w+\s*\)\s*\{[^}]{0,400}(str_replace|strtr)\s*\([^;]{0,200}\{\{/s',
    ];

    /**
     * Escaping at the substitution itself.
     *
     * Checked in a window around the call, not across the whole file. The
     * incident version of AbandonedReminder *did* call e() — on the line items,
     * a hundred lines above — while the placeholder replacement inserted the
     * name raw. A file-wide check calls that file clean and misses the one
     * defect the rule exists for.
     */
    private const ESCAPING = [
        '/(?<![\w$>:\-])e\s*\(/',
        '/htmlspecialchars\s*\(/',
        '/htmlentities\s*\(/',
        '/(?<![\w$>:\-])escape\s*\(/',
        '/->escape\b/',
    ];

    /** A named set of deliberately raw variables is a decision; the file is not sleepwalking. */
    private const ALLOWLIST = [
        '/RAW_VARIABLES/i',
        '/allowlist/i',
        '/allow_list/i',
    ];

    /** How much of the substitution call to read when looking for escaping. */
    private const WINDOW = 600;

    public function id(): string
    {
        return 'code.unescaped-template-variables';
    }

    public function title(): string
    {
        return 'Escape the values your own `{{ }}` replacement inserts';
    }

    public function category(): string
    {
        return 'code';
    }

    public function severity(): string
    {
        return Severity::MAJOR;
    }

    public function rationale(): string
    {
        return 'A hand-written placeholder substitution inserts whatever it is given, and what it is given '
            .'is usually customer input. statamic-payments/src/Support/AbandonedReminder.php (02.09.2026) '
            .'put the name from the checkout straight into an HTML mail sent to an unverified address. '
            .'The fix was e() on every value plus a named RAW_VARIABLES allowlist for the handful that are '
            .'meant to carry markup — which is exactly what this rule looks for.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $this->sourceFiles($addon) !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($this->sourceFiles($addon) as $file) {
            $contents = $addon->read($file);

            // No cheap `str_contains('{{')` pre-filter here: a regex writes the
            // braces escaped (`\{\{`), so the literal two characters never appear
            // in exactly the files this rule is looking for.
            if ($contents === null) {
                continue;
            }

            $offset = $this->substitutionOffset($contents);

            if ($offset === null || ! $this->insertsSuppliedData($contents) || $this->escapesAt($contents, $offset)) {
                continue;
            }

            $findings[] = $this->fail(
                'Own `{{ }}` substitution inserts supplied values without escaping them.',
                $file,
                substr_count($contents, "\n", 0, $offset) + 1,
                'Wrap each inserted value in e(), and name the exceptions in a RAW_VARIABLES allowlist '
                .'so the raw ones are a decision rather than an oversight.'
            );
        }

        return $findings;
    }

    private function sourceFiles(AddonContext $addon): array
    {
        return array_values(array_filter(
            $addon->phpFiles(),
            fn (string $f) => str_starts_with($f, 'src/')
        ));
    }

    /**
     * The byte offset of the first own-substitution call, or null.
     *
     * Matched against the whole file rather than line by line: the pattern
     * argument of a `preg_replace_callback(` usually sits on the *next* line,
     * which is exactly the shape the payments incident had — a line-wise check
     * walks straight past it.
     */
    private function substitutionOffset(string $contents): ?int
    {
        $earliest = null;

        foreach (self::SUBSTITUTION as $pattern) {
            if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $offset = $match[0][1];

            if ($earliest === null || $offset < $earliest) {
                $earliest = $offset;
            }
        }

        return $earliest;
    }

    private function insertsSuppliedData(string $contents): bool
    {
        foreach (self::DATA_INSERTION as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                return true;
            }
        }

        return false;
    }

    private function escapesAt(string $contents, int $offset): bool
    {
        foreach (self::ALLOWLIST as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                return true;
            }
        }

        $window = substr($contents, $offset, self::WINDOW);

        foreach (self::ESCAPING as $pattern) {
            if (preg_match($pattern, $window) === 1) {
                return true;
            }
        }

        return false;
    }
}
