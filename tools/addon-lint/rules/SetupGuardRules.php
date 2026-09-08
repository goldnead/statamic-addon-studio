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
        // The studio's own idiom (`src/Support/Setup.php`), which is what the
        // addons actually call. Without this line the rule fails every addon
        // that follows the standard it exists to enforce.
        '/\bSetup::guard\s*\(/',
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
                $offset = $this->firstQueryLine($body);

                if ($offset === null) {
                    continue;
                }

                // The guard has to come BEFORE the query, not merely somewhere in
                // the same method. Asking only whether the pattern occurs makes the
                // rule passable with an unrelated `class_exists()` further down —
                // a check for the presence of a word rather than for a protected
                // query, and the addon stays broken while the report goes green.
                $guard = $this->firstGuardLine($body);
                $guarded = $guard !== null && $guard < $offset;

                if ($guarded && $this->speaks($addon, $body)) {
                    continue;
                }

                $findings[] = $this->fail(
                    match (true) {
                        $guard === null => 'index() queries the database without checking first that its table exists.',
                        ! $guarded => 'index() queries the database before the setup check that is meant to protect it.',
                        default => 'index() checks its setup but tells nobody why the page is empty.',
                    },
                    $file,
                    $line + $this->lineOfOffset($body, $offset),
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

                // A comment is not a guard. `/* Schema::hasTable(...) would be
                // nice */` acquitted the method as long as the body was searched
                // as raw text. The newlines stay so the reported line still points
                // at the real query.
                if (is_array($tokens[$j]) && ($tokens[$j][0] === T_COMMENT || $tokens[$j][0] === T_DOC_COMMENT)) {
                    $text = str_repeat("\n", substr_count($text, "\n"));
                }

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

    /**
     * Character offset of the first setup check in a method body, or null.
     *
     * Characters, not lines: a guard and the query it is meant to protect often
     * share a line, and comparing line numbers then calls a guard that runs
     * afterwards "before".
     */
    private function firstGuardLine(string $body): ?int
    {
        return $this->firstMatch($body, self::GUARDS);
    }

    /** Character offset of the first query in a method body, or null. */
    private function firstQueryLine(string $body): ?int
    {
        $earliest = $this->firstMatch($body, self::QUERIES);

        if (preg_match(self::INDIRECT_QUERY, $body, $match, PREG_OFFSET_CAPTURE) === 1
            && ! in_array(strtolower($match[1][0]), self::NOT_A_REPOSITORY, true)) {
            $earliest = $earliest === null ? $match[0][1] : min($earliest, $match[0][1]);
        }

        return $earliest;
    }

    /**
     * Character offset of the earliest match of any pattern, or null.
     *
     * @param  string[]  $patterns
     */
    private function firstMatch(string $body, array $patterns): ?int
    {
        $earliest = null;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $match, PREG_OFFSET_CAPTURE) === 1) {
                $earliest = $earliest === null ? $match[0][1] : min($earliest, $match[0][1]);
            }
        }

        return $earliest;
    }

    /** The 1-based line a character offset falls on, counted from the body's start. */
    private function lineOfOffset(string $body, int $offset): int
    {
        return substr_count(substr($body, 0, $offset), "\n");
    }

    /**
     * Does the guard say anything to anyone?
     *
     * The standard is not "the page must not crash", it is "the page must not
     * crash AND the reason must not disappear". An empty state that logs nothing
     * is worse than the 500 it replaced: the site looks installed and never
     * works. Two shapes count — a log call in the method itself, or the studio's
     * `Setup::guard()`, whose own class does the logging.
     */
    private function speaks(AddonContext $addon, string $body): bool
    {
        if (preg_match('/\bLog::|\blogger\s*\(|\breport\s*\(/', $body) === 1) {
            return true;
        }

        if (preg_match('/\bSetup::guard\s*\(/', $body) !== 1) {
            return false;
        }

        foreach ($addon->phpFiles() as $file) {
            if (! str_ends_with($file, 'Setup.php')) {
                continue;
            }

            $contents = $addon->read($file) ?? '';

            if (preg_match('/function guard\s*\(/', $contents) === 1
                && preg_match('/\bLog::|\blogger\s*\(|\breport\s*\(/', $contents) === 1) {
                return true;
            }
        }

        return false;
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
