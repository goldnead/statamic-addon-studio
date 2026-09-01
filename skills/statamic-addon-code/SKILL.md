---
name: statamic-addon-code
description: Write or refactor the PHP and architecture side of a Statamic 6 addon — service provider, bootstrapping, config, repositories, facades, events, permissions, tags/modifiers, console commands, upgrade scripts and the test suite. Use when building a new Statamic addon, restructuring an existing one, or reviewing its code quality before a Marketplace release.
---

# Statamic 6 addon code

Statamic's own conventions are the standard. An addon whose code reads like core code is one that
another Statamic developer can pick up, and one that survives the next minor release.

Full standard: `standards/code-standard.md` in the Statamic Addon Studio
(`~/Documents/WebDev/statamic-addon-studio/` on the Mac, `~/projects/statamic-addon-studio/` on goldneros-host). Read it for the section you are
working in. This skill is the decision layer above it.

## Declare almost nothing

The single most common structural mistake is a service provider that hand-registers what core already
discovers. `AddonServiceProvider` autoloads, by convention:

```
src/Fieldtypes/   src/Tags/       src/Modifiers/   src/Actions/
src/Listeners/    src/Policies/   src/Commands/    src/Scopes/    src/Widgets/
routes/cp.php     routes/web.php  routes/actions.php              config/
```

Listeners are wired by reflecting the first parameter type of their `handle*` methods. Runway declares
*one* property; Advanced SEO declares three. An explicit array silently goes stale the moment someone
adds a class, which surfaces as "my fieldtype doesn't show up".

Express `bootAddon()` as a fluent chain of single-purpose methods returning `$this`:

```php
public function bootAddon()
{
    $this->bootPermissions()
        ->bootNav()
        ->bootListeners()
        ->bootSettings();
}
```

## The rules that matter

**Config.** Core auto-merges and auto-publishes `config/`. If you publish manually, you must also
merge manually — a published-but-unmerged config returns `null` on every site that did not publish it,
so the addon breaks precisely for the users who did nothing wrong.

**Authorization.** Every CP write route needs a real guard: `$this->authorize(...)` in the controller,
or `can:` middleware on the route. Hiding the button in the template is not authorization. This is not
theoretical — one of the largest third-party addons in the reference set leaves its store, update and
destroy routes open to any authenticated CP user, including an endpoint that re-sends customer email.
Routes registered inside `Utility::register()` inherit `can:access {handle} utility` and are covered.

**Never fork core.** Copying a core class or component freezes it at the version you copied. Every
drift incident found across 13 reference addons traces back to a copy. If the extension point you need
does not exist, open a core PR.

**Public API.** Tags, modifiers and facades are the contract site developers build on. Design them
once, document the parameters, and treat a change as semver-major. Antlers tag parameters are the part
users actually touch — bad names there cost more than bad names in `src/`.

**Multi-site.** If the addon stores content, decide explicitly whether values are per-site or shared,
and test both. Silence here is a bug report later.

**No debug leftovers.** `dd()`, `dump()`, `ray()`, `console.log` — they reach a customer's production
response.

## Testing

The suite is what lets you accept a Statamic patch release without fear.

```php
// tests/TestCase.php
class TestCase extends \Statamic\Testing\AddonTestCase
{
    use \Statamic\Testing\Concerns\PreventSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;
}
```

Hand-rolled Testbench setups drift with every Statamic major; `AddonTestCase` does not.
`PreventSavingStacheItemsToDisk` is what stops a test run from leaving content behind and making the
next run's result depend on the previous one.

Cover, in this order of value:

1. **CP endpoints** — including the unauthorized case for every write route. This is the thinnest
   area across the whole reference set and the one that hurts most.
2. **The promise in the README.** Every documented behaviour gets a test. That is what "reliably
   solves the function it promises" means in practice.
3. Fieldtype PHP side: `preProcess`, `process`, `augment`, `preProcessIndex`.
4. Tags and modifiers, with their documented parameters.
5. Permissions: each registered permission actually gates something.

CI runs the matrix the composer constraints promise — both ends of the PHP and Laravel range, not one
combination. If `dist/` is committed, CI must rebuild it and fail on a diff; otherwise a source change
ships green while users load a stale bundle.

## Verify before claiming done

```bash
php <studio>/tools/addon-lint/bin/addon-lint <addon-path> --category=code,structure,bootstrap,testing -v
composer test
vendor/bin/pint --test
```

Report what actually ran. A rule the linter cannot express — whether the tests cover the README's
promises — still needs your judgement, so say explicitly which promises are untested.
