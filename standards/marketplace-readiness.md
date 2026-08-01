# Marketplace Readiness

The gate an addon must pass before it may be released. Everything here is a yes/no question with a
command or a file path behind it.

**Relationship to the other standards.** [`code-standard.md`](./code-standard.md) says how to build it;
[`ui-vocabulary.md`](./ui-vocabulary.md) says how the CP must look; this document says when it may
leave the building. An addon can be perfectly linted and still fail this gate — most of what follows
is about what the *buyer* receives.

**Evidence.** Reference addons under `reference/`, their analyses in `findings/reference/*.md`, and
`addon-lint` rule ids in `tools/addon-lint/rules/`. Cited inline.

---

## 1. Composer metadata

The Marketplace and the CP addon list read `composer.json`. Get it wrong and the listing is wrong.

```json
{
    "name": "vendor/statamic-thing",
    "description": "Same sentence as extra.statamic.description.",
    "type": "statamic-addon",
    "license": "MIT",
    "keywords": ["statamic", "statamic-addon"],
    "authors": [{ "name": "…", "email": "…" }],
    "support": { "issues": "https://github.com/vendor/statamic-thing/issues" },
    "require": { "php": "^8.3", "statamic/cms": "^6.25" },
    "extra": {
        "statamic": {
            "name": "Thing",
            "description": "One sentence. This is the Marketplace listing subtitle."
        },
        "laravel": { "providers": ["Vendor\\Thing\\ServiceProvider"] }
    }
}
```

Hard requirements:

| Key | Why |
|---|---|
| `type: statamic-addon` | Discovery. Missing in `bard-texstyle` (`findings/reference/jacksleight-statamic-bard-texstyle.md` §10.8). |
| `extra.statamic.name` + `description` | The two strings the CP and Marketplace render. Nothing else supplies them. |
| `extra.laravel.providers` | Package discovery. Declared-but-missing is a fatal on install. |
| `license` + a matching `LICENSE.md` | See §2. |
| `require.php` and `require.statamic/cms`, both explicit | See `code-standard.md` §1. |

Must **not** be present:

- `extra.statamic.version` — v6 derives the version from the installed package.
  `logbook` declares `3.2.0` against tag `v4.1.0`
  (`reference/aryehraber__statamic-logbook/composer.json:33`).
- `composer.lock` — a library must not pin its consumers.
- `minimum-stability: dev` without `prefer-stable: true` and a recorded reason
  (`reference/eminos__statamic-tabs/composer.json:10`).
- A `repositories` block pointing at your own packages. The buyer has no such repository configured;
  the install works on your machine and nowhere else (`release.resolvable-dependencies`, BLOCKER).
- Dead `config.allow-plugins` entries. `ssg` allow-lists `pixelfear/composer-dist-plugin` without
  requiring it or declaring `extra.download-dist`
  (`findings/reference/statamic-ssg.md` §10.4); `logbook` does the same
  (`findings/reference/aryehraber-statamic-logbook.md` R24).
- Optional integrations in `require`. They belong in `require-dev` + `suggest`, guarded by
  `class_exists()` (`findings/reference/aerni-advanced-seo.md` R24, `statamic-ssg.md` R6).
- Imported-but-undeclared dependencies. `ssg` imports `wilderborn/partyline` in five files and never
  requires it (`findings/reference/statamic-ssg.md` §10.3).

**Checkable:** `structure.composer-type`, `structure.statamic-metadata`, `structure.service-provider`,
`structure.constraints`, `structure.psr4-src`, `code.no-composer-lock`, `code.stability`,
`release.resolvable-dependencies`, `release.installable`.

---

## 2. Licensing and editions

### Free addon

`"license": "MIT"` in `composer.json` **and** a `LICENSE.md` file. Both, always — `ssg` has neither
(`findings/reference/statamic-ssg.md` §10.1), which makes a public package legally ambiguous.

### Paid addon

`"license": "proprietary"` **and** a `LICENSE.md` containing the actual EULA.
`collaboration` declares `proprietary` and ships no licence file, so the terms are undiscoverable in a
checkout (`findings/reference/statamic-collaboration.md` §10.4). `simple-commerce` does it correctly:
`LICENSE.md` is a proprietary EULA.

**Entitlement is enforced by the Statamic Marketplace, not by your code.** There is no licence-check
class in `seo-pro`, `collaboration`, `simple-commerce` or `runway`
(`findings/reference/statamic-seo-pro.md` §8). Do not build licence-key plumbing.

### Editions

Tiers are declared once and read once:

```json
"extra": { "statamic": { "editions": ["free", "pro"] } }
```

```php
// exactly one read, in one place
Addon::get('vendor/statamic-thing')->edition();
```

- `bard-texstyle` gates its entire paid tier on one `->edition()` call
  (`findings/reference/jacksleight-statamic-bard-texstyle.md` R21). `advanced-seo` centralises it in
  `AdvancedSeo::pro()` and pushes the result to the UI through `Inertia::share()`, so edition state is
  available to every page without threading it through controllers
  (`findings/reference/aerni-advanced-seo.md` R25) — the better pattern for a CP-heavy addon.
- **Decide free-vs-paid before the first tag.** Editions are hard to add retroactively without
  breaking installs.
- If there are no tiers, do not declare `editions`.

**Checkable:** `structure.license`, `release.editions`.

---

## 3. Semver and branch strategy

The convention every reference addon converges on:

- **The addon major tracks the Statamic major.** SEO Pro 7.x ↔ Statamic 6; Runway 9.x ↔ Statamic 6;
  Logbook v4 ↔ Statamic 6; Simple Commerce 8.x ↔ Statamic 6.
- **One long-lived branch per major, named `N.x`**, which is also the default branch. CI triggers on
  `main`/`master` and `'*.x'`. `simple-commerce` and `runway` merge forward from the older major and
  require PR titles prefixed `[8.x]`.
- **Tags are `vX.Y.Z`**, plain semver. Pre-release tags containing `-alpha`/`-beta` are auto-flagged
  `--prerelease` by the release workflow.
- **Support only the latest version.** Runway states this in its README. Older Statamic majors are
  served by old tags on their own `N.x` branch, not by a union constraint.

What is semver-major for an addon, beyond the obvious:

- a tag name, tag parameter, or modifier name;
- a config key (unless an `UpdateScript` migrates it);
- a facade method signature or an event constructor signature;
- a published view path — published Blade templates are public API
  (`findings/reference/spatie-statamic-responsive-images.md` R25);
- a permission string that already exists in a customer's roles;
- raising the `statamic/cms` floor across a major boundary.

Raising the `statamic/cms` floor **within** a major (`^6.25` → `^6.28`) is a minor release, and is the
correct move when you start consuming a newer core API — see `code-standard.md` §1.

**Version-bump mechanics.** Either hand-tagged, or derived from PR labels. `mailchimp` maps branch
prefixes (`feature/*`, `fix/*`, `chore/*`) to labels via `.github/pr-labeler.yml` and resolves them to
major/minor/patch in `.github/release-drafter.yml` with `default: patch`
(`findings/reference/statamic-rad-pack-mailchimp.md` R23). Either is acceptable; **the studio default
is hand-tagged with a generated changelog** (§5), because label-driven bumps silently downgrade a
breaking change that nobody labelled.

**Checkable:** `release.versioning`; branch named `N.x` matching the current major — *(manual)*.

---

## 4. README and documentation

The README **is** the Marketplace listing body. It is the only documentation most buyers read.

### Required shape

````md
<!-- statamic:hide -->
# Thing
> One-sentence pitch.
<!-- /statamic:hide -->

## Requirements
Statamic 6.25+ · PHP 8.3+ · (a queue worker / a database / …)

## Installation
```
composer require vendor/statamic-thing
```
Then: where it appears in the CP.

## Usage
The shortest complete example that does something useful.

## Configuration
Every config key, with its default and what happens when it is wrong.

## Multi-site
Per-site or shared. Say it explicitly.

## Support · Changelog · License
````

Rules:

- **Wrap the title/logo block in `<!-- statamic:hide -->` … `<!-- /statamic:hide -->`** so the
  Marketplace listing does not duplicate the heading it already renders. Used by `ssg`,
  `collaboration`, `runway`, `simple-commerce`, `eloquent-driver`, `spatie/responsive-images` and
  `importer` (`findings/reference/statamic-ssg.md` R30,
  `statamic-collaboration.md` R23).
- **Requirements, installation and usage are mandatory sections** (`release.readme`, BLOCKER). A
  version-compatibility table is the one thing every short README in the set is missing
  (`findings/reference/aryehraber-statamic-logbook.md` §8).
- **Prefer `php please install:<handle>`** as the install line when the addon has a publish step —
  that is the Statamic-native path (`findings/reference/statamic-ssg.md` R30).
- **Every file the README links to must exist.** `mailchimp` links `CONTRIBUTING.md` and `SECURITY.md`;
  neither is in the repo (`findings/reference/statamic-rad-pack-mailchimp.md` R25, §10.15).
- **No stale badges.** `spatie/responsive-images` advertises "Statamic 4.0+" while requiring `^6.0`
  (`findings/reference/spatie-statamic-responsive-images.md` §10.12). If a badge states a version,
  generate it from the constraint or delete it.
- **Document the exit path.** `eloquent-driver` documents exporting back to flat files as prominently
  as importing (`findings/reference/statamic-eloquent-driver.md` §8). An uninstall/rollback section
  materially reduces support load.

### Where the long documentation lives

Two acceptable models, both in-repo so they are PR-able and greppable by an agent:

1. **`DOCUMENTATION.md`** at the root — used by `seo-pro` (554 lines) and `importer` (235 lines). It is
   the source for the Marketplace docs site. **This is the studio default.** It needs no hosting, no
   build, and it ships with the package.
2. **A `docs/` tree** — used by `simple-commerce` (36 files) and `runway` (Mintlify). Justified only
   when the surface is genuinely large.

Do not defer the manual to an external site you also have to maintain unless the addon is big enough to
warrant it; `advanced-seo` and `bard-texstyle` do, and their READMEs then carry no installation
instructions at all.

**Also ship:** `SECURITY.md` (how to report a vulnerability privately), and
`.github/ISSUE_TEMPLATE/config.yml` with `blank_issues_enabled: false` plus a contact link routing
core bugs to `statamic/cms` — used by `ssg`, `collaboration` and `spatie`
(`findings/reference/statamic-ssg.md` R31). A structured bug form requiring the output of
`php please support:details` pays for itself on the first support ticket.

**Checkable:** `release.readme` (BLOCKER); README links all resolve — *(manual)*; no stale version
badge — *(manual)*; `SECURITY.md` present — *(manual)*.

---

## 5. CHANGELOG

`CHANGELOG.md` at the root, **newest first**, one `## X.Y.Z (YYYY-MM-DD)` heading per release. Nine of
the thirteen reference addons ship one; `ssg`, `advanced-seo`, `eloquent-driver` and `tabs` do not, and
in each case the only upgrade documentation is "diff the tags".

The Statamic-house format, consumed by `statamic/changelog-action` during release — so it is
load-bearing, not decorative (`findings/reference/statamic-collaboration.md` §8):

```md
## 2.1.0 (2026-07-20)

### What's new
- Thing now supports X. [#42](…) by @author

### What's fixed
- Fixed Y. [#43](…) by @author

### Major changes
- Statamic 5 is no longer supported.
```

- **Breaking changes are plain sentences under `### Major changes`**, not a footnote.
- **Generated, not hand-written**, where possible: `statamic/changelog-action` (`collaboration`,
  `seo-pro`, `importer`, `simple-commerce`, `runway`) or `release-drafter` +
  `changelog-updater-action` (`mailchimp`). `bard-texstyle`'s hand-written `[new]`/`[fix]` format is
  fine at its scale but does not survive contributors.
- **A paid addon without a CHANGELOG is a support ticket generator.** `advanced-seo` gates a Pro tier
  and runs config-renaming update scripts, and the only record of them is GitHub Releases
  (`findings/reference/aerni-advanced-seo.md` §10.12).

**Checkable:** `release.changelog`; first `##` heading matches `\d+\.\d+\.\d+` — *(manual)*.

---

## 6. Screenshots

Any addon with a CP surface ships at least one Control-Panel screenshot. The Marketplace listing is
browsed visually; a CP addon with no screenshot converts poorly and gives an agent no visual reference
for regressions.

- Store them in `docs/` and reference them with **repo-relative paths**, so they render in a checkout,
  on GitHub and on the Marketplace. `seo-pro` hot-links absolute `refs/heads/7.x` URLs — this works
  until the branch is renamed.
- Shoot in **light mode at the default theme**, at the standard CP width, with realistic data. Then
  shoot the same screen in dark mode: if they differ in anything but colour, the CP work is not done
  (`ui-vocabulary.md` §7.3).
- **One screenshot per distinct surface** — index, form, fieldtype in a publish form. Not five of the
  same page.
- Re-shoot every Statamic major. A v5 screenshot on a v6 listing reads as abandonware.

**Checkable:** `release.screenshots`; screenshots use relative paths — *(manual)*; screenshots taken
against the current Statamic major — *(manual)*.

---

## 7. Support policy

State it in the README, in one sentence, and hold to it. Runway's is the model: *"only the latest
version of this addon is supported."*

The policy must answer:

- **Which addon versions get fixes?** Default: the current major only.
- **Which Statamic versions?** Default: the one major the current addon major targets.
- **Where does support happen?** GitHub issues for free addons; for paid addons, whatever the
  Marketplace listing promises — and the issue tracker must not silently become the paid channel.
- **What is out of scope?** Core bugs go to `statamic/cms`; the ISSUE_TEMPLATE contact link should do
  that routing automatically (§4).

**Security reports go to a private channel**, named in `SECURITY.md` — never a public issue.

**Checkable:** support policy sentence present in README — *(manual)*; `SECURITY.md` names a private
channel — *(manual)*.

---

## 8. `.gitattributes`

Everything in the repo is downloaded into every site that installs the addon unless it is
`export-ignore`d. Tests, CI config, build sources and screenshots are dead weight in a customer's
`vendor/`.

```gitattributes
# Keep the distributed package small
/.github            export-ignore
/tests              export-ignore
/docs               export-ignore
/playground         export-ignore
.gitattributes      export-ignore
.gitignore          export-ignore
.editorconfig       export-ignore
phpunit.xml         export-ignore
pint.json           export-ignore
phpstan.neon        export-ignore
CHANGELOG.md        export-ignore

# Frontend sources — see §9 before adding these
/resources/js       export-ignore
/resources/css      export-ignore
package.json        export-ignore
package-lock.json   export-ignore
vite.config.js      export-ignore
```

- **`resources/js` and `vite.config.js` are only safe to export-ignore when the built bundle ships
  another way** (§9). `advanced-seo` ships its entire uncompiled frontend to every install even though
  `resources/dist/` is what actually loads
  (`findings/reference/aerni-advanced-seo.md` §10.13) — the opposite mistake is export-ignoring the
  source *and* not shipping the build, which installs an addon with no CP assets at all.
- **Never export-ignore `LICENSE.md`, `README.md`, `config/`, `lang/`, `resources/blueprints/`,
  `resources/views/` or `database/`.**
- **`git archive` applies `export-ignore`, so anything that stages a repo with it loses `tests/`.**
  Learned on 2026-08-01, and worth stating precisely because the obvious reading is the wrong one.
  Rolling `.gitattributes` out across all twelve addons was right for the tarball and immediately
  turned leadhub's "Webhook Manager integration" job and marketing's "Cross-addon integration" job red
  with `Pest\Exceptions\FatalException: The test directory [...] does not exist.`

  The first guess — "the sibling was installed from dist, so its tests are gone" — was wrong in both
  repos. Neither job runs a *sibling's* suite; both run the **addon's own** integration tests with the
  siblings installed alongside, and both staged the addon into a scratch directory with
  `git archive HEAD`. That command honours `export-ignore`, so the copy arrived with no `tests/` and
  no `phpunit.xml`. Installing the siblings from dist was, and remains, correct — the jobs only need
  their `src/`, `routes/`, `config/` and `database/`, none of which is ignored.

  So: use `git ls-files -co --exclude-standard` piped into tar, or `git read-tree` +
  `git checkout-index`, whenever you stage a working copy for testing. Reach for
  `composer require … --prefer-source` only when a job genuinely needs to execute *another* package's
  tests, which is rarer than it sounds.
- Verify with `git archive --format=tar HEAD | tar -t` before the first tag.

**Checkable:** `structure.gitattributes`; `git archive` listing contains no `tests/` and does contain
the built assets — *(manual)*.

---

## 9. How built assets reach the consumer

**A buyer must never need Node.** There are exactly two acceptable answers, and the reference set is
genuinely split.

### Option A — commit the build (studio default)

```
dist/build/manifest.json   tracked
dist/build/assets/*        tracked
dist/hot                   gitignored
```

Plus a CI job: `npm ci && npm run build && git diff --exit-code dist`.

Used by `bard-texstyle` (R22), `tabs` (R21), `spatie/responsive-images`, `advanced-seo`.

**Why the studio picks it as the default:** it needs no release infrastructure, the package works from
a git checkout, and it is what `addon-lint` encodes today (`ui.hot-file-ignored`,
`testing.dist-verification`). The cost is content-hashed filenames producing binary-ish diffs and merge
conflicts.

**The CI job is not optional.** Committing build output *without* a rebuild-and-diff check is the
dangerous half of the pattern: `bard-texstyle` has no CI at all, so nothing verifies its committed
`dist/` matches its sources (`findings/reference/jacksleight-statamic-bard-texstyle.md` §10.1), and
`spatie/responsive-images`' build workflow calls an npm script that does not exist, so its `dist/` is
maintained by hand, silently (`findings/reference/spatie-statamic-responsive-images.md` §10.3).

### Option B — `extra.download-dist` (approved alternative)

```json
"require": { "pixelfear/composer-dist-plugin": "^0.1.0" },
"config": { "allow-plugins": { "pixelfear/composer-dist-plugin": true } },
"extra": {
    "download-dist": {
        "url": "https://github.com/vendor/statamic-thing/releases/download/{$version}/dist.tar.gz",
        "path": "resources/dist"
    }
}
```

Plus `.gitignore`-ing the output dir, keeping the directory alive with `dist/.gitignore` (`*` /
`!.gitignore`), and a tag-triggered `release.yml` that runs `npm run build`, `tar -czvf dist.tar.gz dist`
and `gh release create`.

Used by `seo-pro` (R4), `importer` (R22), `collaboration` (R8), `runway` (R5), `simple-commerce` (R20),
`mailchimp` (R20) — i.e. the entire first-party set.

**Choose Option B when** you already have a tag-triggered release workflow, or the bundle is large
enough that its diffs are a real cost. Then it is strictly better.

### Either way

- `vite.config.js` includes `statamic()` from `@statamic/cms/vite-plugin` — without it your bundle
  carries its own Vue (`ui-vocabulary.md` §9.2).
- `package.json` declares `@statamic/cms` as `file:./vendor/statamic/cms/resources/dist-package`, never
  an npm version range — so `composer install` must precede `npm ci`.
- The `$vite` array in the provider is **byte-identical** to the `laravel()` plugin options in
  `vite.config.js` for `input`, `publicDirectory` and `hotFile`. `runway` has three different names for
  the hot file across `vite.config.js`, the provider and `.gitignore`
  (`findings/reference/statamic-rad-pack-runway.md` §10.13); `collaboration` shipped a patch release
  purely to fix a `base` that did not match the package name
  (`findings/reference/statamic-collaboration.md` §10.7).
- **Never both.** `simple-commerce` publishes `dist → public/vendor/simple-commerce` from the provider
  while core's `registerVite()` publishes to `public/vendor/duncanmcclean/simple-commerce/build` — two
  destinations, one of them permanently stale
  (`findings/reference/duncanmcclean-simple-commerce.md` §10.A8).
- **The `dist` decision must be made explicitly.** Silence means the addon installs with no CP assets.
- Harden `.npmrc` with `ignore-scripts=true` (`findings/reference/statamic-collaboration.md` R25,
  `statamic-seo-pro.md` R28).

**Checkable:** `ui.vite-config` (BLOCKER), `ui.vite-property`, `ui.statamic-cms-package`,
`ui.hot-file-ignored`, `testing.dist-verification`; `$vite` matches `vite.config.js` — *(manual)*;
exactly one dist strategy in force — *(manual)*.

---

## 10. Security

The security bar for a released addon is not "no known vulnerabilities". It is these five, each of
which a reference addon failed.

1. **Authorization on every CP write route.** `$this->authorize()`, a `FormRequest::authorize()`, or
   `can:` middleware. Hiding the button is not authorization. `simple-commerce` leaves
   `store`/`update`/three `destroy` actions and a "resend customer notifications" endpoint open to any
   authenticated CP user, with the permissions registered and decorative
   (`findings/reference/duncanmcclean-simple-commerce.md` §10.A1). **This is a release blocker**
   (`bootstrap.cp-authorization`).

2. **Every write route has a 403 test.** Without it, item 1 regresses invisibly.
   (`findings/reference/duncanmcclean-simple-commerce.md` R23.)

3. **No server-side identifier reaches the DOM unencrypted, and every decrypt is guarded.** `logbook`
   encrypts filesystem paths correctly but wraps no `Crypt::decrypt()` in a `try`, turning a tampered
   value into a 500 and an error oracle
   (`findings/reference/aryehraber-statamic-logbook.md` R13–R14).

4. **Input is validated before use** — blueprint validator for blueprint-driven screens, `FormRequest`
   otherwise (`code-standard.md` §10). Never `File::delete()` straight off request input.

5. **No secrets leave the server.** `Statamic::provideToScript()` takes named keys you chose, never a
   whole config array (`code.secrets-to-frontend`, BLOCKER). No API key in a CI test step —
   `mailchimp` injects a live one, and the suite only passes because nothing exercises the network
   (`findings/reference/statamic-rad-pack-mailchimp.md` §10.12). Fake all outbound HTTP in tests.

Plus the supply-chain hygiene from §16 of `code-standard.md`: workflow `permissions: {}`, actions
pinned to SHAs, `persist-credentials: false`, untrusted text through `env:`, a `zizmor` lint job,
`.npmrc` with `ignore-scripts=true`, and Dependabot on `github-actions` **and** `composer` **and**
`npm`.

Also: **sandbox any iframe rendering user-authored content** (`ui.unsandboxed-iframe`), and **escape
`{{`/`}}` in any user- or file-sourced string** rendered into a CP template, because that markup is
compiled as a Vue template at runtime (`findings/reference/aryehraber-statamic-logbook.md` R16).

**Checkable:** `bootstrap.cp-authorization` (BLOCKER), `code.secrets-to-frontend` (BLOCKER),
`ui.unsandboxed-iframe` (BLOCKER), `ui.confirm-destructive`; 403 test per write route — *(manual)*;
no live secret in CI — *(manual)*.

---

## 11. Privacy and telemetry

**The studio ships no telemetry.** Not anonymous, not opt-out, not "just a version ping". No addon in
the reference set has any, and a Statamic site is frequently self-hosted precisely to avoid it.

If a future addon genuinely needs a network call the user did not ask for, all four must hold:

1. it is **opt-in**, defaulting to off, via a documented config key;
2. the README states exactly what is transmitted, to which host, and how often;
3. it is **queued**, never on the request thread — see `code-standard.md` §7;
4. failure is silent and total: no telemetry error may ever surface to a visitor or block a save.

Related obligations that do apply to ordinary addons:

- **Any addon that stores personal data says so in the README**, names the storage location, and
  documents how to delete it. Anything holding form submissions, subscriber records or user identifiers
  qualifies.
- **Do not log personal data.** `collaboration`'s unconditional `debug()` wrapper logs every remembered
  value change to every collaborator's console
  (`findings/reference/statamic-collaboration.md` §10.12) — which is content, on other people's
  screens.
- **Third-party integrations are disclosed:** which vendor, which endpoint, what leaves the site.

**Checkable:** no outbound HTTP outside a documented, user-configured integration — *(manual)*;
personal-data storage documented in README — *(manual)*.

---

## 12. Upgrade guides

One per major, in-repo, at `docs/upgrade-guides/vN-to-vM.md`, linked from the CHANGELOG entry for that
major. `runway` and `simple-commerce` have the best template
(`findings/reference/statamic-rad-pack-runway.md` R24,
`duncanmcclean-simple-commerce.md` R25):

```md
# Upgrading from v1 to v2

> Do not skip majors. Upgrade one major at a time.

## Update the constraint
"vendor/statamic-thing": "^2.0"
composer update vendor/statamic-thing --with-dependencies
php artisan route:clear && php artisan view:clear

## High impact changes
**Affects apps using the `{{ thing }}` tag** — the `limit` parameter is now `per_page`. …

## Medium impact changes
## Low impact changes
```

Rules:

- **Impact sections, each headed "Affects apps using X."** A reader must be able to skip everything
  that does not apply to them.
- **The exact `composer.json` edit and the exact commands**, copy-pasteable, including cache clears.
- **Automate everything automatable first**, with `UpdateScript`s (`code-standard.md` §14); the guide
  then covers only the remainder. `simple-commerce`'s four v6 update scripts rewrite the user's config
  with Proteus so the guide does not have to describe it.
- **Link the GitHub compare view** for the full diff.
- If the upgrade requires `php artisan migrate`, that line is the *first* thing in the guide, not a
  footnote in the README (`findings/reference/statamic-eloquent-driver.md` §8).
- **Published views and config are part of the upgrade surface.** Name every published file that
  changed, and link its commit history as the diff source — `spatie/responsive-images`' `UPGRADE.md` is
  the model (`findings/reference/spatie-statamic-responsive-images.md` R25).

**Checkable:** each `## vN.0.0` CHANGELOG heading has a matching upgrade guide — *(manual)*; every
`$updateScripts`-automatable change is automated rather than documented — *(manual)*.

---

## Release checklist

Every line is a hard yes/no. **A single `no` blocks the release.** Run the linter first; it answers
about half of them.

```bash
php tools/addon-lint/bin/addon-lint <addon-path> -v          # must be green at --fail-on=major
composer test
vendor/bin/pint --test
vendor/bin/phpstan analyse
npm ci && npm run build && git diff --exit-code dist          # Option A only
git archive --format=tar HEAD | tar -t | head -50             # inspect what the buyer receives
```

### Blockers — never release with any of these red

- [ ] `addon-lint` reports **zero** findings at severity `blocker`.
- [ ] Every CP write route is authorized server-side (`bootstrap.cp-authorization`).
- [ ] Every CP write route has a test asserting 403 for an unpermitted user.
- [ ] The test suite exists, runs, and passes (`testing.suite`).
- [ ] `README.md` covers requirements, installation and usage (`release.readme`).
- [ ] `composer.json` has `type`, `extra.statamic.name`, `extra.statamic.description`,
      `extra.laravel.providers`, and the provider class exists.
- [ ] No `repositories` block; every runtime dependency is installable by the buyer.
- [ ] No secrets handed to the frontend; no live API key in CI.
- [ ] The CP assets reach the buyer by exactly one strategy (§9), and a clean install renders the CP
      surface correctly.
- [ ] `vite.config.js` includes `statamic()`; `@statamic/cms` resolves via `file:` from `vendor/`.

### Gates — must be green, may be waived only with a recorded reason in `addon-lint.json`

- [ ] `addon-lint` reports zero findings at severity `major`.
- [ ] `LICENSE.md` exists and matches the `license` key.
- [ ] `CHANGELOG.md` exists, newest-first, with a dated heading for this release.
- [ ] `statamic/cms` is constrained to a **minor** floor, single major.
- [ ] CI runs on `pull_request` across PHP × Laravel × `prefer-lowest|prefer-stable`, and is green.
- [ ] If `dist/` is committed, CI rebuilds it and fails on a diff.
- [ ] `pint --test` and `phpstan analyse` pass in CI; the style workflow does not auto-commit.
- [ ] Tests extend `Statamic\Testing\AddonTestCase` with `PreventsSavingStacheItemsToDisk`.
- [ ] Every promise the README makes has a test.
- [ ] `.gitattributes` export-ignores tests, CI and docs; `git archive` still contains the built assets,
      `config/`, `lang/`, `resources/blueprints/`, `resources/views/` and `LICENSE.md`.
- [ ] Every file the README links to exists; no stale version badges.
- [ ] At least one current-major CP screenshot, in both light and dark, for a CP addon.
- [ ] `SECURITY.md` names a private reporting channel; issue templates route core bugs to
      `statamic/cms`.
- [ ] Support policy stated in one sentence in the README.
- [ ] Multi-site behaviour stated and tested.
- [ ] This is a major → an upgrade guide exists, linked from the CHANGELOG, with impact sections.
- [ ] Update scripts automate everything automatable; every class in `src/UpdateScripts/` registers.
- [ ] Workflows: `permissions:`, SHA-pinned actions, `persist-credentials: false`.
- [ ] Tag is `vX.Y.Z`, on the `N.x` branch for this Statamic major.

### Judgement — no linter can answer these

- [ ] A Statamic developer opening the CP cannot tell which screens are core and which are the addon
      (`ui-vocabulary.md` §9 as the checklist).
- [ ] The addon does what its README promises, end to end, on a clean install — verified by hand once.
- [ ] Nothing in the package is forked from core.
- [ ] The public API (tags, parameters, config keys, facade methods) is named well enough to live with
      for the whole major.
- [ ] No dead files, commented-out features, or `@todo`s ship in the package.
