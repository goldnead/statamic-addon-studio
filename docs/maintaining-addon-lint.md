# Maintaining addon-lint

The linter is deliberately small and dependency-free: plain PHP 8.2+, no Composer install, no
autoloader. `bin/addon-lint` requires the nine classes in `src/` by hand and then scans `rules/`.

## Architecture

```
bin/addon-lint      CLI: argument parsing, output, exit code
src/AddonContext    Everything a rule may know about the addon under inspection
src/Rule            The interface · AbstractRule gives you severity + fail() helpers
src/RuleRegistry    Instantiates every Rule class found under rules/
src/Linter          Runs the rules, applies per-addon config, sorts findings
src/Config          Reads addon-lint.json from the addon root
src/Report          Counts, score, grouping, serialisation
src/Reporter        console / json / markdown
src/Finding         One defect: rule, severity, message, file, line, hint
src/Severity        blocker | major | minor | info, and their ordering
```

`AddonContext` is the important one. It lists files through `git ls-files` when possible, so
`vendor/`, `node_modules/` and build output never leak into an analysis. Prefer its helpers over
touching the filesystem yourself:

`files()` `match(pattern)` `phpFiles()` `vueFiles()` `jsFiles()` `bladeFiles()` `antlersFiles()`
`cssFiles()` `cpViews()` `inertiaPages()` `fieldtypeComponents()` `cpControllers()` `serviceProviders()`
`grep(pattern, files)` `contains(pattern, files)` `read(path)` `has(path)` `composerValue(dotPath)`
`usesInertia()` `hasCpSurface()` `shipsBuiltAssets()` `isBuildOutput(file)`

Note that `vueFiles()`, `jsFiles()` and `cssFiles()` **exclude build output**. Linting a minified
bundle produces findings that point at code the addon does not own — this was a real false-positive
source during calibration.

## Adding a rule

A rule is one class in any file under `rules/`. Several classes per file is fine; group them by
category. There is no registration step — `RuleRegistry` finds them.

```php
final class MyRule extends AbstractRule
{
    public function id(): string { return 'ui.my-check'; }          // stable forever
    public function title(): string { return 'Do the thing'; }       // imperative
    public function category(): string { return 'ui'; }              // structure|bootstrap|ui|code|testing|release
    public function severity(): string { return Severity::MAJOR; }
    public function rationale(): string { return 'ui-vocabulary §9.n: …'; }

    public function appliesTo(AddonContext $addon): bool
    {
        return $addon->fieldtypeComponents() !== [];   // skip cleanly when meaningless
    }

    public function check(AddonContext $addon): array
    {
        return [$this->fail('What is wrong.', $file, $line, 'What to do instead.')];
    }
}
```

Requirements, in order of importance:

1. **Ground it.** The `rationale()` must name its evidence: a `ui-vocabulary.md` section, or a
   reference addon and file. A rule nobody can trace back gets removed the first time it annoys
   someone.
2. **Calibrate it.** Run it across the whole reference set before keeping it:

   ```bash
   php bin/addon-lint ../../statamic-addon-studio/reference/*__* --fail-on=never --format=json
   ```

   If it fires on Runway, SEO Pro or Importer without a real defect behind it, the rule is wrong —
   not the reference addon. Fix the rule. Every current rule survived this.
3. **Never rename an `id()`.** Suppressions in `addon-lint.json` files point at it. Deprecate instead.
4. **Say what to do.** The `hint` is the part a person acts on.
5. **Cap the output.** A rule that can fire hundreds of times (colour utilities, for example) must
   truncate and report how many it suppressed. See `ThemeableColorsRule::cap()`.

## Severity

| | Meaning |
|---|---|
| `blocker` | Ships broken behaviour, a security hole, or blocks a Marketplace release |
| `major` | Visibly non-native, or a real maintenance hazard. Fix before release. |
| `minor` | Polish. Fix when touching the area. |
| `info` | Surfaced for a human to judge; never a defect on its own |

The default `--fail-on=major` means CI goes red on blocker and major.

## Per-addon configuration

`addon-lint.json` in the addon root:

```json
{
  "disable": ["release.screenshots"],
  "severity": { "ui.command-palette": "info" },
  "categories": ["ui", "code"]
}
```

A suppression is a decision. Record why next to it — a rule silenced without a reason is
indistinguishable from a rule nobody understood.

## When Statamic changes

The standards lead, the linter follows. Pull `reference/statamic__cms`, re-verify the affected
section of `ui-vocabulary.md`, update it, and only then change the rules that depend on it.
Rules that encode a removed API (`ui.legacy-cp-api`) grow rather than shrink: a v6-era API that v7
removes becomes a new entry, and the v5 entries stay for anyone still migrating.
