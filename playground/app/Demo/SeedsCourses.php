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
 * Der zweite Kurs hat mit Absicht niemanden: Sperre Lektion für Lektion und
 * Drip nach Wochen, aber keine Lernenden, damit die Zeile mit Nullen zu sehen ist.
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

    /** @return array<string, int> */
    public function run(): array
    {
        $kurse = $this->kurse();
        $lernende = $this->lernende();

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
            'summary' => 'Ein Einsingen, das die Probe trägt. Lektion für Lektion, eine Woche nach der anderen.',
            'sequencing_mode' => 'lesson',
            'drip_mode' => 'schedule',
            'template' => 'course_demo',
        ]);

        $lektionen = [
            // Kurs 1, Phase 1 „Fundament"
            [$grundlagen, 'atem-und-haltung', 'Atem und Haltung', 'video', ['video_duration' => '08:00', 'est_minutes' => 8], 'fundament', 'Fundament', 1, 1, 'fundament', 1],
            [$grundlagen, 'stuetze-verstehen', 'Stütze verstehen', 'text', ['est_minutes' => 6], 'fundament', 'Fundament', 1, 2, 'fundament', 1],
            [$grundlagen, 'quiz-fundament', 'Quiz: Fundament', 'quiz', ['est_minutes' => 5], 'fundament', 'Fundament', 1, 3, 'fundament', 1],
            // Kurs 1, Phase 2 „Klang"
            [$grundlagen, 'einstufung-klang', 'Einstufungstest: Fundament überspringen', 'quiz', ['is_test_out' => true, 'est_minutes' => 10], 'klang', 'Klang', 2, 1, 'klang', 2],
            [$grundlagen, 'resonanz-finden', 'Resonanz finden', 'video', ['video_duration' => '12:30', 'est_minutes' => 13], 'klang', 'Klang', 2, 2, 'klang', 2],
            [$grundlagen, 'erster-freier-ton', 'Meilenstein: der erste freie Ton', 'milestone', [], 'klang', 'Klang', 2, 3, 'klang', 2],
            // Kurs 2, Drip nach Wochen
            [$einsingen, 'warum-einsingen', 'Warum überhaupt einsingen', 'video', ['video_duration' => '05:00', 'week' => 1], 'woche-1', 'Woche 1', 1, 1, null, null],
            [$einsingen, 'aufbau-einsingen', 'Aufbau eines Einsingens', 'text', ['week' => 1], 'woche-1', 'Woche 1', 1, 2, null, null],
            [$einsingen, 'einsingen-mit-atem', 'Übung: Einsingen mit Atem beginnen', 'exercise', ['week' => 2], 'woche-2', 'Woche 2', 2, 1, null, null],
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
        Courses::updateLessonItem($nutzer, $kurs, 'quiz-fundament', ['attempts' => 1, 'best_score' => 40]);
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
