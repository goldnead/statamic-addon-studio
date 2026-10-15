<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('titel', 'Chorfinder') · Chorfinder-Demo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500&display=swap">
    <link rel="stylesheet" href="/marken/chorfinder.css">
</head>
<body class="cf">
@php($weiter = '/'.ltrim(request()->getRequestUri(), '/'))
<header class="cf-kopf">
    <div class="cf-breit cf-kopf-zeile">
        <a class="cf-marke" href="/chorfinder">Chorfinder <span class="cf-etikett">Demo</span></a>
        <nav class="cf-nav" aria-label="Bereiche">
            <a href="/chorfinder/verzeichnis" @class(['aktiv' => $aktiv === 'verzeichnis' || $aktiv === 'chor' || $aktiv === 'ansprueche'])>Verzeichnis</a>
            <a href="/chorfinder/nachrichten" @class(['aktiv' => $aktiv === 'postfach' || $aktiv === 'verlauf'])>Nachrichten @if($ungelesen > 0)<span class="cf-zahl" title="ungelesen">{{ $ungelesen }}</span>@endif</a>
            <a href="/chorfinder/community" @class(['aktiv' => in_array($aktiv, ['community', 'raum', 'beitrag'])])>Community</a>
            <a href="/chorfinder/veranstaltungen" @class(['aktiv' => $aktiv === 'veranstaltungen'])>Veranstaltungen</a>
            <a href="/chorfinder/meldungen" @class(['aktiv' => $aktiv === 'meldungen'])>Meldungen</a>
        </nav>
    </div>
</header>

<div class="cf-person">
    <div class="cf-breit cf-person-zeile">
        @if($nutzer)
            <span>Angemeldet als <strong>{{ $nutzerName }}</strong>@if($moderation) <span class="cf-etikett">Moderation</span>@endif</span>
            <span class="cf-person-wechsel">
                @foreach($personen as $p)
                    @if(strtolower($nutzer->email()) !== strtolower(\App\Demo\SeedsChorfinder::email($p['schluessel'])))
                        <form method="post" action="/chorfinder/anmelden">@csrf<input type="hidden" name="weiter" value="{{ $weiter }}"><button name="person" value="{{ $p['schluessel'] }}" class="cf-link" title="{{ $p['rolle'] }}">als {{ explode(' ', $p['name'])[0] }}</button></form>
                    @endif
                @endforeach
                <form method="post" action="/chorfinder/abmelden">@csrf<input type="hidden" name="weiter" value="{{ $weiter }}"><button class="cf-link">abmelden</button></form>
            </span>
        @else
            <span>Du bist nicht angemeldet. Für Schreiben, Anfragen und Zusagen eine Demo-Person wählen:</span>
            <span class="cf-person-wechsel">
                @foreach($personen as $p)
                    <form method="post" action="/chorfinder/anmelden">@csrf<input type="hidden" name="weiter" value="{{ $weiter }}"><button name="person" value="{{ $p['schluessel'] }}" class="cf-knopf cf-knopf--klein" title="{{ $p['rolle'] }}">{{ $p['name'] }}</button></form>
                @endforeach
            </span>
        @endif
    </div>
</div>

<main class="cf-breit cf-haupt">
    @if(session('ok'))<p class="cf-hinweis cf-hinweis--ok" role="status">{{ session('ok') }}</p>@endif
    @if(session('fehler'))<p class="cf-hinweis cf-hinweis--fehler" role="alert">{{ session('fehler') }}</p>@endif
    @if($errors->any())<p class="cf-hinweis cf-hinweis--fehler" role="alert">{{ $errors->first() }}</p>@endif

    @yield('inhalt')
</main>

<footer class="cf-fuss">
    <div class="cf-breit">
        <p>Alles hier ist erfunden: Chöre, Personen, Beiträge, Nachrichten. Nur die Städte sind echt. Jede Nacht um 03:17 UTC setzt sich die Demo zurück.</p>
        <p>E-Mails gehen nicht hinaus, sie landen im Systemlog. Echtzeit (Reverb) läuft in dieser Demo nicht; Nachrichten erscheinen nach dem Neuladen.</p>
        <p>
            <a href="/">Zur Demo-Startseite</a> ·
            <a href="https://docs.adriangoldner.dev/directory/">Directory</a> ·
            <a href="https://docs.adriangoldner.dev/messaging/">Messaging</a> ·
            <a href="https://docs.adriangoldner.dev/community/">Community</a> ·
            <a href="https://docs.adriangoldner.dev/teams/claims">Ansprüche</a> ·
            <a href="https://docs.adriangoldner.dev/events/rsvp">Zusagen</a> ·
            <a href="https://docs.adriangoldner.dev/notifications/deferred">Verzögerter Versand</a>
        </p>
    </div>
</footer>
</body>
</html>
