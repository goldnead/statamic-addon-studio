@extends('chorfinder.layout')
@section('titel', 'Gespräch')
@section('inhalt')
<p class="cf-krumen"><a href="/chorfinder/nachrichten">‹ Nachrichten</a></p>
<h1>{{ $kopf['gegenueber'] }}</h1>
<p class="cf-unter">
    @if($kopf['thema'])Anfrage zu {{ $kopf['thema'] }}@endif
    @if($kopf['maskiert']) · <span class="cf-etikett">Absender:in anonym</span>@elseif($kopf['anonym']) · <span class="cf-etikett">Profil freigegeben</span>@endif
</p>

<ol class="cf-verlauf" aria-live="polite">
    @foreach($verlauf as $m)
        <li class="{{ $m['mine'] ? 'cf-nachricht cf-nachricht--meine' : 'cf-nachricht' }}">
            <p class="cf-nachricht-kopf"><strong>{{ $m['sender'] }}</strong> <span class="cf-klein">{{ $m['sent_at']?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span></p>
            <p>{{ $m['body'] }}</p>
        </li>
    @endforeach
</ol>

@if($darfFreigeben)
    <form method="post" action="/chorfinder/nachrichten/{{ $k->id }}/freigeben" class="cf-formular cf-formular--zeile">
        @csrf
        <span>Das Team kennt deinen Namen noch nicht.</span>
        <button class="cf-knopf cf-knopf--leise">Profil freigeben</button>
    </form>
@endif

@if($gesperrt)
    <p class="cf-leer">Du hast dieses Gespräch gesperrt und kannst nicht mehr senden.</p>
@else
    <form method="post" action="/chorfinder/nachrichten/{{ $k->id }}/antwort" class="cf-formular">
        @csrf
        <label>Antwort <textarea name="text" rows="3" required maxlength="10000">{{ old('text') }}</textarea></label>
        <button class="cf-knopf">Senden</button>
    </form>
@endif
@endsection
