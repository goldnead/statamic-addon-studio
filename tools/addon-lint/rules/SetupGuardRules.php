<?php

declare(strict_types=1);

namespace StatamicAddonStudio\Lint\Rules;

use StatamicAddonStudio\Lint\AbstractRule;
use StatamicAddonStudio\Lint\AddonContext;
use StatamicAddonStudio\Lint\Severity;

/**
 * A CP index page must survive a database it has not migrated yet.
 *
 * The sibling rule `code.silent-query-exception` guards the other end of the same
 * problem: this one says the page must not die, that one says the reason must not
 * disappear. Both have to hold — a guard that renders an empty state and logs
 * nothing trades a 500 for a lie.
 */
final class CpIndexSetupGuardRule extends AbstractRule
{
    /**
     * A query that runs while the page is being built. Anything here throws
     * `QueryException: no such table` on a database the addon has not migrated.
     */
    private const QUERIES = [
        '/\b[A-Z]\w*::(query|all|where|find|firstWhere|count|latest|orderBy|with|withCount)\s*\(/',
        '/\bDB::(table|select|connection)\s*\(/',
    ];

    /**
     * The same query one indirection away: a repository or manager handed to the
     * controller. `$outboundRepo->countActive()` reaches the table exactly like
     * `Model::query()` does, and half the family reads its listings that way.
     * Group 1 is the immediate receiver, so a chain like `$this->contacts->count()`
     * is judged on `contacts`, not on `this`.
     */
    private const INDIRECT_QUERY = '/\$(?:\w+->)*(\w+)->(count\w*|counts|all|list|paginate|query|forBrand|latest|recent\w*|successRate)\s*\(/';

    /** Receivers that answer from memory, not from the database. */
    private const NOT_A_REPOSITORY = [
        'request', 'errors', 'validator', 'response', 'session', 'config',
        'rows', 'items', 'collection', 'options', 'columns', 'filters', 'registry',
    ];

    /**
     * A guard that answers "is the thing this page needs actually there?"
     * before the first query runs, rather than after it has thrown.
     */
    private const GUARDS = [
        '/Schema::hasTable\s*\(/',
        '/\bhasTable\s*\(/',
        '/\bsetupNotice\s*\(/',
        '/\bmissingSetup\s*\(/',
        '/\bclass_exists\s*\(/',
        '/\binterface_exists\s*\(/',
    ];

    public function id(): string
    {
        return 'code.cp-index-setup-guard';
    }

    public function title(): string
    {
        return 'Show an empty state, not a 500, when the setup is incomplete';
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
        return 'On 03.09.2026 /cp/assessments and /cp/client-rooms answered HTTP 500 on the public demo '
            .'because the addons shipped but their migrations had never run — `no such table: assessments`, '
            .'`no such table: client_rooms`, thrown by the first query in AssessmentController@index and '
            .'RoomsController@index:45. A CP page whose table is missing, or whose optional integration is '
            .'not installed, owes the reader an empty state with one sentence on what to do, and owes the '
            .'log the reason. Statamic core does the same thing: a section with nothing behind it renders '
            .'`EmptyStateMenu` (ui-vocabulary.md §2.7 (a)), it does not throw.';
    }

    public function appliesTo(AddonContext $addon): bool
    {
        return $this->indexControllers($addon) !== [];
    }

    public function check(AddonContext $addon): array
    {
        $findings = [];

        foreach ($this->indexControllers($addon) as $file) {
            $contents = $addon->read($file) ?? '';

            foreach ($this->indexBodies($contents) as [$line, $body]) {
                if ($this->matchesAny($body, self::GUARDS)) {
                    continue;
                }

                $offset = $this->firstQueryLine($body);

                if ($offset === null) {
                    continue;
                }

                $findings[] = $this->fail(
                    'index() queries the database without checking first that its table exists.',
                    $file,
                    $line + $offset,
                    'Guard the first query with Schema::hasTable() (or class_exists() for an optional '
                    .'integration), log the reason with Log::warning(), and render an empty state with a '
                    .'sentence naming the missing piece. Without the guard a fresh install answers '
                    .'HTTP 500 until somebody runs the migrations.'
                );
            }
        }

        return $findings;
    }

    /**
     * CP controllers that serve a listing. `index()` is the entry point a person
     * reaches from the nav, so it is the one that has to hold up on its own.
     *
     * @return string[]
     */
    private function indexControllers(AddonContext $addon): array
    {
        return array_values(array_filter($addon->cpControllers(), function (string $file) use ($addon) {
            if (! str_starts_with($file, 'src/')) {
                return false;
            }

            $contents = $addon->read($file) ?? '';

            return preg_match('/public function index\s*\(/', $contents) === 1;
        }));
    }

    /**
     * The body of every `index()` in the file, as [line of the opening brace, source].
     *
     * Scoped to the method rather than the file on purpose. Read file-wide, an
     * unrelated `class_exists()` elsewhere in the class excuses the page that
     * actually throws — statamic-clientrooms passed that way, and its
     * `/cp/client-rooms` was one of the two 500s this rule exists for
     * (RoomsController.php:478 guards a Leadhub link 430 lines below index()).
     *
     * Tokenised, not brace-counted: a brace inside a string or a comment would
     * otherwise close the body early and hide the rest of it.
     *
     * @return array<int, array{0:int,1:string}>
     */
    private function indexBodies(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $bodies = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $name = null;

            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                    break;
                }

                if ($tokens[$j] === '(' || $tokens[$j] === ';') {
                    break;
                }
            }

            if ($name !== 'index') {
                continue;
            }

            $depth = 0;
            $body = '';
            $line = 0;

            for ($j = $i; $j < $n; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

                // An abstract or interface declaration has no body to inspect.
                if ($depth === 0 && $text === ';') {
                    break;
                }

                if ($text === '{') {
                    if ($depth === 0) {
                        $line = is_array($tokens[$j]) ? $tokens[$j][2] : $this->lineAt($tokens, $j);
                    }
                    $depth++;
                }

                if ($depth > 0) {
                    $body .= $text;
                }

                if ($text === '}' && --$depth === 0) {
                    $bodies[] = [$line, $body];
                    $i = $j;
                    break;
                }
            }
        }

        return $bodies;
    }

    /** Zero-based line offset of the first query inside a method body, or null. */
    private function firstQueryLine(string $body): ?int
    {
        foreach (explode("\n", $body) as $offset => $text) {
            foreach (self::QUERIES as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    return $offset;
                }
            }

            if (preg_match(self::INDIRECT_QUERY, $text, $match) === 1
                && ! in_array(strtolower($match[1]), self::NOT_A_REPOSITORY, true)) {
                return $offset;
            }
        }

        return null;
    }

    /** Line of a single-character token, taken from the nearest array token before it. */
    private function lineAt(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 0;
    }

    /** @param  string[]  $patterns */
    private function matchesAny(string $contents, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                return true;
            }
        }

        return false;
    }
}
