@extends('chorfinder.layout')
@section('titel', $chor['titel'])
@section('inhalt')
<p class="cf-krumen"><a href="/chorfinder/verzeichnis">‹ Verzeichnis</a></p>
<h1>{{ $chor['titel'] }}</h1>
<p class="cf-unter">{{ $chor['stadt'] }}@if($chor['art']) · {{ $chor['art'] }}@endif</p>
<p class="cf-lead">{{ $chor['text'] }}</p>
@if($chor['probe'])<p><strong>Probe:</strong> {{ $chor['probe'] }}</p>@endif

<section class="cf-block">
@if($team)
    <h2>Dieses Profil gehört einem Team</h2>
    <p>{{ $team->name }}, {{ $mitglieder }} {{ $mitglieder === 1 ? 'Mitglied' : 'Mitglieder' }}. Hier kannst du dem Team schreiben.</p>
    @if($nutzer)
        <form method="post" action="/chorfinder/verzeichnis/{{ $id }}/nachricht" class="cf-formular">
            @csrf
            <label>Deine Nachricht <textarea name="text" rows="4" required maxlength="10000">{{ old('text') }}</textarea></label>
            <label class="cf-haken"><input type="checkbox" name="anonym" value="1" @checked(old('anonym'))> Anonym anfragen. Das Team sieht „Anonyme:r Interessent:in“, bis du dein Profil freigibst.</label>
            <button class="cf-knopf">Senden</button>
        </form>
    @else
        <p class="cf-leer">Zum Schreiben oben eine Demo-Person wählen.</p>
    @endif
@else
    <h2>Dieses Profil hat noch keinen Besitzer</h2>
    @if($anspruch)
        <p>
            Dein Anspruch vom {{ $anspruch->created_at->format('d.m.Y H:i') }}:
            <span class="cf-status cf-status--{{ $anspruch->status }}">{{ ['pending' => 'offen', 'approved' => 'angenommen', 'rejected' => 'abgelehnt', 'withdrawn' => 'zurückgezogen'][$anspruch->status] ?? $anspruch->status }}</span>
            @if($anspruch->decision_note)<br><em>„{{ $anspruch->decision_note }}“</em>@endif
        </p>
        <p><a href="/chorfinder/verzeichnis/ansprueche">Alle Ansprüche ansehen</a></p>
    @elseif($nutzer)
        <p>Leitest du diesen Chor? Stelle einen Anspruch. Die Moderation prüft ihn; bei „Annehmen“ entsteht ein Team mit dir als Besitzer:in und das Profil wird daran gebunden.</p>
        <form method="post" action="/chorfinder/verzeichnis/{{ $id }}/anspruch" class="cf-formular">
            @csrf
            <label>Begründung (mindestens 20 Zeichen) <textarea name="begruendung" rows="4" required minlength="20" maxlength="2000">{{ old('begruendung') }}</textarea></label>
            <label>Kontakt für Rückfragen <input type="text" name="kontakt" required minlength="3" maxlength="255" value="{{ old('kontakt') }}"></label>
            <button class="cf-knopf">Anspruch stellen</button>
        </form>
        <p class="cf-klein">In dieser Demo sind es höchstens zwei Anträge je Konto und Tag.</p>
    @else
        <p class="cf-leer">Zum Beanspruchen oben eine Demo-Person wählen.</p>
    @endif
@endif
</section>
@endsection
