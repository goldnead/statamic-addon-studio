<ul class="cf-beitraege">
    @forelse($liste as $b)
        <li>
            <a class="cf-beitrag-titel" href="/chorfinder/community/beitrag/{{ $b['p']->id }}">{{ $b['p']->title !== '' ? $b['p']->title : 'Ohne Titel' }}</a>
            @if($b['angeheftet'])<span class="cf-etikett">angeheftet</span>@endif
            <span class="cf-etikett cf-etikett--leise">{{ $b['kategorie'] }}</span>
            <p class="cf-klein">
                {{ $b['autor'] }}@if($b['team']) für {{ $b['team'] }}@endif
                @if(! empty($mitRaum) && $b['p']->room) · {{ $b['p']->room->name }}@endif
                · {{ $b['p']->published_at?->format('d.m.Y H:i') }}
                · {{ $b['kommentare'] }} {{ $b['kommentare'] === 1 ? 'Kommentar' : 'Kommentare' }}
                · {{ $b['reaktionen'] }} {{ $b['reaktionen'] === 1 ? 'Reaktion' : 'Reaktionen' }}
            </p>
        </li>
    @empty
        <li class="cf-leer">Noch keine Beiträge.</li>
    @endforelse
</ul>
