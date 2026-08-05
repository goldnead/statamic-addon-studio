# Statamic Addon Studio

The standard the `goldnead` Statamic addons are built and measured against, and the tool that
checks it.

Its single question: **would a user notice this addon was not built by Statamic?**
Everything here exists to answer that with evidence instead of impressions. The rules were derived
by reading real, published Statamic addons — every one is cited back to the package it came from.

Established July 2026 against Statamic 6.26 / Laravel 13 / PHP 8.5.

## What this is, and what it is not

It is published because it is more useful in the open than closed, and because the addon
repositories' CI checks against it. Use it, copy from it, disagree with it.

It is **not** a supported product. There is no release schedule, no versioning, no roadmap, and no
promise that anything here stays where it is. Issues are read; they are not a support channel. If
you need it to hold still, vendor a copy.

The audit material behind it — the analyses of individual addons, our own and other people's — is
deliberately not part of this repository. See `standards/README.md`, "How to read a citation".

---

## Layout

| Path | What it is | In git |
|---|---|---|
| `standards/` | What "native" means, and the gate an addon must pass | yes |
| `skills/` | Agent skills — the operating layer over the standards | yes |
| `tools/addon-lint/` | The automated checker | yes |
| `templates/` | Drop-in CI workflow and `.gitattributes` | yes |
| `docs/` | How to maintain the studio, and how to publish an addon | yes |
| `reference/` | Cloned upstream repos, read-only — what the citations point at | no (gitignored) |
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

**3. `tools/addon-lint`** — 56 rules across structure, bootstrap, ui, code, testing and release.

```bash
php tools/addon-lint/bin/addon-lint ../statamic-toc -v
php tools/addon-lint/bin/addon-lint ../statamic-* --format=markdown --output=lint-baseline.md
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

## Where the rules came from

Thirteen published Statamic addons were read in full — official ones, widely used third-party ones,
free and paid — plus `statamic/cms` 6.x itself. Every rule in `standards/` traces back to something
observed in one of them, cited by package name.

The one finding worth stating up front, because it shaped the whole standard: **the hard part is
not making a Control Panel screen look native.** Most addons that ship a Vite build already import
`@statamic/cms/ui` and get that for free. The hard part is distribution — resolvable dependencies,
a licence file that matches the declared licence, a constraint floor that names the minor you
actually depend on, and a committed build output that CI proves is current. That is why
`marketplace-readiness.md` exists as a document of its own, and why the `release.*` lint rules are
the ones that fail most often.
