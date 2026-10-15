<?php

namespace App\Http\Controllers;

use App\Demo\SeedsChorfinder;
use Closure;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Community\Exceptions\CommunityException;
use Goldnead\Community\Models\Comment;
use Goldnead\Community\Models\Post;
use Goldnead\Community\Models\Reaction;
use Goldnead\Community\Models\Room;
use Goldnead\Community\Services\CommentService;
use Goldnead\Community\Services\PostService;
use Goldnead\Community\Services\ReactionService;
use Goldnead\Directory\Models\DirectoryProfile;
use Goldnead\Directory\Services\ProfileSearch;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Events\Rsvps;
use Goldnead\Events\Support\RsvpPolicy;
use Goldnead\IdentityContracts\Facades\IdentityContext;
use Goldnead\Messaging\Exceptions\MessagingException;
use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\Message;
use Goldnead\Messaging\Services\ConversationService;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Notifications\Models\NotificationDelivery;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Teams\Exceptions\ClaimException;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Models\TeamClaim;
use Goldnead\Teams\Services\ClaimService;
use Goldnead\Teams\Support\Users;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\Antlers;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Die Chorfinder-Demo: eine kleine, erfundene Chorplattform.
 *
 * Drei der Addons haben bewusst keine oeffentliche Oberflaeche (messaging,
 * community, die Ansprueche von teams), und directory hat nur eine Suche. Wer
 * sie vorfuehren will, baut die Seiten selbst auf den Diensten, und genau das
 * ist dieser Controller: duenn, jede Aktion ein Aufruf des Dienstes, jede
 * Regel (wer darf, was ist erlaubt) liegt dort und nicht hier.
 *
 * Angemeldet wird ohne Passwort-Formular: die Demo ist offen, die Konten
 * tragen ein oeffentliches Kennwort. Zur Auswahl stehen nur die Personen aus
 * SeedsChorfinder::LEUTE mit `anmelden`, keine mit CP-Superrechten.
 */
class ChorfinderController extends Controller
{
    /** Orte, um die gesucht werden kann: Name => [Breite, Laenge]. */
    private const ORTE = [
        'hamburg' => ['Hamburg', 53.5511, 9.9937],
        'berlin' => ['Berlin', 52.5200, 13.4050],
        'koeln' => ['Köln', 50.9375, 6.9603],
        'frankfurt' => ['Frankfurt', 50.1109, 8.6821],
        'muenchen' => ['München', 48.1351, 11.5820],
        'leipzig' => ['Leipzig', 51.3397, 12.3731],
    ];

    private const ARTEN = [
        'gemischt' => 'Gemischter Chor',
        'frauen' => 'Frauenchor',
        'maenner' => 'Männerchor',
        'kammer' => 'Kammerchor',
        'gospel' => 'Gospel und Pop',
        'jugend' => 'Jugendchor',
    ];

    private const REAKTIONEN = ['like' => 'Gefällt mir', 'helpful' => 'Hilfreich', 'thanks' => 'Danke'];

    private const KATEGORIEN = ['community' => 'Allgemein', 'question' => 'Frage', 'offer' => 'Angebot', 'announcement' => 'Ankündigung'];

    // ------------------------------------------------------------------ Hilfen

    protected function seite(string $name, array $daten = [])
    {
        $nutzer = User::current();

        return view('chorfinder.'.$name, $daten + [
            'nutzer' => $nutzer,
            'nutzerName' => $nutzer ? (Users::name($nutzer) ?? $nutzer->email()) : null,
            'moderation' => $this->moderiert($nutzer),
            'personen' => collect(SeedsChorfinder::LEUTE)->filter(fn ($p) => $p['anmelden'])
                ->map(fn ($p, $k) => $p + ['schluessel' => $k]),
            'ungelesen' => $nutzer ? $this->ungelesen($nutzer) : 0,
            'arten' => self::ARTEN,
            'aktiv' => explode('.', $name)[0],
        ]);
    }

    protected function moderiert(?StatamicUser $nutzer): bool
    {
        return $nutzer !== null && ($nutzer->isSuper() || $nutzer->hasRole('chorfinder_moderation'));
    }

    protected function ungelesen(StatamicUser $nutzer): int
    {
        $schluessel = Users::key($nutzer);

        return Message::query()
            ->whereIn('conversation_id', DB::table('conversation_participants')->where('user_id', $schluessel)->pluck('conversation_id'))
            ->whereNull('read_at')->where('hidden', false)
            ->where(fn ($q) => $q->where('sender_user_id', '!=', $schluessel)->orWhereNull('sender_user_id'))
            ->count();
    }

    /** Ruft den Dienst auf und macht aus den erwartbaren Verweigerungen eine Meldung statt einer Fehlerseite. */
    protected function versuche(Closure $aktion, ?string $erfolg = null): RedirectResponse
    {
        try {
            $ziel = $aktion();
        } catch (AuthorizationException|TeamsException|ClaimException|CommunityException|MessagingException $e) {
            return back()->withInput()->with('fehler', $this->klartext($e));
        }

        $antwort = $ziel instanceof RedirectResponse ? $ziel : back();

        return $erfolg ? $antwort->with('ok', $erfolg) : $antwort;
    }

    protected function klartext(\Throwable $e): string
    {
        if ($e instanceof ClaimException) {
            return match ($e->reason) {
                ClaimException::RATE_LIMITED => 'Zu viele Anträge in kurzer Zeit. In dieser Demo sind es zwei je Konto und Tag.',
                ClaimException::COOLDOWN => 'Dein Antrag auf dieses Profil wurde abgelehnt. Frage erst nach einer Wartezeit erneut.',
                ClaimException::RESOURCE_UNAVAILABLE => 'Dieses Profil ist nicht beanspruchbar: es gehört schon einem Team oder ist ausgeblendet.',
                ClaimException::CONFLICT => 'Das geht nicht: Entschieden ist entschieden, und den eigenen Antrag entscheidet niemand selbst.',
                ClaimException::INVALID => 'Die Angaben passen nicht: Begründung mindestens 20 Zeichen, Kontakt mindestens 3.',
                default => 'Der Anspruch konnte nicht verarbeitet werden ('.$e->reason.').',
            };
        }

        return $e->getMessage() !== '' ? $e->getMessage() : 'Das ist dir hier nicht erlaubt.';
    }

    // ------------------------------------------------------------------ Einstieg

    public function start()
    {
        return $this->seite('start', [
            'zahlen' => [
                'profile' => DirectoryProfile::query()->where('visibility', 'public')->where('hidden', false)->count(),
                'gespraeche' => Conversation::query()->count(),
                'beitraege' => Post::query()->where('hidden', false)->count(),
                'zusagen' => DB::table('event_rsvps')->count(),
            ],
        ]);
    }

    public function anmelden(Request $request)
    {
        $schluessel = (string) $request->input('person');
        $person = SeedsChorfinder::LEUTE[$schluessel] ?? null;

        abort_unless($person && $person['anmelden'], 404);

        $nutzer = User::findByEmail(SeedsChorfinder::email($schluessel));
        abort_unless($nutzer, 404);

        Auth::guard()->login($nutzer);
        $request->session()->regenerate();

        return redirect($this->zurueck($request))->with('ok', 'Angemeldet als '.$person['name'].'.');
    }

    public function abmelden(Request $request)
    {
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($this->zurueck($request));
    }

    /** Nur Pfade dieser Seite, nie ein fremder Host. */
    protected function zurueck(Request $request): string
    {
        $ziel = (string) $request->input('weiter', '/chorfinder');

        return str_starts_with($ziel, '/chorfinder') && ! str_contains($ziel, '//') ? $ziel : '/chorfinder';
    }

    // ------------------------------------------------------------------ Verzeichnis

    public function verzeichnis(Request $request)
    {
        $ort = self::ORTE[(string) $request->query('ort')] ?? null;
        $radius = $ort ? (int) ($request->query('radius') ?: 100) : null;
        $radius = $radius ? min(max($radius, 5), 500) : null;
        $art = array_key_exists((string) $request->query('art'), self::ARTEN) ? (string) $request->query('art') : '';
        $q = mb_substr(trim((string) $request->query('q')), 0, 100);
        $seite = max((int) $request->query('seite', 1), 1);

        $basis = array_filter(['q' => $q, 'ort' => $request->query('ort'), 'radius' => $request->query('radius'), 'art' => $art]);

        // Genau der Tag, den eine Site in ihr Template schreibt. Er gibt nur
        // Titel, Stadt, Beschreibung und, mit Ort, die Entfernung zurueck.
        $tag = <<<'ANTLERS'
{{ directory:search :q="q" :lat="lat" :lng="lng" :radius_km="radius" :choir_type="art" :page="page" per_page="6" }}
  {{ if no_results }}
    <p class="cf-leer">Kein Chor passt zu dieser Suche. Weiter hinaus suchen oder den Filter lösen.</p>
  {{ else }}
    <ul class="cf-liste">
      {{ profiles }}
        <li class="cf-karte">
          <a class="cf-karte-titel" href="/chorfinder/verzeichnis/{{ id }}">{{ title | sanitize }}</a>
          <span class="cf-karte-ort">{{ city | sanitize }}{{ if has_origin }} · {{ distance_km }} km{{ /if }}</span>
          <p>{{ description | sanitize }}</p>
        </li>
      {{ /profiles }}
    </ul>
    <p class="cf-seiten">
      {{ if page > 1 }}<a href="/chorfinder/verzeichnis?{{ base_query }}&amp;seite={{ page | subtract:1 }}">‹ zurück</a>{{ /if }}
      <span>Seite {{ page }} von {{ last_page }} · {{ total }} Chöre</span>
      {{ if page < last_page }}<a href="/chorfinder/verzeichnis?{{ base_query }}&amp;seite={{ page | add:1 }}">weiter ›</a>{{ /if }}
    </p>
  {{ /if }}
{{ /directory:search }}
ANTLERS;

        $ergebnis = Antlers::parse($tag, [
            'q' => $q,
            'lat' => $ort[1] ?? '',
            'lng' => $ort[2] ?? '',
            'radius' => $radius ?? '',
            'art' => $art,
            'page' => $seite,
            'has_origin' => $ort !== null,
            'base_query' => http_build_query($basis),
        ], true);

        return $this->seite('verzeichnis', [
            'ergebnis' => $ergebnis,
            'tag' => $tag,
            'orte' => collect(self::ORTE)->map(fn ($o) => $o[0]),
            'f' => ['q' => $q, 'ort' => (string) $request->query('ort'), 'radius' => (int) ($radius ?? 100), 'art' => $art],
            'bestand' => DirectoryProfile::query()->count(),
        ]);
    }

    public function chor(string $id)
    {
        $profil = DirectoryProfile::query()->where('entry_id', $id)->first();
        abort_unless($profil, 404);

        $eintrag = app(ProfileSearch::class)->publicEntry($profil);
        abort_unless($eintrag, 404);

        $nutzer = User::current();
        $team = $profil->team_id ? Team::query()->find($profil->team_id) : null;

        $anspruch = $nutzer
            ? TeamClaim::query()->where('resource_type', 'directory')->where('resource_key', $id)
                ->where('claimant_id', Users::key($nutzer))->latest('id')->first()
            : null;

        return $this->seite('chor', [
            'id' => $id,
            'chor' => [
                'titel' => $eintrag->get('title'),
                'stadt' => $eintrag->get('city'),
                'text' => $eintrag->get('description'),
                'art' => self::ARTEN[$eintrag->get('choir_type')] ?? null,
                'zielgruppe' => $eintrag->get('target_group'),
                'probe' => $eintrag->get('rehearsal'),
            ],
            'team' => $team,
            'mitglieder' => $team ? $team->members()->count() : 0,
            'anspruch' => $anspruch,
        ]);
    }

    public function anspruchStellen(Request $request, string $id)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);

        $daten = $request->validate([
            'begruendung' => ['required', 'string', 'max:2000'],
            'kontakt' => ['required', 'string', 'max:255'],
        ]);

        return $this->versuche(function () use ($id, $nutzer, $daten) {
            app(ClaimService::class)->request('directory', $id, Site::default()->handle(), $nutzer, $daten['begruendung'], $daten['kontakt']);
        }, 'Dein Anspruch liegt jetzt bei der Moderation. Melde dich als Moderation an, um ihn zu entscheiden.');
    }

    public function ansprueche()
    {
        $nutzer = User::current();
        $moderation = $this->moderiert($nutzer);

        $abfrage = TeamClaim::query()->where('resource_type', 'directory')->latest('id');

        if (! $moderation) {
            $abfrage->where('claimant_id', $nutzer ? Users::key($nutzer) : '-');
        }

        $zeilen = $abfrage->get()->map(function (TeamClaim $c) use ($moderation) {
            return [
                'id' => $c->id,
                'chor' => Entry::find($c->resource_key)?->get('title') ?? 'unbekannt',
                'chor_id' => $c->resource_key,
                'wer' => $c->claimantUser() ? (Users::name($c->claimantUser()) ?? '—') : 'Konto gelöscht',
                'status' => $c->status,
                'begruendung' => $c->justification,
                'kontakt' => $c->contact,
                'notiz' => $c->decision_note,
                'team' => $c->team_id ? Team::query()->find($c->team_id)?->name : null,
                'zeit' => $c->created_at,
            ];
        });

        return $this->seite('ansprueche', ['zeilen' => $zeilen]);
    }

    public function anspruchEntscheiden(Request $request, int $id)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);

        $entscheidung = $request->input('entscheidung');
        $notiz = trim((string) $request->input('notiz'));

        return $this->versuche(function () use ($id, $nutzer, $entscheidung, $notiz) {
            $claims = app(ClaimService::class);

            if ($entscheidung === 'ablehnen') {
                $claims->reject($id, $nutzer, $notiz !== '' ? $notiz : 'Abgelehnt ohne weitere Begründung.');
            } else {
                $claims->approve($id, $nutzer, $notiz);
            }
        }, $entscheidung === 'ablehnen' ? 'Der Anspruch ist abgelehnt.' : 'Angenommen: das Team ist angelegt, das Profil gebunden.');
    }

    // ------------------------------------------------------------------ Nachrichten

    public function postfach()
    {
        $nutzer = User::current();
        $zeilen = collect();

        if ($nutzer) {
            $ich = Users::key($nutzer);
            $zeilen = Conversation::query()
                ->whereIn('id', DB::table('conversation_participants')->where('user_id', $ich)->pluck('conversation_id'))
                ->orderByDesc('last_message_at')->get()
                ->map(fn (Conversation $k) => $this->kopf($k, $nutzer));
        }

        return $this->seite('postfach', ['zeilen' => $zeilen]);
    }

    /** Was die Liste und der Verlauf ueber eine Konversation aus Sicht von `$nutzer` sagen. */
    protected function kopf(Conversation $k, StatamicUser $nutzer): array
    {
        $ich = Users::key($nutzer);
        $andere = $k->participants()->where('user_id', '!=', $ich)->pluck('user_id');

        if ($k->recipient_team_id && $k->isInitiator($nutzer)) {
            $gegenueber = $k->recipientTeam?->name ?? 'Team';
        } elseif ($k->isInitiator($nutzer)) {
            $gegenueber = $andere->map(fn ($a) => Users::name($a))->filter()->implode(', ');
        } else {
            $gegenueber = $k->displayName($k->initiatorKey(), $nutzer);
        }

        $zeit = $k->last_message_at;

        return [
            'id' => $k->id,
            'gegenueber' => $gegenueber,
            'anonym' => $k->anonymous,
            'maskiert' => $k->isMasked(),
            'thema' => $k->subject_type === 'choir' ? Entry::find($k->subject_id)?->get('title') : null,
            'ungelesen' => $k->messages()->whereNull('read_at')->where('hidden', false)
                ->where(fn ($q) => $q->where('sender_user_id', '!=', $ich)->orWhereNull('sender_user_id'))->count(),
            'zeit' => $zeit,
            'markiert' => (bool) $k->participantOf($nutzer)?->flagged,
        ];
    }

    public function verlauf(int $id)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);

        $k = Conversation::query()->findOrFail($id);
        abort_unless(Gate::forUser($nutzer)->allows('view', $k), 404);

        $dienst = app(ConversationService::class);
        $verlauf = $dienst->thread($k, $nutzer);
        $dienst->markRead($k, $nutzer);

        return $this->seite('verlauf', [
            'k' => $k,
            'kopf' => $this->kopf($k, $nutzer),
            'verlauf' => $verlauf,
            'darfFreigeben' => $k->isInitiator($nutzer) && $k->isMasked(),
            'gesperrt' => (bool) $k->participantOf($nutzer)?->blocked,
        ]);
    }

    public function antworten(Request $request, int $id)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:10000']])['text'];
        $k = Conversation::query()->findOrFail($id);

        return $this->versuche(fn () => app(ConversationService::class)->reply($k, $nutzer, $text), 'Gesendet.');
    }

    public function freigeben(int $id)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);
        $k = Conversation::query()->findOrFail($id);

        return $this->versuche(fn () => app(ConversationService::class)->shareProfile($k, $nutzer), 'Dein Name ist jetzt für das Team sichtbar.');
    }

    public function neueNachricht(Request $request, string $id)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);

        $profil = DirectoryProfile::query()->where('entry_id', $id)->firstOrFail();
        $team = Team::query()->find($profil->team_id);
        abort_unless($team, 404);

        $text = (string) $request->validate(['text' => ['required', 'string', 'max:10000']])['text'];

        return $this->versuche(function () use ($nutzer, $team, $text, $request, $id) {
            $k = app(ConversationService::class)->start($nutzer, $team, $text, $request->boolean('anonym'), ['choir', $id]);

            return redirect('/chorfinder/nachrichten/'.$k->id);
        }, 'Gesendet.');
    }

    // ------------------------------------------------------------------ Community

    public function community()
    {
        $raeume = Room::query()->whereNull('archived_at')->orderBy('id')->get()->map(fn (Room $r) => [
            'room' => $r,
            'beitraege' => Post::query()->visible()->where('room_id', $r->id)->count(),
        ]);

        $neu = app(PostService::class)->feed()->with('room')->limit(6)->get()->map(fn ($p) => $this->beitrag($p));

        return $this->seite('community', ['raeume' => $raeume, 'neu' => $neu]);
    }

    public function raum(string $slug)
    {
        $raum = Room::query()->where('slug', $slug)->firstOrFail();
        $beitraege = app(PostService::class)->feed($raum)->paginate(8)->through(fn ($p) => $this->beitrag($p));

        return $this->seite('raum', ['raum' => $raum, 'beitraege' => $beitraege, 'kategorien' => self::KATEGORIEN]);
    }

    protected function beitrag(Post $p): array
    {
        return [
            'p' => $p,
            'autor' => Users::name($p->author_user_id) ?? 'Konto gelöscht',
            'team' => $p->author_team_id ? Team::query()->find($p->author_team_id)?->name : null,
            'kommentare' => Comment::query()->where('post_id', $p->id)->where('hidden', false)->count(),
            'reaktionen' => Reaction::query()->where('target_type', Post::TYPE)->where('target_id', $p->id)->count(),
            'kategorie' => self::KATEGORIEN[$p->category] ?? $p->category,
            'angeheftet' => $p->room?->pinned_post_id === $p->id,
        ];
    }

    public function beitragAnsehen(int $id)
    {
        $p = Post::query()->visible()->findOrFail($id);
        $nutzer = User::current();

        $kommentare = Comment::query()->where('post_id', $p->id)->where('hidden', false)->orderBy('id')->get()
            ->map(fn (Comment $c) => [
                'c' => $c,
                'autor' => Users::name($c->author_user_id) ?? 'Konto gelöscht',
                'reaktionen' => $this->zaehlen(Comment::TYPE, $c->id),
            ]);

        return $this->seite('beitrag', [
            'p' => $p,
            'b' => $this->beitrag($p),
            'raum' => $p->room,
            'kommentare' => $kommentare,
            'reaktionen' => $this->zaehlen(Post::TYPE, $p->id),
            'eigene' => $nutzer ? Reaction::query()->where('target_type', Post::TYPE)->where('target_id', $p->id)->where('user_id', Users::key($nutzer))->value('kind') : null,
            'arten' => self::REAKTIONEN,
        ]);
    }

    /** @return array<string, int> */
    protected function zaehlen(string $typ, int $id): array
    {
        return Reaction::query()->where('target_type', $typ)->where('target_id', $id)
            ->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind')->all();
    }

    public function beitragSchreiben(Request $request, string $slug)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);

        $daten = $request->validate([
            'titel' => ['required', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:5000'],
            'kategorie' => ['required', 'in:'.implode(',', array_keys(self::KATEGORIEN))],
        ]);
        $raum = Room::query()->where('slug', $slug)->firstOrFail();

        return $this->versuche(function () use ($nutzer, $daten, $raum) {
            $p = app(PostService::class)->create($nutzer, $this->absaetze($daten['text']), $raum, $daten['kategorie'], '', $daten['titel']);

            return redirect('/chorfinder/community/beitrag/'.$p->id);
        }, 'Veröffentlicht.');
    }

    public function kommentieren(Request $request, int $id)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:3000']])['text'];
        $p = Post::query()->visible()->findOrFail($id);
        $eltern = $request->integer('antwort_auf') ? Comment::query()->where('post_id', $p->id)->find($request->integer('antwort_auf')) : null;

        return $this->versuche(fn () => app(CommentService::class)->create($p, $nutzer, $this->absaetze($text), $eltern), 'Kommentar gespeichert.');
    }

    public function reagieren(Request $request, string $typ, int $id)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);
        abort_unless(in_array($typ, [Post::TYPE, Comment::TYPE], true), 404);
        $art = (string) $request->input('art');
        abort_unless(array_key_exists($art, self::REAKTIONEN), 422);

        $ziel = $typ === Post::TYPE ? Post::query()->findOrFail($id) : Comment::query()->findOrFail($id);

        return $this->versuche(fn () => app(ReactionService::class)->react($ziel, $nutzer, $art));
    }

    public function ausblenden(Request $request, int $id)
    {
        $nutzer = User::current();
        abort_unless($nutzer && $this->moderiert($nutzer), 403);
        $grund = trim((string) $request->input('grund')) ?: 'Von der Moderation ausgeblendet.';
        $p = Post::query()->findOrFail($id);

        return $this->versuche(function () use ($p, $nutzer, $grund) {
            app(PostService::class)->hide($p, $nutzer, true, $grund);

            return redirect('/chorfinder/community');
        }, 'Der Beitrag ist ausgeblendet. Im Control Panel steht der Grund.');
    }

    /** Freitext in Absaetze: die Dienste nehmen HTML, ein Formular liefert Zeilen. */
    protected function absaetze(string $text): string
    {
        return collect(preg_split('/\R{2,}/', trim($text)))->filter()
            ->map(fn ($a) => '<p>'.nl2br(e($a), false).'</p>')->implode('');
    }

    // ------------------------------------------------------------------ Veranstaltungen

    public function veranstaltungen()
    {
        $nutzer = User::current();
        $rsvps = app(Rsvps::class);

        $ereignisse = Event::acrossBrands()
            ->whereIn('slug', ['probenwochenende-nordchoere', 'tiefdruck-tour', 'stimmwerkstatt-vier-abende'])
            ->get()->map(function (Event $e) use ($rsvps, $nutzer) {
                $termin = Occurrence::acrossBrands()->where('event_id', $e->id)->orderBy('starts_at')->get();
                $kopf = $termin->first();

                return [
                    'e' => $e,
                    'wann' => $kopf?->starts_at?->timezone($e->timezone ?: 'Europe/Berlin'),
                    'wo' => $kopf ? trim(($kopf->venue_name ?? '').', '.($kopf->venue_city ?? ''), ', ') : null,
                    'programm' => $e->program ?? [],
                    'zahlen' => $rsvps->counts($e),
                    'meine' => $rsvps->statusFor($e, $nutzer)?->value,
                    'namen' => RsvpPolicy::canSeeAttendees($nutzer)
                        ? $rsvps->list($e, $nutzer)->map(fn ($r) => [Users::name($r->user_id) ?? '—', $r->status->value])
                        : null,
                    // Der Tag liest im Scope der Marke des Termins: dieser
                    // Termin gehoert einer Kundenmarke, die Seite hier nicht.
                    'tag' => BrandContext::runFor(
                        Brand::query()->findOrFail($e->brand_id),
                        fn () => (string) Antlers::parse(
                            '{{ events:rsvp :event="id" }}{{ if enabled }}{{ going }} sagen zu, {{ interested }} interessiert, {{ not_going }} sagen ab{{ if status }} · deine Antwort: {{ status | replace:not_going,Nicht dabei | replace:going,Ich komme | replace:interested,Interessiert }}{{ /if }}{{ else }}Zusagen sind aus.{{ /if }}{{ /events:rsvp }}',
                            ['id' => (string) $e->uuid],
                            true,
                        ),
                    ),
                ];
            });

        return $this->seite('veranstaltungen', ['ereignisse' => $ereignisse]);
    }

    public function zusagen(Request $request, int $id)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);
        $antwort = (string) $request->input('antwort');
        abort_unless(in_array($antwort, ['going', 'interested', 'not_going', 'remove'], true), 422);
        $event = Event::acrossBrands()->findOrFail($id);
        $rsvps = app(Rsvps::class);

        return $this->versuche(function () use ($rsvps, $event, $nutzer, $antwort) {
            $antwort === 'remove' ? $rsvps->remove($event, $nutzer) : $rsvps->set($event, $nutzer, $antwort);
        }, 'Gespeichert.');
    }

    // ------------------------------------------------------------------ Meldungen

    public function meldungen()
    {
        $nutzer = User::current();
        $typen = ['claim.decided', 'message.received', 'community.comment'];

        $eigene = $nutzer ? Notifications::forRecipient($nutzer, 12)->whereIn('type', $typen)->map(fn (NotificationItem $i) => [
            'text' => Notifications::render($i)['message'] ?? '',
            'link' => Notifications::render($i)['link'] ?? null,
            'gelesen' => $i->read_at !== null,
            'zeit' => $i->created_at,
        ]) : collect();

        $zeilen = NotificationDelivery::query()->with('item')
            ->whereHas('item', fn ($q) => $q->withoutGlobalScopes()->whereIn('type', $typen))
            ->orderByRaw("case when status in ('pending','retry') then 0 when status = 'skipped' then 1 else 2 end")
            ->orderByDesc('id')->limit(14)->get()->map(fn (NotificationDelivery $d) => [
                'status' => $d->status,
                'kanal' => $d->channel,
                'code' => $d->outcome_code,
                'versuche' => $d->attempts,
                'nachricht' => $d->item?->message,
                'zeit' => $d->created_at,
            ]);

        $offen = NotificationDelivery::query()->whereIn('status', NotificationDelivery::CLAIMABLE_STATUSES)->count();
        $mailAn = $nutzer ? Notifications::preferences()->allows(IdentityContext::resolve($nutzer), 'community.comment', 'mail') : null;

        return $this->seite('meldungen', ['eigene' => $eigene, 'zeilen' => $zeilen, 'offen' => $offen, 'mailAn' => $mailAn]);
    }

    public function zustellen()
    {
        // Eine Zustellung je zehn Sekunden fuer alle: ein Knopf auf einer offenen Demo.
        if (! Cache::add('chorfinder:zustellen', 1, 10)) {
            return back()->with('fehler', 'Eben wurde schon zugestellt. Warte ein paar Sekunden.');
        }

        Artisan::call('notifications:dispatch-pending', ['--limit' => 50]);

        return back()->with('ok', trim(Artisan::output()) ?: 'Zustellung gelaufen.');
    }

    public function mailEinstellung(Request $request)
    {
        $nutzer = User::current();
        abort_unless($nutzer, 403);

        Notifications::preferences()->set(IdentityContext::resolve($nutzer), 'community.comment', 'mail', $request->boolean('an'));

        return back()->with('ok', 'Gespeichert. Gilt für die nächsten Meldungen und für alles, was noch wartet.');
    }
}
