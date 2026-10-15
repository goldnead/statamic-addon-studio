@extends('chorfinder.layout')
@section('titel', 'Verzeichnis')
@section('inhalt')
<h1>Chorverzeichnis</h1>
<p class="cf-lead">Alle {{ $bestand }} Profile sind erfunden. Zwei davon tauchen nie in der Suche auf: eines ist ausgeblendet, eines privat.</p>

<form method="get" action="/chorfinder/verzeichnis" class="cf-filter">
    <label>Name oder Stadt
        <input type="search" name="q" value="{{ $f['q'] }}" placeholder="z. B. Kammerchor">
    </label>
    <label>Umkreis um
        <select name="ort">
            <option value="">überall</option>
            @foreach($orte as $schluessel => $name)
                <option value="{{ $schluessel }}" @selected($f['ort'] === $schluessel)>{{ $name }}</option>
            @endforeach
        </select>
    </label>
    <label>bis
        <select name="radius">
            @foreach([25, 50, 100, 200, 400] as $km)
                <option value="{{ $km }}" @selected($f['radius'] === $km)>{{ $km }} km</option>
            @endforeach
        </select>
    </label>
    <label>Art
        <select name="art">
            <option value="">alle</option>
            @foreach($arten as $schluessel => $name)
                <option value="{{ $schluessel }}" @selected($f['art'] === $schluessel)>{{ $name }}</option>
            @endforeach
        </select>
    </label>
    <button class="cf-knopf">Suchen</button>
</form>

<div class="cf-ergebnis">{!! $ergebnis !!}</div>

<p class="cf-seitenlink">
    <a href="/chorfinder/verzeichnis/ansprueche">Ansprüche ansehen</a> · Gehört dir ein Chor? Auf der Profilseite kannst du ihn beanspruchen.
</p>

<details class="cf-code">
    <summary>Der Tag, der diese Liste rendert</summary>
    <pre><code>{{ $tag }}</code></pre>
</details>
@endsection
