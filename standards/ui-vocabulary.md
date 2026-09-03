# Statamic 6 — Native Control Panel UI Vocabulary

**Source of truth:** `statamic/cms` branch `6.x` @ `4bad1c1`, read at
`/Users/adriangoldner/Documents/WebDev/statamic-addon-studio/reference/statamic__cms`.
All `path:line` citations below are relative to that directory and were read directly.

This document defines what "native" means in the Statamic 6 CP. Everything an addon ships is
measured against it.

---

## 0. The five facts that decide everything

1. **The CP is an Inertia 2 + Vue 3 SPA.** `composer.json` requires `inertiajs/inertia-laravel: ^2.0`;
   `package.json` has `@inertiajs/vue3: ^2.1.11`. The whole app is booted in
   `resources/js/bootstrap/statamic.js:203-241` via `createInertiaApp`.
   `src/Http/Middleware/CP/HandleInertiaRequests.php:18` — `public const ROOT_VIEW = 'statamic::layout';`.
   There is exactly **one** Blade layout left (`resources/views/layout.blade.php`, 31 lines) and it
   only renders the Inertia root:
   ```blade
   <div id="statamic" data-page="{{ json_encode($page ?? Statamic::nonInertiaPageData()) }}">
   ```
   `grep -rn "@extends" resources/views` → **zero hits.** `grep -n "layout" src/Statamic.php` → **zero hits.**
   **`Statamic::layout()` no longer exists.** Any addon still doing `@extends('statamic::layout')` is v5 code.

2. **Tailwind CSS 4, CSS-first config.** `package.json` devDeps: `tailwindcss: ^4.1.8`,
   `@tailwindcss/vite: ^4.1.8`; `vite.config.js` registers `tailwindcss()` and the CSS entry is
   `resources/css/app.css`. The root **`tailwind.config.js` is a dead v3 leftover** — it is never
   referenced by `vite.config.js`, has no `@config` import in `app.css`, and its palette
   (`gray-750`, `dark-blue`, `blue.DEFAULT: #43a9ff`) does not match what components actually use
   (`gray-925`, `gray-850`, `primary`, `shadow-ui-sm`). **Do not read it. Do not copy it.**

3. **All UI components are globally registered as `ui-*` kebab tags.** `resources/js/bootstrap/ui.js:1-11`:
   ```js
   const components = await import('@ui');
   for (const [name, component] of Object.entries(components)) {
       app.component(`ui-${name.replace(/([a-z])([A-Z])/g, '$1-$2').toLowerCase()}`, component);
   }
   ```
   So `Button` → `<ui-button>`, `CardListItem` → `<ui-card-list-item>`, `PublishContainer` →
   `<ui-publish-container>`, `ListingCustomizeColumns` → `<ui-listing-customize-columns>`.

4. **Addons consume everything through the `@statamic/cms` npm package**, which is a thin shim over
   `window.__STATAMIC__` — it does **not** bundle Vue or the design system.
   `packages/cms/package.json` exports `.`, `./ui`, `./api`, `./inertia`, `./bard`, `./save-pipeline`,
   `./vite-plugin`, `./tailwind.css`. `resources/js/index.js:21-22` sets
   `window.Statamic` and `window.__STATAMIC__`. `packages/cms/src/ui.js` destructures `__STATAMIC__.ui`.
   From the repo's own `CLAUDE.md:86-90`:
   > *"Code needs to be in the `window` object to prevent addon bundles from re-including our code,
   > and from needing to recompile our source files."*

5. **The design-system contract, stated by core itself** — `resources/js/stories/Overview.mdx`:
   > *"When building custom areas of the Control Panel, you should aim to use the UI components as
   > much as possible… You can treat these components like an extension of HTML itself.
   > For example, if you need a card, don't use `<div class="bg-white p-4 rounded border shadow-sm">`,
   > use the `<ui-card>` component!"*

   Underlying stack: **reka-ui** (headless primitives) + **cva** (variants) + **tailwind-merge**
   (class merging). Confirmed in `resources/js/components/ui/Button/Button.vue:2-6` and Overview.mdx.

---

## 1. Component catalogue

Complete export list: `resources/js/components/ui/index.js` (130 lines) — mirrored 1:1 for addons in
`packages/cms/src/ui.js`. A vitest guards that the two lists match:
`resources/js/tests/Package.test.js:259-267`.

Import styles (both valid, per `Overview.mdx`):
```vue
<!-- global, works in Vue templates -->
<ui-card><ui-heading text="A lovely card" /><ui-button text="Click me" /></ui-card>

<!-- explicit, gives IDE autocomplete — preferred in addon SFCs -->
<script setup>import { Card, Heading, Button } from '@statamic/cms/ui';</script>
```

Legend: props are the real `defineProps` entries. `[…]` = enum options as documented in the source
JSDoc. Slots listed are real `<slot>` elements.

### 1.1 Layout & page structure

| Vue | Tag | Key props | Slots | Purpose |
|---|---|---|---|---|
| `Header` | `<ui-header>` | `icon`, `title` | `default`, `actions`, `title` | **The** CP page header (h1 + primary actions). `Header.vue:13` renders `<header class="… py-6 md:py-8" data-ui-header>` |
| `Heading` | `<ui-heading>` | `text`, `level`, `size` [`base`,`lg`,`xl`,`2xl`], `icon`, `href`, `target` | `default` | Section heading |
| `Subheading` | `<ui-subheading>` | `text`, `size` [`sm`,`base`,`lg`,`xl`], `icon` | `default` | Secondary heading |
| `Text` | `<ui-text>` | `text`, `as` (def `span`), `size` [`xs`,`sm`,`base`,`lg`], `variant` [`default`,`strong`,`subtle`,`code`,`danger`,`success`,`warning`] | `default` | Body copy |
| `Description` | `<ui-description>` | `text` | `default` | Muted explanatory text |
| `Panel` | `<ui-panel>` | `heading`, `subheading` | `default`, `header-actions` | Grey rounded container, `mb-8`. `Panel.vue:17-20` |
| `PanelHeader` | `<ui-panel-header>` | `title` | `default` | Standalone panel header |
| `PanelFooter` | `<ui-panel-footer>` | — | `default` | |
| `Card` | `<ui-card>` | `inset`, `variant` [`default`,`flat`] | `default` | White content surface — goes **inside** a Panel |
| `CardPanel` | `<ui-card-panel>` | `heading`, `subheading` | `default` | Panel+Card combo |
| `CardList` / `CardListItem` | `<ui-card-list>` / `<ui-card-list-item>` | `heading`, `subheading` / — | `default` | List-style card |
| `Separator` | `<ui-separator>` | `text`, `variant` [`line`,`dots`], `vertical` | — | |
| `Widget` | `<ui-widget>` | `title`, `icon`, `href` | `default`, `actions`, `footer` | Dashboard widget shell — see `widget.vue.stub` |
| `AuthCard` | `<ui-auth-card>` | `icon`, `title`, `description` | `default` | Login/outside-CP card |
| `SplitterGroup` / `SplitterPanel` / `SplitterResizeHandle` | `<ui-splitter-group>` etc. | `direction`; `collapsible`, `collapsedSize`, `minSize` | `default` | Resizable split panes |

### 1.2 Actions & navigation

| Vue | Tag | Key props | Slots | Purpose |
|---|---|---|---|---|
| `Button` | `<ui-button>` | `text`, `variant` [`default`,`primary`,`danger`,`filled`,`ghost`,`ghost-pressed`,`subtle`,`pressed`], `size` [`2xs`,`xs`,`sm`,`base`,`lg`], `icon`, `iconAppend`, `iconOnly`, `inset`, `loading`, `round`, `disabled`, `href`, `target`, `as`, `type` | `default` | Renders Inertia `<Link>` when `href` is internal, `<a>` for `_blank`, else `<button>` (`Button.vue:43-49`) |
| `ButtonGroup` | `<ui-button-group>` | `overflow` [`stack`,`gap`], `orientation`, `gap`, `justify` | `default` | Segmented button bar |
| `Dropdown` | `<ui-dropdown>` | `align` [`start`,`center`,`end`], `side` [`top`,`bottom`,`left`,`right`], `offset` (5) | `default`, `trigger` | reka-ui `DropdownMenuRoot`. Default trigger = ghost `dots` icon button |
| `DropdownMenu` | `<ui-dropdown-menu>` | — | `default` | Grid wrapper for items |
| `DropdownItem` | `<ui-dropdown-item>` | `text`, `icon`, `href`, `target`, `as`, `variant` [`default`,`destructive`] | `default` | |
| `DropdownLabel` / `DropdownSeparator` / `DropdownHeader` / `DropdownFooter` | `<ui-dropdown-…>` | `text`; —; `icon`,`appendIcon`,`appendHref`,`text`; `href`,`icon`,`text` | `default` | |
| `Context*` | `<ui-context>`, `<ui-context-menu>`, `<ui-context-item>`, `<ui-context-label>`, `<ui-context-separator>`, `<ui-context-header>`, `<ui-context-footer>` | same shape as Dropdown | `default`, `trigger` | Right-click menu — wraps reka-ui `ContextMenu*` |
| `Tabs` / `TabList` / `TabTrigger` / `TabContent` | `<ui-tabs>` etc. | `modelValue`, `unmountOnHide`, `dir`; —; `text`,`name`; `name` | `default` | Generic tabs (not publish tabs) |
| `Pagination` | `<ui-pagination>` | `resourceMeta` **(required)**, `perPage`, `showTotals`, `showPageLinks`, `showPerPageSelector`, `scrollToTop` | — | Reads Laravel paginator `meta` |
| `CommandPaletteItem` | `<ui-command-palette-item>` | `category`, `icon`, `text` (String\|Array), `url`, `action`, `when`, `badge`, `keys`, `trackRecent`, `prioritize`, `openNewTab` | `default` (scoped `{text,url}`) | Registers a palette entry **and** renders the button; auto-removes on unmount |
| `DocsCallout` | `<ui-docs-callout>` | `topic` **(req)**, `url` **(req)** | — | Bottom-of-page docs link |

### 1.3 Form controls

All of these are the *raw* controls. Inside a publish form you almost never use them directly —
you write a fieldtype (§5) and let `PublishField` wrap it.

| Vue | Tag | Key props | Emits / Slots |
|---|---|---|---|
| `Field` | `<ui-field>` | `label`, `instructions`, `instructionsBelow`, `error`, `errors`, `required`, `badge`, `inline`, `fullWidthSetting`, `disabled`, `readOnly`, `id`, `dir` | slots `default`, `actions`, `label` |
| `Label` | `<ui-label>` | `text`, `for`, `badge`, `required` | `default` |
| `ErrorMessage` | `<ui-error-message>` | `text` | `default` |
| `Input` | `<ui-input>` | `modelValue`, `type`, `size` [`xs`,`sm`,`base`,`lg`], `variant` [`default`,`filled`], `placeholder`, `icon`, `iconPrepend`, `iconAppend`, `prepend`, `append`, `clearable`, `copyable`, `viewable`, `limit`, `loading`, `badge`, `focus`, `required`, `disabled`, `readOnly`, `id`, `inputAttrs`, `inputClass` | `update:modelValue`; slots `prepend`,`append`; exposes `focus()`, `select()` |
| `InputGroup` / `InputGroupPrepend` / `InputGroupAppend` | `<ui-input-group…>` | `required`,`badge`; `text` | `default` |
| `Textarea` | `<ui-textarea>` | `modelValue`, `rows`, `elastic`, `resize` [`both`,`horizontal`,`vertical`,`none`], `limit`, `copyable`, `disabled`, `readOnly`, `required`, `id` | `update:modelValue` |
| `Select` | `<ui-select>` | `modelValue`, `options`, `optionLabel` (`label`), `optionValue` (`value`), `placeholder`, `size` [`xs`…`xl`], `variant` [`default`,`filled`,`ghost`,`subtle`], `align`, `clearable`, `adaptiveWidth`, `icon`, `disabled`, `readOnly` | `update:modelValue`; slots `option`,`selected-option`,`no-options` |
| `Combobox` | `<ui-combobox>` | as Select **plus** `multiple`, `maxSelections`, `taggable`, `pasteDelimiter`, `searchable`, `ignoreFilter`, `labelHtml`, `closeOnSelect`, `shouldOpenDropdown`, `discreteFocusOutline`, `id` | `update:modelValue`, `search`, `selected`, `added`; slots `option`,`selected-option`,`selected-options`,`no-options`; exposes `searchQuery`,`filteredOptions`,`focus` |
| `Checkbox` | `<ui-checkbox>` | `modelValue`, `label`, `description`, `value`, `name`, `align` [`start`,`center`], `size` [`sm`,`base`], `indeterminate`, `solo`, `disabled`, `readOnly` | `update:modelValue`, `keydown` |
| `CheckboxGroup` | `<ui-checkbox-group>` | `modelValue` (Array), `appearance` [`default`,`inline`,`chips`], `name`, `required`; **`inline` is `@deprecated`** | `update:modelValue` |
| `Radio` / `RadioGroup` | `<ui-radio>` / `<ui-radio-group>` | `value` **(req)**, `label`, `description`; `modelValue`, `appearance` [`default`,`inline`,`chips`], `name` | `update:modelValue` |
| `Switch` | `<ui-switch>` | `modelValue`, `size` [`xs`,`sm`,`base`,`lg`], `id`, `required`, `disabled` | `update:modelValue` |
| `ToggleGroup` / `ToggleItem` | `<ui-toggle-group>` / `<ui-toggle-item>` | `modelValue`, `multiple`, `size` [`xs`,`sm`,`base`], `variant` [`default`,`primary`,`filled`,`ghost`]; `value` **(req)**, `label`, `icon`, `disabled` | `update:modelValue` |
| `Slider` | `<ui-slider>` | `modelValue`, `min`, `max`, `step`, `label`, `description`, `size`, `id` | `update:modelValue` |
| `DatePicker` | `<ui-date-picker>` | `modelValue`, `min`, `max`, `granularity` [`day`,`hour`,`minute`,`second`], `inline`, `numberOfMonths`, `clearable`, `badge`, `required`, `disabled`, `readOnly` | `update:modelValue` |
| `DateRangePicker` | `<ui-date-range-picker>` | same, `modelValue` is a reka-ui `DateRange` | `update:modelValue` |
| `TimePicker` | `<ui-time-picker>` | `modelValue`, `granularity` [`hour`,`minute`,`second`], `clearable`, `badge`, `required` | `update:modelValue` |
| `Calendar` | `<ui-calendar>` | `modelValue`, `min`, `max`, `numberOfMonths`, `inline`, `components` | `update:modelValue` |
| `CodeEditor` | `<ui-code-editor>` | `modelValue`, `mode`, `allowModeSelection`, `keyMap` [`sublime`,`vim`], `lineNumbers`, `lineWrapping`, `indentType`, `rulers`, `fieldActions`, `readOnly`, `disabled` | `update:mode`, `update:model-value`, `focus`, `blur`; exposes `toggleFullscreen` |
| `Editable` | `<ui-editable>` | `modelValue`, `startWithEditMode`, `submitMode` [`blur`,`none`,`enter`,`both`], `placeholder` | `update:modelValue`,`cancel`,`submit`,`edit`; exposes `edit()` |
| `CharacterCounter` | `<ui-character-counter>` | `text`, `limit`, `dangerZone` (20) | — |
| `CreateForm` | `<ui-create-form>` | `title` **(req)**, `route` **(req)**, `subtitle`, `icon`, `submitText`, `loading`, `titleInstructions`, `handleInstructions`, `withoutHandle` | `default`, `footer` |

### 1.4 Feedback & status

| Vue | Tag | Key props | Purpose |
|---|---|---|---|
| `Alert` | `<ui-alert>` | `text`, `heading`, `variant` [`default`,`warning`,`error`,`success`], `icon` | Inline banner |
| `Badge` | `<ui-badge>` | `text`, `prepend`, `append`, `color` [`default`,`amber`,`black`,`blue`,`cyan`,`emerald`,`fuchsia`,`green`,`indigo`,`lime`,`orange`,`pink`,`purple`,`red`,`rose`,`sky`,`teal`,`violet`,`white`,`yellow`], `size` [`sm`,`default`,`lg`], `pill`, `icon`, `iconAppend`, `href`, `as` | Metadata pill |
| `StatusIndicator` | `<ui-status-indicator>` | `status` [`published`,`scheduled`,`expired`,`draft`,`hidden`], `showDot` (true), `showLabel` (false), `private` | Entry status dot |
| `Skeleton` | `<ui-skeleton>` | none — you size it yourself | `animate-pulse` placeholder |
| `EmptyStateMenu` / `EmptyStateItem` | `<ui-empty-state-menu>` / `<ui-empty-state-item>` | `heading` **(req)**, `description`; + `icon` **(req)**, `href`, `target` | Empty state (see `pages/forms/Index.vue:35-49`) |
| `Avatar` | `<ui-avatar>` | `user: { name?, initials?, avatar? }` | User avatar w/ initials fallback |
| `MiddleEllipsis` | `<ui-middle-ellipsis>` | `text` **(req)** | Truncates from the middle |
| `Timezones` / `TimezoneHoverCard` | `<ui-timezones>` / `<ui-timezone-hover-card>` | `date` **(req)**, `additionalTimezones` | |
| `DragHandle` | `<ui-drag-handle>` | — | |

### 1.5 Overlays

| Vue | Tag | Key props | Emits / Slots |
|---|---|---|---|
| `Modal` | `<ui-modal>` | `open`, `title`, `icon`, `blur`, `dismissible` (true), `beforeClose` (`() => true`) | `update:open`,`opened`,`dismissed`; slots `default`,`trigger`,`footer`; exposes `open()`,`close()`,`runCloseCallback()` |
| `ModalTitle` / `ModalClose` | `<ui-modal-title>` / `<ui-modal-close>` | — | `default` |
| `ConfirmationModal` | `<ui-confirmation-modal>` | `open`, `title`, `bodyText`, `buttonText` (`Confirm`), `cancelText`, `cancellable`, `submittable`, `danger`, `disabled`, `busy`, `blur` | `update:open`,`opened`,`confirm`,`cancel` |
| `Stack` | `<ui-stack>` | `open`, `title`, `icon`, `size` [`narrow`,`half`,`full`], `inset`, `showCloseButton`, `wrapSlot`, `beforeClose` | `update:open`,`opened`,`closed`; slots `default` (scoped `{close}`), `trigger`, `header-actions`, `footer-start`, `footer-end`; exposes `open`,`close`,`runCloseCallback` |
| `StackHeader`/`StackContent`/`StackFooter`/`StackClose` | `<ui-stack-…>` | `title`,`icon`,`showCloseButton`; `inset`; —; — | slots incl. `actions`, `start`, `end` |
| `Popover` | `<ui-popover>` | `open`, `align`, `side`, `offset` (5), `arrow`, `inset`, `dismissible` | `update:open`; slots `default`,`trigger`,`close` |
| `HoverCard` | `<ui-hover-card>` | `open`, `align` (`center`), `side` (`left`), `offset` (25), `delay` (200), `arrow` (true), `inset` | `update:open`; slots `default`,`trigger` |
| `LivePreview` / `LivePreviewPopout` | `<ui-live-preview>` | `enabled` **(req)**, `targets` **(req)**, `url` | `opened`,`closed`; slots `default`,`buttons` |

### 1.6 Data display

| Vue | Tag | Key props | Purpose |
|---|---|---|---|
| `Listing` | `<ui-listing>` | see §3 — the full CP listing | The one you want 95% of the time |
| `ListingSearch`, `ListingFilters`, `ListingPresets`, `ListingPresetTrigger`, `ListingCustomizeColumns`, `ListingTable`, `ListingTableHead`, `ListingTableBody`, `ListingHeaderCell`, `ListingPagination`, `ListingRowActions`, `ListingToggleAll` | `<ui-listing-…>` | consume `Listing`'s injected context | Only for custom listing layouts |
| `Table`, `TableColumns`, `TableColumn`, `TableRows`, `TableRow`, `TableCell` | `<ui-table>` etc. | mostly slot-only | Dumb static table |
| `Icon` | `<ui-icon>` | `name` **(req)**, `set` (`default`) | See §7 |

### 1.7 Publish-form components

`PublishContainer`, `PublishForm`, `PublishTabs`, `PublishSections`, `PublishFields`,
`PublishFieldsProvider`, `PublishField`, `PublishComponents`, `PublishLocalizations`, `TabProvider`,
`ContentDirection`. Plus the composables `injectPublishContext` / `publishContextKey` /
`useContentDirection` / `useUiDirection`. Full detail in §4.

### 1.8 Non-component exports from `@statamic/cms`

From `packages/cms/src/index.js`:
`Fieldtype`, `IndexFieldtype`, `FieldtypeMixin`, `IndexFieldtypeMixin`, `HasActionsMixin`,
`HasInputOptionsMixin`, `HasPreferencesMixin`, `InlineEditForm`, `DateFormatter`, `NumberFormatter`,
`ItemActions`, `RelatedItem`, `RestoreRevision`, `RevisionHistory`, `RevisionPreview`,
`SaveButtonOptions`, `SortableList`, `requireElevatedSession`, `requireElevatedSessionIf`,
`clone`, `deepClone`, `debounce`, `resetValuesFromResponse`.

From `packages/cms/src/api.js` (the `Statamic.$…` singletons):
`bard, callbacks, commandPalette, components, conditions, config, contrast, dateFormatter, dirty,
echo, events, fieldActions, hooks, inertia, keys, numberFormatter, permissions, portals,
preferences, progress, reveal, slug, stacks, colorMode, toast`.

From `packages/cms/src/inertia.js`:
`Form, Head, Link, router, useForm, usePoll, useArchitecturalBackground, toggleArchitecturalBackground`.

From `packages/cms/src/save-pipeline.js`: `Pipeline, Request, BeforeSaveHooks, AfterSaveHooks, PipelineStopped`.
From `packages/cms/src/bard.js`: `ToolbarButtonMixin`.

---

## 2. Page shell patterns

### 2.1 The render trace

Controller → `Inertia::render('collections/Index', [...])`
(`src/Http/Controllers/CP/Collections/CollectionsController.php:47-53`)
→ root view `statamic::layout` (`src/Http/Middleware/CP/HandleInertiaRequests.php:18`
`public const ROOT_VIEW = 'statamic::layout';`)
→ `resources/js/bootstrap/statamic.js:201-235` resolves the page:

```js
const corePages = import.meta.glob('../pages/**/*.vue');
// …
const pageImport = corePages[`../pages/${name}.vue`];
let page = pageImport ? await pageImport() : null;
if (!page) {                              // Resolve addon pages
    const addonPage = inertia.get(name);
    if (addonPage) page = { default: addonPage };
}
page.default.layout = page.default.layout || Layout;
```

**Core page names win.** An addon cannot override a core page by re-registering its name.
Names never include `.vue`.

### 2.2 The shell you inherit

Every page is wrapped in `resources/js/pages/layout/Layout.vue:79-93`:

```vue
<Header />                                   <!-- global-header/Header.vue, h-14 fixed top-0 -->
<main id="main" class="flex bg-body-bg … fixed top-14 inset-x-0 bottom-0">
    <Nav />                                  <!-- collapsible left sidebar -->
    <div id="main-content" scroll-region class="main-content sm:p-2 h-full flex-1 overflow-y-auto"
         :data-max-width-enabled="isMaxWidthEnabled">
        <div id="content-card" tabindex="-1" class="content-card grid min-h-full mx-auto">
            <div class="w-full min-w-0 mx-auto max-w-page max-[1220px]:mb-18" data-max-width-wrapper>
                <slot />
            </div>
        </div>
    </div>
</main>
```

**You do not build any chrome.** Global header, nav sidebar, scroll region, focus management,
portal targets, tooltips, toasts, session expiry and the licensing alert are all already there.
Alternative layouts exist for special cases: `pages/layout/Blank.vue` and `pages/layout/Outside.vue`
(auth screens).

Padding comes from `.content-card` (`resources/css/app.css:70-72`,
`px-2 sm:px-6 md:px-12 pb-6 … sm:rounded-2xl`).

### 2.3 Content width

`max-w-page` is a **theme token** — `resources/css/ui.css:137-138`:
```css
/* This is the outer width of the page content area. */
--max-width-page: 85rem;
```
The Layout already applies it. Pages then use one of three observed variants:

| Variant | Class | Used by |
|---|---|---|
| Wide (listings, utilities) | `max-w-page mx-auto` | `pages/forms/Index.vue:26`, `pages/utilities/Cache.vue:77`, `pages/utilities/Index.vue:12` |
| Narrow (settings, detail) | `max-w-5xl 3xl:max-w-6xl mx-auto` + `data-max-width-wrapper` | `pages/preferences/Edit.vue:14`, `pages/forms/Show.vue:61`, `pages/utilities/Licensing.vue:19` |
| None (inherit) | — | `pages/collections/Index.vue`, `pages/collections/Show.vue:2` |

Adding `data-max-width-wrapper` opts your wrapper into the user's global "expand layout" toggle
(`Layout.vue:129-132`: `[data-max-width-enabled="false"] [data-max-width-wrapper] { max-width: none }`).

**Never hard-code `max-w-7xl`, `container mx-auto`, or a pixel width.**

### 2.3a Never ship a breakpoint-less grid-column utility

Every addon ships its own Tailwind build, and `@statamic/cms/tailwind.css` routes all of them
into the same `addon-utilities` layer. Media queries add no specificity, so a breakpoint-less
single-column rule from whichever addon stylesheet loads **last** wins against an earlier
addon's `sm:`/`lg:` variant and pins that addon's grid to one column at every width.

The failure is invisible when an addon is checked alone. It only appears once two addons of the
family are installed together, which is the normal case on a real site.

Write `grid sm:grid-cols-2` and leave the one-column case to the default: a grid falls back to
one column on its own.

The utility's track was `minmax(0,1fr)`, and the implicit column is `auto`, so long unbroken
content (a URL, a JSON blob, a long handle) can now push past the container. Restore the guard:

| Addon ships a stylesheet | Guard |
|---|---|
| Yes | `*:min-w-0` on the grid container — one place, cannot be forgotten when a child is added |
| No (JS-only build) | `min-w-0` on each child — core emits `min-w-0` but **not** its child variant, so `*:min-w-0` would be dead markup |

**Do not name the class in a comment either.** Tailwind scans comment text as candidates, so a
comment explaining why the class was removed re-emits it. `statamic-activity` shipped exactly
that: a correct fix whose own annotation kept the rule in the bundle, so the fix did nothing
until it was reworded. `addon-lint` enforces this as `ui.bare-single-column-grid`, comments
included.

### 2.4 Breadcrumbs are server-side

Not declared in Vue. `src/CP/Breadcrumbs/Breadcrumbs.php:23-82` derives them from the **active nav
item** and its active child, including sibling `links` for the crumb dropdown, and reads
`NavItem::extra()['breadcrumbs']` for `create_label` / `create_url` / `configure_url`
(`Breadcrumbs.php:71-73`; producer side `src/CP/Navigation/CoreNav.php:241-258`).

To push an extra crumb from a controller — `src/CP/Breadcrumbs/Breadcrumbs.php:16-21`:
```php
Breadcrumbs::push(new Breadcrumb($text, $url, $icon, $links, $createLabel, $createUrl, $configureUrl));
// → Inertia::share(['additionalBreadcrumbs' => …])
```
Real usage: `src/Http/Controllers/CP/Forms/FormBlueprintController.php:25-32`.
Rendered by `resources/js/components/global-header/Breadcrumbs.vue`.

So: **an addon gets breadcrumbs for free by registering a nav item** (§8). Push extras only for
deep pages.

### 2.5 The page body contract

Three things, always in this order:

1. `<Head :title="…" />` — `resources/js/pages/layout/Head.vue`. Accepts `String | String[]`,
   joins with the direction-aware `‹`/`›` and appends the CMS name.
   **Addons import it from `@statamic/cms/inertia`** — `resources/js/bootstrap/cms/inertia.js:9`
   re-exports Statamic's own `Head.vue`, not Inertia's raw one.
2. A width wrapper (§2.3).
3. `<Header :title :icon>` with primary actions in the default slot
   (`ui/Header.vue:22-24`: the default slot is the fallback for the `actions` slot).

Shared props are read with `useStatamicPageProps()`
(`resources/js/composables/page-props.js:4-12`) — gives `cmsName`, `isPro`, `version`, `nav`,
`licensing`, `supportUrl`, `selectedSiteUrl`.

### 2.6 (a) Index / listing page — canonical

Derived from `resources/js/pages/forms/Index.vue` (86 lines) and `pages/blueprints/ScopedIndex.vue`.

```php
// Controller — modeled on src/Http/Controllers/CP/Forms/FormsController.php:22-57
public function index(Request $request)
{
    $this->authorize('index', Thing::class);

    $columns = [
        Column::make('title')->label(__('Title')),
        Column::make('handle')->label(__('Handle')),
    ];

    if ($request->wantsJson()) {                      // same action serves the listing endpoint
        return ['data' => $rows, 'meta' => ['columns' => $columns]];
    }

    return Inertia::render('my-addon/Index', [
        'rows' => $rows,
        'initialColumns' => $columns,
        'actionUrl' => cp_route('my-addon.actions.run'),
        'createUrl' => cp_route('my-addon.create'),
        'canCreate' => User::current()->can('create', Thing::class),
    ]);
}
```

```vue
<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Header, Button, CommandPaletteItem, Listing, DropdownItem,
         EmptyStateMenu, EmptyStateItem, DocsCallout, Icon } from '@statamic/cms/ui';

const props = defineProps(['rows', 'initialColumns', 'actionUrl', 'createUrl', 'canCreate']);
const isEmpty = computed(() => props.rows.length === 0);
const reloadPage = () => router.reload();
</script>

<template>
    <Head :title="__('Things')" />

    <div class="max-w-page mx-auto">
        <template v-if="isEmpty">
            <!-- Empty-state header is a centered h1, NOT <Header> — pages/forms/Index.vue:28-33 -->
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="collections" class="size-5 text-gray-500" />{{ __('Things') }}
                </h1>
            </header>
            <EmptyStateMenu :heading="__('…intro…')">
                <EmptyStateItem v-if="canCreate" :href="createUrl" icon="collections"
                    :heading="__('Create Thing')" :description="__('…')" />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="__('Things')" icon="collections">
                <CommandPaletteItem v-if="canCreate" category="Actions" :text="__('Create Thing')"
                    icon="collections" :url="createUrl" v-slot="{ text, url }">
                    <Button :href="url" :text="text" variant="primary" />
                </CommandPaletteItem>
            </Header>

            <Listing :items="rows" :columns="initialColumns" :action-url="actionUrl"
                     @refreshing="reloadPage">
                <template #cell-title="{ row }">
                    <Link :href="row.edit_url">{{ row.title }}</Link>
                </template>
                <template #prepended-row-actions="{ row }">
                    <DropdownItem :text="__('Edit')" icon="edit" :href="row.edit_url" />
                </template>
            </Listing>
        </template>

        <DocsCallout :topic="__('Things')" url="my-addon" />
    </div>
</template>
```

Two things that are easy to miss and both are native tells:
- **The primary action is wrapped in `<CommandPaletteItem>`.** Every core page-level action is.
- **Empty states use a centered `<header>` + `EmptyStateMenu`, not `<Header>`**, and call
  `useArchitecturalBackground()` (`pages/collections/Index.vue:15`) which toggles the
  `bg-architectural-lines` treatment on `#content-card`.

### 2.7 (b) Form / detail page — canonical

**Option 1 — zero Vue.** `Statamic\CP\PublishForm` is `Responsable`; return it from a controller.
`src/Http/Controllers/CP/Addons/AddonSettingsController.php:24-29`:
```php
return PublishForm::make($addon->settingsBlueprint())
    ->asConfig()->icon('cog')->title($addon->name())
    ->values($addon->settings()->raw())
    ->submittingTo(cp_route('addons.settings.update', $addon->slug()));
```
and to save (`:43-47`):
```php
$values = PublishForm::make($addon->settingsBlueprint())->submit($request->all());
```
`src/CP/PublishForm.php:92-118` renders `Inertia::render('PublishForm', …)`, which is
`resources/js/pages/PublishForm.vue` (entire file):

```vue
<script setup>
import { PublishForm } from '@/components/ui';
import Head from '@/pages/layout/Head.vue';

const props = defineProps({
    icon: String, title: { type: String, required: true },
    blueprint: { type: Object, required: true },
    values: { type: Object, required: true },
    meta: { type: Object, required: true },
    submitUrl: { type: String, required: true },
    submitMethod: { type: String, required: true },
    readOnly: Boolean, asConfig: Boolean,
});
</script>

<template>
    <Head :title />
    <div :class="{ 'max-w-page mx-auto': asConfig }">
        <PublishForm :icon :title :blueprint :initial-values="values" :initial-meta="meta"
            :submit-url :submit-method :read-only :as-config />
    </div>
</template>
```
`Publish/Form.vue:84-99` renders `<Header>` + primary Save `<Button>` + `<Container><Tabs/></Container>`,
with `mod+s` bound at `Form.vue:79-82`.

**If your addon just needs a settings or config form, stop here — do not write a Vue page.**

**Option 2 — custom page.** Derived from `resources/js/pages/preferences/Edit.vue:14-32`:
```vue
<template>
    <Head :title="title" />

    <PublishContainer ref="container" :name="name" :blueprint="blueprint"
                      v-model="currentValues" :meta="meta" :errors="errors" as-config>
        <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
            <Header :title="title" icon="cog">
                <ButtonGroup role="group" aria-label="Save options">
                    <CommandPaletteItem :category="$commandPalette.category.Actions"
                        :text="__('Save')" icon="save" :action="save" prioritize
                        v-slot="{ text, action }">
                        <Button type="submit" variant="primary" :text="text" @click="action" />
                    </CommandPaletteItem>
                </ButtonGroup>
            </Header>

            <PublishTabs />   <!-- blueprint tab handle "sidebar" becomes the right-hand aside -->
        </div>
    </PublishContainer>
</template>
```

### 2.8 (c) Settings / utility page — canonical

The cheapest native CP page an addon can ship is a **Utility**. Registration —
modeled on `src/CP/Utilities/CoreUtilities.php:46-53`:
```php
Utility::extend(function () {
    Utility::register('my-addon')
        ->inertia('my-addon/Utility', fn ($request) => ['stats' => …, 'runUrl' => cp_route('utilities.my-addon.run')])
        ->title(__('My Addon'))->navTitle(__('My Addon'))
        ->icon('cog')->description(__('…'))->docsUrl('https://…')
        ->routes(fn ($router) => $router->post('run', [MyController::class, 'run'])->name('run'));
});
```
You get the nav entry, the permission (`access my-addon utility`, auto-registered at
`src/CP/Utilities/UtilityRepository.php:56-61`) and the routes (prefixed `utilities.`) for free.
`->view($view, $data)` is the Blade variant — the HTML is piped through `DynamicHtmlRenderer`, so
`<ui-*>` global components still work inside it.

Page body, from `resources/js/pages/utilities/Cache.vue:74-131`:

```vue
<script setup>
import { Head, router } from '@statamic/cms/inertia';
import { Header, Button, Panel, PanelHeader, Heading, Card, Description, Badge,
         CommandPaletteItem, DocsCallout } from '@statamic/cms/ui';

const props = defineProps(['stats', 'runUrl']);
function run() { router.post(props.runUrl); }   // Inertia POST → redirect back + flashed toast
</script>

<template>
    <Head :title="[__('My Addon'), __('Utilities')]" />

    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="__('My Addon')" icon="cog">
            <CommandPaletteItem category="Actions" :text="__('Run')" icon="live-preview"
                :action="run" prioritize v-slot="{ text }">
                <Button :text="text" variant="primary" @click="run" />
            </CommandPaletteItem>
        </Header>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Panel class="h-full flex flex-col">
                <PanelHeader class="flex items-center justify-between min-h-10">
                    <Heading>{{ __('Section') }}</Heading>
                    <Button :text="__('Clear')" size="sm" @click="run" />
                </PanelHeader>
                <Card class="flex-1">
                    <Description>{{ __('…description…') }}</Description>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <Badge :prepend="__('Records')">{{ stats.records }}</Badge>
                    </div>
                </Card>
            </Panel>
        </div>

        <DocsCallout :topic="__('My Addon')" url="…" />
    </div>
</template>
```
Simpler single-box variant: `<CardPanel :heading :subheading>…</CardPanel>`
(`pages/preferences/Index.vue:22-31`).

Mutations go through Inertia (`router.post(...)`), never bare axios. Flash toasts come back
automatically — `src/Http/Middleware/CP/HandleInertiaRequests.php:87-113` maps session
`success|status|error|info` into the `_toasts` prop.

### 2.9 Tabs and sidebar

Page-level tabs in core **always** come from a blueprint via `<PublishTabs>` — no core page uses the
generic `<Tabs>` component directly.

`Publish/Tabs.vue`:
- The blueprint tab whose handle is literally `sidebar` becomes the right-hand aside (`:24`).
- Grid: `grid grid-cols-[1fr_320px] gap-8`, and only when `> 920px`
  (`:46` `shouldShowSidebar = (slots.actions || sidebarTab) && width > 920`).
- The `#actions` slot renders above the sidebar tab (`:216-221`).
- The tab bar hides itself when there's only one visible tab; overflow collapses into a `Dropdown`.
- Active tab syncs to `window.location.hash` when `rememberTab`.
- Sections render as `Panel > PanelHeader(Heading/Subheading) > Card` (`Publish/Sections.vue:54-76`).

### 2.10 How an addon ships a CP page

1. **Routes.** `AddonServiceProvider::$routes = ['cp' => __DIR__.'/../routes/cp.php']` (or just
   create `routes/cp.php` — auto-discovered at `AddonServiceProvider.php:548-556`). These are
   flushed inside core's authenticated CP group (`routes/cp.php:160`
   `Statamic::additionalCpRoutes();`), so they already have auth + Inertia middleware.
2. **Controller** extending `Statamic\Http\Controllers\CP\CpController` — gives `authorize()`,
   `authorizeIf()`, `authorizePro()`, `requireElevatedSession()`, `pageNotFound()`
   (`src/Http/Controllers/CP/CpController.php:36-80`). Return `Inertia::render('my-addon/Index', …)`.
3. **Register the Vue page:**
   ```js
   import { inertia } from '@statamic/cms/api';
   Statamic.booting(() => inertia.register('my-addon/Index', MyIndexPage));
   ```
4. **Nav entry** via `Nav::extend` (§8) — which also gives you breadcrumbs.
5. **Test** with `->assertInertia(fn ($page) => $page->component('my-addon/Index'))`
   (`tests/Auth/LoginTest.php:28`).

Blade-only addons still work through the `NonInertiaPage` escape hatch
(`statamic.js:206-212` + `Statamic::nonInertiaPageData()`, `src/Statamic.php:497-507`) — a Blade view
that `@extends('statamic::layout')` gets dumped into a template string inside the Layout, and
`<ui-*>` components still resolve. **Treat this as legacy compatibility, not as a target.**

---

## 3. Listings

`resources/js/components/ui/Listing/Listing.vue` (764 lines) is the whole Entries-grade listing:
search, filters, sorting, pagination, per-page, column customisation, presets, bulk actions,
row actions, drag reordering.

### 3.1 Two modes

- `:url="…"` → server mode. Everything server-side.
- `:items="[…]"` → client mode (fuzzysort + lodash sortBy). **No pagination, no `meta`.**
  You must handle `@refreshing` yourself (`router.reload()`).

### 3.2 Props (complete, `Listing.vue:38-163`)

`url`, `items`, `columns`, `allowCustomizingColumns` (true), `sortColumn` (`''`),
`sortDirection` (`asc`; `desc` auto for date columns, `:480-482`), `sortable` (true),
`allowSearch` (true), `searchQuery`, `filters` (`[]`), `filtersForReordering`,
`allowPresets` (true), `preferencesPrefix`, `allowBulkActions` (true), `actionUrl`,
`actionContext` (`{}`), `allowActionsWhileReordering` (false), `reorderable` (false),
`selections`, `maxSelections` (`Infinity`), `pushQuery` (false), `additionalParameters` (`{}`),
`perPage` (`Statamic.$config.get('paginationSize', 15)`), `showPaginationTotals`,
`showPaginationPageLinks`, `showPaginationPerPageSelector`.

**Emits:** `update:columns`, `update:sortColumn`, `update:sortDirection`, `update:selections`,
`update:searchQuery`, `requestCompleted`, `reordered`, `refreshing`.
**Exposes:** `refresh()`, `setFilter()`, `parameters`.
**Slots:** `#initializing`, default (scoped `{ items, isColumnVisible, loading }`),
`#cell-<field>` (scoped `{ row, value, isColumnVisible }`), `#prepended-row-actions` (scoped `{ row }`).

Hard gates:
- `hasActions = !!props.actionUrl` (`:186`) — **no `actionUrl` ⇒ no checkboxes, no bulk toolbar, no actions column.**
- `showPresets = allowPresets && preferencesPrefix` (`:188`) — **no `preferencesPrefix` ⇒ no saved views, no persisted columns/per-page.**
- Every row **must** have a unique `id` (`TableBody.vue:80`, selections logic `:520-554`).

### 3.3 Request contract (`Listing.vue:251-259`)

```
GET {url}?page=1&perPage=15&sort=title&order=asc&search=foo
          &columns=title,status&filters=<base64(json)>
```
`filters` is base64-encoded JSON, decoded server-side by
`src/Http/Requests/FilteredRequest.php:9-16`.

### 3.4 Response contract (`Listing.vue:335-348`)

```jsonc
{
  "data": [ { "id": "…", "title": "…", "status": "…" } ],
  "meta": {
    "columns": [ /* Statamic\CP\Column::toArray() shape */ ],
    "activeFilterBadges": { "status": "Published" },
    "current_page": 1, "last_page": 4, "per_page": 15, "from": 1, "to": 15, "total": 52
  }
}
```
`meta.columns` **must be returned on every response** — `Listing.vue:336` overwrites local columns
from it each time.

### 3.5 Column definition — `src/CP/Column.php:15-25`

```php
public $field; public $fieldtype; public $label; public $numeric = false;
public $listable = true; public $defaultOrder; public $defaultVisibility = true;
public $visible = true; public $sortable = true; public $required = false; public $value;
```
Built from blueprints at `src/Fields/Blueprint.php:399-422` — note `->fieldtype($field->fieldtype()->indexComponent())`,
which is what becomes `<{fieldtype}-fieldtype-index>` on the JS side.
`src/CP/Columns.php:56-81` `setPreferred($key)` applies the user's stored column preferences.

### 3.6 What an addon must build

**PHP**
1. Index endpoint taking `FilteredRequest`; apply
   `QueriesFilters::queryFilters($query, $request->filters, $context)`, `request('sort')/('order')`,
   `request('search')`, then `->paginate(Statamic::cpPerPage(request('perPage')))`.
2. A `ResourceCollection` using `Statamic\Http\Resources\CP\Concerns\HasRequestedColumns` with
   `setColumns()` → `Columns` → `->setPreferred($key)->rejectUnlisted()->values()`, and
   `with()` returning `['meta' => ['columns' => $this->visibleColumns()]]`.
3. A per-row `JsonResource` emitting `id` + one key per column (run blueprint values through
   `$field->setValue(…)->setParent(…)->preProcessIndex()->value()`), plus `edit_url` and
   permission flags. Optionally `'actions' => Action::for($item, $ctx)` to skip the lazy fetch.
4. An action controller extending `Statamic\Http\Controllers\CP\ActionController` with
   `getSelectedItems($items, $context)`, and **two** routes:
   ```php
   Route::post('things/actions',      [ThingActionController::class, 'run']);
   Route::post('things/actions/list', [ThingActionController::class, 'bulkActions']);
   ```
5. Actions extending `Statamic\Actions\Action`, registered via `AddonServiceProvider::$actions`.
6. Filters extending `Statamic\Query\Scopes\Filter`, registered via `AddonServiceProvider::$scopes`,
   passed to the page as `Scope::filters('your-key', $context)`.

**Vue** — a `<Listing>` with `:url`, `:columns`, `:action-url`, `:filters`, `:preferences-prefix`,
`:sort-column`, `:sort-direction`, `push-query`, plus `#cell-title` linking to `row.edit_url` and
`#prepended-row-actions`.

`resources/js/stories/docs/Listing.mdx` contains core's own step-by-step addon guide for exactly this
(controller, JSON resource, `ActionController`, `Scope::filters`). Read it before designing an API.

### 3.7 Legacy

`resources/js/components/data-list/` is the **v5 namespace** and is nearly gone: only
`DefaultField.vue`, `HasPreferences.js`, `TableField.vue` remain. All the v5 `DataList*.vue`
components are deleted. But `TableField.vue:19-26` is still the index-fieldtype resolver
(imported by the new `ui/Listing/TableBody.vue:2`), so `{fieldtype}-fieldtype-index` registration
is **current**, not legacy. Do not build new code against `data-list/`.

---

## 4. Publish forms

Directory: `resources/js/components/ui/Publish/`. Public docs:
`resources/js/stories/docs/PublishContainer.mdx`.

### 4.1 Hierarchy

```
PublishForm            ← Header + Save button + Container + Tabs (use this)
└─ PublishContainer    ← state, conditions, dirty tracking, provide/inject
   ├─ PublishComponents   ← slots pushed by other addons (e.g. Collaboration)
   └─ PublishTabs
      └─ TabProvider → PublishSections (Panel/Card per section)
         └─ PublishFieldsProvider → PublishFields → PublishField
            └─ <component :is="`${type}-fieldtype`">
```

### 4.2 `PublishContainer` props (`Container.vue:18-97`)

`blueprint` **(required — the `toPublishArray()` output)**, `modelValue`, `meta`, `name`
(defaults to a nanoid; doubles as the dirty-state key), `reference` (`entry::id`), `extraValues`,
`originValues`, `originMeta`, `errors`, `site`, `modifiedFields`, `trackDirtyState` (true),
`syncFieldConfirmationText`, `readOnly`, `asConfig`, `rememberTab`, `provide`.

Emits `update:modelValue`, `update:visibleValues`, `update:modifiedFields`, `update:meta`.

Context key is the string `"PublishContainerContext"` (`Publish/context.js:3` +
`resources/js/util/createContext.js:4-14`). Consume it:
```js
import { injectPublishContext } from '@statamic/cms/ui';
const { values, setFieldValue, errors, readOnly } = injectPublishContext();
// refs — unwrap with .value
```
Options API: `inject: { publishContext: { from: publishContextKey } }`.

Full key list is in `PublishContainer.mdx` "Available keys" — notably `values`, `visibleValues`,
`meta`, `errors`, `blueprint`, `readOnly`, `asConfig`, `site`, `direction`, `setFieldValue(path, v)`,
`setValues`, `setFieldMeta`, `setFieldPreviewValue`, `syncField` / `desyncField`,
`isDirty()`, `withoutDirtying(cb)`, `hiddenFields`, `revealerFields`, `localizedFields`, `previews`.

`visibleValues` (`Container.vue:111-116`) strips condition-hidden fields with `omitValue` —
**that is what gets POSTed**, not `values`.

### 4.3 Saving — the pipeline

`PublishForm.vue:61-74`:
```js
new Pipeline()
    .provide({ container, errors, saving })
    .through([ new Request(props.submitUrl, props.submitMethod) ])
    .then((response) => {
        Statamic.$toast.success(__('Saved'));
        if (response.data.redirect) router.get(response.data.redirect);
    });
```
`SavePipeline.js:18-20` deliberately waits `UPDATE_DEBOUNCE_MS + 1` (151 ms) before running, so
in-flight debounced fieldtype updates land first. Payload is
`{ ...container.visibleValues, ...extraData }` (`SavePipeline.js:74`).

Richer forms add hook steps — `resources/js/components/globals/PublishForm.vue:237-257`:
```js
.through([
    new BeforeSaveHooks('global-set', { … }),   // fires Statamic.$hooks.run('global-set.saving')
    new Request(this.actions.save, this.method, { _blueprint: …, _localized: … }),
    new AfterSaveHooks('global-set', { … }),    // 'global-set.saved'
])
```
⚠️ `SavePipeline.js:6-8` keeps `container/errors/saving` in module-level vars — one pipeline at a time.

### 4.4 Validation errors

Standard Laravel 422: `{ message, errors: { 'dotted.field.path': ['msg'] } }`
(`SavePipeline.js:85-99`). Attached per field at `Publish/Field.vue:82-87`:
```js
watch(() => containerErrors.value,
    (newErrors) => errors.value = newErrors[fullPath.value] || [], { immediate: true });
```
where `fullPath = [fieldPathPrefix, handle].filter(Boolean).join('.')` (`Field.vue:74`).
Tabs go red by matching the first dotted segment (`Tabs.vue:97-110`), and `reveal.invalid()`
auto-opens the tab holding the first invalid field.

### 4.5 Dirty state & unsaved-changes guard

`Container.vue:190-207` → `Statamic.$dirty` (`resources/js/composables/dirty-state.js`).
Three guards:
- Inertia `router.on('before')` → `confirm(__('statamic::messages.dirty_navigation_warning'))` (`:43-52`)
- `window.onbeforeunload = () => ''` (`:56`)
- a `popstate` interceptor registered at module load, ahead of Inertia's (`:70-101`)

Opt-outs: user preference `confirm_dirty_navigation`; `Statamic.$dirty.disableWarning()`;
`:track-dirty-state="false"` on the container.

### 4.6 Localisation switcher

`Publish/Localizations.vue` — a **sidebar Panel**, not a header select. Props `localizations` (Array),
`localizing` (Boolean|String); emits `selected`. Renders buttons for ≤5 sites, a `<Combobox>` above.
`Localization.vue:17-34` renders the coloured dot + `Origin`/`Active`/`Root` badges.
The switching logic (dirty confirm + fetch + `history.replaceState`) lives in the consumer
(`components/globals/PublishForm.vue:270-300`).

### 4.7 Legacy note

`resources/js/components/{entries,globals,terms,users}/PublishForm.vue` are still Options-API with
`mixins` and `HasActions`. They are current code but **not the pattern to copy** — they drive the
new `ui/Publish` components. Copy `resources/js/pages/PublishForm.vue` instead.

---

## 5. Fieldtypes

### 5.1 Registration

**There is no `$fieldtypes` registry and no `Statamic.componentExists` in v6** (zero grep hits in
`resources/js`). Fieldtypes are plain global Vue components resolved by name.

`Publish/Field.vue:66-68`:
```js
const fieldtypeComponent = computed(() => `${props.config.component || props.config.type}-fieldtype`);
```
Missing component → a visible red "Component `x-fieldtype` does not exist." (`Field.vue:281-283`).

Register inside `Statamic.booting()` — the app doesn't exist yet, so `Components.js:25-32` queues:
```js
// resources/js/addon.js — from src/Console/Commands/stubs/addon/addon.js.stub
import StarsFieldtype from './components/fieldtypes/StarsFieldtype.vue';

Statamic.booting(() => {
    Statamic.$components.register('stars-fieldtype', StarsFieldtype);
    Statamic.$components.register('stars-fieldtype-index', StarsIndexFieldtype);
});
```
`Statamic.component(name, c)` is an alias (`bootstrap/statamic.js:172-174`).

### 5.2 Props & emits (the contract)

`resources/js/components/fieldtypes/props.js` (whole file):
```js
export default {
    value:       { required: true },
    config:      { type: Object, default: () => ({}) },
    handle:      { type: String, required: true },
    meta:        { type: Object, default: () => ({}) },
    readOnly:    { type: Boolean, default: false },
    showFieldPreviews: { type: Boolean, default: false },
    namePrefix: String, fieldPathPrefix: String, metaPathPrefix: String, id: String,
};
```
`emits.js`: `['update:value', 'update:meta', 'focus', 'blur', 'replicator-preview-updated']`.
`constants.js`: `UPDATE_DEBOUNCE_MS = 150`.

### 5.3 The composable (the modern API)

`resources/js/components/fieldtypes/fieldtype.js` exports `{ use, emits, props, mixin }`.
`use(emit, props)` returns:

| Key | Source | Meaning |
|---|---|---|
| `name` | `:11-17` | `namePrefix ? \`${namePrefix}[${handle}]\` : handle` |
| `isReadOnly` | `:19-26` | `readOnly \|\| config.visibility === 'read_only' \|\| 'computed'` |
| `update(v)` | `:54-56` | `emit('update:value', v)` |
| `updateDebounced(v)` | `:58-60` | same, debounced 150 ms |
| `updateMeta(v)` | `:62-64` | `emit('update:meta', v)` |
| `replicatorPreview` | `:30-36` | defaults to `value` when `showFieldPreviews` |
| `defineReplicatorPreview(fn)` | `:38-40` | override it |
| `defineFieldActions(arr)` / `fieldActions` | `:80-88` | field action menu |
| `fieldPathKeys` | `:48-52` | |
| **`expose`** | `:90-95` | `{ handle, name, fieldActions, replicatorPreview }` |

**`defineExpose(expose)` is mandatory.** `Publish/Field.vue:94-96` and `:119` read `fieldActions` and
`replicatorPreview` off the template ref. Omit it and both silently break.

Options-API alternative (still fully supported): `mixins: [Fieldtype]` from
`resources/js/components/fieldtypes/Fieldtype.vue` — same props/emits, plus an injected
`publishContainer` computed and a deprecated `fieldId`.

### 5.4 Index / preview components

Naming: `{indexComponent}-fieldtype-index`, resolved at `data-list/TableField.vue:19-26`.
Props (`index-props.js`, whole file): `{ handle, value, values }`.

```vue
<!-- ToggleIndexFieldtype.vue — entire core file -->
<template>
    <div class="flex items-center">
        <ui-icon name="checkmark" class="text-green-600" v-if="this.value" />
        <ui-icon name="x-square" class="text-gray-400 dark:text-gray-600" v-if="!this.value" />
    </div>
</template>
<script>
import IndexFieldtype from './IndexFieldtype.vue';
export default { mixins: [IndexFieldtype] };
</script>
```

### 5.5 PHP side — `src/Fields/Fieldtype.php`

```php
public static function handle()                  // :70-73  strips '_fieldtype'
public function component(): string              // :75-78  ?? handle()  →  "<x>-fieldtype"
public function indexComponent(): string         // :80-83  ?? handle()  →  "<x>-fieldtype-index"
protected function configFieldItems(): array     // :289-292
public function preProcess($data)                // :324-327  server → publish form
public function process($data)                   // :319-322  publish form → storage
public function preProcessIndex($data)           // :339-342  → listing tables
public function augment($value)                  // :174-177  storage → templates
public function preload()                        // :374-377  → the `meta` prop
public function rules(): array                   // :139-…
public function icon()                           // :314-317  ?? "fieldtype-{handle}"
```
`configFieldItems()` accepts a flat map **or** sections when the first key is `0`
(`configFieldsUseSections()`, `:255-262`) — see `src/Fieldtypes/Toggle.php:18-52`.

### 5.6 Minimal complete example

Core's own stub, `src/Console/Commands/stubs/fieldtype.vue.stub` (verbatim):
```vue
<script setup>
import { Fieldtype } from '@statamic/cms';
import { Input } from '@statamic/cms/ui';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, update } = Fieldtype.use(emit, props);
defineExpose(expose);
</script>

<template>
    <Input :model-value="value" @update:model-value="update" />
</template>
```

Fleshed out (PHP + Vue + registration):

```php
// src/Fieldtypes/Stars.php
namespace Acme\Rating\Fieldtypes;

use Statamic\Facades\GraphQL;
use Statamic\Fields\Fieldtype;
use function Statamic\trans as __;

class Stars extends Fieldtype
{
    protected static $title = 'Stars';
    protected $categories = ['controls'];
    protected $defaultValue = 0;
    protected $icon = 'star';

    protected function configFieldItems(): array
    {
        return ['max' => [
            'display' => __('Maximum'), 'type' => 'integer', 'default' => 5, 'width' => 50,
        ]];
    }

    public function preProcess($data) { return (int) $data; }
    public function process($data)    { return $data === null ? null : (int) $data; }
    public function augment($value)   { return (int) $value; }
    public function preload()         { return ['max' => (int) $this->config('max', 5)]; }
    public function toGqlType()       { return GraphQL::int(); }
}
```

```vue
<!-- resources/js/components/fieldtypes/StarsFieldtype.vue -->
<script setup>
import { Fieldtype } from '@statamic/cms';
import { Button } from '@statamic/cms/ui';
import { computed } from 'vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { isReadOnly, update, defineReplicatorPreview, expose } = Fieldtype.use(emit, props);

const max = computed(() => props.meta?.max ?? props.config.max ?? 5);
defineReplicatorPreview(() => '★'.repeat(props.value || 0));

function set(n) { if (!isReadOnly.value) update(n === props.value ? 0 : n); }

defineExpose(expose);   // required
</script>

<template>
    <div class="flex items-center gap-1" :id="id">
        <Button v-for="n in max" :key="n" variant="ghost" size="sm"
            :icon="n <= (value || 0) ? 'star' : 'star-outline'"
            :disabled="isReadOnly" @click="set(n)"
            @focus="$emit('focus')" @blur="$emit('blur')" />
    </div>
</template>
```

```php
// ServiceProvider
class ServiceProvider extends AddonServiceProvider
{
    protected $fieldtypes = [\Acme\Rating\Fieldtypes\Stars::class];
    protected $vite = ['input' => ['resources/js/addon.js'], 'publicDirectory' => 'resources/dist'];
}
```

Build config — `src/Console/Commands/stubs/addon/vite.config.js.stub`:
```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/addon.js'], publicDirectory: 'resources/dist' }),
        statamic(),
    ],
});
```
The `statamic()` plugin rewrites every `import … from 'vue'` to `window.Vue`
(`packages/cms/src/vite-plugin/externals.js:11-28`). **Skip it and you ship a second Vue instance —
provide/inject breaks, the publish context is `null`, nothing works.**

`AddonServiceProvider::$vite` → `registerVite()` (`src/Providers/AddonServiceProvider.php:670-695`)
publishes the build to `public/vendor/{package}/build` and calls `Statamic::vite(...)`.

---

## 6. Interaction primitives

| Concern | API | Notes |
|---|---|---|
| **Toasts** | `Statamic.$toast.success(msg, opts)` / `.error()` / `.info()` | `resources/js/components/Toasts.js:74,89,104`. Backed by `@hoppscotch/vue-toasted`, defaults `{ position: 'bottom-left', duration: 3500, theme: 'statamic' }`. Server side: `Statamic\CP\Toasts\Manager` (`info/error/success`, session key `_toasts`) — auto-drained from axios responses and Inertia props (`Toasts.js:11-33`) |
| **Modals** | `<Modal v-model:open>` | Hand-rolled `<teleport>` + reka-ui `FocusScope` + the `portals` API. **`vue-final-modal` is in package.json:98 but has zero imports — dead dependency.** `dismissible`, `beforeClose` veto, esc via `keys.bindGlobal` |
| **Confirmations** | `<ConfirmationModal :danger :busy @confirm>` | Passing `busy` means *you* own the open state |
| **Stacks / slideouts** | `<Stack v-model:open size="narrow\|half\|full">` | `Stack/Stacks.js` + `portals`. Cascading offsets, RTL-aware, hit-area click-to-close, `FocusScope` trapped only on the top portal |
| **Dropdowns** | `<Dropdown><template #trigger>…</template><DropdownMenu><DropdownItem/></DropdownMenu></Dropdown>` | reka-ui `DropdownMenu*` |
| **Context menus** | `<Context>` + `<ContextMenu>` + `<ContextItem>` | reka-ui `ContextMenu*` |
| **Popover / HoverCard** | `<Popover>`, `<HoverCard>` | |
| **Progress** | `Statamic.$progress.start(name)` / `.complete(name)` / `.loading(name, bool)` | `composables/progress-bar.js`, nprogress, **500 ms delay before showing**, name-based reference counting |
| **Skeletons** | `<Skeleton class="h-8 w-40" />` | No props; you size it |
| **Empty states** | `<EmptyStateMenu>` + `<EmptyStateItem>` | |
| **Keyboard** | `Statamic.$keys.bind(bindings, cb)` / `.bindGlobal(...)` → `Binding` with `.destroy()` | mousetrap. Bindings stack per key; `destroy()` restores the previous handler — that's how nested modals hand `esc` back. Always `destroy()` in `onUnmounted` |
| **Command palette** | `Statamic.$commandPalette.add({ category, text, icon, url\|action, keys, prioritize, trackRecent, persist, when })` or `<CommandPaletteItem>` | Categories: `Actions, Recent, Navigation, Fields, Miscellaneous, Preferences, Search`. PHP: `Statamic\CommandPalette\Palette::add(...)` (cached forever) |
| **Focus** | reka-ui `<FocusScope loop :trapped>` in Modal/Stack | Page-level `focusMain()` runs on mount and every Inertia `router.on('success')`; honours `[autofocus]` |
| **Tooltips** | `v-tooltip="'text'"` | The only directive in `resources/js/directives/`. `v-elastic` (autosize) is defined inline at `statamic.js:334-336` |

---

## 7. Design tokens

### 7.1 Tailwind 4, theme variables, no JS config

`resources/css/app.css` is the entry:
```css
@layer base, addon-theme, addon-utilities, components, utilities, ui, ui-states;
@import 'tailwindcss';
@import './ui.css';
@import './cp.css';
@import './core/utilities.css';
@import './dark.css';
@import './tailwind-animate.css';

@custom-variant dark (&:where(.dark, .dark *));
```

### 7.2 Colour tokens — `resources/css/ui.css:1-134`

Every colour is a `@theme inline` variable pointing at a **runtime** `--theme-color-*` variable:
```css
@theme inline {
    --color-primary: var(--theme-color-primary);
    --color-primary-border: color-mix(in oklch, var(--color-primary) 100%, black 20%);
    --color-primary-hover: color-mix(in oklch, var(--color-primary) 100%, black 30%);
    --color-success: var(--theme-color-success);
    --color-gray-50 … --color-gray-950;   /* incl. non-standard 150, 850, 925 */
    --color-body-bg;  --color-body-border;
    --color-content-bg; --color-content-border;
    --color-global-header-bg; --color-progress-bar; --color-focus-outline;
    --color-ui-accent-bg; --color-ui-accent-text; --color-switch-bg;
    --color-volt: oklch(93.86% 0.2018 122.24);
}
```
The `--theme-color-*` values are injected at runtime into a `<style id="theme-colors">` tag by
`resources/js/components/themes/index.ts` (`applyTheme`). Defaults come from PHP —
`src/CP/Color.php:388-426`:
```php
'primary'   => self::Indigo[700],
'gray-*'    => self::Zinc[*],
'success'   => self::Green[400],
'danger'    => self::Red[600],
'body-bg'   => self::Zinc[100],   'dark-body-bg'   => self::Zinc[900],
'content-bg'=> 'white',            'dark-content-bg'=> self::Zinc[900],
'global-header-bg' => self::Zinc[800],
'progress-bar'     => self::Volt,
'focus-outline'    => self::Blue[400],
```

**Consequence:** users can re-theme the whole CP (`themes/Custom.vue`, gray palette picker).
If your addon hard-codes `bg-white`, `text-slate-700`, `bg-indigo-600`, it breaks the moment
someone changes the theme. Use `bg-content-bg`, `text-gray-700`, `bg-primary`, `border-content-border`.

The full user-themeable list is `ColorVariableName` in `resources/js/components/themes/types.ts:5-32`.

### 7.3 Dark mode — **class, not media**

`app.css:27`:
```css
@custom-variant dark (&:where(.dark, .dark *));
```
Toggled by `resources/js/components/ColorMode.js:38-46`:
```js
watch(this.#mode, (mode) => document.documentElement.classList.toggle('dark', mode === 'dark'), { immediate: true });
```
Preference is `auto | light | dark`; `auto` follows `prefers-color-scheme` and syncs across tabs via
`localStorage['statamic.color_mode']`. **Every addon surface must supply `dark:` variants.**

### 7.4 High-contrast mode

`app.css:183`:
```css
@custom-variant with-contrast (&:where([data-contrast="increased"] *));
```
Driven by the `strict_accessibility` user preference (`layout.blade.php:15`). Core uses it for
stronger borders (`Button.vue:58` `with-contrast:border-gray-500`). Not mandatory for addons, but
`ui-*` components get it for free.

### 7.5 Other tokens — `resources/css/ui.css:136-170`

```css
--max-width-page: 85rem;          /* the content width container: class "max-w-page" */
--text-4xs: 0.4rem;  --text-3xs: 0.5rem;  --text-2xs: 0.7rem;  --text-xs: 0.825rem;
--tracking-tight: -0.01em;
--breakpoint-3xs: 15rem; --breakpoint-2xs: 20rem; --breakpoint-xs: 30rem; --breakpoint-3xl: 120rem;
--shadow-ui-xs / -sm / -md / -lg / -xl;
--animate-wiggle;
```
Spacing/radius/typography otherwise are stock Tailwind 4 defaults. Buttons standardise on
`rounded-lg` (`sm`/`base`/`lg`) and `rounded-md` (`xs`/`2xs`) — `Button.vue:74-80`.
Panels use `rounded-2xl` (`Panel.vue:18`).

Custom utilities you may use (all in `resources/css/core/utilities.css`):
`st-text-trim-start`, `st-text-trim-cap`, `st-text-legibility`, `st-custom-scrollbar`,
`st-mask-horizontal-overflow`, `focus-outline`, `focus-none`, `bg-checkerboard`,
`bg-architectural-lines`, `shape-squircle`, `animate-pulse-on-appearance`.

### 7.6 Icons

- Set: `resources/svg/icons/*.svg` — **548 files**, referenced by bare filename.
- Component: `<ui-icon name="cog" />` / `<Icon name="cog" />`. `Icon.vue` props: `name` (required),
  `set` (default `'default'`). Also accepts `set::name` syntax and a raw `<svg …>` string
  (sanitised with DOMPurify).
- Default class is `size-4 shrink-0` (`Icon.vue:96`) — override with `class`.
- **No SVG sprite.** Icons are lazily `import.meta.glob`'d as raw strings
  (`Icon/registry.js:26`).
- Addon icon sets (PHP): `Icon::register('my-addon', __DIR__.'/../resources/svg')`
  (`src/Icons/IconManager.php:11`; `'default'` is reserved). They're serialised to
  `config.customSvgIcons` and registered client-side via `registerIconSetFromStrings`
  (`statamic.js:345-350`). Then use `<ui-icon name="my-addon::thing" />`.
- Anywhere a component takes an `icon` prop it takes an icon **name**, not markup.

### 7.7 How an addon consumes all of this

`src/Console/Commands/stubs/addon/addon.css.stub` (whole file):
```css
@import '@statamic/cms/tailwind.css';
@source '../js';

/** Your custom styles go here */
```
`packages/cms/src/tailwind.css`:
```css
@layer addon-theme, addon-utilities;
@import "tailwindcss/theme.css" layer(addon-theme) source(none);
@import "tailwindcss/utilities.css" layer(addon-utilities) source(none);
@import "./ui.css";
@custom-variant dark (&:where(.dark, .dark *));
```
This gives you core's theme variables and the `dark` variant, in dedicated cascade layers that sit
below core's `ui`/`ui-states` layers so **your CSS can never out-specify a `ui-*` component**.
`source(none)` means you must declare your own `@source` globs.

**Do not create your own `tailwind.config.js`. Do not `@import 'tailwindcss'` yourself.**
In most addons you should not need a CSS file at all — note that the stub vite config has the CSS
entry commented out.

---

## 8. Nav & permissions

### 8.1 `Nav::extend`

`src/CP/Navigation/Nav.php:16` + magic section methods at `:185`
(`Nav::tools('X')` == `Nav::findOrCreate('Tools', 'X')`; also `content`, `fields`, `settings`,
`users`, `topLevel`, or any custom section name).

Real core example — `src/CP/Navigation/CoreNav.php:241-258`:
```php
Nav::tools('Forms')
    ->route('forms.index')
    ->icon('forms')
    ->can('index', Form::class)
    ->extra(['breadcrumbs' => ['create_label' => 'Create Form', 'create_url' => cp_route('forms.create')]])
    ->children(function () {
        return FormAPI::all()->sortBy->title()->map(fn ($form) =>
            Nav::item($form->title())->url($form->showUrl())->can('view', $form));
    });
```

`NavItem` fluent API (`src/CP/Navigation/NavItem.php`):
`display()` `:45` · `section()` `:56` · `id()` `:67` · `route($name, $params)` `:106` ·
`url()` `:117` · `icon()` `:195` (name from the icon set, or a raw `<svg>` string) ·
`attributes(array)` `:233` · `extra(array)` `:246` · `children($items|Closure)` `:260` ·
`can($ability, $args)` `:397` (alias of `authorization()` `:376`) · `view($bladeView)` `:494` ·
`order(int)` `:505` · `hidden(bool)` `:516`.

**There is no `badge()` method.** Badges are done through `view()` — see
`resources/views/nav/updates.blade.php` which renders `<updates-badge>`
(`CoreNav.php:260-264`).

Removal: `Nav::remove($section, $name = null, $childName = null)` (`Nav.php:85`).

### 8.2 How nav renders

Still a **left sidebar** (`resources/js/components/nav/Nav.vue:199`, `<nav class="nav-main">`),
plus a new fixed 56px global top header (`components/global-header/Header.vue:13`) holding logo,
breadcrumbs, site selector, command-palette search, and the user dropdown.
Sidebar is collapsible (`command+\` or `[`), persisted in `localStorage['statamic.nav']`.

Nav data is **server-rendered into Inertia shared props**, shaped at
`src/Http/Middleware/CP/HandleAuthenticatedInertiaRequests.php:103-119`
(`id, display, icon, url, attributes, active, children, extra, view`).

### 8.3 Permissions

```php
Permission::extend(function () {
    Permission::group('my-addon', __('My Addon'), function () {
        Permission::register('view my-addon')->label(__('View'))->children([
            Permission::make('edit my-addon')->label(__('Edit')),
        ]);
    });
});
```
`src/Auth/Permissions.php`: `extend($cb)` `:39`, `make($value)` `:44`, `register($p, $cb)` `:55`,
`group($name, $label, $permissions)` `:121`.
`src/Auth/Permission.php`: `value()` `:28` · `label()` `:52` · `description()` `:225` ·
`group()` `:220` · `children()` `:164` · `addChild()` `:177` ·
`replacements($placeholder, $cb)` `:82` · `hiddenBy($p)` `:230`.

Real core example — `src/Auth/CorePermissions.php:97-114`:
```php
$this->register('view {collection} entries', function ($permission) {
    $this->permission($permission)->hiddenBy('configure collections')->children([...])
        ->replacements('collection', fn () => Collection::all()->map(fn ($c) =>
            ['value' => $c->handle(), 'label' => __($c->title())]));
});
```
Addons get a free settings permission: `"edit {$addon->package()} settings"` (`CorePermissions.php:230`).

### 8.4 Gating the UI

**PHP.** `NavItem::can()` is enforced at build time — `src/CP/Navigation/NavBuilder.php:210-219`:
```php
return collect($items)->filter(fn ($item) => $item->authorization()
    ? User::current()->can($item->can()->ability, $item->can()->arguments) : true)->all();
```
Policies live in `src/Policies/` and follow the `before($user)` super/`configure X` shortcut pattern
(`src/Policies/CollectionPolicy.php:12-49`).

**JS.** Two paths, both current:
```js
this.can('edit my-addon')                  // global helper, statamic.js:314-318 (base64 config blob)
Statamic.$permissions.has('edit my-addon') // components/Permission.js (reads Statamic.user.permissions)
```
Both treat `super` as always true. `can()` is the one used in templates.

---

## 9. Antipatterns — what makes an addon look non-native in Statamic 6

Ordered roughly by how loudly each one shouts "third-party addon".

1. **Shipping your own Tailwind build.** A `tailwind.config.js` + `@import 'tailwindcss'` in the
   addon produces a second, unlayered utility set with stock colours. Use
   `@import '@statamic/cms/tailwind.css'` + `@source` (`addon.css.stub`) — or, better, no CSS at all.

2. **Bundling Vue.** Omitting `statamic()` from the vite plugins means your bundle carries its own
   Vue. Provide/inject silently returns `null`, the publish context breaks, reactivity fragments.
   `packages/cms/src/vite-plugin/externals.js` exists precisely to prevent this.

3. **Hard-coded colours.** `bg-white`, `bg-slate-50`, `text-gray-700` (the *stock* gray),
   `bg-indigo-600`, hex values. Core's colours are runtime-themeable (`--theme-color-*`,
   `src/CP/Color.php:388-426`); yours are not. Use `bg-content-bg`, `bg-body-bg`, `bg-primary`,
   `text-gray-900`, `border-content-border` — all of which resolve through the user's theme.

4. **No dark-mode variants.** Dark mode is class-based and always available
   (`app.css:27`, `ColorMode.js:42`). A surface without `dark:` classes glows white in dark mode.

5. **Hand-rolled buttons.** `<button class="px-4 py-2 rounded bg-blue-500 text-white">` will never
   match `Button.vue`'s 8 variants × 5 sizes, gradient, inset shadow, icon slotting, disabled
   states, and Inertia `<Link>` switching. Use `<ui-button>`.

6. **Hand-rolled modals/overlays.** Core modals participate in the portal stack, the esc-key
   binding stack, and `FocusScope` trapping-on-top-portal. A bespoke `<div class="fixed inset-0">`
   will steal `esc` from a parent stack, break focus return, and z-fight.

7. **v5-era page shells.** `Statamic::layout()` **is gone** (`grep -n "layout" src/Statamic.php`
   → zero hits) and core itself has no Blade CP pages left
   (`grep -rn "@extends" resources/views` → zero hits). A Blade view that
   `@extends('statamic::layout')` + `@section('content')` still renders, but only through the
   `NonInertiaPage` compatibility path (`statamic.js:206-212`) — no breadcrumbs, no Inertia
   navigation, no shared props. Ship an Inertia page instead.

8. **v5-era class names.** `.card`, `.btn`, `.btn-primary`, `.publish-fields`, `.flexy`,
   `.little-heading`, `.subhead`, `.outline-none`. Statamic 6 has no `.btn` (only two stray
   references remain, in `assets.css:85` and `datetime.css:93`). These render as unstyled markup.

9. **`Statamic.$fieldtypes` / `Statamic.componentExists()`.** Both removed — zero grep hits.
   Use `Statamic.$components.register(name, c)` and `Statamic.$components.has(name)`.

10. **Forgetting `defineExpose(expose)` in a `<script setup>` fieldtype.** Field actions and
    replicator previews silently stop working (`Publish/Field.vue:94-96`).

11. **Building against `resources/js/components/data-list/`.** It's the v5 namespace; the DataList
    components are deleted. Use `ui/Listing/`. (Exception: the `{fieldtype}-fieldtype-index`
    *naming convention* resolved by `TableField.vue` is still current.)

12. **A listing that isn't `<Listing>`.** A hand-built `<table>` loses search, filters, presets,
    column customisation, bulk actions, keyboard bulk shortcuts, sticky headers, drag reordering
    and pagination — and looks visibly different from Entries.

13. **Non-Inertia page mutations.** Using `axios.post` + a manual reload instead of
    `router.post(url)` breaks the progress bar, flash toasts, dirty-state guard and back-button
    behaviour.

14. **Page-level actions that aren't command-palette entries.** Every core primary action is
    wrapped in `<CommandPaletteItem>`. Skipping it makes the addon feel inert next to core.

15. **Custom width containers.** `max-w-7xl`, `container mx-auto`, fixed pixel widths. The token is
    `max-w-page` (`--max-width-page: 85rem`), and the user can toggle full width via the header's
    `MaxWidthButton`.

16. **Inline SVG icons.** Every `icon` prop wants a **name** from the 548-icon set (or your own
    registered set via `Icon::register()`), not markup. Pasting an SVG gets you wrong size, wrong
    colour, wrong opacity behaviour.

17. **Untranslated strings.** Core's PR checklist (`CLAUDE.md:94-98`) requires every user-facing
    string to go through `__()`. Raw English text is an instant tell.

18. **Manual dirty-state / unsaved-changes handling.** `Statamic.$dirty` already owns
    `beforeunload`, the Inertia `before` hook and `popstate`. A second `onbeforeunload` produces a
    double prompt.

19. **Interactive content sitting directly on a `Panel`.** `Panel` is the grey frame — `bg-gray-150
    dark:bg-gray-950/35 … p-1.75` (`ui/Panel/Panel.vue`). The padding that content needs lives on
    `Card` (`px-4.5 py-5 space-y-2`), not on the panel. Core puts nothing but a heading, a
    subheading and header actions on the grey; every publish section is `Panel > PanelHeader >
    Card > Fields` (`ui/Publish/Sections.vue`), and `CardPanel` is the shorthand. The single
    exception is a table: `Listing` drops `Table` straight into the `Panel`. Inputs and buttons on
    bare grey are the loudest "not core" signal after a hand-rolled button.

20. **An inline form for creating or editing a record.** The CP has no such surface. Creating and
    editing happen on their own page, or in a `Stack` sliding in from the right — the same
    component the listing's own filters use (`ui/Listing/Filters.vue`): `Stack size="narrow|half"`
    holding `Panel > PanelHeader > Card`, with a primary button and a ghost Cancel underneath. A
    form wedged above the table (and a second one below it for the row you picked) is a shape core
    uses nowhere.

21. **A card list where a table belongs.** Three panels of cards grouped by state is not how the CP
    shows records — `Listing` is, and it brings search, sortable columns, a column picker, row
    actions and pagination that a card list has to reimplement and never does. The grouping
    survives as a column plus a set of filter buttons above the table.

22. **`Badge color="default" size="sm"` as a status.** That combination is visually a broken
    button, and the reason is in the class strings: `color: "default"` is
    `bg-gray-50 dark:bg-gray-800 border-gray-300 …` plus the base `border` — the same chip as
    `Button variant="default"` minus the gradient — and `size: "sm"` adds
    `rounded-[0.1875rem]`, a 3px radius where every button is `rounded-lg`. A status is
    `StatusIndicator` when it is one of the five publish states, otherwise a `Badge` with `pill`
    and a semantic colour and **no** `size` (core's own example:
    `pages/collections/Index.vue` renders green/yellow/default `pill` badges for
    Published/Scheduled/Drafts).

23. **Printing a raw column value at a reader.** `open`, `won`, `note_added`,
    `leadhub.score_changed`. The database's vocabulary is not the user's. Resolve the label next
    to the value — server-side, where the translation table is — and never fall back to the key
    when a label is missing; fall back to a generic sentence.

24. **A `Button variant="danger"` in a page header.** Core uses `danger` in exactly one place: the
    confirm button inside a modal (`ConfirmationModal`: `variant: danger ? "danger" : "primary"`).
    A destructive page action is a `DropdownItem variant="destructive"` (the only two values are
    `default` and `destructive`) inside the header's `…` menu. And that menu already renders its
    own trigger — `Dropdown` defaults to `Button icon="dots" variant="ghost" size="sm"`, so
    passing a `#trigger` duplicates core. Header order is: the `…` dropdown first, the primary
    action last (`pages/user-groups/Show.vue`, `pages/taxonomies/Show.vue`).

25. **A relationship panel that only reports.** A panel listing linked records needs the action
    that creates the link, and it goes **below** the list, not in the panel header — that is the
    relationship fieldtype's shape (`components/inputs/relationship/RelationshipInput.vue`):
    `Button size="sm" icon="link"` with no `variant`, beside the create button. Core has no
    example of "list + add" in a panel header; `header-actions` is used for bulk toggles.

26. **An active filter with nothing on screen saying so.** A dashboard tile linking to
    `?from=2026-08-27` leaves a table showing 3 of 19 rows and looking broken. The CP's answer is a
    chip per active filter beside the filter control, each clearing itself:
    `Button as="div" variant="filled"` wrapping a `Button variant="ghost" size="xs" icon="x"
    icon-only inset` (`ui/Listing/Filters.vue`). The native way to own the filter itself is a
    PHP `Statamic\Query\Scopes\Filter` registered in the provider's `$scopes` and passed down as
    the `filters` prop via `Scope::filters($key, $context)` — then the addon writes no filter UI
    at all. `Listing` has **no slot** for injecting filter markup.

27. **No dirty-state guard at all.** Antipattern 18 warns against a *second* handler beside
    `Statamic.$dirty`. The commoner mistake is the opposite one: never registering with it. A
    sweep over this family on 03.09.2026 found `grep -rl '\$dirty' resources/js` returning zero
    files in **all twelve addons that write**, across 43 pages that mutate. Half-edited settings,
    a half-drawn automation and a half-filled publish form all vanish on a stray click with no
    prompt, because the guard core already owns was never told the page had unsaved work. The
    call is `Statamic.$dirty.state(name, bool)`.

    **Do not bolt this onto a hand-built form without reading the next paragraph.** Attempted on
    03.09.2026 and reverted. Saving is itself an Inertia visit, so with the flag up core's
    `$dirty` challenges *the request that saves the work*: the user gets a confirmation dialog on
    pressing Save, and a dismissed dialog cancels the visit — **the save silently never happens**,
    with nothing in the console. Measured both ways: with the guard the `PATCH` was never sent,
    without it the same click sent it. Clearing the flag in the visit's `onBefore` does not help
    (core hooks the router's global `before`, which runs first), and clearing it synchronously
    while building the options did not either.

    Core's own answer is `PublishContainer`'s `trackDirtyState` prop
    (`dist-package/types/components/ui/Publish/Container.vue.d.ts:56`), which owns the whole
    lifecycle. A screen that wants the guard probably wants to be a publish form. Whoever wires
    it by hand must copy `PublishContainer`'s ordering around its own save, and must prove it
    with a **browser** test asserting the request actually goes out — no unit test and no console
    error catches this.

---

## 9.1 Silent failures — the ones that cost the most time

Everything above looks wrong. These do nothing at all, and say nothing while doing it. No Vue
warning, no console error, no failing test. Each one shipped in a real addon and survived review.

| Mistake | What you see | Why |
|---|---|---|
| A slot name `Listing` does not have (`#actions`, `#empty`) | The action never renders. In one addon the entire "edit" path was unreachable for months. | Vue drops unknown slots silently. The full set is `initializing`, `default`, `prepended-row-actions`, plus `cell-{name}` and `tbody-start` from the table children. |
| An icon name that is not in the set | An empty box the width of an icon. Next to a `Header` title it reads as "the heading is indented". | `Icon` renders nothing for an unknown name. Check against `resources/svg/icons/*.svg` — 548 names, and `user`, `add`, `check`, `tags`, `tasks`, `archive`, `list`, `refresh`, `chart-pie`, `file`, `book-open-cover` are **not** among them (`users`, `plus`, `checkmark`, `fieldtype-taggable`, `clipboard-check`, `package-box-crate`, `layout-list`, `sync`, `charts-donut-graph`, `file-content-list`, `content-book-open` are). **Two spellings, and the second is the one people forget:** `icon="…"` as a prop on any component, and `name="…"` on a standalone `<Icon>`. A check that only reads the first misses every icon rendered on its own — which is how `chart-pie` survived a whole pass over one addon. |
| `Combobox`/`Select` bound to `''` | The field renders blank — no placeholder, no value — with a clear button offering to clear nothing. | An empty string counts as a selection, so the trigger renders `getOptionLabel(selectedOption)`, which is empty, instead of the placeholder branch. Bind `null`. |
| `Checkbox` in a table cell without `solo` | The literal text `false` printed where the label goes. | `solo` is documented as exactly this case: "hides the label and description … like in a table cell". |
| `Select` in a narrow container without `adaptive-width` | Options truncated to `Qualif…` in a popover no wider than the trigger. | The only width rule is `min-w-[--reka-combobox-trigger-width]` and option labels carry `truncate`. `adaptive-width` adds `w-max max-w-md` and renders a hidden measuring block of all labels. |
| **A prop the component has not got** | Nothing. Vue passes an unknown prop through as a plain HTML attribute, so it lands in the DOM and has no effect. | The worst of the family, because the damage scales with what the prop was for. Real examples, all shipped: `TabTrigger :label` (it takes `text`/`name`) rendered an empty tab strip and made **two whole tabs unreachable**; `Alert variant="danger"` and `variant="info"` (it knows `default\|warning\|error\|success`) drew a failure and a hint in the neutral style; `DropdownItem danger` instead of `variant="destructive"` coloured nothing; `Panel collapsible` (it takes `heading`/`subheading`/`icon`) never collapsed; `CommandPaletteItem @click` instead of `:action` produced a palette entry that did nothing. **Look the props up in `dist-package/types/components/ui/*.d.ts` — do not infer them from the name.** |

`tools/ui-sweep.sh` checks all five of these plus the mechanical half of §9:

```bash
bash <studio>/tools/ui-sweep.sh <addon-path>   # or no argument for the whole family
```

Two things it had to learn the hard way, and which any replacement needs too:

- **Read whole tags, not lines.** A Vue tag routinely spans six lines, so a line-based grep
  reports every multi-line `<Checkbox>` as missing the `solo` that sits two lines down.
- **Scope each rule to where the mistake is a mistake.** `#actions` is correct on `Header` and
  dead only inside `<Listing>`. `variant="danger"` is wrong on a header `Button` and right on
  `<Text>` for an error message. A `''`-bound combobox is correct when the option list really
  carries a `value: ''` entry ("No opportunity"). Without those three exclusions the first run of
  this sweep produced more false alarms than findings — which is worse than no tool, because
  somebody acts on them.

---

## Appendix: quick reference for a generator

- Global tag form: `ui-` + kebab-case of the export name from `packages/cms/src/ui.js`.
- Import form: `import { X } from '@statamic/cms/ui'`.
- Runtime singletons: `import { toast, keys, stacks, … } from '@statamic/cms/api'` **or**
  `Statamic.$toast`, `Statamic.$keys`, `Statamic.$stacks`, … (`bootstrap/statamic.js:68-162`).
- Inertia helpers: `import { Head, Link, router, useForm, usePoll, Form,
  useArchitecturalBackground, toggleArchitecturalBackground } from '@statamic/cms/inertia'`
  (`resources/js/bootstrap/cms/inertia.js` — note `Head` is Statamic's `pages/layout/Head.vue`).
- Global helpers available in every component template (`bootstrap/statamic.js:298-322`):
  `__(key, replacements)`, `__n(key, n, replacements)`, `$markdown(value, opts)`, `cp_url(url)`,
  `docs_url(url)`, `can(permission)`, `$wait(ms)`.
- Shared Inertia props: `useStatamicPageProps()` (`resources/js/composables/page-props.js:4-12`)
  → `cmsName, isPro, version, nav, licensing, supportUrl, selectedSiteUrl, logos, sessionExpiry`.
- Storybook is the canonical component docs: `resources/js/stories/*.stories.ts` +
  `resources/js/stories/docs/*.mdx`, published at <https://ui.statamic.dev>
  (`resources/boost/guidelines/core.blade.php:94`).
- Scaffolding commands: `php please statamic:setup-cp-vite`, `make:fieldtype`, `make:widget`,
  `make:addon` — they write the vite config, `package.json` scripts (`cp:dev`, `cp:build`) and the
  correct stubs (`src/Console/Commands/SetupCpVite.php`).
