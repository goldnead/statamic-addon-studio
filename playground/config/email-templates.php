<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. When false the addon does not ensure the collection or
    | register its CP navigation entry. The import command and resolver stay
    | callable (they operate on the collection directly).
    |
    */

    'enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Branded layout
    |--------------------------------------------------------------------------
    |
    | Optional. The name of a host-app Blade layout that wraps every rendered
    | template body in the app's branded email shell (header / footer / styling).
    | The layout must expose a `@yield('content')` section; the rendered body is
    | injected there and the subject is passed as `$title`.
    |
    | Default `null` keeps the addon marketplace-generic: bodies render raw, as
    | before. A host app opts in by pointing this at one of its own layouts,
    | e.g. adriangoldner.com sets it to `emails.layout`. Applies to BOTH the CP
    | Live Preview and every real send (they share one render path).
    |
    */

    'branded_layout' => null,

    /*
    |--------------------------------------------------------------------------
    | Per-template layouts
    |--------------------------------------------------------------------------
    |
    | Optional. A map of `handle => Blade-view-name` that lets each individual
    | template ENTRY choose which shell wraps it. The entry's `layout` select
    | field (see the blueprint) is populated from the KEYS of this map, and the
    | chosen key is resolved to its view here. Typical use: transactional mails
    | pick a lean transactional shell, sequences/campaigns pick a marketing one.
    |
    |   'layouts' => [
    |       'sequence'      => 'emails.layouts.sequence',
    |       'transactional' => 'emails.layouts.transactional',
    |   ],
    |
    | Resolution precedence for an entry (see LayoutResolver):
    |   1. the entry's own `layout` handle → its view in this map, else
    |   2. the `default_layout` handle below → its view in this map, else
    |   3. `branded_layout` above (the ultimate, back-compatible fallback).
    |
    | An unknown/blank handle, or a mapped view that does not exist, always
    | falls back down this chain — nothing throws mid-send. Empty by default,
    | so the addon keeps behaving exactly as before (single `branded_layout`).
    |
    */

    // Zwei Hüllen für die Demo, die in resources/views/mail/ liegen. Die Keys
    // ('transactional', 'kampagne') füllen das layout-Select im Blueprint (über
    // Str::headline: "Transactional", "Kampagne"); der Wert ist der Blade-View,
    // der den gerenderten Body umschließt.
    'layouts' => [
        'transactional' => 'mail.transactional',
        'kampagne' => 'mail.kampagne',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default layout
    |--------------------------------------------------------------------------
    |
    | Optional. A handle from the `layouts` map above, used to wrap any entry
    | that does not pick its own `layout`. `null` means fall through to
    | `branded_layout` (the historic single-layout behaviour).
    |
    */

    // Wer keine Hülle wählt, bekommt die transaktionale. So wird eine Vorlage
    // ohne gesetztes layout trotzdem umschlossen statt roh verschickt.
    'default_layout' => 'transactional',

    /*
    |--------------------------------------------------------------------------
    | Preview
    |--------------------------------------------------------------------------
    |
    | The CP preview renders a template with placeholder recipient data so it
    | can be viewed without a real contact. `sample_data` is the documented
    | default merge-variable set; it is deep-merged over the built-in defaults
    | and can be extended or overridden here. Values are referenced in
    | templates as `{{ dotted.key }}`, e.g. `{{ contact.first_name }}`.
    |
    */

    'preview' => [
        'sample_data' => [
            'contact' => [
                'first_name' => 'Maria',
                'last_name' => 'Beispiel',
                'full_name' => 'Maria Beispiel',
                'email' => 'maria.beispiel@example.com',
                'salutation' => 'Hallo Maria',
            ],
            'unsubscribe_url' => 'https://example.com/newsletter/abmelden',
        ],
    ],

];
