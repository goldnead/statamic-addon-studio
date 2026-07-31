# Standards

The Statamic Addon Studio's written standards. Three documents, no overlap.

| File | What it is | Authority over |
|---|---|---|
| [`ui-vocabulary.md`](./ui-vocabulary.md) | The native Statamic 6 Control-Panel UI vocabulary, extracted from `statamic/cms` 6.x with file-and-line citations | Everything visible in the CP: components, page shells, listings, publish forms, fieldtypes, tokens, nav, permissions UI |
| [`code-standard.md`](./code-standard.md) | Architecture and PHP/JS standard for an addon | Everything that is not the CP UI: skeleton, service provider, config, domain code, events, queues, permissions, tags, testing, CI, static analysis, formatting |
| [`marketplace-readiness.md`](./marketplace-readiness.md) | The release gate | What the buyer receives: metadata, licensing, semver, docs, changelog, screenshots, support, dist strategy, security, privacy, upgrade guides |

---

## There is no `ui-standard.md`

Deliberately. `ui-vocabulary.md` already fills that role.

It is not a summary of good practice — it is the extracted vocabulary of `statamic/cms` 6.x, with the
component catalogue, prop signatures, request/response contracts and design tokens quoted from source
with file and line numbers. A second document restating it in prose would drift from core the first
time core changes, and there would be no way to tell which of the two was right.

So: **for any question about how a CP surface should look or behave, `ui-vocabulary.md` is the
authority.** `code-standard.md` links into it (§2 page shells, §3 listings, §4 publish forms,
§5 fieldtypes, §7 tokens, §8 nav & permissions, §9 antipatterns) rather than repeating it.

One known inconsistency: `addon-lint`'s finding hints and `findings/lint/baseline.json` still point at
`standards/ui-standard.md`. Read that as `standards/ui-vocabulary.md`. The hint strings in
`tools/addon-lint/rules/` need updating; the rules themselves are correct.

---

## Which one to read for which task

| You are doing | Read |
|---|---|
| Starting a new addon | `code-standard.md` §1–§4, then the `statamic-addon-scaffold` skill |
| Writing the service provider | `code-standard.md` §2–§3 |
| Adding a config file | `code-standard.md` §4 |
| Building any CP screen | `ui-vocabulary.md` §2, then `code-standard.md` §10 for the server side |
| Building a listing | `ui-vocabulary.md` §3 + `code-standard.md` §10 |
| Building a form | `ui-vocabulary.md` §4 + `code-standard.md` §10 |
| Building a fieldtype | `ui-vocabulary.md` §5 (§5.6 is a complete example) |
| Adding a settings screen | `code-standard.md` §9 — you probably should not build one |
| Nav entries and permissions | `ui-vocabulary.md` §8 + `code-standard.md` §8 |
| Domain code, repositories, facades | `code-standard.md` §5 |
| Events, listeners, queues | `code-standard.md` §6–§7 |
| Multi-site behaviour | `code-standard.md` §11 |
| Antlers tags, modifiers, commands | `code-standard.md` §12 |
| Error handling | `code-standard.md` §13 |
| Upgrade scripts | `code-standard.md` §14 |
| Writing tests | `code-standard.md` §15 |
| CI, PHPStan, Pint | `code-standard.md` §16–§18 |
| Localisation, `lang/` files | `code-standard.md` §19 |
| "Does this look native?" | `ui-vocabulary.md` §9 (antipatterns), as a checklist |
| Preparing a release | `marketplace-readiness.md`, ending at the Release checklist |
| Reviewing somebody else's addon | `marketplace-readiness.md` checklist + the `statamic-addon-audit` skill |

---

## How the standards, the skills and `addon-lint` relate

Three layers over one body of evidence.

```
findings/reference/*.md        13 deep analyses of real addons — the evidence
findings/existing/*.md         12 analyses of the studio's own addons — the backlog
reference/                     the addons themselves, checked out
        │
        ▼
standards/                     the written rules, with citations back to the evidence
        │
        ├──────────────► skills/          the decision layer an agent loads
        │
        └──────────────► tools/addon-lint the subset that can be checked mechanically
```

**Standards are the reference.** Long, complete, cited. Nobody reads them front to back; you read the
section you are in. They are the thing you argue with when a rule seems wrong, and the thing you edit
when it is.

**Skills are the decision layer.** Four of them, short by design, each pointing back into the standards
for detail:

| Skill | Loads when | Points at |
|---|---|---|
| `statamic-addon-scaffold` | starting a new addon, or migrating a v5 addon to v6 | `code-standard.md` §1–§4 |
| `statamic-addon-ui` | any CP surface — page, listing, form, fieldtype, widget, CSS | `ui-vocabulary.md` |
| `statamic-addon-code` | provider, domain code, tests, architecture | `code-standard.md` |
| `statamic-addon-audit` | review, QA, "is this releasable?" | `marketplace-readiness.md` + `addon-lint` |

A skill tells you *which* decision to make and *when*; the standard tells you what the decision is and
why. If a skill and a standard disagree, the standard wins and the skill is stale — fix it.

**`addon-lint` is the mechanical subset.** Roughly 55 rules across six categories (`structure`,
`bootstrap`, `code`, `ui`, `testing`, `release`), each with an `id()`, a `title()` and a `rationale()`
that quotes the same evidence the standards cite.

```bash
php tools/addon-lint/bin/addon-lint <addon-path> -v
php tools/addon-lint/bin/addon-lint <addon-path> --list-rules
php tools/addon-lint/bin/addon-lint <addon-path> --category=code,testing --fail-on=blocker
```

Every section of `code-standard.md` and `marketplace-readiness.md` ends with a **Checkable** list. Items
in `code font` are lint rule ids; items marked `(manual)` are rules the linter cannot express yet.
That mapping is deliberate and is the roadmap: **a `(manual)` item that keeps being missed is a request
for a new rule.**

The linter does not have opinions the standards do not. If you want to change what the linter enforces,
change the standard first, then the rule.

Twelve `ui.*` rules — `legacy-cp-api`, `legacy-class-names`, `themeable-colors`, `tailwind-tokens`,
`dark-mode`, `native-components`, `no-dom-piercing`, `hand-rolled-overlay`, `page-width`,
`inline-svg-icons`, `dirty-state`, `command-palette` — appear in no Checkable list here. That is
intentional: their authority is `ui-vocabulary.md`, and each rule's `rationale()` cites the section it
enforces (`ui-vocabulary §9.7`, `§9.3`, `§9.15`, …). Read the rationale, then the cited section.

Per-addon suppression lives in `addon-lint.json` at the addon root:

```json
{ "disable": ["release.changelog"], "severity": { "ui.dark-mode": "minor" } }
```

A suppression needs a reason recorded there. A silent entry is a lie about the addon's state.

**The score is a trend indicator; the findings are the truth.** `findings/lint/baseline.json` holds the
current state of the twelve in-house addons (scores 0–93). It exists to be driven up, not to be
admired.

---

## When the reference addons disagree

They frequently do. Where that happens, the standards state the disagreement, name which side the
studio picks, and say why. The arbitrations currently on record:

| Question | Studio picks | Where |
|---|---|---|
| Blade `<ui-*>` pages vs Inertia pages | Inertia | `code-standard.md` §10 |
| Commit `dist/` vs `extra.download-dist` | Commit + CI rebuild-and-diff (download-dist approved) | `marketplace-readiness.md` §9 |
| Flat `config/<slug>.php` vs `config/statamic/<slug>.php` | Flat, zero wiring | `code-standard.md` §4 |
| Explicit `$fieldtypes`/`$tags` arrays vs folder autoload | Autoload | `code-standard.md` §2 |
| `statamic/cms: ^6.0` vs a minor floor | Minor floor | `code-standard.md` §1 |
| Pint auto-commit vs `pint --test` | Check and fail | `code-standard.md` §18 |
| Pest vs PHPUnit | PHPUnit default; Pest with a bound `TestCase` | `code-standard.md` §15 |
| `$request->validate()` vs blueprint validator | Blueprint validator; FormRequest elsewhere | `code-standard.md` §10 |
| Fieldtype JS: `FieldtypeMixin` vs `Fieldtype.use()` | The composable API; mixin only in a file being migrated | `ui-vocabulary.md` §5.3, rule `ui.fieldtype-contract` |
| `__()` everywhere vs "English is fine" | Wrap everything, and ship `lang/en/` | `code-standard.md` §19 |
| `minimum-stability: dev` | Absent | `code-standard.md` §1 |
| Label-driven version bumps vs hand-tagged | Hand-tagged, generated changelog | `marketplace-readiness.md` §3 |

Adding an arbitration means editing the relevant standard *and* this table. An undocumented preference
is not a standard.
