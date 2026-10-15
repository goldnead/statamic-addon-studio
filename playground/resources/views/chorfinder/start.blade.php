@extends('chorfinder.layout')
@section('titel', 'Start')
@section('inhalt')
<section class="cf-hero">
    <p class="cf-vorspann">Eine erfundene Chorplattform auf Statamic-Addons</p>
    <h1>Chorfinder: Chöre finden, ansprechen, miteinander reden.</h1>
    <p class="cf-lead">
        Diese Seiten gibt es nur in der Demo. Sie sind auf sechs Addons gebaut, von denen drei
        bewusst keine eigene öffentliche Oberfläche mitbringen. Die Seiten rufen nur deren Dienste
        auf; jede Regel (wer darf was) liegt im Addon.
    </p>
    <p class="cf-zahlen">
        <span><strong>{{ $zahlen['profile'] }}</strong> öffentliche Chorprofile</span>
        <span><strong>{{ $zahlen['gespraeche'] }}</strong> Gespräche</span>
        <span><strong>{{ $zahlen['beitraege'] }}</strong> Beiträge</span>
        <span><strong>{{ $zahlen['zusagen'] }}</strong> Zusagen</span>
    </p>
</section>

<section class="cf-block">
    <h2>Wer bist du?</h2>
    <p>Oben stehen vier Personen zur Wahl. Sie haben bestätigte Adressen, aber unterschiedliche Rollen. Das ändert, was die Seiten anbieten.</p>
    <dl class="cf-personen">
        @foreach($personen as $p)
            <div>
                <dt>{{ $p['name'] }}</dt>
                <dd>{{ $p['rolle'] }}</dd>
                <form method="post" action="/chorfinder/anmelden">@csrf<input type="hidden" name="weiter" value="/chorfinder">
                    <button name="person" value="{{ $p['schluessel'] }}" class="cf-knopf cf-knopf--klein">Als {{ explode(' ', $p['name'])[0] }} anmelden</button>
                </form>
            </div>
        @endforeach
    </dl>
</section>

<section class="cf-block">
    <h2>Sechs Bereiche, sechs Addons</h2>
    <div class="cf-raster">
        <a class="cf-kachel" href="/chorfinder/verzeichnis">
            <h3>Verzeichnis</h3>
            <p><code>statamic-directory</code> 0.1.1. Suche nach Name, Stadt und Art, mit Umkreis um sechs Städte. Der Tag <code>&#123;&#123; directory:search &#125;&#125;</code> liefert die Liste.</p>
        </a>
        <a class="cf-kachel" href="/chorfinder/verzeichnis/ansprueche">
            <h3>Ansprüche</h3>
            <p><code>statamic-teams</code> 0.4.0. Ein Chor beansprucht sein Profil; die Moderation entscheidet, bei „Annehmen“ entsteht das Team. Offen ist einer.</p>
        </a>
        <a class="cf-kachel" href="/chorfinder/nachrichten">
            <h3>Nachrichten</h3>
            <p><code>statamic-messaging</code> 0.1.1. Gespräche an ein Team, anonym oder offen, mit „Profil freigeben“. Verschlüsselt gespeichert.</p>
        </a>
        <a class="cf-kachel" href="/chorfinder/community">
            <h3>Community</h3>
            <p><code>statamic-community</code> 0.1.1. Sechs Räume, Beiträge, Kommentare, Reaktionen und ein angehefteter Beitrag. Ein Beitrag ist ausgeblendet.</p>
        </a>
        <a class="cf-kachel" href="/chorfinder/veranstaltungen">
            <h3>Veranstaltungen</h3>
            <p><code>statamic-events</code> 2.8.0. Zusagen („ich komme“, „interessiert“, „nicht dabei“) mit Zahlen für alle und Namen nur für den Kalender-Pfleger. Dazu das Programm.</p>
        </a>
        <a class="cf-kachel" href="/chorfinder/meldungen">
            <h3>Meldungen</h3>
            <p><code>statamic-notifications</code> 1.11.0. Meldungen gehen verzögert hinaus; die Warteschlange zeigt zugestellt, übersprungen und offen.</p>
        </a>
    </div>
</section>

<section class="cf-block">
    <h2>Im Control Panel</h2>
    <p>
        Dieselben Daten, von der anderen Seite: <a href="/cp/auth/login">Control Panel</a> mit
        <code>mira@nordlicht.beispiel</code> / <code>demo-local-password</code>. Dort liegen
        <a href="/cp/directory">Directory</a>, <a href="/cp/messaging">Nachrichten</a> (nur Metadaten, nie der Text),
        <a href="/cp/community/posts">Community</a>, Veranstaltungen mit Zusagen und Programm, und die Teams.
    </p>
</section>
@endsection
