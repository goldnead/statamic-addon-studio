---
name: statamic-addon-scaffold
description: Start a new Statamic 6 addon on the studio's standards from the first commit. Use when creating a new Statamic addon, setting up its package skeleton, Vite/CP build, test harness or CI, or when migrating an existing Statamic 5 addon to the Statamic 6 layout.
---

# Scaffold a Statamic 6 addon

Getting the skeleton right costs ten minutes now and saves a rewrite later. Every item below exists
because the studio's reference analysis found an addon that got it wrong.

Studio root: `/Users/adriangoldner/Documents/WebDev/statamic-addon-studio/`

## Use core's generators

Statamic ships them, and they write the correct stubs for the *current* version — which a
hand-written skeleton will not:

```bash
php please make:addon vendor/package
php please statamic:setup-cp-vite     # writes vite.config.js + package.json scripts
php please make:fieldtype MyFieldtype
php please make:widget MyWidget
```

Run them inside the studio playground (`<studio>/playground`, Statamic 6.26), then move the package
into its own repository and wire it back with a path repository for development.

## The skeleton

```
composer.json          type: statamic-addon · one PSR-4 prefix → src · php + statamic/cms ^6.0
                       extra.statamic.{name,description} · extra.laravel.providers
src/ServiceProvider.php    extends Statamic\Providers\AddonServiceProvider
src/                   Fieldtypes/ Tags/ Modifiers/ Actions/ Listeners/ Policies/ Commands/ Scopes/
routes/cp.php          auto-registered
config/<handle>.php    auto-merged and auto-published
resources/js/cp.js     the only CP entry
resources/css/cp.css   starts with @import "@statamic/cms/tailwind.css";
lang/en/               every user-facing string goes through __()
tests/TestCase.php     extends Statamic\Testing\AddonTestCase
vite.config.js         statamic() + tailwindcss() + laravel()
pint.json  phpunit.xml  .gitattributes  README.md  CHANGELOG.md  LICENSE.md
.github/workflows/tests.yml
```

Do not create `src/Fieldtypes` and then also list `$fieldtypes` in the provider — core discovers the
directory. See `statamic-addon-code` for what else is autoloaded.

## Decisions to make before the first commit

**Free or paid.** Paid means `"license": "proprietary"` plus `extra.statamic.editions` if there are
tiers; free means MIT. Editions are hard to add retroactively without breaking installs.

**Statamic support line.** Pick v6-only. The v6 CP is a different design system — an addon that spans
`^5 || ^6` has to ship two UIs or look wrong on one of them. Keep v5 on a maintenance branch.

**How built assets reach the consumer.** Either commit `dist/build` + manifest **and** add a CI job
that rebuilds and fails on a diff, or use `extra.download-dist` + `pixelfear/composer-dist-plugin`.
Silence here means the addon installs with no CP assets at all.

**The public API.** Tag names, parameters, config keys and facade methods are semver-locked from the
first release. Name them once, properly.

## Wire it up for development

```bash
cd <studio>/playground
composer config repositories.<name> path ../../<addon-dir>
composer require <vendor>/<package>:@dev
cd ../../<addon-dir> && composer install && npm install && npm run build
```

`@statamic/cms` resolves from `./vendor/statamic/cms/resources/dist-package`, so `composer install`
must precede `npm install`.

## Before the first tag

```bash
php <studio>/tools/addon-lint/bin/addon-lint . -v
```

Green at `--fail-on=major` is the entry gate. Then run the `statamic-addon-audit` skill —
the linter cannot tell you whether the addon does what its README promises.

## Related skills

- `statamic-addon-ui` — read before writing any CP screen or fieldtype
- `statamic-addon-code` — architecture, testing and release hygiene
- `statamic-addon-audit` — the release gate
