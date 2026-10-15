@extends('chorfinder.layout')
@section('titel', 'Meldungen')
@php($statusText = ['pending' => 'wartet', 'retry' => 'wiederholt', 'accepted' => 'zugestellt', 'skipped' => 'übersprungen', 'cancelled' => 'abgebrochen', 'failed' => 'fehlgeschlagen'])
@section('inhalt')
<h1>Meldungen</h1>
<p class="cf-lead">
    Nachrichten, Kommentare und Entscheidungen lösen Meldungen aus (<code>statamic-notifications</code> 1.11.0).
    Sie werden <strong>geschrieben, nicht gesendet</strong>: ein Befehl stellt sie später zu, mit Wiederholung.
    Die Demo-Mails landen im Systemlog, nie bei einer echten Adresse.
</p>

<section class="cf-block">
    <h2>Warteschlange</h2>
    <p>
        <strong>{{ $offen }}</strong> {{ $offen === 1 ? 'Zeile wartet' : 'Zeilen warten' }} auf die Zustellung.
        Auf einer Site läuft der Befehl <code>notifications:dispatch-pending</code> jede Minute; hier stößt du ihn selbst an.
    </p>
    <form method="post" action="/chorfinder/meldungen/zustellen" class="cf-formular cf-formular--zeile">
        @csrf
        <button class="cf-knopf">Jetzt zustellen</button>
    </form>

    <table class="cf-tabelle">
        <thead><tr><th>Meldung</th><th>Kanal</th><th>Stand</th><th>Grund</th><th>Versuche</th></tr></thead>
        <tbody>
        @forelse($zeilen as $z)
            <tr>
                <td>{{ $z['nachricht'] }}</td>
                <td>{{ $z['kanal'] === 'in_app' ? 'In der App' : 'E-Mail' }}</td>
                <td><span class="cf-status cf-status--{{ $z['status'] }}">{{ $statusText[$z['status']] ?? $z['status'] }}</span></td>
                <td><code>{{ $z['code'] ?? '–' }}</code></td>
                <td>{{ $z['versuche'] }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="cf-leer">Keine Zustellzeilen.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="cf-klein">„Übersprungen“ heißt hier: Bea hat die Mail für Kommentare ausgestellt, <em>nachdem</em> die Zeile geschrieben war. Die Einstellung wird beim Zustellen noch einmal geprüft.</p>
</section>

<section class="cf-block">
    <h2>Deine Meldungen</h2>
    @if(! $nutzer)
        <p class="cf-leer">Melde dich oben an, um deine eigenen Meldungen zu sehen. <strong>Bea</strong> hat die meisten.</p>
    @else
        <ul class="cf-meldungen">
            @forelse($eigene as $m)
                <li class="{{ $m['gelesen'] ? '' : 'cf-ungelesen' }}">
                    @if($m['link'])<a href="{{ $m['link'] }}">{{ $m['text'] }}</a>@else{{ $m['text'] }}@endif
                    <span class="cf-klein">{{ $m['zeit']?->format('d.m.Y H:i') }}</span>
                </li>
            @empty
                <li class="cf-leer">Keine Meldungen.</li>
            @endforelse
        </ul>

        <form method="post" action="/chorfinder/meldungen/mail" class="cf-formular cf-formular--zeile">
            @csrf
            <label class="cf-haken"><input type="checkbox" name="an" value="1" @checked($mailAn)> Mail bei Kommentaren zu meinen Beiträgen</label>
            <button class="cf-knopf cf-knopf--leise">Speichern</button>
        </form>
    @endif
</section>
@endsection
