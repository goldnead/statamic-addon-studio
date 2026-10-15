<?php

namespace App\Demo;

use Goldnead\Directory\Models\DirectoryProfile;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Services\ClaimService;
use Goldnead\Teams\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Chorfinder, erste Haelfte: die Demo fuer statamic-directory und die Ansprueche
 * von statamic-teams, dazu die Konten, die messaging und community brauchen.
 *
 * Erfunden ist alles: Chornamen, Personen, Beschreibungen. Die Staedte und ihre
 * Koordinaten sind echt, weil eine Umkreissuche Koordinaten braucht, die zu
 * einer Stadt passen. Keine Zeile stammt aus Chorgesucht oder einem echten
 * Verzeichnis, und die Adressen enden auf `.beispiel`, die es nicht gibt.
 *
 * ## Was hier geschieht, in dieser Reihenfolge
 *
 * 1. Aufraeumen. Der Seeder darf zweimal laufen: er raeumt seine eigenen
 *    Tabellen und die Teams seiner Konten weg und baut neu. Fremde Teams
 *    (Kurse, Team-Demo) bleiben stehen.
 * 2. Konten. Sieben, alle mit bestaetigter Adresse, eines mit der Rolle
 *    `chorfinder_moderation`. Die Rolle hat nur drei CP-Bereiche und darf
 *    Ansprueche entscheiden.
 * 3. Chorprofile als Eintraege der Sammlung `choirs`. Die Projektion in
 *    `directory_profiles` baut ein Entry-Hook beim Speichern.
 * 4. Ansprueche ueber `ClaimService`, nicht ueber die Tabelle: zwei genehmigt
 *    (dabei entsteht je ein Team und die Bindung), einer abgelehnt, einer offen.
 *
 * Danach `SeedsChorfinderGespraeche` fuer Nachrichten, Community, Zusagen.
 */
class SeedsChorfinder
{
    public const PASSWORT = 'demo-local-password';

    /**
     * Die Personen. `anmelden` heisst: die Chorfinder-Seite bietet sie in der
     * Auswahl an. Die uebrigen schreiben nur Beitraege und Antworten.
     *
     * @var array<string, array<string, mixed>>
     */
    public const LEUTE = [
        'bea' => ['name' => 'Bea Lorenz', 'anmelden' => true, 'rolle' => 'Sängerin, sucht einen Alt-Platz'],
        'tom' => ['name' => 'Tom Wiegand', 'anmelden' => true, 'rolle' => 'Leitet den Kammerchor Alsterhain'],
        'lena' => ['name' => 'Lena Voss', 'anmelden' => true, 'rolle' => 'Leitet den Elbtöne Gospelchor'],
        'moderation' => ['name' => 'Moderation Chorfinder', 'anmelden' => true, 'rolle' => 'Entscheidet Ansprüche, moderiert', 'cprolle' => 'chorfinder_moderation'],
        'mia' => ['name' => 'Mia Sander', 'anmelden' => false, 'rolle' => 'Alt im Kammerchor Alsterhain'],
        'felix' => ['name' => 'Felix Brandt', 'anmelden' => false, 'rolle' => 'Tenor, immer auf Suche'],
        'jo' => ['name' => 'Jo Kessler', 'anmelden' => false, 'rolle' => 'Bass, will einen Männerchor übernehmen'],
    ];

    /**
     * Siebzehn Chorprofile. Stadt, Koordinaten, dann Art und Zielgruppe.
     * Drei sind absichtlich nicht oeffentlich: ein ausgeblendeter, ein
     * privater, und einer, der erst durch einen Anspruch einen Besitzer hat.
     *
     * @var list<array<string, mixed>>
     */
    public const CHOERE = [
        ['kammerchor-alsterhain', 'Kammerchor Alsterhain', 'Hamburg', 53.5511, 9.9937, 'kammer', 'erwachsene', 'Mittwochs 19:30 Uhr, Gemeindesaal Eppendorf', 'Anspruchsvolle A-cappella-Literatur von der Renaissance bis zu Neuer Musik. Wir singen zwei Projekte im Jahr und suchen für das nächste Tenöre.'],
        ['elbtoene-gospelchor', 'Elbtöne Gospelchor', 'Dresden', 51.0504, 13.7373, 'gospel', 'alle', 'Donnerstags 19:00 Uhr, Kulturhaus Neustadt', 'Gospel, Spirituals und ein bisschen Soul. Ohne Vorsingen, ohne Notenkenntnisse. Wer atmen kann, darf mitsingen.'],
        ['jugendchor-nordwind', 'Jugendchor Nordwind', 'Lübeck', 53.8655, 10.6866, 'jugend', 'jugend', 'Montags 17:00 Uhr, Musikschule', 'Für Stimmen von 13 bis 19. Popsongs im Satz, ein Musical pro Jahr und Stimmbildung in kleinen Gruppen.'],
        ['pfeffersack-chor', 'Gemischter Chor Pfeffersack', 'Bremen', 53.0793, 8.8017, 'gemischt', 'erwachsene', 'Dienstags 20:00 Uhr, Schnoor', 'Ein Chor, der sich nicht zu ernst nimmt. Von Volkslied bis Popsatz, jedes Frühjahr ein Konzert im Hof.'],
        ['spreewind-chorgemeinschaft', 'Chorgemeinschaft Spreewind', 'Berlin', 52.5200, 13.4050, 'gemischt', 'alle', 'Freitags 18:30 Uhr, Treptow', 'Vierstimmig gemischt, vom Anfänger bis zur Chorleiterin. Wir proben im Wechsel mit einer Stimmbildungs-Viertelstunde.'],
        ['pleissenklang', 'Vokalensemble Pleißeklang', 'Leipzig', 51.3397, 12.3731, 'kammer', 'erwachsene', 'Montags 19:30 Uhr, Leipzig-Plagwitz', 'Vierzehn Stimmen, kein Dirigat, viel Hören. Wir erarbeiten Programme in Klausurwochenenden.'],
        ['rheinische-singbrueder', 'Rheinische Singbrüder', 'Köln', 50.9375, 6.9603, 'maenner', 'erwachsene', 'Mittwochs 20:00 Uhr, Südstadt', 'Männerchor mit Humor und Anspruch. Karneval überlassen wir anderen, wir singen Balladen und Shanties.'],
        ['kupfergasse-jazzchor', 'Jazzchor Kupfergasse', 'Düsseldorf', 51.2277, 6.7735, 'gospel', 'erwachsene', 'Dienstags 19:30 Uhr, Altstadt', 'Jazz-Arrangements für 16 Stimmen. Vorsingen mit Absprache, Hörproben auf Anfrage.'],
        ['herbstlaub-seniorenchor', 'Seniorenchor Herbstlaub', 'Hannover', 52.3759, 9.7320, 'gemischt', 'senioren', 'Donnerstags 15:00 Uhr, Nordstadt', 'Singen am Nachmittag, mit Kaffee. Alte Schlager, neue Kanons, nach der Probe Zeit für einen Plausch.'],
        ['sonnenwirbel-mainufer', 'Mainuferchor Sonnenwirbel', 'Frankfurt', 50.1109, 8.6821, 'gemischt', 'alle', 'Mittwochs 19:00 Uhr, Sachsenhausen', 'Offener Projektchor mit fester Probe. Wir singen Popchor-Literatur und Filmmusik.'],
        ['neckarlicht-kammerchor', 'Kammerchor Neckarlicht', 'Stuttgart', 48.7758, 9.1829, 'kammer', 'erwachsene', 'Donnerstags 20:00 Uhr, Gaisburg', 'Geistliche Chormusik, Motetten, Passionen. Proben mit Stimmbildung, Auftritte zu Advent und Ostern.'],
        ['isarstimmen', 'Isarstimmen Gospel & Soul', 'München', 48.1351, 11.5820, 'gospel', 'erwachsene', 'Montags 19:30 Uhr, Giesing', 'Drei Konzerte im Jahr, ein Chor-Wochenende im Voralpenland. Neue Stimmen werden nach einem Probemonat aufgenommen.'],
        ['kuckucksberg-maennerchor', 'Männerchor Kuckucksberg', 'Freiburg', 47.9990, 7.8421, 'maenner', 'erwachsene', 'Dienstags 20:00 Uhr, Vauban', 'Gegründet 1953, gesucht wird ein neuer Chorleiter. Der Verein hat noch keinen Zugang zu seinem Profil.'],
        ['lindenhof-maedchenchor', 'Mädchenchor Lindenhof', 'Münster', 51.9607, 7.6261, 'frauen', 'jugend', 'Freitags 16:00 Uhr, Aegidiimarkt', 'Stimmbildung ab acht Jahren, Konzertchor ab zwölf. Eltern dürfen zuhören.'],
        ['nebelhorn-projektchor', 'Projektchor Nebelhorn', 'Rostock', 54.0924, 12.0991, 'gemischt', 'alle', 'Nach Vereinbarung', 'Wird vom Verzeichnis nicht ausgegeben: das Profil ist ausgeblendet.', 'hidden'],
        ['zwoelfuhr-hauschor', 'Hauschor Zwölfuhr', 'Berlin', 52.5000, 13.3600, 'gemischt', 'erwachsene', 'Sonntags 12:00 Uhr', 'Ein Chor, der nur auf Einladung singt. Das Profil ist privat und taucht in keiner Suche auf.', 'private'],
    ];

    /** @return array<string, int> */
    public function run(): array
    {
        $this->aufraeumen();

        $leute = $this->leute();
        $choere = $this->choere();

        $this->ansprueche($leute, $choere);

        return [
            'chorfinder-konten' => count($leute),
            'chorfinder-profile' => count($choere),
            'chorfinder-ansprueche' => DB::table('team_claims')->count(),
        ];
    }

    /** Stabiler Schluessel fuer Konten und Profile. */
    public static function uuid(string $art, string $schluessel): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "chorfinder-demo:$art:$schluessel")->toString();
    }

    public static function email(string $schluessel): string
    {
        return $schluessel.'@chorfinder.beispiel';
    }

    protected function aufraeumen(): void
    {
        // Abhaengige zuerst. Die Tabellen gehoeren in dieser Demo allein dem
        // Chorfinder, deshalb wird nicht nach Zeilen gefiltert.
        foreach ([
            'messages', 'conversation_participants', 'conversations',
            'community_reactions', 'community_comments', 'community_room_memberships', 'community_posts',
            'event_rsvps',
            'team_claim_outbox', 'team_claim_limit_locks', 'team_claims',
        ] as $tabelle) {
            if (Schema::hasTable($tabelle)) {
                DB::table($tabelle)->delete();
            }
        }

        if (Schema::hasTable('community_rooms')) {
            DB::table('community_rooms')->update(['pinned_post_id' => null]);
        }

        // Die Teams der eigenen Konten, nicht alle. Kurse und die Team-Demo
        // haben eigene.
        $schluessel = array_map(fn ($k) => self::uuid('user', $k), array_keys(self::LEUTE));
        $teams = Team::query()->whereIn('owner_id', $schluessel)->pluck('id')->all();

        if ($teams !== []) {
            foreach (['team_members', 'team_invitations'] as $tabelle) {
                DB::table($tabelle)->whereIn('team_id', $teams)->delete();
            }
            DB::table('teams')->whereIn('id', $teams)->delete();
        }

        // Meldungen der drei Typen und ihre Zustellzeilen.
        if (Schema::hasTable('notification_items')) {
            $typen = ['claim.decided', 'message.received', 'community.comment'];
            $ids = DB::table('notification_items')->whereIn('type', $typen)->pluck('id')->all();

            if ($ids !== [] && Schema::hasTable('notification_deliveries')) {
                DB::table('notification_deliveries')->whereIn('notification_item_id', $ids)->delete();
            }
            DB::table('notification_items')->whereIn('type', $typen)->delete();
        }

        // Die Chorprofile; der Hook raeumt die Projektion mit ab, der Rest steht
        // sicherheitshalber darunter.
        Entry::query()->where('collection', 'choirs')->get()->each(fn ($e) => $e->delete());
        DirectoryProfile::query()->delete();
    }

    /** @return array<string, \Statamic\Contracts\Auth\User> */
    protected function leute(): array
    {
        $konten = [];

        foreach (self::LEUTE as $schluessel => $daten) {
            $nutzer = User::findByEmail(self::email($schluessel));

            if (! $nutzer) {
                $nutzer = User::make()->id(self::uuid('user', $schluessel))->email(self::email($schluessel));
                $nutzer->password(self::PASSWORT);
            }

            $nutzer->set('name', $daten['name']);
            // Bestaetigt: Community und die Anspruchs-Policy verlangen es.
            $nutzer->set('email_verified_at', '2026-09-01 10:00:00');
            $nutzer->set('chorfinder_rolle', $daten['rolle']);
            $nutzer->save();

            if (isset($daten['cprolle']) && ! $nutzer->hasRole($daten['cprolle'])) {
                $nutzer->assignRole($daten['cprolle']);
                $nutzer->save();
            }

            $konten[$schluessel] = $nutzer;
        }

        return $konten;
    }

    /** @return array<string, string> Schluessel => Eintrags-id */
    protected function choere(): array
    {
        $ids = [];

        foreach (self::CHOERE as $zeile) {
            [$slug, $titel, $stadt, $lat, $lng, $art, $gruppe, $probe, $text] = $zeile;
            $sonder = $zeile[9] ?? null;
            $id = self::uuid('choir', $slug);

            Entry::make()
                ->collection('choirs')
                ->id($id)
                ->slug($slug)
                ->locale(Site::default()->handle())
                ->published(true)
                ->data([
                    'title' => $titel,
                    'city' => $stadt,
                    'description' => 'Beispieleintrag: '.$text,
                    'choir_type' => $art,
                    'target_group' => $gruppe,
                    'rehearsal' => $probe,
                    'visibility' => $sonder === 'private' ? 'private' : 'public',
                    'hidden' => $sonder === 'hidden',
                    'lat' => $lat,
                    'lng' => $lng,
                ])
                ->save();

            $ids[$slug] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, \Statamic\Contracts\Auth\User>  $leute
     * @param  array<string, string>  $choere
     */
    protected function ansprueche(array $leute, array $choere): void
    {
        $claims = app(ClaimService::class);
        $site = Site::default()->handle();

        $a = $claims->request('directory', $choere['kammerchor-alsterhain'], $site, $leute['tom'],
            'Ich leite den Kammerchor Alsterhain seit 2019 und habe den Vorsitz im Verein. Das Programmheft vom letzten Frühjahr liegt als Beleg bei.',
            'tom@chorfinder.beispiel');
        $claims->approve($a->id, $leute['moderation'], 'Der Vorstand hat per Mail bestätigt. Angenommen.');

        $b = $claims->request('directory', $choere['elbtoene-gospelchor'], $site, $leute['lena'],
            'Ich bin die Chorleiterin der Elbtöne und pflege die Webseite des Vereins. Die Adresse im Impressum passt zu diesem Konto.',
            'lena@chorfinder.beispiel');
        $claims->approve($b->id, $leute['moderation'], 'Impressum geprüft. Angenommen.');

        // Abgelehnt: Lena wollte auch einen Chor, der ihr nicht gehört.
        $c = $claims->request('directory', $choere['jugendchor-nordwind'], $site, $leute['lena'],
            'Ich würde den Jugendchor Nordwind gern als zweites Profil pflegen, wir arbeiten mit der Musikschule zusammen.',
            'lena@chorfinder.beispiel');
        $claims->reject($c->id, $leute['moderation'], 'Die Leitung hat den Anspruch nicht bestätigt.');

        // Offen: Jo will den Männerchor Kuckucksberg. Das ist die Zeile, die
        // der Besucher als Moderation entscheiden kann.
        $claims->request('directory', $choere['kuckucksberg-maennerchor'], $site, $leute['jo'],
            'Ich übernehme zum Herbst die Leitung des Männerchors Kuckucksberg. Der Vorsitzende kann das gern telefonisch bestätigen.',
            'jo@chorfinder.beispiel');

        // Ein zweites Mitglied im Kammerchor, damit eine Nachricht an das Team
        // zwei Leute erreicht und nicht nur den Besitzer.
        $alsterhain = Team::query()->where('name', 'Kammerchor Alsterhain')->first();

        if ($alsterhain) {
            app(MembershipService::class)->add($alsterhain, $leute['mia'], 'member');
        }
    }
}
