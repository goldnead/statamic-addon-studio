---
name: statamic-addon-ui
description: Build or fix a Statamic 6 addon's Control Panel UI so it is indistinguishable from core. Use whenever working on any CP surface of a Statamic addon — a CP page, listing, publish form, fieldtype, widget, utility, settings screen, nav item, modal, or the addon's CSS/Vite build. Also use when reviewing a CP screen that "looks off", "doesn't feel native", or was built for Statamic 5.
---

# Statamic 6 addon UI

The goal is not "looks similar". The goal is that a user cannot tell which screens
Statamic shipped and which the addon did.

## Before you write any UI code

Read `standards/ui-vocabulary.md` in the Statamic Addon Studio
(`/Users/adriangoldner/Documents/WebDev/statamic-addon-studio/standards/ui-vocabulary.md`).
It was extracted directly from `statamic/cms` 6.x and is the authority. Read the section you need,
not the whole file:

| You are building | Read |
|---|---|
| Anything at all | §0 the five facts |
| A CP page | §2 page shell patterns (§2.6 index, §2.7 form, §2.8 settings) |
| A table of records | §3 listings |
| A blueprint-driven form | §4 publish forms |
| A fieldtype | §5 fieldtypes, §5.6 minimal complete example |
| Modals, toasts, stacks | §6 interaction primitives |
| Anything with colour or spacing | §7 design tokens |
| A nav entry or permission | §8 nav & permissions |
| A review of existing UI | §9 antipatterns, then run `addon-lint --category=ui` |

The component catalogue is §1 — around 130 components. Do not guess a component's props;
look it up. Core's Storybook at <https://ui.statamic.dev> is the canonical rendered documentation.

## The five facts

1. **The CP is an Inertia 2 + Vue 3 SPA.** `Statamic::layout()` does not exist any more and core has
   no Blade CP pages left. A new screen is an Inertia page.
2. **Tailwind 4, configured from CSS.** The root `tailwind.config.js` in core is a dead v3 leftover —
   do not read it, do not copy it.
3. **Every UI component is globally registered as a `ui-*` kebab tag** and importable from
   `@statamic/cms/ui`. Prefer the explicit import in addon SFCs for IDE autocomplete.
4. **`@statamic/cms` is a shim over `window.__STATAMIC__`, not a bundle.** Its Vite plugin rewrites
   `import … from 'vue'` to `window.Vue`. Omit the plugin and you ship a second Vue instance:
   provide/inject silently returns null and the publish context breaks.
5. **Core states the contract itself:** *"if you need a card, don't use
   `<div class="bg-white p-4 rounded border shadow-sm">`, use the `<ui-card>` component."*

## The build

Non-negotiable, and identical across every v6 reference addon:

```js
// vite.config.js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        statamic(),
        tailwindcss(),
        laravel({
            input: ['resources/js/cp.js', 'resources/css/cp.css'],
            publicDirectory: 'dist',
            hotFile: 'dist/hot',
        }),
    ],
});
```

```json
// package.json — @statamic/cms comes from the installed vendor directory,
// so `composer install` must run before `npm install`.
"devDependencies": {
    "@statamic/cms": "file:./vendor/statamic/cms/resources/dist-package"
}
```

```php
// The provider registers assets through $vite. Never $scripts / $stylesheets.
protected array $vite = [
    'hotFile' => __DIR__.'/../dist/hot',
    'publicDirectory' => 'dist',
    'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
];
```

The three `$vite` values must byte-match `laravel()` in the Vite config.

CSS entry starts with the Statamic token layer, never bare Tailwind:

```css
@import "@statamic/cms/tailwind.css";
@source "../js";
```

Consumers install with Composer and have no Node toolchain, so built assets must reach them:
either commit `dist/build` plus its manifest **and** add a CI job that rebuilds and fails on a diff,
or fetch the artefact from the GitHub release via `extra.download-dist` +
`pixelfear/composer-dist-plugin`. Never commit the hot file.

## Decision rules

**Building a CP screen** → Inertia page. Register the route inside `Utility::register()` when it is a
utility (you get the nav entry, the permission and `can:access {handle} utility` middleware for free),
otherwise `routes/cp.php` plus an explicit `can:` middleware. Breadcrumbs are derived server-side from
the active nav item, so registering `Nav::extend` is what earns them.

**Showing records in a table** → core's `<Listing>`. Feeding it correctly is the work: base64-encoded
`filters`, `meta.columns` on *every* response, `actionUrl` (no `actionUrl` ⇒ no checkboxes or bulk
actions), `preferencesPrefix` (none ⇒ no saved views or persisted columns). A hand-built `<table>`
loses search, filters, presets, column customisation, bulk actions, keyboard shortcuts, sticky
headers, drag reordering and pagination — and is visibly not the Entries screen.

**Addon settings** → no Vue at all.
`Statamic\CP\PublishForm::make($blueprint)->asConfig()->submittingTo($route)` is `Responsable` and
renders the shared settings page.

**A fieldtype** → §5.6 has the minimal complete example. Two legal shapes:

- `<script setup>` + `Fieldtype.use(emit, props)` — then `defineExpose(expose)` is **mandatory**;
  omit it and field actions plus replicator previews die silently.
- Options API + `FieldtypeMixin`.

Either way: bind `:read-only="isReadOnly"`, `:name`, `:id`; re-emit `focus`/`blur`; emit values only
through `update()` / `updateDebounced()`. Listing columns register under the *different* suffix
`{handle}-fieldtype-index`.

**Mutating anything** → `router.post()` from `@statamic/cms/inertia`, not axios. The Inertia router
drives the progress bar, flash toasts, the dirty-state guard and back-button behaviour.

**Unsaved changes** → `Statamic.$dirty` already owns `beforeunload`, the Inertia `before` hook and
`popstate`. A second handler produces two stacked prompts.

**Colour** → core colours are runtime-themeable through `--theme-color-*`. `bg-white`, `bg-indigo-600`
and hex literals are not, so they drift the moment a user re-themes. Use `bg-content-bg`,
`bg-body-bg`, `bg-primary`, `text-gray-900`, `border-content-border`. Dark mode is **class-based** and
always available: every surface needs its `dark:` pair.

**Width** → `max-w-page` (`--max-width-page: 85rem`), never `max-w-7xl` or `container mx-auto`. The
header's MaxWidthButton lets the user toggle full width, and a custom container ignores it.

**Icons** → a *name* from the 548-icon set, or your own set via `Icon::register()`. Never inline SVG.

**Page-level actions** → wrap the primary action in `<CommandPaletteItem>`. Every core action is.

**Strings** → `__()`, always. Raw English in a German CP is an instant tell.

## Never

- Fork a core component. Every UI-drift incident in the reference set traces back to a copy.
  If an extension point is missing, open a core PR — do not paste the file.
- Reach out of your component: `closest()`, `MutationObserver`, `document.querySelector('[data-ui-…]')`.
- Style core internals by `[data-ui-*]`, or reach for `!important`.
- Build your own modal. Core overlays participate in the portal stack, the esc-key binding stack and
  FocusScope trapping; a bespoke `fixed inset-0` steals esc from its parent and z-fights.
- Use v5 class names (`.btn`, `.card`, `.publish-fields`, `.flexy`). They render unstyled in v6.
- Call `Statamic.$fieldtypes` or `Statamic.componentExists()`. Both are removed.

## Verify before claiming done

```bash
php <studio>/tools/addon-lint/bin/addon-lint <addon-path> --category=ui -v
```

A clean UI category is the floor, not the ceiling — the linter cannot see layout, spacing or whether
the screen actually feels like core. Install the addon into the studio playground
(`<studio>/playground`, Statamic 6.26, superuser `studio@local` / `studio-local-password`) and compare
the screen side by side with the nearest core equivalent: Entries for a listing, an entry publish form
for a form, a core utility for a settings screen. Compare the rendered HTML and computed styles, not
just a screenshot.
