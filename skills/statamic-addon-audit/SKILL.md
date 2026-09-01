---
name: statamic-addon-audit
description: Audit a Statamic addon against the Statamic Addon Studio standards and produce a release verdict. Use when asked to review, validate, QA or release-check a Statamic addon, when deciding whether an addon is Marketplace-ready, or when working through the studio's remediation backlog.
---

# Statamic addon audit

An audit ends in a verdict — **ship**, **fix first**, or **rework** — backed by a numbered defect list
that someone can work through. It does not end in impressions.

Studio root: `~/Documents/WebDev/statamic-addon-studio/` on the Mac,
`~/projects/statamic-addon-studio/` on goldneros-host. Addon repos live next to it
(`~/Documents/WebDev/<addon>` resp. `~/projects/<addon>`).

## 1. Run the linter first

```bash
php <studio>/tools/addon-lint/bin/addon-lint <addon-path> -v --fail-on=never
php <studio>/tools/addon-lint/bin/addon-lint <addon-path> --format=markdown \
    --output=<findings>/lint/<addon>.md --fail-on=never
```

`<findings>` is wherever you keep audit output — it is deliberately not a path inside this
repository. Reports name and rank an addon's defects, which is working material, not something a
repository of standards should carry. Set it once and use it for both files below.

It covers structure, bootstrap, ui, code, testing and release. `--list-rules` shows the catalogue.
`addon-lint.json` in the addon root can disable a rule or change its severity — but a suppression
needs a reason recorded in that file's context, not a silent entry.

The score is a trend indicator. The findings are the truth.

## 2. Then look at what the linter cannot see

The linter reads text. These need judgement:

**UI (the priority).** Install the addon into the studio playground and open every screen it adds
next to the nearest core equivalent — Entries for a listing, an entry publish form for a form, a core
utility for a settings screen.

```bash
cd <studio>/playground
composer config repositories.local path ../../<addon-dir>
composer require <vendor>/<package>:@dev
php artisan serve --port=8099   # superuser: studio@local / studio-local-password
```

On goldneros-host the playground is already the public demo (`demo.adriangoldner.dev` proxies
`127.0.0.1:8099`, nightly reset 03:17) and every addon is symlinked from `vendor/goldnead/*` to
its working copy — so a code change is live in the playground at once, and a migration you run
there runs against the demo. Do not start a second server on 8099; use another port
(`php artisan serve --port=8137`) or the one already running. For a CP screenshot:

```bash
cd ~/GoldnerOS
COOKIE=$(bash scripts/dev/playground-cp-cookie.sh)   # logs in, prints statamic-session=…
CDP_COOKIE="$COOKIE" \
CDP_EVAL='[...document.querySelectorAll("button")].find(b=>b.textContent.trim()==="Später erinnern")?.click()' \
node scripts/dev/cdp-screenshot.js http://127.0.0.1:8137/cp/utilities/offers shot.png 1440 1000
```

The `CDP_EVAL` line dismisses the "Lizenzwarnung" modal the trial-mode playground shows on
every CP page; without it the modal covers the screen you wanted to judge.

Two traps found on 01.09.2026, both look like "my UI is broken" and are not:

- **The playground serves addon JS from a copy, not from the symlink.** Bundles come from
  `public/vendor/<addon>/build`. After `npm run build` in the addon run
  `php8.4 artisan vendor:publish --tag=<addon> --force` in the playground, otherwise the CP shows
  the old bundle (or a white page when the manifest and the copy disagree).
- **The screenshot Chrome is shared.** `cdp-screenshot.js` defaults to port 9333; when several
  agents screenshot at once, foreign tabs land in your PNG. Set `CDP_PORT=<free port>` per agent.
- **A PHP parse error in any addon's `config/` takes the whole playground down** (HTTP 500 for
  everyone). Run `php -l` on a config file right after editing it.

Judge: does the page shell match (header, breadcrumbs, primary action placement, content width)?
Does the empty state exist and look like core's? The loading state? The error state? Does dark mode
hold on every surface? Does the screen survive a narrow viewport? Do keyboard focus and esc behave
like core? Compare rendered HTML and computed styles, not just screenshots.

**The promise.** Read the README, list every behaviour it promises, and verify each one actually works
in the playground. An addon that is beautiful and does not do what the listing says is worse than an
ugly one that does.

**The tests.** Do they cover the promises, or only the easy internals? Is there a test for the
unauthorized case on every CP write route?

**The API surface.** Are tag names, parameters and config keys ones you would still want to support in
two years? They are semver-locked from the first release.

## 3. Write the verdict

Write to `<findings>/audits/<addon>-<YYYY-MM-DD>.md`:

- **Verdict:** ship / fix first / rework, in one sentence with the reason.
- **Blockers** — must fix before release. Each: `file:line`, what breaks, one-line fix.
- **Majors** — visibly non-native or a real maintenance hazard.
- **Minors** — polish.
- **Untested promises** — README claims with no test behind them.
- **What is good** — genuinely, briefly. It tells the next person what not to touch.

Every finding needs a location and a concrete fix. "The UI feels off" is not a finding;
"`resources/js/pages/Index.vue:42` hand-builds a `<table>`, so the screen has no filters, saved views
or bulk actions unlike every core listing — replace with `<Listing>`" is.

## 4. If the verdict is "ship"

Publishing is its own checklist, and the order matters: an addon is only as installable as its least
available dependency. See `<studio>/docs/publishing-to-packagist.md`.

## 5. Filing the work

Per the GoldnerOS convention: agent-buildable work goes to `STATE/backlog/` as a candidate,
work needing Adrian goes to `STATE/tasks/`, decisions go to `STATE/approvals/`.

## Related skills

- `statamic-addon-ui` — for fixing what the audit found in the CP
- `statamic-addon-code` — for fixing architecture, tests and release hygiene
