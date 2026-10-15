@extends('chorfinder.layout')
@section('titel', $raum->name)
@section('inhalt')
<p class="cf-krumen"><a href="/chorfinder/community">‹ Community</a></p>
<h1><span aria-hidden="true">{{ $raum->icon }}</span> {{ $raum->name }}</h1>
<p class="cf-lead">{{ $raum->description }}</p>

@include('chorfinder.partials.beitragsliste', ['liste' => $beitraege])
@if($beitraege->hasPages())
    <p class="cf-seiten">
        @if($beitraege->previousPageUrl())<a href="{{ $beitraege->previousPageUrl() }}">‹ neuere</a>@endif
        <span>Seite {{ $beitraege->currentPage() }} von {{ $beitraege->lastPage() }}</span>
        @if($beitraege->nextPageUrl())<a href="{{ $beitraege->nextPageUrl() }}">ältere ›</a>@endif
    </p>
@endif

<section class="cf-block">
    <h2>Neuer Beitrag</h2>
    @if($nutzer)
        <form method="post" action="/chorfinder/community/{{ $raum->slug }}/beitrag" class="cf-formular">
            @csrf
            <label>Titel <input type="text" name="titel" required maxlength="120" value="{{ old('titel') }}"></label>
            <label>Art
                <select name="kategorie">
                    @foreach($kategorien as $schluessel => $name)<option value="{{ $schluessel }}" @selected(old('kategorie', 'community') === $schluessel)>{{ $name }}</option>@endforeach
                </select>
            </label>
            <label>Text <textarea name="text" rows="5" required maxlength="5000">{{ old('text') }}</textarea></label>
            <button class="cf-knopf">Veröffentlichen</button>
        </form>
    @else
        <p class="cf-leer">Zum Schreiben oben eine Demo-Person wählen.</p>
    @endif
</section>
@endsection
