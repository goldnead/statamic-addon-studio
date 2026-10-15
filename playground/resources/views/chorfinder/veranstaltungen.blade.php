@extends('chorfinder.layout')
@section('titel', 'Veranstaltungen')
@php($antworten = ['going' => 'Ich komme', 'interested' => 'Interessiert', 'not_going' => 'Nicht dabei'])
@section('inhalt')
<h1>Veranstaltungen</h1>
<p class="cf-lead">
    Zusagen und Programm (<code>statamic-events</code> 2.8.0). Alle sehen die Zahlen, die Namen sieht nur,
    wer <code>manage events</code> hat: Melde dich dafür im <a href="/cp/auth/login">Control Panel</a> als Mira an
    und lade diese Seite neu.
</p>

@foreach($ereignisse as $x)
    <article class="cf-ereignis">
        <header>
            <h2>{{ $x['e']->title }}</h2>
            <p class="cf-unter">
                @if($x['wann']){{ $x['wann']->format('d.m.Y, H:i') }} Uhr @endif
                @if($x['wo']) · {{ $x['wo'] }}@endif
            </p>
        </header>
        @if($x['e']->description)<p>{{ $x['e']->description }}</p>@endif

        @if($x['programm'])
            <h3>Programm</h3>
            <ol class="cf-programm">
                @foreach($x['programm'] as $zeile)
                    <li><span>{{ $zeile['time'] }}</span> {{ $zeile['title'] }}</li>
                @endforeach
            </ol>
        @endif

        <h3>Zusagen</h3>
        <p class="cf-zusage-zahlen">{{ $x['tag'] }}</p>

        @if($nutzer)
            <form method="post" action="/chorfinder/veranstaltungen/{{ $x['e']->id }}/zusage" class="cf-zusage">
                @csrf
                @foreach($antworten as $schluessel => $name)
                    <button name="antwort" value="{{ $schluessel }}" class="cf-knopf cf-knopf--klein {{ $x['meine'] === $schluessel ? '' : 'cf-knopf--leise' }}" @if($x['meine'] === $schluessel) aria-pressed="true" @endif>{{ $name }}</button>
                @endforeach
                @if($x['meine'])<button name="antwort" value="remove" class="cf-link">Antwort zurücknehmen</button>@endif
            </form>
        @else
            <p class="cf-leer">Zum Antworten oben eine Demo-Person wählen.</p>
        @endif

        @if($x['namen'])
            <details class="cf-code"><summary>Namen (nur mit <code>manage events</code>)</summary>
                <ul>@foreach($x['namen'] as [$wer, $status])<li>{{ $wer }}: {{ $antworten[$status] ?? $status }}</li>@endforeach</ul>
            </details>
        @endif
    </article>
@endforeach
@endsection
