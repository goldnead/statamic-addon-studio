<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Models\ClientRoomSession;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmission;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmissionFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Die Eins-zu-eins-Beziehungen: sechs Klientenräume mit Verlauf.
 *
 * ## Wo Klientenräume in dieser Demo hingehören
 *
 * Zwei Marken, aus zwei verschiedenen Gründen. **Praxis Lindhorst** macht
 * 1:1-Begleitung; das ist der Fall, für den das Addon gebaut ist — eine
 * Person, eine Adresse, ein laufender Prozess aus Sitzungen und Aufgaben.
 * **Nordlicht Studio** ist die Agentur, und ihre Klienten sind die drei
 * Kundenmarken. Derselbe Raum, andere Sprache: aus der Sitzung wird der Jour
 * fixe, aus der Übungsaufgabe die Zulieferung. Beides nebeneinander zu zeigen
 * ist der Punkt, denn ein Raum, der nur für Stimmbildung funktioniert, wäre
 * eine Stimmbildungssoftware und kein Addon.
 *
 * Die drei anderen Marken bleiben leer, und das ist eine Aussage: ein Chor
 * (`chorwerkstatt`), eine Band (`halbmond`) und die Bösewicht-Marke
 * (`sonderzeichen`) haben keine Klienten. Eine Liste, die auf jeder Marke
 * gefüllt ist, beweist nicht, dass die Skopierung greift.
 *
 * ## Wiederholbarkeit
 *
 * `demo:seed` läuft oft hintereinander, also muss jeder Schritt hier beim
 * zweiten Lauf ein Nichts sein. Nur `open()` bringt das mit: der Raum ist über
 * `unique(brand_id, email)` eindeutig, und die Adresse wird dabei
 * kleingeschrieben, weshalb `BÄRBEL.Öztürk@Beispiel.DE` auch beim zehnten Lauf
 * denselben Raum findet. **Nicht** die Kontakt-Id ist der Schlüssel: ein Raum
 * kann ohne LeadHub-Kontakt bestehen, und genau das tun die drei
 * Agentur-Räume, für deren Adressen es unter `nordlicht` keinen Kontakt gibt.
 *
 * Sitzungen, Aufgaben und Einreichungen legen dagegen bedingungslos an. Als
 * stabiler Schlüssel dient deshalb **der Titel innerhalb des Raums** (bei
 * Einreichungen der Rumpf innerhalb der Aufgabe) — kein zusätzlicher Wert im
 * `meta`, der nur für den Seeder existierte. Der Titel ist das, was die
 * Redaktion im CP ohnehin sieht; steht er schon da, ist die Zeile da. Das
 * verbietet zwei gleichnamige Sitzungen im selben Raum, was hier keine
 * Einschränkung ist, sondern eine Ansage: „Jour fixe" allein wäre auch für
 * einen Menschen kein Titel.
 *
 * `completeTask()` und `attach()` brauchen keinen eigenen Schutz beziehungsweise
 * bekommen ihn über den Dateititel: eine erledigte Aufgabe bleibt erledigt und
 * feuert nichts, und eine Datei wird nur angehängt, wenn im Raum noch keine
 * mit demselben Titel liegt.
 *
 * ## Absichtliche Lücken
 *
 * Zu sieben der sechzehn Aufgaben gibt es **keine** Einreichung. Das ist kein
 * Sparen: der Bildschirm, den eine Coachin morgens aufmacht, lebt von der
 * Frage „was ist offen", und ein Demo, in dem alles abgegeben wurde, hat darauf
 * keine Antwort. Ebenso liegt eine Raumdatei auf `visible_to_client = false` —
 * die interne Notiz, die der Klient nie sieht.
 *
 * Läuft **nach** {@see SeedsCrm} und {@see SeedsTeam}: die Räume hängen sich
 * den LeadHub-Kontakt selbst an die Adresse (`Contacts::idFor()`), und die
 * Eigentümer sind CP-Konten, die es vorher geben muss.
 */
class SeedsClientRooms
{
    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken = []): array
    {
        foreach ($this->raeume() as $raum) {
            if (! isset($marken[$raum['marke']])) {
                continue;
            }

            BrandContext::runFor($marken[$raum['marke']], function () use ($raum) {
                $this->raumAufbauen($raum);
            });
        }

        return [
            'klientenraeume' => ClientRoom::query()->acrossBrands()->count(),
            'klienten_sitzungen' => ClientRoomSession::query()->count(),
            'klienten_aufgaben' => ClientRoomTask::query()->count(),
            'klienten_einreichungen' => ClientRoomTaskSubmission::query()->count(),
            'klienten_dateien' => ClientRoomFile::query()->count(),
            'klienten_einreichungsdateien' => ClientRoomTaskSubmissionFile::query()->count(),
        ];
    }

    /**
     * Einen Raum samt allem, was in ihm passiert ist.
     *
     * Die Reihenfolge ist nicht beliebig: erst der Raum, dann seine Sitzungen
     * und Aufgaben, und die Einreichung immer nach der Aufgabe, zu der sie
     * gehört. `touchActivity()` läuft bei jedem dieser Schreibvorgänge mit, das
     * Feld `last_activity_at` steht am Ende also auf dem letzten Vorgang und
     * nicht auf dem Zeitpunkt der Eröffnung.
     *
     * @param  array<string, mixed>  $raum
     */
    protected function raumAufbauen(array $raum): void
    {
        $klientenraum = ClientRooms::open($raum['adresse'], $raum['eigentuemer'], [
            'name' => $raum['name'],
            'notes' => $raum['notiz'],
        ]);

        foreach ($raum['sitzungen'] as [$titel, $wann, $dauer, $zusammenfassung]) {
            $this->sitzung($klientenraum, $titel, $wann, $dauer, $zusammenfassung);
        }

        foreach ($raum['aufgaben'] as $daten) {
            $aufgabe = $this->aufgabe($klientenraum, $daten);

            if ($daten['einreichung'] !== null) {
                [$text, $dateien] = $daten['einreichung'];

                $this->einreichung($aufgabe, $text, $raum['adresse'], $dateien);
            }

            // Erst einreichen, dann abhaken. Andersherum stünde im CP eine
            // erledigte Aufgabe, die danach noch etwas bekommen hat — ein
            // Ablauf, den es gibt, aber nicht als Normalfall in einem Demo.
            if ($daten['erledigt']) {
                ClientRooms::completeTask($aufgabe, $raum['eigentuemer']);
            }
        }

        foreach ($raum['dateien'] as [$titel, $dateiname, $sichtbar]) {
            $this->anhang($klientenraum, $titel, $dateiname, $sichtbar, $raum['eigentuemer']);
        }
    }

    /**
     * Eine Sitzung, wenn sie nicht schon im Raum steht.
     *
     * Gegen `$room->sessions()`, nicht gegen `ClientRoomSession::query()`: die
     * Sitzung trägt keine `brand_id` und ist deshalb gar nicht markenskopiert.
     * Ihre Marke ist die ihres Raums, und ein Titel wie „Kickoff" käme über
     * alle Räume hinweg mehrfach vor.
     */
    protected function sitzung(ClientRoom $raum, string $titel, string $wann, int $dauer, string $zusammenfassung): void
    {
        if ($raum->sessions()->where('title', $titel)->exists()) {
            return;
        }

        ClientRooms::recordSession($raum, $titel, $this->wann($wann), [
            // Alle Sitzungen liegen in der Vergangenheit und sind deshalb
            // `completed`. Eine geplante Sitzung wäre ein eigener Demo-Fall,
            // gehörte aber in die Zukunft und damit in einen Kalender, den
            // dieses Addon nicht führt.
            'status' => ClientRoomSession::STATUS_COMPLETED,
            'duration_minutes' => $dauer,
            'summary' => $zusammenfassung,
        ]);
    }

    /**
     * Eine Aufgabe, und beim zweiten Lauf die vorhandene.
     *
     * Zurückgegeben wird in beiden Fällen ein Modell, weil der Aufrufer danach
     * noch einreichen und abhaken will. Ein `return` bei „gibt es schon" hätte
     * beim zweiten Lauf die Einreichung mit verschluckt — und die steht in
     * einer anderen Tabelle, die dann für immer leer bliebe.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function aufgabe(ClientRoom $raum, array $daten): ClientRoomTask
    {
        if ($vorhanden = $raum->tasks()->where('title', $daten['titel'])->first()) {
            return $vorhanden;
        }

        return ClientRooms::addTask(
            $raum,
            $daten['titel'],
            $daten['faellig'] !== null ? $this->wann($daten['faellig']) : null,
            $raum->owner_user_id,
            [
                'description' => $daten['beschreibung'],
                'type' => $daten['art'],
                'priority' => $daten['rang'],
            ],
        );
    }

    /**
     * Was der Klient zurückgegeben hat.
     *
     * Der Rumpf ist der Schlüssel, und deshalb bekommt hier jede Einreichung
     * einen — auch die mit Datei. `submitTask()` nimmt Text ODER Datei und
     * wirft bei beidem leer; eine reine Dateiabgabe wäre also erlaubt, hätte
     * aber nichts, woran ein zweiter Lauf sie wiedererkennt, und der Raum
     * sammelte bei jedem `demo:seed` eine weitere Kopie derselben Aufnahme.
     *
     * Die Dateien werden erst nach dem Wächter geschrieben: eine temporäre
     * Datei anzulegen, die danach niemand benutzt, wäre Müll in
     * `sys_get_temp_dir()` bei jedem Lauf.
     *
     * `$absender` ist die Adresse des Klienten. Der Manager löst sie über
     * `Owners::resolveId()` auf und legt sie, wenn kein CP-Konto dahinter
     * steckt, im Klartext ab — das CP zeigt dann die Adresse statt eines
     * Namens, was für jemanden ohne Konto die richtige Antwort ist.
     *
     * @param  list<string>  $dateien  Dateinamen; der Inhalt kommt aus {@see inhalt()}
     */
    protected function einreichung(ClientRoomTask $aufgabe, string $text, string $absender, array $dateien): void
    {
        if ($aufgabe->submissions()->where('body', $text)->exists()) {
            return;
        }

        ClientRooms::submitTask(
            $aufgabe,
            $text,
            array_map(fn (string $name) => $this->hochgeladeneDatei($name, $this->inhalt($name)), $dateien),
            $absender,
        );
    }

    /**
     * Eine Datei im Raum selbst, vom Coach oder von der Agentur hochgeladen.
     *
     * Der Titel ist der Wächter. Der Dateiname taugt dafür nicht:
     * `Asset::upload()` macht aus einer zweiten `angebot.pdf` eine
     * `angebot-1.pdf`, ein Vergleich über `path` fände die erste also nie
     * wieder und jeder Lauf legte eine weitere Nummer daneben.
     */
    protected function anhang(ClientRoom $raum, string $titel, string $dateiname, bool $sichtbar, ?string $hochgeladenVon): void
    {
        if ($raum->files()->where('title', $titel)->exists()) {
            return;
        }

        ClientRooms::attach(
            $raum,
            $this->hochgeladeneDatei($dateiname, $this->inhalt($dateiname)),
            $titel,
            $sichtbar,
            $hochgeladenVon,
        );
    }

    // -- Dateien -------------------------------------------------------------

    /**
     * Eine echte `UploadedFile` aus einer echten Datei auf der Platte.
     *
     * `attach()` und `submitTask()` nehmen nichts anderes, und das ist richtig
     * so: der Weg geht durch `RoomFiles::storeInto()`, dort durch den
     * Endungs-Wächter aus `statamic-clientrooms.allowed_extensions` und dann
     * durch Statamics `Asset::upload()`. Ein Seeder, der stattdessen eine Zeile
     * in `client_room_files` schriebe, hätte einen Datensatz, hinter dem keine
     * Datei liegt — und die Download-Adresse im CP führte ins Leere.
     *
     * `test: true` ist die einzige Abweichung vom Ernstfall. Ohne sie besteht
     * `UploadedFile` auf `is_uploaded_file()`, was außerhalb einer
     * HTTP-Anfrage nie zutrifft. Gelesen wird die Datei über `getRealPath()`,
     * nicht über `move()`, deshalb reicht das hier.
     *
     * Der Quellpfad wird von Statamics `Uploader` nach dem Schreiben gelöscht,
     * solange die Anwendung in der Konsole läuft. Aufgeräumt wird also von
     * selbst; der feste Name unter `sys_get_temp_dir()` ist trotzdem einer, den
     * ein zweiter Lauf gefahrlos überschreiben kann.
     */
    protected function hochgeladeneDatei(string $name, string $inhalt): UploadedFile
    {
        $pfad = sys_get_temp_dir().'/demo-clientrooms-'.md5($name).'.'.pathinfo($name, PATHINFO_EXTENSION);

        file_put_contents($pfad, $inhalt);

        // Der Mime-Typ bleibt offen: `UploadedFile` liest ihn dann aus dem
        // Inhalt, statt eine Behauptung des Seeders zu übernehmen. Genau das
        // täte ein Browser auch nicht, und die Endung entscheidet ohnehin.
        return new UploadedFile($pfad, $name, null, null, true);
    }

    /**
     * Der Inhalt einer Demo-Datei, passend zu ihrer Endung.
     *
     * Ein PDF, das wirklich eines ist: `finfo` liest die ersten Bytes, und ein
     * `application/pdf` in den Asset-Metadaten, hinter dem eine Textdatei
     * liegt, wäre genau die Sorte Halbwahrheit, die dieses Playground sonst
     * aufdeckt. Der Rest ist Text und gibt sich auch als solcher aus.
     */
    protected function inhalt(string $name): string
    {
        if (str_ends_with(mb_strtolower($name), '.pdf')) {
            return "%PDF-1.4\n"
                ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
                ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
                ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n"
                ."trailer<</Root 1 0 R>>\n"
                ."%%EOF\n";
        }

        if (str_ends_with(mb_strtolower($name), '.mp3')) {
            // ID3v2-Kopf und ein paar MPEG-Rahmen: genug, dass die Datei als
            // Audio erkannt wird. Klingen soll sie nicht.
            return "ID3\x04\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x64", 256);
        }

        return "Demo-Datei aus demo:seed. Inhalt ist Platzhalter, die Endung ist die Aussage.\n";
    }

    /**
     * Ein Zeitpunkt aus einem Ausdruck wie `-14 days`.
     *
     * Feste Uhrzeit, kein `now()`: eine Sitzung, die bei jedem Lauf eine halbe
     * Minute später stattgefunden hat, ist im Diff nicht von einer echten
     * Änderung zu unterscheiden. Elf Uhr, weil eine Sitzung um Mitternacht
     * niemandem etwas erklärt.
     */
    protected function wann(string $ausdruck): Carbon
    {
        $tag = str_starts_with($ausdruck, '-')
            ? Carbon::today()->sub(ltrim($ausdruck, '-'))
            : Carbon::today()->add(ltrim($ausdruck, '+'));

        return $tag->setTime(11, 0);
    }

    // -- Die Räume -----------------------------------------------------------

    /**
     * Sechs Räume: drei in der Praxis, drei in der Agentur.
     *
     * Die Adressen kommen zum Teil aus {@see DemoData::AWKWARD_EMAILS} —
     * `a@b.de` als kürzest mögliche und `BÄRBEL.Öztürk@Beispiel.DE` als die mit
     * Großbuchstaben und Nicht-ASCII im lokalen Teil. Die zweite ist hier der
     * eigentliche Prüfstein: der Raum wird unter der kleingeschriebenen Form
     * abgelegt, und wenn `open()` das beim zweiten Lauf nicht wieder findet,
     * verbietet der eindeutige Index den Neuanlage-Versuch und der Seeder
     * bricht ab. Die übrigen Adressen sind mit Absicht unauffällig: die
     * Bösartigkeit gehört an die Stellen, an denen sie etwas prüft, nicht in
     * jede Zeile.
     *
     * Die Agentur-Räume laufen auf den Absenderadressen der drei Kundenmarken.
     * Unter `nordlicht` gibt es zu keiner davon einen LeadHub-Kontakt, also
     * bleibt `contact_id` dort null — der Fall, in dem ein Raum für sich allein
     * steht und das CP keinen Kontaktverweis anzeigen kann.
     *
     * @return list<array<string, mixed>>
     */
    protected function raeume(): array
    {
        return [
            [
                'marke' => 'lindhorst',
                'adresse' => 'a@b.de',
                'name' => 'Ana María Ñuñez',
                'eigentuemer' => 'said@nordlicht.beispiel',
                'notiz' => 'Kam über das Erstgespräch-Formular. Singt Alt im Kammerchor, seit drei Wochen belegt nach jeder Probe.',
                'sitzungen' => [
                    ['Erstgespräch: belegt nach der Probe', '-62 days', 60, 'Anamnese, Atembeobachtung im Sitzen und im Stehen. Kein medizinischer Befund, aber deutliche Pressatmung ab der zweiten Probenstunde.'],
                    ['Zweite Sitzung: Atemstütze im Sitzen', '-45 days', 60, 'Übungsaufbau auf Lippenflattern und Sirene. Im Sitzen trägt der Ton, im Stehen kippt er weg.'],
                    ['Dritte Sitzung: Registerübergang', '-17 days', 75, 'Erster Durchgang durch den Passaggio auf „ng". Ana hört den Wechsel selbst, bevor er kommt — das ist neu.'],
                ],
                'aufgaben' => [
                    [
                        'titel' => 'Lippenflattern, fünf Minuten täglich',
                        'beschreibung' => 'Jeden Morgen vor dem ersten Kaffee, im Sitzen. Nicht üben, nur aufwärmen.',
                        'art' => 'exercise',
                        'rang' => 'high',
                        'faellig' => '-11 days',
                        'erledigt' => false,
                        'einreichung' => ['An sechs von zehn Tagen geschafft. An den anderen war ich zu spät dran und habe es gelassen, statt es im Auto nachzuholen.', []],
                    ],
                    [
                        'titel' => 'Probenmitschnitt der letzten Chorprobe schicken',
                        'beschreibung' => 'Handy reicht. Interessant ist die zweite Hälfte, wenn die Stimme müde wird.',
                        'art' => 'homework',
                        'rang' => 'medium',
                        'faellig' => '+5 days',
                        'erledigt' => false,
                        'einreichung' => [
                            'Aufnahme vom letzten Dienstag. Ab Minute 40 hört man, was ich meine.',
                            ['probenmitschnitt-kammerchor.mp3'],
                        ],
                    ],
                    [
                        'titel' => 'Notieren, an welchen Tagen die Stimme belegt war',
                        'beschreibung' => 'Vier Wochen lang, eine Zeile pro Tag. Datum, Probe ja oder nein, wie es sich angefühlt hat.',
                        'art' => 'reflection',
                        'rang' => 'low',
                        'faellig' => '-31 days',
                        'erledigt' => true,
                        'einreichung' => ['Vier Wochen notiert. Auffällig: belegt war es fast immer dienstags und donnerstags, also an den beiden Probentagen hintereinander.', []],
                    ],
                ],
                'dateien' => [
                    ['Übungsblatt Atemstütze', 'uebungsblatt-atemstuetze.pdf', true],
                ],
            ],
            [
                'marke' => 'lindhorst',
                'adresse' => 'aennchen@beispiel.de',
                'name' => "Sängerin's Ännchen",
                'eigentuemer' => 'said@nordlicht.beispiel',
                'notiz' => 'Vor Publikum wird die Stimme eng. Technisch ist alles da, es kommt nur nicht heraus.',
                'sitzungen' => [
                    ['Erstgespräch: Enge vor Publikum', '-34 days', 60, 'Im Raum allein trägt alles. Sobald jemand zuhört, geht der Kehlkopf hoch. Kein technisches, ein Ansteuerungsproblem.'],
                    ['Zweite Sitzung: Auftrittsangst und Atem', '-13 days', 60, 'Mit geschlossenen Augen vor zwei Zuhörenden gesungen. Funktioniert. Mit offenen Augen kippt es sofort zurück.'],
                ],
                'aufgaben' => [
                    [
                        'titel' => 'Drei Minuten Summen vor jedem Auftritt',
                        'beschreibung' => 'Nicht einsingen, nur summen. Auch auf dem Weg zur Bühne.',
                        'art' => 'practice',
                        'rang' => 'medium',
                        'faellig' => '+9 days',
                        'erledigt' => false,
                        // Offen und ohne Abgabe: der Normalfall zwischen zwei
                        // Sitzungen, und der, den das CP zeigen können muss.
                        'einreichung' => null,
                    ],
                    [
                        'titel' => 'Aufnahme aus dem Konzert in Kiel hochladen',
                        'beschreibung' => 'Falls jemand mitgeschnitten hat. Wenn nicht, ist das auch eine Antwort.',
                        'art' => 'homework',
                        'rang' => 'low',
                        'faellig' => '-4 days',
                        'erledigt' => false,
                        // Überfällig und nichts abgegeben. Ohne diese Zeile gäbe
                        // es im ganzen Demo keine Aufgabe, bei der jemand
                        // nachfassen müsste.
                        'einreichung' => null,
                    ],
                    [
                        'titel' => 'Fragebogen zur Stimmbelastung ausfüllen',
                        'beschreibung' => 'Acht Fragen, fünf Minuten. Grundlage für die nächste Sitzung.',
                        'art' => 'reflection',
                        'rang' => 'medium',
                        'faellig' => '-20 days',
                        'erledigt' => true,
                        'einreichung' => ['Ausgefüllt. Das mit den vier Stunden Sprechen am Tag war mir vorher nicht klar.', []],
                    ],
                ],
                'dateien' => [],
            ],
            [
                'marke' => 'lindhorst',
                // Die Adresse mit Großbuchstaben und Umlauten. Sie steht hier
                // wegen `Emails::normalize()`: der Raum wird klein abgelegt,
                // gesucht wird klein, und beim zweiten Lauf muss genau diese
                // Zeile wieder gefunden werden.
                'adresse' => 'BÄRBEL.Öztürk@Beispiel.DE',
                'name' => 'Bärbel Öztürk-Weiß',
                'eigentuemer' => 'said@nordlicht.beispiel',
                'notiz' => 'Leitet selbst einen Chor und kommt für die eigene Stimme. Termine nur samstags.',
                'sitzungen' => [
                    ['Erstgespräch: Stimme trägt im Chor nicht', '-52 days', 60, 'Chorleitung und Singen am selben Abend. Die Sprechstimme frisst die Singstimme auf, nicht umgekehrt.'],
                    ['Zweite Sitzung: Registerarbeit', '-25 days', 90, 'Längere Sitzung, weil die Anreise weit ist. Bruststimme bis zum Übergang stabil, darüber fällt der Ton ab.'],
                ],
                'aufgaben' => [
                    [
                        'titel' => 'Tonleiter auf „ng" aufnehmen',
                        'beschreibung' => 'Eine Oktave auf und ab, zweimal pro Woche, immer im selben Raum aufnehmen.',
                        'art' => 'exercise',
                        'rang' => 'medium',
                        'faellig' => '+2 days',
                        'erledigt' => false,
                        'einreichung' => null,
                    ],
                    [
                        'titel' => 'Probenplan der nächsten vier Wochen schicken',
                        'beschreibung' => 'Damit die Termine nicht auf die Tage nach einer Doppelprobe fallen.',
                        'art' => 'homework',
                        'rang' => 'high',
                        'faellig' => '-7 days',
                        'erledigt' => false,
                        'einreichung' => ['Plan steht bis auf die letzte Woche, da ist noch ein Konzert offen. Die Doppelproben sind Dienstag und Donnerstag.', []],
                    ],
                ],
                'dateien' => [],
            ],
            [
                'marke' => 'nordlicht',
                'adresse' => 'hallo@chorwerkstatt.beispiel',
                'name' => 'Chorwerkstatt Nord',
                'eigentuemer' => 'mira@nordlicht.beispiel',
                'notiz' => 'Website und Kursanmeldung. Ansprechpartner ist der Vorstand, Entscheidungen brauchen eine Sitzung.',
                'sitzungen' => [
                    ['Kickoff: Website und Kursanmeldung', '-90 days', 90, 'Umfang abgesteckt: neue Kursseiten, Anmeldung über das bestehende Formular, Newsletter bleibt wie er ist.'],
                    ['Jour fixe: Anmeldestrecke', '-30 days', 45, 'Anmeldung läuft, Bezahlung fehlt noch. Offen ist, wer die Kurstexte schreibt.'],
                    ['Jour fixe: Newsletter-Übergabe', '-9 days', 45, 'Zugänge übergeben, erster eigener Versand ist raus. Ab jetzt schreibt die Chorwerkstatt selbst.'],
                ],
                'aufgaben' => [
                    [
                        'titel' => 'Logo in Vektorform liefern',
                        'beschreibung' => 'SVG oder EPS. Das PNG aus der alten Seite reicht für den Druck nicht.',
                        'art' => 'homework',
                        'rang' => 'high',
                        'faellig' => '-21 days',
                        'erledigt' => false,
                        'einreichung' => null,
                    ],
                    [
                        'titel' => 'Texte für die Kursseite freigeben',
                        'beschreibung' => 'Entwurf liegt im Raum. Änderungen bitte direkt im Dokument.',
                        'art' => 'homework',
                        'rang' => 'medium',
                        'faellig' => '+7 days',
                        'erledigt' => false,
                        'einreichung' => [
                            'Freigegeben bis auf den Abschnitt zu den Preisen, der geht noch in die Vorstandssitzung.',
                            ['kursseite-texte-freigabe.pdf'],
                        ],
                    ],
                    [
                        'titel' => 'Zugang zum Newsletter-Konto einrichten',
                        'beschreibung' => 'Ein eigenes Konto für die Agentur, kein geteiltes Passwort.',
                        'art' => 'homework',
                        'rang' => 'high',
                        'faellig' => '-35 days',
                        'erledigt' => true,
                        'einreichung' => ['Konto ist angelegt, Einladung ging an post@nordlicht.beispiel.', []],
                    ],
                ],
                'dateien' => [
                    ['Angebot Website-Relaunch', 'angebot-website-relaunch.pdf', true],
                ],
            ],
            [
                'marke' => 'nordlicht',
                'adresse' => 'crew@halbmond.beispiel',
                'name' => 'Kollektiv Halbmond',
                'eigentuemer' => 'mira@nordlicht.beispiel',
                'notiz' => 'Tourseite und Vorverkauf. Antworten kommen nachts, Termine tagsüber sind schwierig.',
                'sitzungen' => [
                    ['Kickoff: Tourseite und Vorverkauf', '-75 days', 60, 'Eine Seite, zwanzig Termine, ein Ticketanbieter. Der Vorverkauf liegt außerhalb, wir verlinken nur.'],
                    ['Jour fixe: Vinyl-Vorbestellung', '-21 days', 45, 'Vorbestellung läuft über den Shop. Offen sind die Bildrechte an den Tourfotos.'],
                ],
                'aufgaben' => [
                    [
                        'titel' => 'Bildrechte für die Tourfotos klären',
                        'beschreibung' => 'Wer hat fotografiert, und was steht im Vertrag. Ohne das geht keins der Bilder online.',
                        'art' => 'homework',
                        'rang' => 'urgent',
                        'faellig' => '-9 days',
                        'erledigt' => false,
                        'einreichung' => ['Bei zwölf von zwanzig Fotos geklärt, die restlichen sind vom Fotografen aus Kiel, der meldet sich nicht.', []],
                    ],
                    [
                        'titel' => 'Setliste für die Tourseite schicken',
                        'beschreibung' => 'Reihenfolge egal, es geht nur um die Titel für die Seite.',
                        'art' => 'homework',
                        'rang' => 'low',
                        'faellig' => '+14 days',
                        'erledigt' => false,
                        'einreichung' => null,
                    ],
                ],
                'dateien' => [],
            ],
            [
                'marke' => 'nordlicht',
                'adresse' => 'praxis@lindhorst.beispiel',
                'name' => 'Praxis Lindhorst',
                'eigentuemer' => 'mira@nordlicht.beispiel',
                'notiz' => 'Praxisseite, Terminbuchung und der Klientenbereich. Die Praxis nutzt denselben Raum, den sie selbst ihren Klientinnen gibt.',
                'sitzungen' => [
                    ['Kickoff: Praxisseite', '-120 days', 90, 'Bestand gesichtet: alte Seite bleibt online, bis die Terminbuchung steht.'],
                    ['Jour fixe: Terminbuchung', '-40 days', 45, 'Buchung läuft im Testbetrieb. Absagefrist und Ausfallhonorar müssen noch in die Texte.'],
                    ['Jour fixe: Klientenbereich', '-6 days', 60, 'Erste Räume angelegt. Offen ist, was der Klient sehen darf und was interne Notiz bleibt.'],
                ],
                'aufgaben' => [
                    [
                        'titel' => 'Öffnungszeiten bestätigen',
                        'beschreibung' => 'Die aus dem Impressum stimmen seit dem Umzug nicht mehr.',
                        'art' => 'homework',
                        'rang' => 'medium',
                        'faellig' => '-50 days',
                        'erledigt' => true,
                        'einreichung' => ['Dienstag bis Freitag 9 bis 18 Uhr, Samstag nur nach Absprache. Montags geschlossen.', []],
                    ],
                    [
                        'titel' => 'Datenschutzerklärung gegenlesen',
                        'beschreibung' => 'Besonders der Abschnitt zu den Aufnahmen aus den Sitzungen.',
                        'art' => 'homework',
                        'rang' => 'high',
                        'faellig' => '+3 days',
                        'erledigt' => false,
                        'einreichung' => null,
                    ],
                    [
                        'titel' => 'Einwilligungstext für die Aufnahmen abstimmen',
                        'beschreibung' => 'Ein Satz, den die Klientin vor der ersten Aufnahme liest und bestätigt.',
                        'art' => 'homework',
                        'rang' => 'urgent',
                        'faellig' => '-2 days',
                        'erledigt' => false,
                        'einreichung' => null,
                    ],
                ],
                'dateien' => [
                    ['Freigabe Praxisseite', 'freigabe-praxisseite.pdf', true],
                    // Nicht für den Klienten sichtbar: die eine Datei, an der
                    // sich zeigt, ob `visible_to_client` im Mitgliederbereich
                    // wirklich filtert oder nur im CP als Häkchen steht.
                    ['Interne Notiz zur Migration', 'interne-notiz-migration.pdf', false],
                ],
            ],
        ];
    }
}
