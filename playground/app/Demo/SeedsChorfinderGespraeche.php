<?php

namespace App\Demo;

use Goldnead\Community\Models\Post;
use Goldnead\Community\Models\Room;
use Goldnead\Community\Services\CommentService;
use Goldnead\Community\Services\PostService;
use Goldnead\Community\Services\ReactionService;
use Goldnead\Community\Services\RoomService;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Rsvps;
use Goldnead\IdentityContracts\Facades\IdentityContext;
use Goldnead\Messaging\Models\ConversationParticipant;
use Goldnead\Messaging\Services\ConversationService;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Teams\Models\Team;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\User;

/**
 * Chorfinder, zweite Haelfte: Nachrichten, Community, Zusagen, Meldungen.
 *
 * Laeuft nach `SeedsChorfinder` (Konten, Profile, Teams entstehen dort). Alles
 * geht durch die Dienste der Addons, nie an ihnen vorbei: die Policies, die
 * Bereinigung der Texte und die Ereignisse (und damit die Meldungen) sollen so
 * laufen, wie sie im Betrieb laufen.
 *
 * Die Reihenfolge am Ende ist Absicht. Erst gehen die Meldungen hinaus, dann
 * stellt Bea Mails fuer Kommentare ab, dann kommen die letzten Aktionen. So
 * stehen in der Warteschlange alle drei Zustaende nebeneinander: zugestellt,
 * bewusst uebersprungen (`preference_disabled`) und offen. Alles Erfundene,
 * keine echte Person, keine Adresse ausser `.beispiel`.
 */
class SeedsChorfinderGespraeche
{
    /** @var array<string, \Statamic\Contracts\Auth\User> */
    protected array $u = [];

    /** @return array<string, int> */
    public function run(): array
    {
        foreach (array_keys(SeedsChorfinder::LEUTE) as $schluessel) {
            $this->u[$schluessel] = User::findByEmail(SeedsChorfinder::email($schluessel))
                ?? throw new \RuntimeException("Konto $schluessel fehlt, erst SeedsChorfinder laufen lassen.");
        }

        $this->nachrichten();
        $this->community();
        $this->termine();
        $this->meldungen();

        return [
            'chorfinder-gespraeche' => DB::table('conversations')->count(),
            'chorfinder-nachrichten' => DB::table('messages')->count(),
            'chorfinder-beitraege' => DB::table('community_posts')->count(),
            'chorfinder-kommentare' => DB::table('community_comments')->count(),
            'chorfinder-zusagen' => DB::table('event_rsvps')->count(),
            'chorfinder-zustellzeilen' => DB::table('notification_deliveries')->count(),
        ];
    }

    protected function nachrichten(): void
    {
        $c = app(ConversationService::class);
        $team = fn (string $name) => Team::query()->where('name', $name)->firstOrFail();
        $profil = fn (string $slug) => SeedsChorfinder::uuid('choir', $slug);

        // 1. Anonym angefragt, beantwortet, am Ende gibt sich Bea zu erkennen.
        $k = $c->start($this->u['bea'], $team('Kammerchor Alsterhain'),
            'Hallo, ich singe seit zehn Jahren Alt, zuletzt in einem Kirchenchor, und suche einen Chor, der auch Neue Musik wagt. Habt ihr im Alt noch Platz, und darf ich einmal zuhören?',
            anonymous: true, subject: ['choir', $profil('kammerchor-alsterhain')]);
        $c->reply($k, $this->u['tom'], 'Hallo! Im Alt sind wir gut besetzt, aber zuhören darfst du jederzeit. Mittwochs um 19:30 im Gemeindesaal Eppendorf. Magst du dich kurz vorstellen?');
        $c->reply($k, $this->u['bea'], 'Gern. Ich komme am Mittwoch vorbei und gebe mich dann auch zu erkennen.');
        $c->shareProfile($k, $this->u['bea']);
        $c->markRead($k, $this->u['tom']);
        $c->reply($k, $this->u['mia'], 'Ich sitze im Alt, ich rutsche dir einen Platz neben mich. Bis Mittwoch!');

        // 2. Offen, an das Gospel-Team. Die letzte Zeile hat Lena noch nicht gelesen.
        $k = $c->start($this->u['bea'], $team('Elbtöne Gospelchor'),
            'Hallo Lena, braucht man bei euch ein Vorsingen? Ich bin sonst eher klassisch unterwegs.',
            subject: ['choir', $profil('elbtoene-gospelchor')]);
        $c->reply($k, $this->u['lena'], 'Kein Vorsingen. Komm einfach donnerstags um 19:00 ins Kulturhaus Neustadt, die erste Probe ist frei.');
        $c->markRead($k, $this->u['bea']);
        $c->reply($k, $this->u['bea'], 'Wunderbar, dann bis Donnerstag!');

        // 3. Anonym und unbeantwortet, vom Team gemeldet: die Zeile fuer die Moderation.
        $k = $c->start($this->u['felix'], $team('Kammerchor Alsterhain'),
            'Ich brauche SOFORT einen Chor, antwortet mir bis morgen! Wer nicht antwortet, ist unhöflich.',
            anonymous: true, subject: ['choir', $profil('kammerchor-alsterhain')]);
        ConversationParticipant::query()
            ->where('conversation_id', $k->id)
            ->where('user_id', (string) $this->u['tom']->id())
            ->update(['flagged' => true]);

        // 4. Von Person zu Person: Tom fragt Lena nach einem Satz.
        $k = $c->start($this->u['tom'], null,
            'Hast du den Satz von „Wade in the Water“ für gemischten Chor noch irgendwo? Ich würde ihn im Frühjahr gern einmal ausprobieren.',
            recipientUser: $this->u['lena']);
        $c->reply($k, $this->u['lena'], 'Liegt in der Chorbibliothek, ich schicke dir die PDF morgen.');
        $c->markRead($k, $this->u['tom']);
    }

    protected function community(): void
    {
        $posts = app(PostService::class);
        $comments = app(CommentService::class);
        $reactions = app(ReactionService::class);
        $rooms = app(RoomService::class);

        $rooms->syncCurated();
        $raum = fn (string $slug) => Room::query()->where('slug', $slug)->firstOrFail();

        foreach (['bea', 'tom', 'lena', 'mia', 'felix'] as $wer) {
            $rooms->join($raum('community'), $this->u[$wer]);
        }
        $rooms->join($raum('stimme'), $this->u['bea']);
        $rooms->join($raum('chorleitung'), $this->u['tom']);
        $rooms->join($raum('chorleitung'), $this->u['lena']);

        $willkommen = $posts->create($this->u['moderation'],
            '<p>Das ist die <strong>Demo</strong> von statamic-community. Alle Räume, Beiträge und Personen sind erfunden, und jede Nacht um 03:17 UTC setzt sich alles zurück.</p><p>Melde dich oben als eine der Personen an, dann kannst du schreiben, kommentieren und reagieren. Als Moderation siehst du außerdem, was im Control Panel zu tun wäre.</p>',
            $raum('community'), 'community', '', 'Willkommen in der Demo');
        $rooms->pin($raum('community'), $willkommen);

        $hallo = $posts->create($this->u['bea'],
            '<p>Hallo zusammen, ich bin Bea und singe seit zehn Jahren Alt. Nach dem Umzug nach Hamburg suche ich einen Chor, der auch Neue Musik singt. Habt ihr Tipps für den Einstieg?</p>',
            $raum('community'), 'community', 'Hamburg', 'Hallo aus Hamburg');

        $passion = $posts->create($this->u['tom'],
            '<p>Für unsere Passionsaufführung im Frühjahr suchen wir <strong>zwei bis drei Tenöre</strong>. Probe mittwochs 19:30 Uhr, Gemeindesaal Eppendorf. Notenkenntnisse sind von Vorteil, ein Probemonat ist selbstverständlich.</p>',
            $raum('aushilfen'), 'offer', 'Hamburg', 'Tenöre für die Passion gesucht',
            Team::query()->where('name', 'Kammerchor Alsterhain')->first());

        $einsingen = $posts->create($this->u['lena'],
            '<p>Ich frage mich seit Wochen: Was macht bei euch das Einsingen wirklich besser? Bei uns hilft vor allem, <em>nicht</em> gleich mit Tonleitern anzufangen, sondern mit Atem und Gähnen.</p><ul><li>Gähnen mit offenem Rachen</li><li>Summen auf „ng“</li><li>erst dann Töne</li></ul>',
            $raum('stimme'), 'question', '', 'Einsingen: Was macht bei euch den Unterschied?');

        $passaggio = $posts->create($this->u['felix'],
            '<p>Mein Tenor kippt im Passaggio um das e1. Gibt es Übungen, die ihr empfehlen könnt? Ich singe seit einem Jahr im Chor und habe noch keinen Gesangsunterricht.</p>',
            $raum('stimme'), 'question', '', 'Mein Tenor kippt im Passaggio');

        $posts->create($this->u['mia'],
            '<p>Zum Probenstart sammle ich Kanons, die auch ohne Noten funktionieren. Bisher: „Dona nobis pacem“, „Hallelujah“ im Quodlibet und ein selbstgeschriebener Zungenbrecher. Ergänzungen willkommen.</p>',
            $raum('material'), 'community', '', 'Kanons für den Probenstart');

        $posts->create($this->u['tom'],
            '<p>Wie viel Zeit gebt ihr in einer 90-Minuten-Probe der Stimmbildung? Wir sind bei 15 Minuten und merken, dass das Programm trotzdem fertig wird. Ist das Zufall?</p>',
            $raum('chorleitung'), 'question', '', 'Probenplanung: Wie viel Stimmbildung passt hinein?');

        $posts->create($this->u['jo'],
            '<p>Ich übernehme im Herbst einen Männerchor mit 14 Sängern. Er hat Tradition, aber wenig Nachwuchs. Womit würdet ihr in den ersten drei Proben anfangen?</p>',
            $raum('verein'), 'question', 'Freiburg', 'Einen Männerchor übernehmen: Wo anfangen?');

        $posts->create($this->u['lena'],
            '<p>Für den kommenden Sonntag suchen wir <strong>eine Altistin</strong> für das Konzert in der Kreuzkirche. Proben Freitag und Samstag. Ihr kennt den Satz oder lernt ihn schnell.</p>',
            $raum('aushilfen'), 'offer', 'Dresden', 'Altistin für Sonntag gesucht');

        // Werbung, die die Moderation wieder ausblendet: Grund im CP sichtbar.
        $werbung = $posts->create($this->u['felix'],
            '<p>Günstige Mikrofone für Chor und Band! Jetzt zuschlagen.</p>',
            $raum('material'), 'offer', '', 'Mikrofone zum Spottpreis');
        $posts->hide($werbung, $this->u['moderation'], true, 'Werbung ohne Bezug zum Raum.');

        // Kommentare, eine Ebene tief.
        $k1 = $comments->create($hallo, $this->u['tom'], '<p>Willkommen, Bea! Mittwochs singen wir im Gemeindesaal Eppendorf. Komm gern zum Zuhören vorbei.</p>');
        $comments->create($hallo, $this->u['bea'], '<p>Vielen Dank, ich komme am Mittwoch!</p>', $k1);
        $comments->create($hallo, $this->u['mia'], '<p>Ich sitze im Alt und freue mich auf dich.</p>');

        $comments->create($einsingen, $this->u['bea'], '<p>Bei uns hilft ein Lied auf „mmm“, bevor irgendein Ton kommt. Die Stimme wird sofort weicher.</p>');
        $comments->create($einsingen, $this->u['tom'], '<p>Atem vor Ton, genau. Wir fangen mit einer Minute Stille an, das ändert die Probe.</p>');

        $c1 = $comments->create($passaggio, $this->u['lena'], '<p>Ein paar Einzelstunden bei jemandem, der CVT unterrichtet, lohnen sich. Das Passaggio ist ein Technikthema, kein Fleißthema.</p>');
        $comments->create($passaggio, $this->u['felix'], '<p>Danke, das probiere ich aus.</p>', $c1);

        $comments->create($passion, $this->u['jo'], '<p>Falls es mit Freiburg nicht klappt: Ich schicke dir Kontakte aus meinem Chorverband.</p>');

        // Reaktionen. Eine je Konto und Ziel, eine zweite aendert die Art.
        foreach (['tom', 'lena', 'mia'] as $wer) {
            $reactions->react($willkommen, $this->u[$wer], 'like');
        }
        $reactions->react($hallo, $this->u['tom'], 'thanks');
        $reactions->react($hallo, $this->u['mia'], 'like');
        $reactions->react($einsingen, $this->u['bea'], 'helpful');
        $reactions->react($einsingen, $this->u['tom'], 'helpful');
        $reactions->react($passaggio, $this->u['lena'], 'thanks');
        $reactions->react($passion, $this->u['mia'], 'like');
        $reactions->react($k1, $this->u['bea'], 'thanks');
        $reactions->react($c1, $this->u['felix'], 'thanks');
        $reactions->react($c1, $this->u['bea'], 'helpful');

        // Spaeter im Lauf zugesagt: ein Beitrag, der noch niemand kommentiert hat.
        $this->kommentarZiel = $hallo;
    }

    /** Der Beitrag, unter den am Ende noch ein Kommentar kommt. */
    protected ?Post $kommentarZiel = null;

    protected function termine(): void
    {
        $program = [
            'probenwochenende-nordchoere' => [
                ['time' => 'Fr 18:00', 'title' => 'Ankommen und gemeinsames Abendessen'],
                ['time' => 'Fr 20:00', 'title' => 'Einsingen mit der ganzen Gruppe'],
                ['time' => 'Sa 09:30', 'title' => 'Stimmbildung in Kleingruppen'],
                ['time' => 'Sa 14:00', 'title' => 'Probe: Programm der Nordchöre'],
                ['time' => 'Sa 19:30', 'title' => 'Offenes Singen im Garten'],
                ['time' => 'So 10:30', 'title' => 'Abschlusskonzert im Gemeindehaus'],
            ],
            'tiefdruck-tour' => [
                ['time' => '18:00', 'title' => 'Einlass'],
                ['time' => '19:00', 'title' => 'Vorband'],
                ['time' => '20:00', 'title' => 'Tiefdruck, erster Teil'],
                ['time' => '21:15', 'title' => 'Pause'],
                ['time' => '21:45', 'title' => 'Tiefdruck, zweiter Teil und Zugaben'],
            ],
            'stimmwerkstatt-vier-abende' => [
                ['time' => '19:00', 'title' => 'Atem und Haltung'],
                ['time' => '19:45', 'title' => 'Klang finden: Vokale und Resonanz'],
                ['time' => '20:30', 'title' => 'Übung im Kleinen Chor'],
            ],
        ];

        foreach ($program as $slug => $zeilen) {
            Event::acrossBrands()->where('slug', $slug)->first()?->update(['program' => $zeilen]);
        }

        $rsvps = app(Rsvps::class);
        $event = fn (string $slug) => Event::acrossBrands()->where('slug', $slug)->firstOrFail();

        $wochenende = $event('probenwochenende-nordchoere');
        $rsvps->set($wochenende, $this->u['bea'], 'interested');
        $rsvps->set($wochenende, $this->u['tom'], 'going');
        $rsvps->set($wochenende, $this->u['mia'], 'going');
        $rsvps->set($wochenende, $this->u['lena'], 'going');
        $rsvps->set($wochenende, $this->u['felix'], 'not_going');

        $tour = $event('tiefdruck-tour');
        $rsvps->set($tour, $this->u['bea'], 'going');
        $rsvps->set($tour, $this->u['mia'], 'interested');

        $werkstatt = $event('stimmwerkstatt-vier-abende');
        $rsvps->set($werkstatt, $this->u['felix'], 'going');
        $rsvps->set($werkstatt, $this->u['jo'], 'interested');
    }

    protected function meldungen(): void
    {
        $dispatch = fn () => Artisan::call('notifications:dispatch-pending', ['--limit' => 200]);

        // Ein Kommentar vor dem Versand: Bea bekommt In-App und Mail geschrieben.
        app(CommentService::class)->create($this->kommentarZiel, $this->u['felix'], '<p>Willkommen im Chor-Dschungel. Frag ruhig nach, hier beißt niemand.</p>');

        // Bea stellt Mails fuer Kommentare ab, bevor die Zustellung laeuft: die
        // Mail-Zeile endet `skipped / preference_disabled`, die In-App-Zeile
        // `accepted`. Beide stehen danach in der Warteschlange.
        $bea = IdentityContext::resolve($this->u['bea']);
        Notifications::preferences()->set($bea, 'community.comment', 'mail', false);

        $dispatch();

        // Nach dem Versand: neue Fakten, noch nicht zugestellt. Die Zeilen stehen
        // `pending`, bis jemand `notifications:dispatch-pending` anstoesst; die
        // Chorfinder-Seite hat dafuer den Knopf "Jetzt zustellen".
        app(CommentService::class)->create($this->kommentarZiel, $this->u['tom'], '<p>Schön, dass du dabei bist, Bea. Bis Mittwoch.</p>');
        $konv = DB::table('conversations')->where('recipient_team_id', Team::query()->where('name', 'Elbtöne Gospelchor')->value('id'))->value('id');
        app(ConversationService::class)->reply(
            \Goldnead\Messaging\Models\Conversation::query()->findOrFail($konv),
            $this->u['lena'],
            'Noch ein Tipp: Bring eine Wasserflasche mit, es wird warm im Saal.',
        );
    }
}
