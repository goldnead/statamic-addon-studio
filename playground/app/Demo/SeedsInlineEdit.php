<?php

namespace App\Demo;

use Statamic\Facades\Entry;

/**
 * The entry behind https://demo.adriangoldner.dev/inline-edit.
 *
 * One page that shows every kind of field statamic-inline-edit can edit on the
 * page, in English. The template is `resources/views/inline_edit_demo.antlers.html`
 * and each seeded value below is one example there; the blueprint is
 * `resources/blueprints/collections/pages/inline_edit_demo.yaml`.
 *
 * Written through the Entry facade, with a fixed id, so a second run overwrites
 * the page instead of adding another. Grid rows carry fixed ids too: the
 * add-on names a row by its id, and a re-seed must not hand the page new ones.
 */
class SeedsInlineEdit
{
    public const ID = '3f7c9d21-4b88-41ae-9f52-6c1d0a77e100';

    /** @return array<string, int> */
    public function run(): array
    {
        $entry = Entry::find(self::ID) ?? Entry::make()->collection('pages')->id(self::ID);

        $entry
            ->slug('inline-edit')
            ->published(true)
            ->data([
                'blueprint' => 'inline_edit_demo',
                'template' => 'inline_edit_demo',
                'title' => 'Voice first',
                // Left empty on purpose: the empty state gets a box in edit mode.
                'kicker' => '',
                'intro' => "In a choir the work is musical.\nVocally, most of it stays open.",
                'note' => 'A textarea without a real line break in its value. It renders exactly as a visitor sees it and does not change when you switch editing on.',
                'body' => implode("\n", [
                    '## What happens in a choir',
                    '',
                    'The work is musical. Vocally, **most of it stays open**.',
                    '',
                    '- The pitch is right',
                    '- The text is right',
                    '- The sound does not carry',
                ]),
                'seats' => 12,
                'availability' => 'waitlist',
                'starts_on' => '2026-10-04',
                'promoted' => true,
                'hero_image' => 'marken/chorwerkstatt/probenwoche-01.jpg',
                'lines' => [
                    ['id' => 'price-label', 'type' => 'row', 'text' => 'Single lesson', 'value' => ''],
                    ['id' => 'price-value', 'type' => 'row', 'text' => 'EUR 60 for 45 minutes', 'value' => ''],
                    ['id' => 'button', 'type' => 'row', 'text' => 'Book a lesson', 'value' => ''],
                    ['id' => 'lead', 'type' => 'row', 'text' => 'Sing with the *whole* body', 'value' => ''],
                    ['id' => 'headline-1', 'type' => 'row', 'text' => 'Technique is a stage,', 'value' => ''],
                    ['id' => 'headline-2', 'type' => 'row', 'text' => 'not the *goal*.', 'value' => ''],
                    ['id' => 'pull-quote', 'type' => 'row', 'text' => 'Warm-ups that finally make sense', 'value' => ''],
                    ['id' => 'room', 'type' => 'row', 'text' => '', 'value' => '/assets/marken/lindhorst/raum-01.jpg'],
                    ['id' => 'room-alt', 'type' => 'row', 'text' => '', 'value' => 'The practice room in the morning light'],
                ],
                'testimonial_quote' => 'I finally hear what my voice does in the choir.',
                'testimonial_author' => 'Mira, alto',
                'article' => $this->article(),
                'tags' => ['choir', 'voice training', 'CVT'],
                'faq' => [
                    [
                        'id' => 'faq-set-1',
                        'type' => 'question',
                        'enabled' => true,
                        'q' => 'Do I need to read music?',
                        'a' => 'No. We work with what you hear and what you feel.',
                    ],
                    [
                        'id' => 'faq-set-2',
                        'type' => 'question',
                        'enabled' => true,
                        'q' => 'Can I join in the middle of the term?',
                        'a' => 'Yes, as long as there is a seat on the list.',
                    ],
                ],
            ])
            ->save();

        return ['inline-edit demo page' => 1];
    }

    /**
     * The Bard value as a `save_html: true` field stores it.
     */
    private function article(): string
    {
        return '<h2>Why the voice comes first</h2>'
            .'<p>Technique is a stage, not a goal. Once you have understood your own voice, you suddenly hear in the choir where the problem is.</p>'
            .'<ul><li><p>Hear it</p></li><li><p>Name it</p></li><li><p>Change it</p></li></ul>';
    }
}
