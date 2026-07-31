# Statamic Addon Studio

The place where Adrian's Statamic addons are built, measured and released.

Its single question: **would a user notice this addon was not built by Statamic?**
Everything here exists to answer that with evidence instead of impressions.

Established July 2026 against Statamic 6.26 / Laravel 13 / PHP 8.5.

---

## Layout

| Path | What it is | In git |
|---|---|---|
| `standards/` | What "native" means, and the gate an addon must pass | yes |
| `skills/` | Agent skills — the operating layer over the standards | yes |
| `tools/addon-lint/` | The automated checker | yes |
| `findings/reference/` | Deep analyses of 13 official and third-party addons | yes |
| `findings/existing/` | Audit of the 12 in-house addons (July 2026 baseline) | yes |
| `findings/lint/` | Machine + markdown lint reports | yes |
| `findings/audits/` | Per-addon release verdicts | yes |
| `reference/` | Cloned upstream repos, read-only | no (gitignored) |
| `playground/` | A real Statamic 6 site for live comparison | no (gitignored) |

---

## The four things you use

**1. `standards/ui-vocabulary.md`** — the authority on the native Statamic 6 CP, extracted directly
from `statamic/cms` 6.x with `path:line` citations. ~130 components, page shells, the listing and
publish contracts, the fieldtype contract, design tokens, and 18 named antipatterns. Read the section
you need; do not guess a component's props.

**2. The skills** (installed at `~/.claude/skills/`, symlinked to `skills/` here):

| Skill | Use it when |
|---|---|
| `statamic-addon-ui` | Building or fixing any CP surface. Read before writing UI code. |
| `statamic-addon-code` | Provider, architecture, config, tests, CI |
| `statamic-addon-audit` | Reviewing an addon, deciding whether it may ship |
| `statamic-addon-scaffold` | Starting a new addon, or migrating one from v5 |

**3. `tools/addon-lint`** — 55 rules across structure, bootstrap, ui, code, testing and release.

```bash
php tools/addon-lint/bin/addon-lint ../statamic-toc -v
php tools/addon-lint/bin/addon-lint ../statamic-* --format=markdown --output=findings/lint/baseline.md
php tools/addon-lint/bin/addon-lint --list-rules
```

`--category=ui` · `--fail-on=blocker|major|minor|never` · `--format=console|json|markdown`.
Per-addon suppressions go in `addon-lint.json` in the addon root.

Every rule traces to evidence: its `rationale()` names the reference addon or the
`ui-vocabulary.md` section it came from. The rules were calibrated by running them against the 13
reference addons — a rule that fired on Runway or SEO Pro without a real defect behind it was fixed,
not kept.

**4. `playground/`** — a working Statamic 6 site. Install an addon into it and compare its screens
with the nearest core equivalent. Superuser `studio@local` / `studio-local-password`.

> Not the same thing as `~/Documents/WebDev/statamic-addon-testbench`. That one exists to test the
> four-addon interplay with real wiring and seeded data. The playground is deliberately pristine:
> stock Statamic, one addon at a time, so that any difference you see is the addon's doing.

```bash
cd playground
composer config repositories.local path ../../statamic-toc
composer require goldnead/statamic-toc:@dev
php artisan serve --port=8099
```

---

## Operating it

**Building a new addon:** `statamic-addon-scaffold` → `statamic-addon-ui` / `statamic-addon-code`
while building → `statamic-addon-audit` before the first tag.

**Releasing an existing addon:** `statamic-addon-audit`. It runs the linter, then covers what the
linter cannot see: the playground comparison, whether the README's promises actually work, and whether
the tests cover them.

**Keeping the standards current:** when Statamic ships a CP change, re-run the reference analysis
against the updated `reference/statamic__cms` and update `ui-vocabulary.md` before touching any rule.
The standards lead; `addon-lint` follows.

Refresh the clones with:

```bash
for d in reference/*/; do git -C "$d" pull --ff-only; done
```

---

## The July 2026 baseline

The working hypothesis going in was that the in-house addons were below native CP quality. The audit
found that is true for two of them and wrong for the rest: 79 of 81 Vue components already import
`@statamic/cms/ui`. `statamic-activity` and `statamic-notifications` are the exceptions — they have no
Vite build, so they hand-rolled Blade plus substitute CSS.

The actual release blocker turned out to be distribution, not UI: 11 of 12 packages do not resolve on
Packagist, and nine reach their siblings through a `repositories` block that Composer ignores in a
dependency — so every README install command is currently untrue.

Full detail: `findings/existing/_OVERVIEW.md`. Current lint state: `findings/lint/baseline.md`.
