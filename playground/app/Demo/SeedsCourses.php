<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Carbon;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Zwei Kurse, drei Lernende, drei Lagen.
 *
 * Ein Kurs, in dem alle gleich weit sind, zeigt nur, dass eine Zahl steht. Die
 * Aussage von statamic-courses ist, wer wo festhängt und warum eine Lektion zu
 * ist. Deshalb hier: eine, die über den Einstufungstest die erste Phase
 * übersprungen und den Kurs abgeschlossen hat, eine mitten drin mit einem
 * durchgefallenen Quiz, und einer, der vor drei Wochen angefangen und seitdem
 * nichts getan hat (der „hängt fest" auf der CP-Seite).
 *
 * Der zweite Kurs, `einsingen-leiten`, trägt seit 23.09.2026 die Bausteine aus
 * dem ThriveCart-Rundgang (K1 bis K6): eine Lektion als Baukasten (Text,
 * Hinweis, Spalten, FAQ, Download, Knopf), Drip am 15. des Monats, eine Woche 3
 * nur für die Gruppe `team`, Zahlungsausfall pausiert den Drip, fünf
 * Teamplätze und ein Paket. Dazu zwei Leute: eine Lernende, deren Drip nach
 * einer geplatzten Abbuchung pausiert ist, und eine Käuferin mit Team.
 * Im ersten Kurs hängt am Quiz jetzt ein echter Fragebogen (`cw-stimm-check`,
 * K4) mit Mindestpunktzahl, und die Lernende „mittendrin" ist einmal
 * durchgefallen.
 *
 * Zugang über `Entitlements::grant()`, Fortschritt über die Fassade des Addons,
 * nie per Einfügen: die Tabellen tragen Ereignisse und Sperren, die nur die
 * Fassade richtig schreibt. Wiederholbar: Einträge werden über den Slug
 * wiedergefunden, Zugänge über (Subjekt, Produkt, Quelle, Referenz).
 */
class SeedsCourses
{
    public const LERNENDE = [
        // [E-Mail, Name, Lage]
        ['ana@kurs.beispiel', 'Ana María Ñuñez', 'fertig'],
        ['baerbel@kurs.beispiel', 'Bärbel Öztürk-Weiß', 'mittendrin'],
        ['jean-luc@kurs.beispiel', 'Jean-Luc «Loup» Fabre', 'festgehangen'],
    ];

    /** K5 und K6: [E-Mail, Name]. */
    public const PAUSIERT = ['mo@kurs.beispiel', 'Mo Lindqvist'];

    public const TEAMKAEUFERIN = ['kasse@chor.beispiel', 'Chor Beispiel e. V.'];

    public const TEAMMITGLIED = 'chorleiterin@example.test';

    /** @return array<string, int> */
    public function run(): array
    {
        $kurse = $this->kurse();
        $lernende = $this->lernende();
        $lernende += $this->einsingenLeiten();

        return ['kurse' => $kurse['kurse'], 'lektionen' => $kurse['lektionen'], 'lernende' => $lernende];
    }

    /** @return array{kurse: int, lektionen: int} */
    public function kurse(): array
    {
        $grundlagen = $this->eintrag('courses', 'stimme-grundlagen', [
            'title' => 'Stimme zuerst: Grundlagen',
            'summary' => 'Atem, Stütze, Resonanz. Wer die Grundlagen schon kann, springt über den Einstufungstest in die zweite Phase.',
            'sequencing_mode' => 'none',
            'drip_mode' => 'none',
            'template' => 'course_demo',
        ]);

        $einsingen = $this->eintrag('courses', 'einsingen-leiten', [
            'title' => 'Einsingen leiten',
            'summary' => 'Ein Einsingen, das die Probe trägt. Lektion für Lektion, jeden Monat am 15. ein Schritt weiter.',
            'sequencing_mode' => 'lesson',
            // K2: Drip nach Kalender. `drip_after` an der Lektion zählt die
            // Monate nach der Einschreibung, geöffnet wird am 15.
            'drip_mode' => 'day_of_month',
            'drip_day_of_month' => 15,
            // K5: eine geplatzte Abbuchung hält den Drip an, statt den Kurs
            // zu schließen. Sobald die Zahlung durchgeht, läuft er weiter.
            'on_payment_failure' => 'pause_drip',
            // K6: fünf Plätze je Kauf, und der Kurs gehört zu einem Paket.
            // `product` ausdrücklich: Teamplätze hängen am Kauf, und ohne
            // Produktangabe kennt der Kurs als Kauf nur das Paket.
            'product' => 'einsingen-leiten',
            'team_seats' => 5,
            'bundles' => ['chorleitung-paket'],
            // K3: Woche 3 nur für das Team und für Kontakte mit dem Tag
            // „Chorleitung". Für alle anderen verschwinden diese Lektionen,
            // auch aus der Zählung.
            'section_audiences' => [[
                'section_key' => 'woche-3',
                'groups' => ['team'],
                'tags' => ['Chorleitung'],
            ]],
            'template' => 'course_demo',
        ]);

        $lektionen = [
            // Kurs 1, Phase 1 „Fundament"
            [$grundlagen, 'atem-und-haltung', 'Atem und Haltung', 'video', ['video_duration' => '08:00', 'est_minutes' => 8], 'fundament', 'Fundament', 1, 1, 'fundament', 1],
            [$grundlagen, 'stuetze-verstehen', 'Stütze verstehen', 'text', ['est_minutes' => 6], 'fundament', 'Fundament', 1, 2, 'fundament', 1],
            // K4: der Fragebogen aus statamic-assessments. Bestanden ab 13
            // Punkten oder mit einem der beiden oberen Ergebnisse.
            [$grundlagen, 'quiz-fundament', 'Quiz: Fundament', 'quiz', [
                'est_minutes' => 5,
                'assessment' => 'cw-stimm-check',
                'assessment_min_score' => 13,
                'assessment_pass_levels' => ['unterwegs', 'sicher'],
            ], 'fundament', 'Fundament', 1, 3, 'fundament', 1],
            // Kurs 1, Phase 2 „Klang"
            [$grundlagen, 'einstufung-klang', 'Einstufungstest: Fundament überspringen', 'quiz', ['is_test_out' => true, 'est_minutes' => 10], 'klang', 'Klang', 2, 1, 'klang', 2],
            [$grundlagen, 'resonanz-finden', 'Resonanz finden', 'video', ['video_duration' => '12:30', 'est_minutes' => 13], 'klang', 'Klang', 2, 2, 'klang', 2],
            [$grundlagen, 'erster-freier-ton', 'Meilenstein: der erste freie Ton', 'milestone', [], 'klang', 'Klang', 2, 3, 'klang', 2],
            // Kurs 2, Drip am 15. des Monats. `warum-einsingen` ist die
            // Lektion als Baukasten (K1).
            [$einsingen, 'warum-einsingen', 'Warum überhaupt einsingen', 'text', ['blocks' => $this->bausteine()], 'woche-1', 'Woche 1', 1, 1, null, null],
            [$einsingen, 'aufbau-einsingen', 'Aufbau eines Einsingens', 'text', ['drip_after' => 2], 'woche-1', 'Woche 1', 1, 2, null, null],
            [$einsingen, 'einsingen-mit-atem', 'Übung: Einsingen mit Atem beginnen', 'exercise', ['drip_after' => 1], 'woche-2', 'Woche 2', 2, 1, null, null],
            // K3: Woche 3 ist dem Team vorbehalten (Regel am Kurs), die
            // Lektion sagt es zusätzlich selbst.
            [$einsingen, 'einsingen-fuer-die-stimmgruppe', 'Einsingen für die eigene Stimmgruppe', 'text', ['audience_groups' => ['team']], 'woche-3', 'Woche 3', 3, 1, null, null],
        ];

        foreach ($lektionen as [$kurs, $slug, $titel, $typ, $extra, $sektion, $sektionTitel, $sektionOrder, $sort, $phase, $phaseOrder]) {
            $this->eintrag('course_lessons', $slug, array_filter([
                'title' => $titel,
                'course' => $kurs,
                'item_type' => $typ,
                'section_key' => $sektion,
                'section_title' => $sektionTitel,
                'section_order' => $sektionOrder,
                'sort_order' => $sort,
                'phase_key' => $phase,
                'phase_title' => $phase ? ucfirst($phase) : null,
                'phase_order' => $phaseOrder,
                'content' => 'Lektionstext für „'.$titel.'".',
                ...$extra,
            ], fn ($wert) => $wert !== null));
        }

        return ['kurse' => 2, 'lektionen' => count($lektionen)];
    }

    /**
     * K1: die Lektion als Baukasten, jeder mitgelieferte Baustein einmal.
     *
     * Kein Video: die Vorgabe will eine echte Aufnahme, und eine fremde
     * Platzhalter-URL zeigte „unavailable". Der Baustein kommt dazu, sobald es
     * eine gibt. Der Download ist öffentlich (`assets`); der private Weg braucht
     * statamic-private-media, das die Demo noch nicht fährt.
     *
     * @return list<array<string, mixed>>
     */
    protected function bausteine(): array
    {
        return [
            ['id' => 'k1text', 'type' => 'text', 'enabled' => true,
                'text' => 'Einsingen ist kein Aufwärmen der Stimmbänder, sondern das Einstellen des Instruments auf den Raum und die Gruppe.'],
            ['id' => 'k1hinweis', 'type' => 'callout', 'enabled' => true, 'tone' => 'tip',
                'heading' => 'Vor dem Start', 'text' => 'Ein Glas Wasser bereitstellen und stehend üben.'],
            ['id' => 'k1spalten', 'type' => 'columns', 'enabled' => true, 'columns' => [
                ['id' => 'k1sp1', 'text' => "**Körper**\n\nStand, Atem, Lockerung."],
                ['id' => 'k1sp2', 'text' => "**Klang**\n\nResonanz, Vokale, Register."],
            ]],
            ['id' => 'k1download', 'type' => 'download', 'enabled' => true, 'private' => false,
                'file' => ['kurs/einsingen-ablauf.pdf'], 'label' => 'Ablauf zum Ausdrucken'],
            ['id' => 'k1faq', 'type' => 'faq', 'enabled' => true, 'items' => [
                ['id' => 'k1f1', 'question' => 'Wann öffnet die nächste Lektion?',
                    'answer' => 'Am 15. des Monats: die zweite Übung einen Monat nach der Einschreibung, der Aufbau zwei Monate danach.'],
                ['id' => 'k1f2', 'question' => 'Was passiert, wenn eine Abbuchung nicht klappt?',
                    'answer' => 'Der Kurs bleibt offen, nur neue Lektionen kommen nicht dazu. Sobald die Zahlung durchgeht, geht es weiter.'],
            ]],
            ['id' => 'k1knopf', 'type' => 'button', 'enabled' => true, 'label' => 'Zur nächsten Lektion',
                'link' => '/courses/einsingen-leiten/aufbau-einsingen', 'style' => 'primary'],
        ];
    }

    public function lernende(): int
    {
        $kurs = 'stimme-grundlagen';
        $marke = Brand::query()->findOrFail(app('brand-context')->currentId());

        foreach (self::LERNENDE as $i => [$adresse, $name, $lage]) {
            $nutzer = User::findByEmail($adresse);

            if (! $nutzer) {
                $nutzer = User::make()->email($adresse);
                $nutzer->password('demo-local-password');
            }

            $nutzer->set('name', $name);
            $nutzer->save();

            // In der Marke, die das Frontend ohne Zuordnung nimmt. Ohne runFor
            // hat die Konsole keine aktuelle Marke, der Markenfilter schlaegt
            // zu, und der zweite Lauf findet den eigenen Zugang nicht wieder.
            BrandContext::runFor($marke, fn () => Entitlements::grant(
                subject: new SubjectReference('user', (string) $nutzer->id()),
                productSlug: $kurs,
                source: 'manual',
                sourceRef: 'demo_kurs_'.($i + 1),
                startsAt: Carbon::now()->subDays(45),
                meta: ['demo' => true],
            ));

            match ($lage) {
                'fertig' => $this->fertig($nutzer, $kurs),
                'mittendrin' => $this->mittendrin($nutzer, $kurs),
                'festgehangen' => $this->festgehangen($nutzer, $kurs),
            };
        }

        return count(self::LERNENDE);
    }

    /** Einstufungstest bestanden, Phase 1 übersprungen, Rest fertig. */
    protected function fertig(mixed $nutzer, string $kurs): void
    {
        Courses::enroll($nutzer, $kurs);
        Courses::completeLesson($nutzer, $kurs, 'einstufung-klang', 'quiz', ['best_score' => 92]);
        Courses::updateLessonProgress($nutzer, $kurs, 'resonanz-finden', ['watched_seconds' => 750, 'resume_seconds' => 750]);
        Courses::acknowledgeLesson($nutzer, $kurs, 'erster-freier-ton');
    }

    /** Video gesehen, Text gelesen, Quiz einmal nicht bestanden, Phase 2 noch zu. */
    protected function mittendrin(mixed $nutzer, string $kurs): void
    {
        Courses::enroll($nutzer, $kurs);
        Courses::updateLessonProgress($nutzer, $kurs, 'atem-und-haltung', ['watched_seconds' => 480, 'resume_seconds' => 480]);
        Courses::acknowledgeLesson($nutzer, $kurs, 'stuetze-verstehen');
        // K4: 9 von nötigen 13 Punkten, so wie statamic-assessments es meldet.
        Courses::updateLessonItem($nutzer, $kurs, 'quiz-fundament', [
            'assessment' => 'cw-stimm-check', 'score' => 9, 'attempts' => 1, 'passed' => false,
        ]);
    }

    /**
     * K5 und K6 im Kurs `einsingen-leiten`.
     *
     * Die Zugänge im selben Markenkontext wie die der übrigen Lernenden: in der
     * Marke, die das Frontend unter `/courses/…` nimmt. Sonst findet der
     * zweite Lauf den eigenen Zugang nicht, und das Team hängt an nichts.
     */
    public function einsingenLeiten(): int
    {
        $kurs = 'einsingen-leiten';
        $marke = Brand::query()->findOrFail(app('brand-context')->currentId());

        // K5: eingeschrieben, dann ist eine Abbuchung geplatzt.
        [$adresse, $name] = self::PAUSIERT;
        $pausiert = $this->konto($adresse, $name);
        $this->zugang($marke, $pausiert, $kurs, 'demo_kurs_k5');
        Courses::enroll($pausiert, $kurs);
        Courses::pauseDrip($pausiert, $kurs, 'payment_failed');

        // K6: hat das Paket gekauft, bekommt damit den Kurs samt fünf
        // Plätzen, und hat eine Chorleiterin ins Team geholt.
        [$adresse, $name] = self::TEAMKAEUFERIN;
        $kaeuferin = $this->konto($adresse, $name);
        $this->zugang($marke, $kaeuferin, 'chorleitung-paket', 'demo_kurs_k6');
        Courses::enroll($kaeuferin, $kurs);

        // In der Marke: ob die Käuferin den Kauf hält, fragt entitlements, und
        // dessen Markenfilter schließt auf der Konsole ohne aktuelle Marke.
        // Ohne runFor meldet addTeamMember() still null. `add()` ist
        // wiederholbar, eine vorhandene Adresse kommt unverändert zurück.
        BrandContext::runFor($marke, fn () => Courses::addTeamMember($kaeuferin, $kurs, self::TEAMMITGLIED));

        return 2;
    }

    protected function konto(string $adresse, string $name): mixed
    {
        $nutzer = User::findByEmail($adresse);

        if (! $nutzer) {
            $nutzer = User::make()->email($adresse);
            $nutzer->password('demo-local-password');
        }

        $nutzer->set('name', $name);
        $nutzer->save();

        return $nutzer;
    }

    protected function zugang(Brand $marke, mixed $nutzer, string $kurs, string $referenz): void
    {
        BrandContext::runFor($marke, fn () => Entitlements::grant(
            subject: new SubjectReference('user', (string) $nutzer->id()),
            productSlug: $kurs,
            source: 'manual',
            sourceRef: $referenz,
            startsAt: Carbon::now()->subDays(40),
            meta: ['demo' => true],
        ));
    }

    /** Vor drei Wochen angefangen, seitdem nichts. */
    protected function festgehangen(mixed $nutzer, string $kurs): void
    {
        Carbon::setTestNow(Carbon::now()->subDays(21));

        try {
            Courses::enroll($nutzer, $kurs);
            Courses::updateLessonProgress($nutzer, $kurs, 'atem-und-haltung', ['watched_seconds' => 140, 'resume_seconds' => 140]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @param array<string, mixed> $daten */
    protected function eintrag(string $sammlung, string $slug, array $daten): string
    {
        $eintrag = Entry::query()->where('collection', $sammlung)->where('slug', $slug)->first()
            ?? Entry::make()->collection($sammlung)->slug($slug);

        $eintrag->data($daten)->published(true)->save();

        return (string) $eintrag->id();
    }
}
