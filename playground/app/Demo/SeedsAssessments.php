<?php

namespace App\Demo;

use Goldnead\Assessments\Facades\Assessments;
use Goldnead\Assessments\Models\Assessment;
use Goldnead\Assessments\Models\Question;
use Goldnead\Assessments\Models\Response;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Support\Carbon;

/**
 * Fragebögen, und die Leute, die sie ausgefüllt haben.
 *
 * `/cp/assessments` war im Schauraum sichtbar und leer: drei Tabellen ohne
 * eine einzige Zeile. Eine Übersicht ohne Daten beweist nur, dass die Route
 * antwortet. Was sie nicht zeigt, ist das, wofür das Addon gebaut wurde, und
 * das ist die Punktrechnung: Fragen mit Punkten, Stufen mit Grenzen, und eine
 * Verteilung von Ergebnissen, an der man sieht, dass die Grenzen sitzen.
 *
 * ## Alles über die Fassade
 *
 * `Assessments::create()` und `Assessments::submit()`, kein `Model::create()`
 * daneben. Der Unterschied ist hier nicht Stil, sondern die halbe Prüfung:
 * `create()` schreibt die Fragen in einer Transaktion und lässt die Stufen
 * gegen Lücken, Überlappungen und (bei veröffentlichten Bögen) gegen den
 * erreichbaren Punktebereich laufen. `submit()` rechnet die Antworten durch
 * den Scorer, friert sie als `answers_readable` ein, sucht die Stufe und
 * feuert `AssessmentCompleted`. Von Hand geschriebene Zeilen hätten Punkte,
 * die niemand nachgerechnet hat, und eine Stufe, die niemand zugewiesen hat.
 *
 * `contact_id` wird hier bewusst NICHT gesetzt. Das macht der LeadHub-Listener
 * am Ereignis, sofern das Geschwister-Addon installiert ist; setzt der Seeder
 * die Spalte selbst, sieht die Verbindung auch dann aus wie hergestellt, wenn
 * die Brücke gar nicht läuft.
 *
 * ## Marken
 *
 * `Assessment` und `Response` tragen `HasBrand`, also läuft jeder Schreibweg
 * in `BrandContext::runFor()`. `handle` ist dabei die Ausnahme: der ist über
 * alle Marken eindeutig, weil die öffentliche Adresse nur aus ihm besteht.
 * Deshalb wird vor dem Anlegen mit `withoutGlobalScopes()` gesucht, sonst
 * findet die markenskopierte Abfrage den fremden Bogen nicht und läuft in den
 * Unique-Index.
 *
 * ## Stabile Kennung: `visit_token`
 *
 * `demo:seed` läuft oft hintereinander, und eine Antwort hat keinen
 * natürlichen Schlüssel: dieselbe Adresse darf denselben Bogen zweimal
 * ausfüllen, das ist kein Fehler, sondern der Normalfall. Also bekommt jede
 * Demo-Antwort einen festen `visit_token` nach dem Muster
 *
 *     demo-assess-{handle}-{laufende Nummer, zweistellig}
 *
 * also etwa `demo-assess-cw-stimm-check-07`. Die Spalte ist unique, und sie
 * ist genau die Kennung, unter der eine Antwort später wiedergefunden wird.
 * Steht der Token schon da, wird nichts eingereicht. `submit()` würfelt den
 * Token selbst, deshalb wird er direkt nach dem Einreichen überschrieben,
 * zusammen mit dem Datum (dazu unten).
 *
 * Für die Bögen selbst ist die Kennung der `handle`. Ein vorhandener Bogen
 * wird NICHT aktualisiert, und das ist wichtiger, als es aussieht:
 * `Assessments::update()` schreibt die Fragenliste neu und löscht jede Frage,
 * die die Liste nicht mehr nennt. Die Fragen-IDs sind aber die Schlüssel, unter
 * denen jede gespeicherte Antwort ihre Werte ablegt. Ein zweiter Lauf, der die
 * Fragen „nur nachzieht", macht damit jede Antwort des ersten Laufs zu einer
 * Sammlung von Punkten ohne Frage.
 *
 * ## Datum
 *
 * `submit()` stempelt `created_at` auf jetzt, und das ist im Betrieb richtig:
 * eine Antwort entsteht, wenn jemand auf Absenden drückt. Ein Demo mit
 * dreißig Antworten von heute Vormittag zeigt aber keine Zeitachse, keinen
 * Verlauf und keine Filterleiste, die etwas zu filtern hätte. Deshalb wird das
 * Datum nach dem Einreichen auf einen festen Tagesabstand gesetzt. Beim
 * zweiten Lauf passiert das nicht noch einmal, weil die Antwort dann schon
 * über ihren Token gefunden wird.
 *
 * ## Was hier zu sehen sein soll
 *
 * Zwei veröffentlichte Bögen mit Antworten und einer im Entwurf, damit das CP
 * beide Zustände nebeneinander zeigt. Der Entwurf hat absichtlich Stufen, die
 * den oberen Teil des erreichbaren Bereichs nicht abdecken: genau das lässt
 * das Addon nur zu, solange `published` falsch ist, und es ist der Fall, der
 * beim Veröffentlichen mit `level_range` abgelehnt wird. Er hat auch keine
 * einzige Antwort, denn ein Entwurf stand nie im Netz. Damit steht im CP ein
 * Bogen mit Ergebnisliste neben einem mit Leerzustand.
 *
 * Die Punktzahlen sind so gestreut, dass jede Stufe beider veröffentlichter
 * Bögen mindestens einmal getroffen ist, einschließlich der Antworten, die
 * genau auf einer Stufengrenze landen (13 und 25 beim Stimm-Check, 17 bei der
 * Praxis). Wer eine Grenze falsch herum programmiert, sieht es an diesen
 * Zeilen und sonst nirgends.
 */
class SeedsAssessments
{
    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken = []): array
    {
        foreach ($this->boegen() as $daten) {
            $marke = $marken[$daten['marke']] ?? null;

            if (! $marke instanceof Brand) {
                continue;
            }

            BrandContext::runFor($marke, function () use ($daten, $marke) {
                $bogen = $this->bogen($daten, $marke);

                if ($bogen === null) {
                    return;
                }

                $this->antworten($bogen, $daten);
            });
        }

        return [
            'assessments' => Assessment::withoutGlobalScopes()->count(),
            // `Question` trägt kein `HasBrand`: eine Frage gehört ihrem Bogen,
            // und der Bogen gehört der Marke. Deshalb hier keine Skopierung,
            // die es gar nicht gibt.
            'assessment_fragen' => Question::query()->count(),
            'assessment_antworten' => Response::withoutGlobalScopes()->count(),
        ];
    }

    /**
     * Den Bogen anlegen, oder den vorhandenen nehmen.
     *
     * Gesucht wird ohne Markenskopierung, weil `handle` global eindeutig ist:
     * eine skopierte Abfrage fände einen Bogen der Nachbarmarke nicht und
     * liefe beim Anlegen in den Unique-Index. Gehört der gefundene Bogen einer
     * anderen Marke, fasst dieser Seeder ihn nicht an und schreibt auch keine
     * Antworten hinein: es ist dann nicht seiner.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function bogen(array $daten, Brand $marke): ?Assessment
    {
        $vorhanden = Assessment::withoutGlobalScopes()->where('handle', $daten['handle'])->first();

        if ($vorhanden !== null) {
            if ((int) $vorhanden->brand_id !== (int) $marke->id) {
                return null;
            }

            return $vorhanden->load('questions');
        }

        return Assessments::create([
            'title' => $daten['title'],
            'handle' => $daten['handle'],
            'intro' => $daten['intro'],
            'outro' => $daten['outro'],
            'published' => $daten['published'],
            'collect' => $daten['collect'],
            'questions' => $daten['fragen'],
            'scoring' => $daten['stufen'],
        ]);
    }

    /**
     * Die Antworten, über den echten Einreichungsweg.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function antworten(Assessment $bogen, array $daten): void
    {
        foreach ($daten['antworten'] as [$nummer, $adresse, $name, $tage, $wahl]) {
            $kennung = $this->kennung($daten['handle'], $nummer);

            if (Response::withoutGlobalScopes()->where('visit_token', $kennung)->exists()) {
                continue;
            }

            $antwort = Assessments::submit($bogen, $adresse, $name, $this->nachFragenId($bogen, $wahl));

            // Der gewürfelte Token wird durch die Kennung ersetzt und das
            // Jetzt-Datum durch den gewollten Abstand. Beides erst hier und
            // nicht vorher: `submit()` nimmt weder das eine noch das andere
            // entgegen, und das ist richtig so, ein Besucher bestimmt seine
            // Uhrzeit nicht selbst.
            $antwort->visit_token = $kennung;
            $antwort->created_at = Carbon::now()->startOfDay()->subDays($tage)->addHours(8 + ($nummer % 11));
            $antwort->save();
        }
    }

    protected function kennung(string $handle, int $nummer): string
    {
        return sprintf('demo-assess-%s-%02d', $handle, $nummer);
    }

    /**
     * Die Auswahl nach Fragen-ID umschlüsseln.
     *
     * Der Scorer liest die Antworten unter `(string) $frage->id`, und die IDs
     * kennt erst die Datenbank. Die Definitionen unten zählen deshalb nach
     * Position (die Reihenfolge, in der die Fragen dort stehen), was sie
     * lesbar hält und gegen jede Auto-Increment-Lage des jeweiligen Rechners
     * immun macht.
     *
     * @param  array<int, mixed>  $wahl
     * @return array<string, mixed>
     */
    protected function nachFragenId(Assessment $bogen, array $wahl): array
    {
        $antworten = [];

        foreach ($bogen->questions->values() as $position => $frage) {
            if (! array_key_exists($position, $wahl)) {
                continue;
            }

            $antworten[(string) $frage->id] = $wahl[$position];
        }

        return $antworten;
    }

    /**
     * Die drei Bögen mit ihren Fragen, Stufen und Antworten.
     *
     * Antwortzeile: `[Nummer, Adresse, Name, Tage zurück, Auswahl je Frage]`.
     * Die Auswahl ist nach Position gekeyt, `single` als Options-Index,
     * `multi` als Liste von Indizes, `scale` als Zahl zwischen `min` und `max`.
     *
     * @return list<array<string, mixed>>
     */
    protected function boegen(): array
    {
        $adressen = DemoData::AWKWARD_EMAILS;
        $namen = DemoData::AWKWARD_NAMES;

        return [
            [
                'marke' => 'chorwerkstatt',
                'handle' => 'cw-stimm-check',
                'title' => 'Der Stimm-Check',
                'published' => true,
                // `optional`: der Name wird gefragt und darf fehlen. Genau
                // deshalb steht unten bei einigen Antworten `null`, sonst wäre
                // die Ergebnisliste eine Spalte, die immer gefüllt ist, und
                // niemand sähe, wie sie ohne Namen aussieht.
                'collect' => ['name' => 'optional'],
                'intro' => 'Fünf Fragen, zwei Minuten. Danach weißt du, woran du bei deiner Stimme gerade arbeiten kannst.',
                'outro' => 'Das Ergebnis ist eine Momentaufnahme, keine Diagnose.',
                'fragen' => [
                    [
                        'text' => 'Wie oft singst du außerhalb der Chorprobe?',
                        'type' => 'single',
                        'options' => [
                            ['label' => 'Fast täglich', 'points' => 6],
                            ['label' => 'Ein paar Mal die Woche', 'points' => 4],
                            ['label' => 'Selten', 'points' => 2],
                            ['label' => 'Gar nicht', 'points' => 0],
                        ],
                    ],
                    [
                        'text' => 'Wie fühlt sich deine Stimme nach einer Probe an?',
                        'type' => 'single',
                        'options' => [
                            ['label' => 'So frei wie vorher', 'points' => 6],
                            ['label' => 'Etwas müde', 'points' => 4],
                            ['label' => 'Belegt, am nächsten Tag wieder gut', 'points' => 2],
                            ['label' => 'Heiser, oft bis in den nächsten Tag', 'points' => 0],
                        ],
                    ],
                    [
                        'text' => 'Was davon kennst du aus deinem eigenen Singen?',
                        'help' => 'Mehrfachauswahl. Nichts anzukreuzen ist auch eine Antwort.',
                        'type' => 'multi',
                        // Alle Punkte positiv, damit das Minimum dieser Frage
                        // null ist. Eine negative Option zöge den erreichbaren
                        // Bereich unter null, und die Stufen unten müssten dort
                        // anfangen, wo sie sonst nur verwirren.
                        'options' => [
                            ['label' => 'Ich weiß, wo mein Registerwechsel liegt', 'points' => 3],
                            ['label' => 'Ich kann einen Ton leise und laut halten', 'points' => 3],
                            ['label' => 'Ich wärme mich auch allein ein', 'points' => 2],
                            ['label' => 'Ich merke, wenn ich presse', 'points' => 2],
                        ],
                    ],
                    [
                        'text' => 'Wie sicher fühlst du dich in der Höhe?',
                        'help' => '0 heißt gar nicht sicher, 5 heißt völlig sicher.',
                        'type' => 'scale',
                        'min' => 0,
                        'max' => 5,
                        'points_per_step' => 2,
                    ],
                    [
                        'text' => 'Wie lange singst du schon im Chor?',
                        'type' => 'single',
                        'options' => [
                            ['label' => 'Im ersten Jahr', 'points' => 1],
                            ['label' => 'Zwei bis fünf Jahre', 'points' => 3],
                            ['label' => 'Länger als fünf Jahre', 'points' => 5],
                            ['label' => 'Ich singe gerade in keinem Chor', 'points' => 0],
                        ],
                    ],
                ],
                // Erreichbar sind 0 bis 37 Punkte (6 + 6 + 10 + 10 + 5). Der
                // Bogen ist veröffentlicht, also prüft das Addon genau diesen
                // Bereich auf lückenlose Abdeckung: 0 bis 12, 13 bis 25, 26
                // bis 37, ohne Loch und ohne Überlappung.
                'stufen' => [
                    [
                        'key' => 'anfang',
                        'label' => 'Am Anfang',
                        'min' => 0,
                        'max' => 12,
                        'text' => 'Deine Stimme trägt dich durch die Probe, aber sie arbeitet dabei gegen dich. Der erste Schritt ist nicht mehr Singen, sondern anderes Singen.',
                    ],
                    [
                        'key' => 'unterwegs',
                        'label' => 'Unterwegs',
                        'min' => 13,
                        'max' => 25,
                        'text' => 'Du hast ein Gefühl für deine Stimme und merkst, wenn etwas nicht stimmt. Was fehlt, ist der Griff, mit dem du es änderst.',
                    ],
                    [
                        'key' => 'sicher',
                        'label' => 'Sicher',
                        'min' => 26,
                        'max' => 37,
                        'text' => 'Du weißt, was du tust. Ab hier geht es um Feinarbeit, und darum, das Gehörte auch bei anderen zu erkennen.',
                    ],
                ],
                'antworten' => [
                    // Zwei Adressen aus der Liste der hässlichen Fälle, und
                    // zwar 4 und 5: dieselbe Adresse in zwei Schreibweisen.
                    // `submit()` kleinschreibt, also stehen am Ende zwei
                    // Antworten derselben Person in der Liste, was im Betrieb
                    // genau so passiert und in keinem Bericht auffällt, solange
                    // niemand danach sucht.
                    [1, $adressen[0], $namen[2], 214, [0, 0, [0, 1, 2, 3], 5, 2]],
                    [2, 'lena.brauer@beispiel.de', 'Lena Brauer', 201, [1, 0, [0, 1], 4, 2]],
                    [3, 'k.simon@beispiel.de', 'Katrin Simon', 188, [1, 1, [2, 3], 3, 1]],
                    [4, $adressen[1], null, 176, [2, 1, [0], 2, 1]],
                    // Null Punkte in vier von fünf Fragen. Die Zeile, an der
                    // man sieht, ob die unterste Stufe überhaupt vergeben wird.
                    [5, 'erster.abend@beispiel.de', 'Tobias Reh', 165, [3, 3, [], 0, 0]],
                    [6, 'm.kowalski@beispiel.de', null, 152, [2, 2, [3], 1, 3]],
                    // Genau 13: die erste Zahl der mittleren Stufe. Wer die
                    // Grenze exklusiv programmiert, wirft diese Antwort eine
                    // Stufe tiefer, und nur hier fällt es auf.
                    [7, 'grenzfall@beispiel.de', 'Ida Lem', 141, [2, 2, [2, 3], 1, 1]],
                    // Und genau 25: die letzte Zahl derselben Stufe.
                    [8, 'a.thiele@beispiel.de', 'Arne Thiele', 133, [1, 1, [0, 1], 3, 2]],
                    [9, $adressen[4], $namen[4], 120, [0, 1, [0, 1, 2], 4, 2]],
                    [10, $adressen[5], $namen[4], 119, [1, 2, [1, 2], 2, 1]],
                    [11, 'p.novak@beispiel.de', 'Petra Novak', 104, [3, 2, [3], 0, 0]],
                    [12, 'chorbass@beispiel.de', null, 97, [2, 3, [0], 1, 0]],
                    [13, $adressen[3], $namen[9], 88, [0, 0, [0, 1, 2, 3], 4, 1]],
                    [14, 's.hollmann@beispiel.de', 'Sven Hollmann', 76, [1, 1, [1], 3, 2]],
                    [15, 'r.baumgart@beispiel.de', 'Rieke Baumgart', 61, [2, 1, [2, 3], 2, 2]],
                    [16, 'neu.im.chor@beispiel.de', null, 54, [3, 1, [], 1, 1]],
                    [17, 'j.wendt@beispiel.de', 'Jule Wendt', 42, [0, 2, [0, 3], 5, 0]],
                    [18, $adressen[7], null, 31, [1, 0, [0, 1, 2], 5, 1]],
                    [19, 'h.oesterle@beispiel.de', 'Hanna Österle', 18, [2, 2, [], 2, 0]],
                    // Vorgestern, damit die Liste oben nicht mit einem halben
                    // Jahr Abstand zur Gegenwart beginnt.
                    [20, 'ganz.frisch@beispiel.de', 'Milan Fischer', 2, [3, 3, [3], 0, 3]],
                ],
            ],

            [
                'marke' => 'lindhorst',
                'handle' => 'lh-vor-dem-erstgespraech',
                'title' => 'Vor dem Erstgespräch',
                'published' => true,
                // `required`: ohne Namen kein Termin. Der dritte Modus, `off`,
                // steht beim Entwurf unten. Damit sind alle drei im Schauraum
                // vertreten und keiner nur in der Konfigurationsdoku.
                'collect' => ['name' => 'required'],
                'intro' => 'Vier Fragen, damit das Erstgespräch nicht mit dem Ausfüllen eines Bogens anfängt.',
                'outro' => 'Die Antworten liegen beim Termin vor. Nichts davon geht an Dritte.',
                'fragen' => [
                    [
                        'text' => 'Was führt Sie her?',
                        'type' => 'single',
                        'options' => [
                            ['label' => 'Heiserkeit, die nicht weggeht', 'points' => 4],
                            ['label' => 'Die Stimme wird bei Auftritten eng', 'points' => 3],
                            ['label' => 'Ich möchte technisch weiterkommen', 'points' => 1],
                            ['label' => 'Ärztlich abgeklärt, jetzt Stimmtraining', 'points' => 5],
                        ],
                    ],
                    [
                        'text' => 'Wie belastet fühlt sich Ihre Stimme im Alltag?',
                        'help' => '0 heißt unbelastet, 10 heißt am Ende des Tages nicht mehr tragfähig.',
                        'type' => 'scale',
                        'min' => 0,
                        'max' => 10,
                        'points_per_step' => 1,
                    ],
                    [
                        'text' => 'Was trifft auf Sie zu?',
                        'type' => 'multi',
                        'options' => [
                            ['label' => 'Ich spreche beruflich viel', 'points' => 2],
                            ['label' => 'Ich rauche oder habe geraucht', 'points' => 2],
                            ['label' => 'Ich trinke wenig über den Tag', 'points' => 1],
                            ['label' => 'Ich hatte schon einmal Stimmtherapie', 'points' => 1],
                        ],
                    ],
                    [
                        'text' => 'Seit wann besteht das?',
                        'type' => 'single',
                        'options' => [
                            ['label' => 'Seit ein paar Tagen', 'points' => 1],
                            ['label' => 'Seit einigen Wochen', 'points' => 3],
                            ['label' => 'Seit Monaten', 'points' => 5],
                        ],
                    ],
                ],
                // Erreichbar sind 2 bis 26 Punkte: die erste und die letzte
                // Frage geben mindestens einen Punkt, weil es dort keine
                // Option für „nichts davon" gibt. Die Stufen fangen trotzdem
                // bei 0 an und reichen bis 30. Das ist erlaubt, sie müssen den
                // Bereich decken und nicht treffen, und es hält den Bogen
                // veröffentlichbar, wenn die Praxis später eine Option mit null
                // Punkten ergänzt.
                'stufen' => [
                    [
                        'key' => 'leicht',
                        'label' => 'Unauffällig',
                        'min' => 0,
                        'max' => 9,
                        'text' => 'Nach dem, was Sie angeben, ist Ihre Stimme belastbar. Im Erstgespräch geht es dann eher um Technik als um Entlastung.',
                    ],
                    [
                        'key' => 'auffaellig',
                        'label' => 'Auffällig',
                        'min' => 10,
                        'max' => 17,
                        'text' => 'Ein paar Ihrer Angaben deuten auf eine dauerhafte Belastung hin. Wir schauen uns im Erstgespräch zuerst den Alltag an, nicht das Singen.',
                    ],
                    [
                        'key' => 'dringend',
                        'label' => 'Zeitnah abklären',
                        'min' => 18,
                        'max' => 30,
                        'text' => 'Bitte bringen Sie einen ärztlichen Befund mit, falls vorhanden. Ohne den bleibt die Ursache offen, und Training auf eine ungeklärte Ursache ist keine gute Idee.',
                    ],
                ],
                'antworten' => [
                    [1, 'b.reinert@beispiel.de', 'Birte Reinert', 196, [0, 8, [0, 1], 2]],
                    [2, $adressen[3], $namen[3], 172, [1, 5, [0], 1]],
                    [3, 'f.kalb@beispiel.de', 'Florian Kalb', 158, [2, 2, [], 0]],
                    [4, 'm.strunz@beispiel.de', 'Marlene Strunz', 143, [3, 9, [0, 1, 2], 2]],
                    [5, 'chorleitung.nord@beispiel.de', 'Uwe Peschel', 129, [1, 4, [2], 1]],
                    [6, $adressen[1], 'Tine Adler', 112, [2, 1, [3], 0]],
                    [7, 'l.wiedemann@beispiel.de', 'Lilo Wiedemann', 95, [0, 6, [1], 1]],
                    // Alles angekreuzt, die Skala ganz oben: die Zeile, die den
                    // höchsten überhaupt erreichbaren Wert erzeugt. Gäbe es
                    // oberhalb der letzten Stufe eine Lücke, stünde hier keine
                    // Stufe, und man sähe es sofort.
                    [8, 'notfall@beispiel.de', 'Gerrit Ohm', 77, [3, 10, [0, 1, 2, 3], 2]],
                    [9, 'a.sablotny@beispiel.de', 'Anke Sablotny', 58, [2, 3, [2], 0]],
                    // Genau 17: die letzte Zahl der mittleren Stufe.
                    [10, 'v.hartig@beispiel.de', 'Vera Hartig', 39, [1, 7, [0], 2]],
                    [11, 'ruhige.stimme@beispiel.de', 'Nils Barg', 24, [0, 0, [], 0]],
                    [12, 'e.kroeger@beispiel.de', 'Elske Kröger', 6, [2, 5, [0, 3], 1]],
                ],
            ],

            [
                // Die Agentur selbst, und nur als Entwurf. Ein unveröffentlichter
                // Bogen ist keine Beigabe, sondern der zweite Zustand, den die
                // Übersicht unterscheiden muss.
                'marke' => 'nordlicht',
                'handle' => 'nl-website-check-entwurf',
                'title' => 'Website-Check (Entwurf)',
                'published' => false,
                'collect' => ['name' => 'off'],
                'intro' => 'Noch nicht fertig. Drei Fragen stehen, die Auswertung noch nicht.',
                'outro' => null,
                'fragen' => [
                    [
                        'text' => 'Wie alt ist die Website?',
                        'type' => 'single',
                        'options' => [
                            ['label' => 'Unter zwei Jahre', 'points' => 0],
                            ['label' => 'Zwei bis fünf Jahre', 'points' => 3],
                            ['label' => 'Älter', 'points' => 6],
                        ],
                    ],
                    [
                        'text' => 'Wer pflegt die Inhalte?',
                        'type' => 'single',
                        'options' => [
                            ['label' => 'Ich selbst', 'points' => 0],
                            ['label' => 'Eine Agentur', 'points' => 2],
                            ['label' => 'Im Moment niemand', 'points' => 5],
                        ],
                    ],
                    [
                        'text' => 'Was fehlt?',
                        'type' => 'multi',
                        'options' => [
                            ['label' => 'Termine online buchbar', 'points' => 2],
                            ['label' => 'Newsletter-Anmeldung', 'points' => 2],
                            ['label' => 'Ein Shop', 'points' => 3],
                        ],
                    ],
                ],
                // Erreichbar sind 0 bis 18 Punkte, abgedeckt ist nur 0 bis 12.
                // Das ist Absicht und genau der Grund, warum der Bogen nicht
                // veröffentlicht ist: `Levels::problems()` prüft den Bereich
                // nur für veröffentlichte Bögen, meldet hier also nichts, und
                // beim Umlegen des Schalters meldet es `level_range`. Lücken
                // ZWISCHEN den Stufen wären dagegen auch im Entwurf ein Fehler,
                // deshalb schließt 7 direkt an 6 an.
                'stufen' => [
                    [
                        'key' => 'ok',
                        'label' => 'Läuft',
                        'min' => 0,
                        'max' => 6,
                        'text' => 'Text fehlt noch.',
                    ],
                    [
                        'key' => 'luecken',
                        'label' => 'Lücken',
                        'min' => 7,
                        'max' => 12,
                        'text' => 'Text fehlt noch.',
                    ],
                ],
                // Keine. Ein Entwurf stand nie im Netz, also kann ihn niemand
                // ausgefüllt haben. Nebenbei ist das der Leerzustand der
                // Ergebnisliste, der sonst nirgends im Schauraum vorkäme.
                'antworten' => [],
            ],
        ];
    }
}
