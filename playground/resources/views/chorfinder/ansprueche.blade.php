@extends('chorfinder.layout')
@section('titel', 'Ansprüche')
@php($statusText = ['pending' => 'offen', 'approved' => 'angenommen', 'rejected' => 'abgelehnt', 'withdrawn' => 'zurückgezogen'])
@section('inhalt')
<p class="cf-krumen"><a href="/chorfinder/verzeichnis">‹ Verzeichnis</a></p>
<h1>Ansprüche auf Chorprofile</h1>
<p class="cf-lead">
    @if($moderation)
        Du bist Moderation und siehst alle Ansprüche. Entscheiden darfst du jeden, nur nicht deinen eigenen.
    @elseif($nutzer)
        Du siehst deine eigenen Ansprüche. Wer sie entscheidet, ist die Moderation: melde dich oben als „Moderation Chorfinder“ an.
    @else
        Melde dich oben an, um Ansprüche zu sehen. Die Moderation sieht alle.
    @endif
</p>

@forelse($zeilen as $z)
    <article class="cf-anspruch">
        <header>
            <h2><a href="/chorfinder/verzeichnis/{{ $z['chor_id'] }}">{{ $z['chor'] }}</a></h2>
            <span class="cf-status cf-status--{{ $z['status'] }}">{{ $statusText[$z['status']] ?? $z['status'] }}</span>
        </header>
        <p class="cf-klein">von {{ $z['wer'] }}, {{ $z['zeit']?->format('d.m.Y H:i') }}@if($z['team']) · Team „{{ $z['team'] }}“ entstanden @endif</p>
        <p>{{ $z['begruendung'] }}</p>
        <p class="cf-klein">Kontakt: {{ $z['kontakt'] }}</p>
        @if($z['notiz'])<p class="cf-klein">Entscheidung: <em>{{ $z['notiz'] }}</em></p>@endif

        @if($moderation && $z['status'] === 'pending')
            <form method="post" action="/chorfinder/ansprueche/{{ $z['id'] }}/entscheiden" class="cf-formular cf-formular--zeile">
                @csrf
                <label>Notiz <input type="text" name="notiz" maxlength="2000" placeholder="bei „Ablehnen“ Pflicht"></label>
                <button class="cf-knopf" name="entscheidung" value="annehmen">Annehmen</button>
                <button class="cf-knopf cf-knopf--leise" name="entscheidung" value="ablehnen">Ablehnen</button>
            </form>
        @endif
    </article>
@empty
    <p class="cf-leer">Noch keine Ansprüche{{ $nutzer ? ' von dir' : '' }}.</p>
@endforelse
@endsection
