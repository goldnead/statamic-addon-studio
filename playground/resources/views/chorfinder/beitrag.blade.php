@extends('chorfinder.layout')
@section('titel', $p->title !== '' ? $p->title : 'Beitrag')
@section('inhalt')
<p class="cf-krumen"><a href="/chorfinder/community">Community</a>@if($raum) › <a href="/chorfinder/community/{{ $raum->slug }}">{{ $raum->name }}</a>@endif</p>
<h1>{{ $p->title !== '' ? $p->title : 'Ohne Titel' }}</h1>
<p class="cf-unter">
    {{ $b['autor'] }}@if($b['team']) für {{ $b['team'] }}@endif · {{ $p->published_at?->format('d.m.Y H:i') }}
    · <span class="cf-etikett cf-etikett--leise">{{ $b['kategorie'] }}</span>
    @if($b['angeheftet'])<span class="cf-etikett">angeheftet</span>@endif
</p>

{{-- Der Dienst bereinigt beim Speichern (Whitelist der Tags), die Ausgabe ist deshalb Markup. --}}
<div class="cf-text">{!! $p->body !!}</div>

<div class="cf-reaktionen">
    @foreach($arten as $schluessel => $name)
        <form method="post" action="/chorfinder/community/reaktion/post/{{ $p->id }}">@csrf
            <button name="art" value="{{ $schluessel }}" class="cf-reaktion {{ $eigene === $schluessel ? 'cf-reaktion--meine' : '' }}">{{ $name }} <span>{{ $reaktionen[$schluessel] ?? 0 }}</span></button>
        </form>
    @endforeach
</div>

@if($moderation)
    <form method="post" action="/chorfinder/community/beitrag/{{ $p->id }}/ausblenden" class="cf-formular cf-formular--zeile">
        @csrf
        <label>Grund <input type="text" name="grund" maxlength="200" placeholder="steht im Control Panel"></label>
        <button class="cf-knopf cf-knopf--leise">Beitrag ausblenden</button>
    </form>
@endif

<section class="cf-block">
    <h2>{{ $kommentare->count() }} {{ $kommentare->count() === 1 ? 'Kommentar' : 'Kommentare' }}</h2>
    <ul class="cf-kommentare">
        @foreach($kommentare as $k)
            <li class="{{ $k['c']->parent_id ? 'cf-kommentar cf-kommentar--antwort' : 'cf-kommentar' }}">
                <p class="cf-klein"><strong>{{ $k['autor'] }}</strong> · {{ $k['c']->created_at?->format('d.m.Y H:i') }}</p>
                <div class="cf-text">{!! $k['c']->body !!}</div>
                <div class="cf-reaktionen cf-reaktionen--klein">
                    @foreach($arten as $schluessel => $name)
                        <form method="post" action="/chorfinder/community/reaktion/comment/{{ $k['c']->id }}">@csrf
                            <button name="art" value="{{ $schluessel }}" class="cf-reaktion">{{ $name }} <span>{{ $k['reaktionen'][$schluessel] ?? 0 }}</span></button>
                        </form>
                    @endforeach
                </div>
            </li>
        @endforeach
    </ul>

    @if($nutzer)
        <form method="post" action="/chorfinder/community/beitrag/{{ $p->id }}/kommentar" class="cf-formular">
            @csrf
            <label>Kommentar <textarea name="text" rows="3" required maxlength="3000">{{ old('text') }}</textarea></label>
            <button class="cf-knopf">Kommentieren</button>
        </form>
    @else
        <p class="cf-leer">Zum Kommentieren oben eine Demo-Person wählen.</p>
    @endif
</section>
@endsection
