@extends('chorfinder.layout')
@section('titel', 'Nachrichten')
@section('inhalt')
<h1>Nachrichten</h1>
<p class="cf-lead">
    Gespräche zwischen Personen und Teams. Der Text liegt verschlüsselt in der Datenbank
    (<code>APP_KEY</code> der Demo). Eine anonyme Anfrage zeigt dem Team nur „Anonyme:r Interessent:in“.
</p>

@if(! $nutzer)
    <p class="cf-leer">Melde dich oben an. <strong>Bea</strong> hat drei Gespräche (eines anonym), <strong>Tom</strong> und <strong>Lena</strong> leiten Teams und empfangen sie.</p>
@else
    <ul class="cf-gespraeche">
        @forelse($zeilen as $z)
            <li>
                <a href="/chorfinder/nachrichten/{{ $z['id'] }}">
                    <span class="cf-gespraech-kopf">
                        <strong>{{ $z['gegenueber'] }}</strong>
                        @if($z['maskiert'])<span class="cf-etikett">anonym</span>@endif
                        @if($z['markiert'])<span class="cf-etikett cf-etikett--warn">gemeldet</span>@endif
                        @if($z['ungelesen'] > 0)<span class="cf-zahl">{{ $z['ungelesen'] }}</span>@endif
                    </span>
                    @if($z['thema'])<span class="cf-gespraech-thema">Anfrage zu {{ $z['thema'] }}</span>@endif
                    <span class="cf-klein">{{ $z['zeit']?->format('d.m.Y H:i') }}</span>
                </a>
            </li>
        @empty
            <li class="cf-leer">Keine Gespräche. Schreibe einem Team über die <a href="/chorfinder/verzeichnis">Profilseite</a> eines Chors, der schon einem Team gehört.</li>
        @endforelse
    </ul>
@endif
@endsection
