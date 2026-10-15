@extends('chorfinder.layout')
@section('titel', 'Community')
@section('inhalt')
<h1>Community</h1>
<p class="cf-lead">Sechs kuratierte Räume, chronologisch sortiert, ohne Algorithmus. Reaktionen ändern die Reihenfolge nie.</p>

<div class="cf-raster cf-raster--raeume">
    @foreach($raeume as $r)
        <a class="cf-kachel" href="/chorfinder/community/{{ $r['room']->slug }}">
            <h3><span aria-hidden="true">{{ $r['room']->icon }}</span> {{ $r['room']->name }}</h3>
            <p>{{ $r['room']->description }}</p>
            <p class="cf-klein">{{ $r['beitraege'] }} {{ $r['beitraege'] === 1 ? 'Beitrag' : 'Beiträge' }}</p>
        </a>
    @endforeach
</div>

<section class="cf-block">
    <h2>Zuletzt</h2>
    @include('chorfinder.partials.beitragsliste', ['liste' => $neu, 'mitRaum' => true])
</section>
@endsection
