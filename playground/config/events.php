<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Control Panel
    |--------------------------------------------------------------------------
    |
    | The kill switch bites on the nav entry *and* on the routes — see
    | routes/cp.php. Hiding the entry while leaving the screens reachable by URL
    | is not a disabled Control Panel.
    |
    */

    'cp' => [
        'enabled' => true,
        'per_page' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults for new events
    |--------------------------------------------------------------------------
    |
    | `timezone` is the IANA identifier a new event starts with. Null falls back
    | to the application timezone. Occurrences may override it per date, which is
    | what a tour needs.
    |
    | `types` is the option list the Control Panel offers. The stored value is a
    | free string, so removing a type here never orphans an existing event; it
    | only stops being offered.
    |
    */

    'defaults' => [
        'timezone' => null,
        'visibility' => 'public',
    ],

    'types' => [
        'workshop' => 'Werkstatt',
        'masterclass' => 'Meisterkurs',
        'concert' => 'Konzert',
        'rehearsal' => 'Probe',
        'course_session' => 'Kurstermin',
        'other' => 'Sonstiges',
    ],

    /*
    |--------------------------------------------------------------------------
    | Calendar feeds and ICS downloads
    |--------------------------------------------------------------------------
    |
    | `name` becomes X-WR-CALNAME. Null falls back to the application name.
    |
    | `past_days` is how far back a feed reaches. Subscribers generally want the
    | recent past visible so a just-finished date does not vanish mid-day.
    |
    | `max_occurrences` caps a single feed response. A feed is a public,
    | unauthenticated endpoint; without a cap one query can be made arbitrarily
    | expensive from the outside.
    |
    */

    'feeds' => [
        'enabled' => true,

        // Der Rückfall, wenn keine Marke aufgelöst ist. Ohne ihn heißt der
        // Kalender im Telefon „Statamic", weil das der Anwendungsname ist.
        'name' => 'Nordlicht Studio, Termine',

        /*
        | Ein Name je Marke.
        |
        | Das Addon liest diesen Schlüssel NICHT — es kennt nur `feeds.name`,
        | und `config()` wird beim Hochfahren gelesen, lange bevor irgendetwas
        | weiß, wessen Kalender gerade abgeholt wird. Angewendet wird die
        | Zuordnung deshalb zur Laufzeit von
        | App\Http\Middleware\MarkeAusDemPfad, sobald die Marke der Anfrage
        | feststeht. Hier steht sie, weil sie hierher gehört: es ist eine
        | Einstellung, keine Logik.
        |
        | Der Name landet als X-WR-CALNAME im .ics und ist das, was in einem
        | fremden Kalender in der Seitenleiste steht. „Statamic" wäre dort die
        | schlechteste aller Antworten.
        */

        'names_per_brand' => [
            'nordlicht' => 'Nordlicht Studio, Termine',
            'chorwerkstatt' => 'Chorwerkstatt Nord, Kurse und Werkstätten',
            'halbmond' => 'Kollektiv Halbmond, Livetermine',
            'lindhorst' => 'Praxis Lindhorst, Sprechstunden',
        ],

        'past_days' => 1,
        'max_occurrences' => 500,
        'cache_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional sibling bridges
    |--------------------------------------------------------------------------
    |
    | Never Composer requirements — each one attaches only when the sibling
    | addon is actually installed (class_exists). A concert calendar on an
    | artist's website has to install without a CRM.
    |
    */

    'bridges' => [
        'activity' => true,
    ],

];
