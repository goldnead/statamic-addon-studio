# Statamic 6 Addon — Code Standard

Architecture and PHP/JS standard for an addon built by the Statamic Addon Studio.

**Scope.** Everything that is not the Control-Panel UI. The CP UI has its own authority:
[`ui-vocabulary.md`](./ui-vocabulary.md), extracted from `statamic/cms` 6.x. This document links to it
and does not restate it. Release and Marketplace gates live in
[`marketplace-readiness.md`](./marketplace-readiness.md).

**Evidence.** Every rule below traces to one of: a published Statamic addon we read, cited inline
by package name as `observed in vendor/package`; a section of `ui-vocabulary.md`; or the current
behaviour of `statamic/cms` 6.x. The working analyses behind the package citations are internal
and not part of this repository — they name and assess other maintainers' packages, and that is
not something to publish. The rule, its reasoning and its origin are here; the source it was read
from is public and you can check it yourself.

**How to read a section.** Each section ends with a **Checkable** list. Items in `code font` are
`addon-lint` rule ids — run `php tools/addon-lint/bin/addon-lint <path>` to check them. Items marked
`(manual)` are not automated yet; they belong to the reviewer (see the `statamic-addon-audit` skill).

---

## 1. Package skeleton

One layout. All 15 reference addons agree on the shape; they differ only where noted.

```
composer.json
src/
  ServiceProvider.php          extends Statamic\Providers\AddonServiceProvider
  Fieldtypes/  Tags/  Modifiers/  Actions/  Listeners/  Subscribers/
  Policies/  Commands/  Scopes/  Widgets/  Dictionaries/  UpdateScripts/
  Http/Controllers/CP/         CP controllers
  Http/Requests/CP/            one FormRequest per authorized verb
  Http/Resources/              JsonResource classes for listing payloads
  Facades/  Contracts/  Exceptions/
routes/cp.php                  auto-registered
routes/web.php                 auto-registered
routes/actions.php             auto-registered
config/<slug>.php              auto-merged and auto-published
resources/blueprints/          auto-namespaced; settings.yaml is special (§9)
resources/fieldsets/           auto-namespaced
resources/views/               auto-namespaced under the package name
resources/js/cp.js             the single CP entry point
resources/css/cp.css           starts with @import "@statamic/cms/tailwind.css";
lang/en/                       every user-facing string
tests/TestCase.php             extends Statamic\Testing\AddonTestCase
tests/__fixtures__/            content fixtures AddonTestCase points the Stache at
vite.config.js  package.json  pint.json  phpunit.xml
.gitattributes  .github/workflows/tests.yml
README.md  CHANGELOG.md  LICENSE.md
```

### composer.json

```json
{
    "name": "vendor/statamic-thing",
    "type": "statamic-addon",
    "license": "MIT",
    "require": {
        "php": "^8.3",
        "statamic/cms": "^6.25"
    },
    "autoload": { "psr-4": { "Vendor\\Thing\\": "src" } },
    "autoload-dev": { "psr-4": { "Vendor\\Thing\\Tests\\": "tests" } },
    "extra": {
        "statamic": {
            "name": "Thing",
            "description": "One sentence, shown in the CP addon list and the Marketplace."
        },
        "laravel": { "providers": ["Vendor\\Thing\\ServiceProvider"] }
    }
}
```

Non-negotiables, each with a failure it prevents:

- **`"type": "statamic-addon"`.** Statamic discovers addons by package type. `bard-texstyle` omits it
  (observed in `jacksleight/statamic-bard-texstyle`) — harmless until tooling keys off it.
- **Exactly one PSR-4 prefix mapping to `src`.** All 15 reference addons do this. Two prefixes break
  `AddonServiceProvider`'s `shouldBootRootItems()` check
  (`reference/statamic__cms/src/Providers/AddonServiceProvider.php:896-903`), which silently disables
  config, translation, view, blueprint and route autoloading.
- **`extra.statamic.name` + `description`.** These two strings *are* the CP listing and the Marketplace
  listing body. Nothing else supplies them.
- **`extra.laravel.providers`.** Package discovery. Declared-but-missing is a fatal on install.
- **No `extra.statamic.version`.** Statamic 6 reads the version from the installed Composer package.
  `logbook` still declares `3.2.0` while tagged `v4.1.0`
  (`reference/aryehraber__statamic-logbook/composer.json:33`).
- **No `composer.lock`.** A lock file in a library pins nothing for consumers and makes the CI matrix
  lie about what was tested.
- **No `repositories` block reaching your own packages.** A buyer running `composer require` has no
  such repository configured, so the install fails on their machine and nowhere else.
- **`minimum-stability` absent, or `stable`.** `tabs` and `logbook` ship `dev`
  (`reference/eminos__statamic-tabs/composer.json:10`). Composer only honours the root package's
  value so it does not actually leak, but it is a reliable marker of a package that was never
  release-checked. Studio arbitration: **absent.** `seo-pro` and `eloquent-driver` deliberately keep
  `dev` + `prefer-stable: true` to test against CMS betas — permitted only with `prefer-stable: true`
  *and* a recorded reason in `addon-lint.json`.

### Constraint policy

Pin `statamic/cms` to **the minor that introduced the APIs you call**, not the bare major:
`^6.25`, not `^6.0`. This is the majority position and it is load-bearing:
`collaboration` pins `^6.15.0` for the `pushComponent` seam, `seo-pro` `^6.25` for
`@statamic/cms/save-pipeline`, `importer` `^6.25`, `runway` `^6.26`, `eloquent-driver` `^6.10`
(observed in `statamic/collaboration`, `statamic/seo-pro`). `bard-texstyle` and
`tabs` use `^6.0` and are the minority. A `^6.0` floor combined with `@statamic/cms` linked from
`vendor/` means the JS API and the PHP API can disagree at install time.

Support **one Statamic major per addon major**. Do not ship `^5.0 || ^6.0`: the v6 CP is a different
design system, so a union constraint means two UIs or a wrong one. Keep v5 on a maintenance branch.

**Checkable**
- `structure.composer-type`, `structure.statamic-metadata`, `structure.service-provider`,
  `structure.psr4-src`, `structure.constraints`, `structure.license`, `structure.gitattributes`
- `code.no-composer-lock`, `code.stability`
- `release.resolvable-dependencies`, `release.installable`
- Constraint floor is a **minor**, not `^N.0` — *(manual)*
- No `extra.statamic.version` key — *(manual)*

---

## 2. The AddonServiceProvider contract

`Statamic\Providers\AddonServiceProvider::boot()` wraps everything in `Statamic::booted()` and then runs
a fixed 26-step chain before calling your `bootAddon()`
(`reference/statamic__cms/src/Providers/AddonServiceProvider.php:192-231`). Knowing that chain is the
difference between a 20-line provider and a 400-line one.

### Extend it. Always.

`ssg` extends the plain Laravel provider (`reference/statamic__ssg/src/ServiceProvider.php:11`) and is
narrowly right to — it registers nothing Statamic-specific — but it pays for that with manual command
registration. Any addon touching `Nav::`, `Permission::`, a fieldtype, a tag, `$routes`, `$vite` or
`$scripts` must extend `AddonServiceProvider`; otherwise none of the below happens.

### What core autoloads — do not declare it

`autoloadFilesFromFolder()` (`AddonServiceProvider.php:838`) scans these directories under your PSR-4
root and registers every class of the right type:

| Folder | Registered as | Chain step |
|---|---|---|
| `src/Subscribers/` | `Event::subscribe()` | `bootEvents()` `:237` |
| `src/Listeners/` | `Event::listen()`, discovered by reflection | `bootEvents()` `:279` |
| `src/Tags/` | Antlers tags | `bootTags()` `:297` |
| `src/Scopes/`, `src/Query/Scopes/`, `src/Query/Scopes/Filters/` | query scopes & filters | `bootScopes()` `:310` |
| `src/Actions/` | CP actions | `bootActions()` `:325` |
| `src/Dictionaries/` | dictionaries | `bootDictionaries()` `:338` |
| `src/Fieldtypes/` | fieldtypes | `bootFieldtypes()` `:351` |
| `src/Modifiers/` | Antlers modifiers | `bootModifiers()` `:364` |
| `src/Widgets/` | dashboard widgets | `bootWidgets()` `:377` |
| `src/Commands/`, `src/Console/Commands/` | artisan commands (console only) | `bootCommands()` `:408` |
| `src/UpdateScripts/` | update scripts | `bootUpdateScripts()` `:628` |

And by path convention, with no property at all:

| Path | Effect | Chain step |
|---|---|---|
| `config/<slug>.php` | `mergeConfigFrom(…, '<slug>')` + `publishes(…, '<slug>-config')` | `bootConfig()` `:467` |
| `lang/` (or `resources/lang/`) | `loadTranslationsFrom(…, '<slug>')` + `<slug>-translations` tag | `bootTranslations()` `:490` |
| `routes/web.php`, `routes/cp.php`, `routes/actions.php` | registered with the right middleware groups | `bootRoutes()` `:534` |
| `resources/views/` | `loadViewsFrom(…, '<packageName>')` | `bootViews()` `:641` |
| `resources/blueprints/` | `Blueprint::addNamespace('<slug>', …)` | `bootBlueprints()` `:782` |
| `resources/fieldsets/` | `Fieldset::addNamespace('<slug>', …)` | `bootFieldsets()` `:800` |
| `resources/blueprints/settings.yaml` | a whole settings screen, nav item and permission | `bootSettingsBlueprint()` `:825` |

Listener discovery reflects the **first parameter type** of every public `handle*`/`__invoke` method
(`AddonServiceProvider.php:277-291`). So this is the entire wiring for an event listener:

```php
namespace Vendor\Thing\Listeners;

class SyncOnSave
{
    public function handle(EntrySaved $event): void { /* … */ }
}
```

No `$listen` array. `runway` declares one provider property; `advanced-seo` declares three
(observed in `aerni/statamic-advanced-seo`, `statamic-rad-pack/mailchimp`). An explicit
array goes stale the moment somebody adds a class, and surfaces as "my fieldtype doesn't show up".

### Properties you may still declare

`$vite` (§13 of `ui-vocabulary.md` territory — see `ui-vocabulary.md` §7.7), `$routes` when a route file
lives somewhere non-standard, `$publishables`, `$middlewareGroups`, `$viewNamespace` /
`$blueprintNamespace` / `$fieldsetNamespace` when you need a namespace other than the slug,
`$policies` (not autoloaded — `bootPolicies()` `:399` reads the property only), `$config = false` /
`$translations = false` to opt out of the automatic wiring, and `$publishAfterInstall`.

`$scripts` and `$stylesheets` are legacy: they publish pre-built files and skip the Vite manifest, so
there is no hot reload and no cache-busting. Use `$vite`. The only defensible `$scripts` case is a
dependency-free, browser-native file with no `import`
(observed in `aryehraber/statamic-logbook`).

### Never fork core

Every UI-drift incident in the reference set traces back to a copied core file:
`advanced-seo`'s `SiteSelector.vue` is literally commented `// Copied from Statamic Core.`;
`simple-commerce`'s `GatewayFieldtype.vue` reproduces relationship-item chrome that core *exports* as
`RelatedItem`; `runway`'s `PublishActions.vue` and `Revision.vue` both open with "mostly the same as
the one in Statamic Core", and its CHANGELOG shows that drift being paid down in patch releases
(observed in `statamic-rad-pack/runway`). If the extension point does not exist, open
a core PR — do not copy the file.

**Checkable**
- `bootstrap.addon-service-provider`, `bootstrap.redundant-registration`, `bootstrap.forked-core-component`
- `ui.vite-property`
- No `$listen` array where `src/Listeners/` exists — covered by `bootstrap.redundant-registration`
- `$policies` entries all resolve to existing classes — *(manual)*

---

## 3. `bootAddon()` structure

```php
public function bootAddon()
{
    $this
        ->bootPermissions()
        ->bootNav()
        ->bootBlueprintExtensions()
        ->bootRouteBindings();
}

protected function bootPermissions(): self
{
    Permission::extend(fn () => Permission::group('thing', __('Thing'), function () { /* … */ }));

    return $this;
}
```

A fluent chain of single-purpose `boot*(): self` methods. `runway`, `advanced-seo`, `seo-pro` and
`bard-texstyle` all converge on this shape (observed in `statamic/seo-pro`, `aerni/statamic-advanced-seo`, `jacksleight/statamic-bard-texstyle`). Keep `bootAddon()` itself to
the chain — no `if`, no work — and each `boot*()` under ~40 lines.

Rules for what goes where:

- **`register()`** — container bindings, `registerSerializableClasses()`, and nothing that reads
  config that `bootConfig()` has not merged yet. `eloquent-driver` merges its config in `boot()` while
  `register()` reads it; Laravel runs every `register()` before any `boot()`, so the merge arrives too
  late (observed in `statamic/eloquent-driver`). If you must merge manually, merge in
  `register()`.
- **`bootAddon()`** — everything that touches a Statamic facade: `Nav::`, `Permission::`, `Form::`,
  `Utility::`, `Addon::`, `Augmentor::`, `*::appendConfigFields()`. These are only guaranteed inside
  core's `Statamic::booted()` chain (observed in `statamic-rad-pack/mailchimp`, `aryehraber/statamic-logbook`).
- **Never `public function boot()`** on an `AddonServiceProvider` subclass — you would override the
  26-step chain.
- **No `Route::get|post|patch|delete` in the provider.** Route *definitions* go in `routes/cp.php`;
  only `Route::bind()` belongs in the provider (observed in `aerni/statamic-advanced-seo`).
- **No filesystem mutation at boot.** `mailchimp` runs two config migrations inside `bootAddon()` on
  *every request*, doing `File::exists()` and potentially `ConfigWriter` writes
  (`reference/statamic-rad-pack__mailchimp/src/ServiceProvider.php:32-33`). That is what
  `src/UpdateScripts/` exists for (§12), and it is a landmine on read-only deploys.

**Checkable**
- `bootstrap.boot-addon-size`
- No `public function boot()` override — *(manual)*
- No `Route::(get|post|put|patch|delete)` in `src/ServiceProvider.php` — *(manual)*
- No `File::`/`ConfigWriter` writes reachable from `bootAddon()` — *(manual)*

---

## 4. Config: merge and publish

**Default: write no config wiring at all.** Put the file at `config/<slug>.php` where `<slug>` is the
second segment of the package name. `bootConfig()` (`AddonServiceProvider.php:467-488`) merges it under
the key `<slug>` and registers the publish tag `<slug>-config`. That is the whole feature.

```php
// config/thing.php  →  config('thing.driver')
return [
    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    | One of: "file", "database". Defaults to "file" so an unpublished install
    | is inert.
    */
    'driver' => 'file',
];
```

Rules:

- **Merged or not at all.** A published-but-unmerged config returns `null` on every site that did not
  publish it — the addon breaks precisely for the users who did nothing wrong. If you set
  `$config = false` and wire it by hand, you must call both `mergeConfigFrom()` *and*
  `publishes(..., '<slug>-config')`.
- **The merge key must be the exact prefix every `config()` call reads.** `eloquent-driver` merges into
  `statamic-eloquent-driver` while all 133 reads use `statamic.eloquent-driver`; the merge is dead code
  (observed in `statamic/eloquent-driver`).
- **Every `publishes()` takes a tag.** `ssg` omits it
  (`reference/statamic__ssg/src/ServiceProvider.php:30`), forcing users into an untagged
  `vendor:publish` picker.
- **Every config read has an inert default:** `config('thing.driver', 'file')`. An unpublished,
  unmerged config must leave the addon completely inactive
  (observed in `statamic/eloquent-driver`).
- **Secrets come from `env()` in the config file, never from a settings blueprint field**
  (observed in `statamic-rad-pack/mailchimp`).
- **Never hand a whole config array to the frontend.** `Statamic::provideToScript()` takes named keys
  you have chosen, not `config('thing')` (observed in `jacksleight/statamic-bard-texstyle`).

**Disagreement — config namespace.** `ssg`, `seo-pro` and `spatie/responsive-images` publish to
`config/statamic/<slug>.php` and merge under `statamic.<slug>`, which requires `$config = false` plus
manual wiring. `collaboration`, `mailchimp`, `bard-texstyle` and core's own default use the flat
`config/<slug>.php`. **The studio picks the flat, zero-wiring form.** The `statamic.` namespace is
reserved for infrastructure drivers that replace a core subsystem (an eloquent-driver-shaped addon),
where sitting next to `config/statamic/*.php` is genuinely clearer. Everything else: flat.

**Checkable**
- `code.config-publishing`, `code.secrets-to-frontend`
- Every `config('<slug>.…')` read has a second argument — *(manual)*
- Every `publishes()` call passes a tag string — *(manual)*
- `mergeConfigFrom` key is a prefix of every `config()` read — *(manual)*

---

## 5. Repositories, facades and contracts

If the addon has a domain, give it three layers and no more: a **contract**, an **implementation**, and
a **facade** over a container singleton.

```php
// src/Contracts/ThingRepository.php   — the seam
// src/Stache/ThingRepository.php      — the default implementation
// src/Facades/Thing.php               — Facade::getFacadeAccessor() → ThingRepository::class
```

Rules, from `eloquent-driver` — the reference for swappable storage
(observed in `statamic/eloquent-driver`):

- **Swap through `Statamic::repository($contract, $impl)`**, not `$this->app->bind($contract, …)`.
  Core's own facade/resolution machinery hangs off the former.
- **One `register<Thing>()` method per swappable repository**, each with a single early-return guard.
  Not one giant conditional.
- **Bind the model/class resolver *before* the driver guard**, so import/export tooling can resolve it
  even when the driver is inactive.
- **Extend the framework's repository class**, override only the methods that must hit your store —
  do not implement the contract from scratch.
- **Never reference a model class literal in repository or query-builder code.** Resolve it through
  `config('<slug>.<thing>.model')` → container alias.
- **Disable the store you replace at the same moment you swap:** every `Statamic::repository()` call
  that shadows a Stache store sits in the same method as `Stache::exclude('<store>')`.
- **Expose every swappable behaviour as an interface bound in the container**, so a test can replace it
  with one `$this->mock()` line (observed in `spatie/statamic-responsive-images`).
- **A package must not assume the host app's namespace.** `eloquent-driver`'s `EntryModel::author()`
  returns `$this->belongsTo(\App\Models\User::class, …)`
  (`reference/statamic__eloquent-driver/src/Entries/EntryModel.php:23-26`). Read
  `config('auth.providers.users.model')`.
- **Facades.** A facade over a `singleton()` binding is the right way to expose a programmatic API that
  a host app's `boot()` can register into before your command runs
  (observed in `statamic/ssg`). If you hand-maintain `@method` docblocks on it, pin them
  with a test — `mailchimp`'s facade docblock has drifted from its driver by three methods
  (observed in `statamic-rad-pack/mailchimp`).
- **Never reach into another package's private state.** `runway` ships `spatie/invade` as a production
  dependency; `eloquent-driver` reads `BlueprintRepository::$fallbacks` by reflection. Both are
  couplings no Composer constraint can express. If unavoidable, pin the behaviour with a test.
- **Caching.** Every negative lookup you cache must be evicted immediately, and every write must
  invalidate its keys (observed in `statamic/eloquent-driver`). Use `Blink` for
  request-scoped memoisation; do not use `Cache::forever` as a coordination log between queue workers
  (observed in `statamic/importer`).
- **Never `env()` at runtime.** It returns `null` once `config:cache` has run
  (`reference/statamic__importer/src/Importer.php:26`).

**Checkable**
- `bootstrap.forked-core-component`
- No `env(` outside `config/` — *(manual)*
- Every `Statamic::repository(` with a Stache-backed domain has a sibling `Stache::exclude(` — *(manual)*
- No model class literal in `*Repository.php` / `*QueryBuilder.php` — *(manual)*
- No `spatie/invade` or `ReflectionProperty` against a core class without a pinning test — *(manual)*

---

## 6. Events and listeners

**Emitting.** Fire an event for anything a site developer might reasonably want to hook. Name it after
what happened, past tense, in `src/Events/`. Events are public API: their constructor signature is
semver-locked.

**Consuming.** One class per concern in `src/Listeners/`, wired by reflection (§2):

```php
class SendWelcome
{
    public function handle(SubmissionCreated $event): void { … }
}
```

- The first parameter must be a **class type-hint**, or the listener is silently never registered
  (`AddonServiceProvider.php:283-289`).
- Methods named `handle<Something>` register under `Class@handleSomething`, so one class can consume
  several events (`AddonServiceProvider.php:286`).
- **Convention-based wiring has no compile-time safety net.** Add a test per listener that dispatches
  the real event and asserts the effect (observed in `statamic-rad-pack/mailchimp`).
- **Never do synchronous third-party HTTP inside a listener on the request thread.** `mailchimp`'s
  `AddFromSubmission` listener performs a live Mailchimp `PUT` during form submission, with
  `Log::error()` as its entire failure handling
  (`reference/statamic-rad-pack__mailchimp/src/Subscriber.php:134`). A vendor outage becomes a failed
  form submission for the visitor. Queue it (§7).

**Extending core screens via events.** To add fields to somebody else's publish form, mutate the
blueprint in a `*BlueprintFound` listener, scope it by route-name pattern, and **hide** rather than
remove fields when the user is not authorised
(observed in `aerni/statamic-advanced-seo`). Do not `array_replace_recursive` a foreign blueprint
array: it is order-dependent and silently clobbers a user's field with the same handle
(`reference/aerni__advanced-seo/src/Listeners/HandleContentSeoBlueprint.php:55-58`). Guard handle
collisions explicitly.

**Checkable**
- `bootstrap.redundant-registration` (no `$listen` beside `src/Listeners/`)
- Every public `handle*`/`__invoke` in `src/Listeners/` has a class-typed first parameter — *(manual)*
- One test per listener dispatching the real event — *(manual)*
- No network call inside a non-queued listener — *(manual)*

---

## 7. Queues and jobs

Anything that talks to a third party, processes a file, or loops over content goes on a queue.

- Jobs live in `src/Jobs/` and implement `ShouldQueue`.
- **Register serialisable domain objects.** Any Statamic object a job holds must be listed in
  `registerSerializableClasses([...])` inside `register()`, or it will not survive the queue round-trip
  (observed in `statamic/seo-pro`).
- **Give every job a public accessor for its payload** (`getParams()`), so tests can write
  `Queue::pushed(Job::class)->filter(...)` instead of counting blindly
  (observed in `spatie/statamic-responsive-images`).
- **Do not coordinate between workers through the cache.** `importer`'s `ImportItemJob` does
  read-modify-write on a `Cache::forever` key from concurrent workers
  (`reference/statamic__importer/src/Jobs/ImportItemJob.php:110-115`). Use batches, a database row, or
  a lock.
- **The default queue connection in a fresh Statamic install is `sync`.** If your feature is unusable
  on `sync`, say so in the README and detect it, rather than assuming a worker exists.
- **Restore global state in a `finally`.** Loops that mutate `Site::setCurrent()`, `app()->setLocale()`
  or `Date::setToStringFormat()` must restore them and flush `Blink`
  (observed in `statamic/ssg`).

**Checkable**
- Every class dispatched to a queue appears in `registerSerializableClasses` — *(manual)*
- Every `ShouldQueue` class has a public payload accessor — *(manual)*
- No `Cache::` read-modify-write inside a job — *(manual)*

---

## 8. Permissions and policies

Registration shape and the full facade surface: `ui-vocabulary.md` §8.3. Gating the UI: §8.4.

The standard:

```php
Permission::extend(function () {
    Permission::group('thing', __('thing::permissions.group'), function () {
        Permission::register('view things')
            ->label(__('thing::permissions.view'))
            ->description(__('thing::permissions.view_description'))
            ->children([
                Permission::make('edit things')->label(__('thing::permissions.edit')),
                Permission::make('delete things')->label(__('thing::permissions.delete')),
            ]);
    });
});
```

- **Always a named `Permission::group()`.** Ungrouped permissions land in a miscellaneous bucket in the
  role editor (observed in `statamic-rad-pack/runway`).
- **Hierarchy, not a flat list:** `view` → `edit` → `create`/`delete`
  (observed in `duncanmcclean/simple-commerce`).
- **`label()` and `description()` from `lang/` keys**, never bare English
  (observed in `aerni/statamic-advanced-seo`).
- **Register only permissions that apply to the configured feature set**, and gate routes, nav items,
  permissions and stores on the *same* expression so they cannot disagree
  (observed in `duncanmcclean/simple-commerce`).
- **A settings blueprint gives you `edit {package} settings` for free**
  (`reference/statamic__cms/src/Auth/CorePermissions.php:230`). A registered Utility gives you
  `access {handle} utility` for free, plus the middleware
  (observed in `aryehraber/statamic-logbook`). Do not re-register either.

### Authorization is server-side or it does not exist

**This is the one blocker in this document.** Every CP controller action that reads or writes must
carry `$this->authorize(...)`, a one-method `FormRequest` with `authorize()`, or `can:` middleware on
the route. Hiding a button in the template is not authorization.

`simple-commerce` is the cautionary case: `grep -rn "authorize\|->can(\|abort(403" src/Http/Controllers/CP/`
returns exactly one hit. `CouponController::store/update`, three `destroy` actions and
`ResendNotificationsController::__invoke` are reachable by any authenticated CP user — the last one
re-sends customer email on demand — while the Blade views dutifully gate the buttons and
`Permission::register('delete tax rates')` exists and is decorative
(observed in `duncanmcclean/simple-commerce`).

Preferred shape: one `FormRequest` per verb under `src/Http/Requests/CP/`, containing `authorize()`
and nothing else — validation belongs to the blueprint (§10 below and
observed in `statamic-rad-pack/runway`).

And every authorized route gets a **403 test** (§13).

### Policies

`src/Policies/` classes are **not** autoloaded — list them in `protected $policies`
(`AddonServiceProvider.php:399`). Follow core's shape: a `before($user)` super-user/`configure X`
shortcut, then one method per ability
(`reference/statamic__cms/src/Policies/CollectionPolicy.php:12-49`).

**Checkable**
- `bootstrap.cp-authorization` (BLOCKER)
- Every `Permission::register(` is inside a `Permission::group(` — *(manual)*
- Every permission has `->label(__(` — *(manual)*
- Every registered permission gates at least one route or nav item — *(manual)*
- A 403 test exists for every write route — see §13, *(manual)*

---

## 9. Settings screens

Do not build one. Name a blueprint `resources/blueprints/settings.yaml` and `bootSettingsBlueprint()`
(`AddonServiceProvider.php:825-836`) gives you the screen, the Tools nav entry and the
`edit {package} settings` permission (observed in `statamic-rad-pack/mailchimp`).

`mailchimp` still carries `resources/views/cp/config.blade.php` — a hand-rolled `<publish-form>` in
Blade posting to a route that does not exist, referenced by nothing
(observed in `statamic-rad-pack/mailchimp`). It is exactly the page the settings
blueprint replaced.

To add configuration to an *existing* core screen, use `Form::appendConfigFields()` /
`Fieldtype::appendConfigFields()` in `bootAddon()` rather than adding a page
(observed in `jacksleight/statamic-bard-texstyle`).

**Checkable**
- Blueprint named exactly `settings.yaml`; no `registerSettingsBlueprint(` call beside it — *(manual)*
- No CP route rendering a view whose blueprint is the settings blueprint — *(manual)*
- No orphan views: every file under `resources/views/` is referenced by a controller or `view()` call
  (observed in `statamic-rad-pack/mailchimp`) — *(manual)*

---

## 10. CP controllers — the PHP side

The UI half of this is `ui-vocabulary.md` §2 (page shells), §3 (listings), §4 (publish forms). The
server contract:

- **Extend core controllers**: `Statamic\Http\Controllers\CP\CpController`, `ActionController`,
  `PreviewController` (observed in `statamic-rad-pack/runway`).
- **Validate through the blueprint, not the request.**
  `$blueprint->fields()->addValues($request->all())->validator()->validate()`, then
  `->process()->values()`. Zero `$request->validate([` in a blueprint-driven CP controller
  (observed in `aerni/statamic-advanced-seo`, `statamic/importer`, `statamic-rad-pack/runway`). Non-blueprint endpoints — utilities, one-off actions — use a
  `FormRequest`; **never nothing** (observed in `aryehraber/statamic-logbook`).
- **Row-dependent validation belongs in the fieldtype's `extraRules()`**, not the controller
  (observed in `statamic/importer`).
- **Never build CP URLs by hand.** `cp_route()` / `cp_url()` in PHP; pass URLs to Vue as props. Vue must
  not concatenate `/cp/…` (observed in `aryehraber/statamic-logbook`, `statamic-rad-pack/runway`).
- **Bind domain objects with `Route::bind()`** in the provider, guarded with `Statamic::isCpRoute()`,
  throwing `Statamic\Exceptions\NotFoundHttpException` for unknown handles
  (observed in `statamic/seo-pro`, `statamic-rad-pack/runway`).
- **Resolve permissions server-side into boolean props.** No `can(` in Vue
  (observed in `statamic/seo-pro`).
- **Index and its JSON come from the same route**, branched on `$request->wantsJson()`, so the
  `<Listing>` `:url` and the page are never out of sync (observed in `statamic/seo-pro`).
- **Format listing cells server-side** through the blueprint's index fieldtypes:
  `$field->setValue($v)->setParent($item)->preProcessIndex()->value()` in a JsonResource — not in Vue
  (observed in `duncanmcclean/simple-commerce`, `statamic/seo-pro`).
- **A GET must never mutate.** `importer`'s edit page calls `Artisan::call('migrate')` while rendering
  (`reference/statamic__importer/src/Http/Controllers/ImportController.php:220-247`).

**Disagreement — Blade vs Inertia CP pages.** `simple-commerce` (R1) and `logbook` (R6, R10) build CP
screens as Blade views extending `statamic::layout` with `<ui-*>` elements. `advanced-seo`, `runway`,
`seo-pro` and `importer` build them as Inertia pages. **The studio picks Inertia pages.** Reasons: core
has no Blade CP pages left (`ui-vocabulary.md` §9.7, §2.10); Blade-in-Vue re-parses the DOM, which
already costs `logbook` two live bugs (self-closing `<ui-header/>` swallowing the page, camelCase props
never binding — observed in `aryehraber/statamic-logbook`); and `logbook`'s own
analysis concedes it is "outside the path core itself uses". `ui.legacy-blade-shell` enforces this.

**Checkable**
- `bootstrap.cp-authorization`, `ui.legacy-blade-shell`, `ui.cp-route-helper`, `ui.listing-component`,
  `ui.inertia-navigation`
- No `$request->validate(` in `src/Http/Controllers/CP/` — *(manual)*
- Every non-scalar `{param}` in `routes/cp.php` has a `Route::bind(` — *(manual)*
- No `can(` in `resources/js/` — *(manual)*

---

## 11. Multi-site

Silence here is a bug report later. Decide explicitly and write it in the README:

- **Is a stored value per-site or shared?** If per-site, the record needs a `site` column/key, the
  repository needs a site filter, and the CP needs the localisation switcher
  (`ui-vocabulary.md` §4.6).
- **`Site::setCurrent()` inside a loop must be restored in a `finally`, with `Blink::flush()`**
  (observed in `statamic/ssg`).
- **Test both.** A single-site test suite proves nothing about the multi-site path. `advanced-seo`'s
  site-scoped SEO sets and `ssg`'s `tests/Localized/GenerateTest.php` (a subclass overriding
  `$siteFixture`, observed in `statamic/ssg`) are the two workable patterns.
- **Do not derive context from the browser URL.** `mailchimp`'s `FormFieldsFieldtype.vue:48` reads
  `StatamicConfig.urlPath.split('/')[1]` to discover which form it is on
  (observed in `statamic-rad-pack/mailchimp`). Pass it through `preload()`.
- **RTL is part of multi-site.** Logical spacing utilities (`ms-`/`me-`/`ps-`/`pe-`/`start-`/`end-`),
  never `ml-`/`mr-`/`left-`/`right-` — core sets `dir` from `Statamic::cpDirection()`
  (`ui-vocabulary.md` §7).

**Checkable**
- Multi-site behaviour is stated in the README — *(manual)*
- At least one test runs against a multi-site fixture — *(manual)*
- No `\b(ml|mr|pl|pr|left|right)-\d` in `resources/js` — *(manual)*

---

## 12. Antlers tags, modifiers and the public API

Tag names, tag parameters, modifier names, config keys, facade methods, event constructor signatures
and published view paths are **the addon's semver contract**. Name them once, properly; changing any of
them is a major release.

### Tags

```php
namespace Vendor\Thing\Tags;

class Thing extends \Statamic\Tags\Tags
{
    protected static $handle = 'thing';       // {{ thing }}

    public function index() { … }             // {{ thing }}
    public function latest() { … }            // {{ thing:latest }}
    public function wildcard($method) { … }   // {{ thing:anything }}
}
```

Design rules:

- **One namespace per addon**, matching the slug. Do not squat a generic word.
- **Parameters are snake_case**, mirroring core's own tags (`{{ collection:blog limit="5" }}`).
  Read them with `$this->params->get('limit', 5)` — always with a default.
- **Return arrays/collections, not HTML.** Let the user's template do layout. If the tag must render,
  render a **publishable** view so the user can override it, and document that path as public API
  (observed in `spatie/statamic-responsive-images`).
- **Augment what you return.** Raw values in an Antlers context are a bug (dates, assets, Bard).
- **Pair every tag with a documented signature in the README/`DOCUMENTATION.md` and a test per
  documented parameter** (§13).
- **Modifiers** live in `src/Modifiers/`, take `($value, $params, $context)`, and must be pure and
  side-effect free. A modifier that queries or writes is a tag.
- **GraphQL / REST**: if you expose either, the field names are part of the same semver contract.

### Console commands

Autoloaded from `src/Commands/` (console only). Follow `ssg`, which is the reference
(observed in `statamic/ssg`):

- Signature `statamic:<handle>:<verb>` plus `use Statamic\Console\RunsInPlease;` so the command works
  under `php please` as well as `php artisan`.
- Every `{arg}`/`{--opt}` token carries an inline ` : ` description; every command has a
  `$description`.
- Inject the domain service in `__construct()`; `handle(): int` stays under ~40 lines and returns an
  explicit exit code.
- Long-running services emit output through `Partyline` — do not pass the command object or hold an
  `OutputInterface`.
- Result and exception value objects render themselves via `consoleMessage()`.
- Multi-part import/export commands get `--force` plus one `--only-<part>` per part, with matching
  flag names on both legs — `eloquent-driver`'s import and export flags disagree for the same domain,
  which breaks an operator's round-trip script
  (observed in `statamic/eloquent-driver`).
- No hand-written ANSI escapes for progress (`reference/statamic__ssg/src/Generator.php:243`). Use
  `withProgressBar()` or `$this->components->task()`.

**Checkable**
- Every documented tag parameter has a test — *(manual)*
- Every class in `src/Commands/` has `RunsInPlease`, a `$description` and `handle(): int` — *(manual)*
- Modifiers have no side effects — *(manual)*
- Tag/modifier renames appear only in major releases — *(manual)*

---

## 13. Error handling

The theme across the reference set is that addons fail *silently* where they should fail *visibly*, and
fail with a 500 where they should fail with a 404 or a validation message.

- **Never `catch` into silence.** `mailchimp` returns `[]` from `MailchimpField::callApi()` when the API
  key is missing, and every SFC does `.catch(() => { this.fields = [] })` — a missing key renders as an
  empty dropdown with no explanation (observed in `statamic-rad-pack/mailchimp`). Show
  an alert or an error state.
- **`match` on untrusted input needs a `default`.** `importer` matches on `Storage::mimeType()` in four
  places with no default: an unexpected type is an `UnhandledMatchError` → 500 instead of a validation
  message (observed in `statamic/importer`).
- **Decryption and decoding of user input is wrapped**, returning 404/422 rather than 500
  (observed in `aryehraber/statamic-logbook`).
- **404, consistently.** `seo-pro`'s `show()` uses `throw_unless(…, NotFoundHttpException::class)` while
  its `destroy()` returns a raw boolean from `Report::find($id)->delete()`
  (observed in `statamic/seo-pro`). Pick the guard and apply it to every action,
  especially destructive ones.
- **Index and preview rendering must be total.** Catch domain exceptions in `preProcessIndex()` and
  return a valid empty shape with the same keys as the success path — a listing must never 500 because
  one row is broken (observed in `spatie/statamic-responsive-images`).
- **Public APIs do not silently no-op.** `eloquent-driver`'s `with()` returns `$this` and discards the
  eager-load request (observed in `statamic/eloquent-driver`). Throw, or implement.
- **Ship no debug statements.** `dd()`, `dump()`, `ray()`, `console.log` reach a customer's production
  response. Gate verbose logging behind a config key — `collaboration`'s unconditional `debug()` wrapper
  spams every collaborator's console on every keystroke in a Bard field
  (observed in `statamic/collaboration`).
- **Sandbox any iframe rendering user-authored content** (`ui.unsandboxed-iframe`), and **confirm every
  destructive action** through core's confirmation modal, not `window.confirm`
  (`ui-vocabulary.md` §6; observed in `aryehraber/statamic-logbook`).

**Checkable**
- `code.debug-leftovers`, `ui.unsandboxed-iframe`, `ui.confirm-destructive`
- No `match` without `default` on request/filesystem input — *(manual)*
- `preProcessIndex()` bodies contain a `try`/`catch` returning the success shape — *(manual)*
- No empty `catch` blocks — *(manual)*

---

## 14. Upgrade scripts

Anything that has to happen once, at upgrade time, is an `UpdateScript` in `src/UpdateScripts/`.
Autoloaded (`AddonServiceProvider.php:628-639`); `$updateScripts` is only for classes living elsewhere.

```php
class MigrateConfigKeys extends \Statamic\UpdateScripts\UpdateScript
{
    public function shouldUpdate($newVersion, $oldVersion): bool
    {
        return $this->isUpdatingTo('3.0.0') && Schema::hasColumn('things', 'legacy');
    }

    public function update(): void { … }
}
```

Rules from `eloquent-driver` (observed in `statamic/eloquent-driver`) and
`simple-commerce` (R25):

- **`shouldUpdate()` combines a version gate with a live probe** (`Schema::hasTable/hasColumn`,
  `File::exists`, a config read). Never a version gate alone.
- **Migration stubs get a runtime timestamp** (`date('Y_m_d_His')`) when copied, so they always sort
  after the consumer's existing migrations — never a hard-coded one.
- **Stubs that alter an existing table repeat the probe internally.**
- **Every class in `src/UpdateScripts/` runs, and every class listed in `$updateScripts` exists.**
  `eloquent-driver` ships an orphaned `MoveConfig.php` that is referenced nowhere and will never run
  (observed in `statamic/eloquent-driver`).
- **Automate everything automatable, and the upgrade guide describes only what is left.** That is
  `simple-commerce`'s model: four update scripts rewrite the user's config with Proteus, and the guide
  covers the remainder.
- **Never run a migration from `bootAddon()`** — see §3.
- **Deprecations need an owner and a deadline.** `eloquent-driver` carries five parallel legacy-config
  branches whose comments say "lets remove this when we hit 3.0.0" while the repo is on 5.x
  (observed in `statamic/eloquent-driver`).

**Checkable**
- `ls src/UpdateScripts/*.php` basenames ⊆ classes that actually register — *(manual)*
- Every `shouldUpdate()` contains a `Schema::has*` / `File::exists` / config probe — *(manual)*
- Every `database_path('migrations/'…)` in `src/UpdateScripts/` uses `date('Y_m_d_His')` — *(manual)*
- Each major in the CHANGELOG has a matching upgrade guide — see
  `marketplace-readiness.md` — *(manual)*

---

## 15. The testing standard

The suite is what lets you accept a Statamic patch release without fear. It is also the single
thinnest area across all 13 reference addons: `collaboration`, `tabs` and `logbook` have **no tests at
all**, and every CP-heavy addon in the set has near-zero CP coverage.

### The base case

```php
namespace Vendor\Thing\Tests;

use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = \Vendor\Thing\ServiceProvider::class;
}
```

That is the entire bootstrap. `AddonTestCase`
(`reference/statamic__cms/src/Testing/AddonTestCase.php`) already does, for free:

- registers `StatamicServiceProvider`, `Inertia\ServiceProvider` and your provider, in the right order,
  plus the GraphQL provider when present;
- aliases the `Statamic` facade;
- builds a fake addon `Manifest` entry by reading your `composer.json` — so `Addon::get()` works;
- points every Stache store at `tests/__fixtures__/…` and disables the watcher;
- points Inertia's page-existence check at `resources/js/pages` and disables `ensure_pages_exist`;
- calls `withoutMix()` / `withoutVite()`;
- with `PreventsSavingStacheItemsToDisk`, redirects writes to `tests/__fixtures__/dev-null` and cleans
  up in `tearDown()`.

**Never hand-roll `getPackageProviders()`.** `grep -c 'getPackageProviders(' tests/` must be 0.
Hand-rolled Testbench bootstraps drift with every Statamic major; `AddonTestCase` does not
(observed in `spatie/statamic-responsive-images`).

### Framework

**PHPUnit is the default** — it is what core and `seo-pro` use, and `phpunit.xml` is required either
way. **Pest is permitted** if `tests/Pest.php` contains exactly `uses(TestCase::class)->in('.');`.
`bard-texstyle` ships Pest without that binding, so its tests run against plain
`PHPUnit\Framework\TestCase` and its carefully written Testbench bootstrap is dead code
(observed in `jacksleight/statamic-bard-texstyle`). Do not keep a `phpunit.xml`
claiming a PHPUnit 9.3 schema under a Pest 4 project
(observed in `aerni/statamic-advanced-seo`).

### `phpunit.xml`

Pin the environment in the file so no `.env.testing` is needed, and turn the strict flags on
(observed in `spatie/statamic-responsive-images`, `statamic/ssg`):

```xml
<phpunit executionOrder="random"
         failOnWarning="true" failOnRisky="true" failOnEmptyTestSuite="true"
         beStrictAboutOutputDuringTests="true">
    <testsuites><testsuite name="Tests"><directory suffix="Test.php">tests</directory></testsuite></testsuites>
    <source><include><directory>./src</directory></include></source>
    <php>
        <env name="APP_KEY" value="base64:…"/>
        <env name="APP_URL" value="http://localhost"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="CACHE_DRIVER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
    </php>
</phpunit>
```

Scope `<source>` to `./src` — `mailchimp` points coverage at `./app`, a Laravel-app path that does not
exist in a package (observed in `statamic-rad-pack/mailchimp`). Watch the
`suffix="Test.php"` trap: `runway` has a controller test file without the suffix, so restore-revision
is silently untested (observed in `statamic-rad-pack/runway`).

### What must be covered, in order of value

1. **Every CP endpoint, including the unauthorized case.** For each route in `routes/cp.php`: a 200 for
   a permitted user and a 403 (or `assertRedirect('/cp')`) for a denied one. For Inertia pages, assert
   the component name and prop shape:
   `->assertInertia(fn (Assert $p) => $p->component('thing::Index')->has('items'))`
   (observed in `statamic/importer`, `statamic/seo-pro`, `duncanmcclean/simple-commerce`). This is the gap in *every* reference addon — `advanced-seo`
   has ~130 test files and one CP controller test.
2. **Every promise the README makes.** If the README says it does X, a test asserts X. That is what
   "reliably solves the function it promises" means operationally.
3. **The fieldtype PHP side**: `preload()`, `preProcess()`, `process()`, `augment()`,
   `preProcessIndex()`, `extraRules()` — including the failure branch of `preProcessIndex()`
   (§13).
4. **Tags and modifiers**, one test per documented parameter, rendering real Antlers.
5. **Permissions**: each registered permission actually gates something.
6. **Listeners**: dispatch the real event, assert the effect (§6).
7. **Commands**: `$this->artisan(Cmd::class)->expectsOutput(…)->assertExitCode(0)`, including the
   precondition-not-met path (observed in `spatie/statamic-responsive-images`).
8. **Pure client-side logic** — diffing, debouncing, chunking, value transformation — with Vitest.
   Nine of thirteen reference addons ship zero JS tests while shipping 300–1,900 lines of Vue.

### Fixtures and determinism

- Fixtures live in `tests/__fixtures__/` — the path `AddonTestCase` already points the Stache at.
- Copy binary fixtures into a temp dir in `setUp()`, delete and recreate that dir in **both** `setUp()`
  and `tearDown()`, and expose them through named accessors rather than hard-coded path strings
  (observed in `spatie/statamic-responsive-images`).
- Load the addon's **real** config file rather than restating defaults:
  `$app['config']->set('thing', require __DIR__.'/../config/thing.php');` (R3).
- Snapshot for *shape*; use `expect()->toBe()` for any value that is the thing under test. Strip
  non-deterministic fragments through one named helper, defined once (R8–R9, §10.14).
- Fake every outbound HTTP call. Never inject a live API key into CI — `mailchimp` does, and the suite
  only passes because nothing exercises the network
  (observed in `statamic-rad-pack/mailchimp`).
- Annotate regression tests with the issue URL they close (R15).

**Checkable**
- `testing.suite` (BLOCKER), `testing.addon-testcase`, `testing.stache-isolation`
- `grep -c getPackageProviders tests/` == 0 — *(manual)*
- Every route name in `routes/cp.php` appears in `tests/` — *(manual)*
- ≥1 `assertForbidden()`/`assertStatus(403)`/`assertRedirect('/cp')` per write route — *(manual)*
- Pest projects bind `uses(TestCase::class)->in('.')` — *(manual)*
- `phpunit.xml` `<source>` points at `./src` — *(manual)*
- No live API secret in a CI test step — *(manual)*

---

## 16. CI matrix

One workflow, `.github/workflows/tests.yml`, triggered on `push` to `main`/`*.x` **and on
`pull_request`**. `collaboration` has no PR-triggered workflow at all, so a PR that breaks the Vue
build is caught only when someone tags a release
(observed in `statamic/collaboration`).

The matrix must actually test what `composer.json` promises — three axes:

```yaml
strategy:
  fail-fast: false
  matrix:
    php: ['8.3', '8.4']
    laravel: ['12.*', '13.*']
    stability: [prefer-lowest, prefer-stable]
```

- **`prefer-lowest` is not optional.** Without it the declared floor (`statamic/cms: ^6.25`) is never
  verified. `spatie/responsive-images` runs only `--prefer-stable`
  (observed in `spatie/statamic-responsive-images`).
- **The PHP floor in the matrix must equal the `require.php` lower bound**
  (observed in `statamic-rad-pack/mailchimp`).
- **Pin the Laravel version before resolving:**
  `composer require "illuminate/contracts:${{ matrix.laravel }}" --no-update`, then `composer update`
  (observed in `statamic/ssg`).
- **Declare every PHP extension the tests need** in `setup-php` — snapshot output depends on them
  (observed in `spatie/statamic-responsive-images`).
- **`composer install` runs before `npm ci`**, because `@statamic/cms` resolves from
  `file:./vendor/statamic/cms/resources/dist-package`
  (observed in `statamic/importer`).
- **Every npm script a workflow calls must exist in `package.json`.**
  `spatie/responsive-images`' `build-assets.yml` runs `yarn run production`, which is not defined — so
  its committed `dist/` is maintained by hand, silently
  (observed in `spatie/statamic-responsive-images`).
- **If `dist/` is committed, CI rebuilds it and fails on a diff:**
  `npm ci && npm run build && git diff --exit-code dist`. Otherwise a source change ships green while
  users load a stale bundle (observed in `eminos/statamic-tabs`, `jacksleight/statamic-bard-texstyle`).
- A nightly `schedule:` run catches upstream breakage between PRs
  (observed in `statamic/ssg`).

### Workflow hardening

Non-negotiable, and consistent across `collaboration`, `runway`, `simple-commerce`, `importer`,
`seo-pro` and `ssg`:

- top-level `permissions: {}`, narrowed per job with an inline justification;
- every `uses:` pinned to a **40-character commit SHA** with the human version in a trailing comment;
- `persist-credentials: false` on `actions/checkout`;
- untrusted text passed through `env:` and referenced as `"$VAR"`, never interpolated into `run:`;
- a `zizmor` job linting the workflows;
- Dependabot on `github-actions` **and** `composer` **and** `npm` — `collaboration` watches only
  Actions despite a committed `package-lock.json`
  (observed in `statamic/collaboration`).

**Checkable**
- `testing.ci`, `testing.dist-verification`
- Workflow triggers include `pull_request` — *(manual)*
- Matrix declares `php`, `laravel` and a `prefer-lowest` stability axis — *(manual)*
- All `uses:` lines match `@[0-9a-f]{40}` — *(manual)*
- `permissions:` present in every workflow; `persist-credentials: false` on checkout — *(manual)*
- Every `npm run <x>` in a workflow exists in `package.json.scripts` — *(manual)*

---

## 17. Static analysis

Not present in a single reference addon. `spatie/responsive-images` calls its absence "the biggest
missing safety net" for a package doing heavy array-shape juggling
(observed in `spatie/statamic-responsive-images`). The studio adopts it as a default
rather than inheriting the ecosystem's gap.

- **PHPStan + Larastan at level 5**, `phpstan.neon` scoping `paths: [src]`, with a committed
  `phpstan-baseline.neon` for anything inherited. A CI job runs `vendor/bin/phpstan analyse --error-format=github`.
- **Level 5 is the floor, not the target.** Raise it and shrink the baseline; never regenerate the
  baseline to make a red build green.
- **Types on the public surface.** Every method in `src/Contracts/`, `src/Facades/` and every
  controller action carries parameter and return types. Docblock return types must be honest —
  `@return array` on a method returning `null` is a lie the tooling will believe
  (observed in `eminos/statamic-tabs`).
- **Delete no-op overrides.** A `preProcess()` whose body is `return $data;` is noise (R24).
- **`class_exists()` guards on every optional integration.** Any reference to a class from a
  `require-dev` or `suggest` package must be inside such a branch, or an absent dev dependency fatals
  a production site (observed in `duncanmcclean/simple-commerce`, `statamic/ssg`). Optional integrations belong in `require-dev` + `suggest`, never `require`
  (observed in `aerni/statamic-advanced-seo`).
- **Depend on what you import.** `ssg` imports `wilderborn/partyline` in five files and does not
  require it — it resolves only because `statamic/cms` happens to pull it in transitively
  (observed in `statamic/ssg`).
- **JS**: ESLint is optional; Vitest coverage of pure logic (§15) matters more.

**Checkable**
- `release.installable` (runtime deps installable by the buyer)
- `phpstan.neon` exists and a CI job runs it — *(manual)*
- Every `suggest`ed/`require-dev` class reference is inside a `class_exists(` branch — *(manual)*
- Every imported vendor namespace appears in `require` or `require-dev` — *(manual)*

---

## 18. Pint

```json
{ "preset": "laravel" }
```

`pint.json` at the package root, `"preset": "laravel"`, and **at most five deliberate rule overrides**
(observed in `statamic/ssg`). Matching core's formatting means a diff between your addon
and core is a diff of substance.

- Scope Pint to the **whole package**, not just `src/`. `spatie/responsive-images` scopes its
  formatter to `src` only, leaving 2,000 lines of inconsistently formatted tests
  (observed in `spatie/statamic-responsive-images`).
- **CI runs `pint --test` (or the action's `testMode: true`) and fails the build.**

**Disagreement — auto-fix vs check-and-fail.** `advanced-seo` and `spatie/responsive-images` run a
formatter on every push and push a "Fix styling" commit back to the branch
(observed in `aerni/statamic-advanced-seo`, `spatie/statamic-responsive-images`). `ssg` runs Pint in `testMode` on PRs with no commit step
(observed in `statamic/ssg`). **The studio picks check-and-fail.** Auto-fix rewrites
contributors' branches, can race with a local push, produces noise commits, and — decisively — means
the repo never actually enforces "formatted before merge", it only repairs afterwards.

- **Pin the Pint version identically** in `composer.json` `require-dev` and in the lint workflow
  (observed in `statamic/seo-pro`).
- Ship `.editorconfig` alongside it.

**Checkable**
- `code.pint`
- `pint.json` `rules` has ≤5 keys — *(manual)*
- The style workflow contains no `git push`/commit step — *(manual)*
- Pint version in `composer.json` == version in the workflow — *(manual)*

---

## 19. Localisation

Half-localised is the most common state in the reference set, and it is worse than not localised at
all: it looks finished and is not.

- **Ship `lang/en/*.php`.** Namespaced keys without a `lang/` directory render the raw key in the CP.
  `spatie/responsive-images` wraps every string in `__()` and ships no `lang/` at all, so the wrapping
  buys a host app nothing (observed in `spatie/statamic-responsive-images`);
  `logbook` and `collaboration` do the same.
- **`lang/`, not `resources/lang/`.** Core checks `lang/` first
  (`AddonServiceProvider.php:490-517`). Exactly one lang root — `seo-pro` ships a byte-identical
  duplicate under a directory it never loads
  (observed in `statamic/seo-pro`).
- **Namespaced keys for addon copy** (`__('thing::messages.saved')`), **bare core keys for verbs core
  already translates** (`__('Save')`, `__('Edit')`, `__('Delete')`). A namespaced key whose English
  value duplicates a core value is a bug (observed in `aerni/statamic-advanced-seo`).
- **Wrap everything, including the strings that are easy to forget**: empty states, flash messages,
  toast bodies built in plain `.js` files, confirmation text, validation messages, permission labels
  and descriptions, `configFieldItems()` `display`/`instructions`, and JSON error responses.
  `collaboration` localises its SFCs and leaves every toast in `Workspace.js` as a raw English template
  literal (observed in `statamic/collaboration`); `seo-pro` `__()`-wraps everything
  except one error message rendered directly in a modal (observed in `statamic/seo-pro`).
- **Validation closures translate:** `$fail('thing::validation.key')->translate()`
  (observed in `statamic/importer`).
- **Utility `->title()`/`->description()` must be `__()`-wrapped**, because those exact strings become
  the permission label and description (observed in `aryehraber/statamic-logbook`).
- English is the source language and lives in the repo. Translations are contributions, not a
  prerequisite for release.

**Checkable**
- `code.translation-files`, `ui.translated-strings`
- Exactly one lang root — *(manual)*
- No bare English literal in `'display' =>` / `'instructions' =>` / `->with('success', …)` — *(manual)*
- Every `__('thing::…')` key resolves in `lang/en/` — *(manual)*

---

## Appendix: the pre-commit loop

```bash
php <studio>/tools/addon-lint/bin/addon-lint . -v          # green at --fail-on=major
composer test
vendor/bin/pint --test
vendor/bin/phpstan analyse
npm run build && git diff --exit-code dist                 # if dist/ is committed
```

Green here is the entry gate, not the finish line. The linter cannot tell you whether the tests cover
the README's promises — that is the `statamic-addon-audit` skill, and
[`marketplace-readiness.md`](./marketplace-readiness.md) is the gate that follows.
