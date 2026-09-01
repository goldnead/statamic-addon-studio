<?php

namespace App\Demo;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Support\Countdown;
use Goldnead\StatamicFunnels\Support\Split;
use Illuminate\Support\Carbon;

/**
 * One path per client, and one that is deliberately broken.
 *
 * The real ones exist so the demo has something to walk. The broken one
 * exists because a funnel is a graph somebody draws by hand, and the shapes
 * they draw by accident — a step nothing leads to, a loop, a branch that stops,
 * a funnel with no way in — are the ones that decide whether the editor and the
 * front end are honest or merely lucky.
 */
class SeedsFunnels
{
    /** @return array<string, int> */
    public function run(): array
    {
        $this->chorwerkstatt();
        $this->halbmond();
        $this->lindhorst();
        $this->kaputt();
        $this->besuche();

        return [
            'funnels' => Funnel::count(),
            'funnel_besuche' => FunnelStepEvent::count(),
        ];
    }

    /**
     * The full one: a freebie, a form, an offer with a deadline and a split
     * test, and both ways out of it.
     */
    protected function chorwerkstatt(): void
    {
        $funnel = $this->funnel('fruehlingskurs', 'Frühlingskurs', true);

        $this->schritte($funnel, [
            // Every telling step points at an entry of the funnel_pages
            // collection: the entry IS the page, with its own text and its
            // own button. That is the pattern the addon exists for — the
            // funnel carries the walk, the entry carries the words.
            ['entry_1', 'entry', null, 'Willkommen', [
                'entry' => 'e71b5ae4-897e-4729-b85e-d9c54a8be4d4',
            ]],
            ['capture_1', 'capture', 'anmeldung', 'Anmeldung', [
                'entry' => '6b750ef4-04d3-4306-b681-ff62bf5000f2',
            ]],
            ['offer_1', 'offer', 'angebot', 'Angebot', [
                'template' => 'funnel/chorwerkstatt',
                'offer' => 'cw-kurs-angebot',
                'headline' => 'Nur jetzt: der Frühlingskurs',
                'body' => 'Vier Abende, alle Aufnahmen, unbefristet.',
                // A window per visitor, so the demo has a running clock in it
                // whenever somebody opens it.
                'countdown' => Countdown::ROLLING,
                'countdown_hours' => 48,
                // And a split test on the same step, because that is the
                // combination nobody tries until it is live.
                'split_share' => 50,
                'variant_headline' => 'Letzte Chance auf den Frühlingskurs',
                'variant_body' => 'Vier Abende. Danach ist er weg bis zum Herbst.',
            ]],
            ['upsell_1', 'offer', 'dazu', 'Noch etwas dazu', [
                'template' => 'funnel/chorwerkstatt',
                'offer' => 'cw-upsell',
                'headline' => 'Die Aufnahmen aller vier Abende',
                'body' => 'Einmal zahlen, für immer behalten.',
            ]],
            ['danke_1', 'finish', 'danke', 'Danke', [
                'entry' => '3e57b67a-c36e-439c-bec0-8a4e47a5bc20',
            ]],
            ['auch-gut_1', 'page', 'auch-gut', 'Auch gut', [
                'entry' => 'bf38770e-1c78-4ba5-aa41-a4c332e82628',
            ]],
        ]);

        $this->kanten($funnel, [
            ['entry_1', 'capture_1', 'default'],
            // A capture advances on `default`, not `submitted`: the form's
            // validation is Statamic's, and from the walk's view submitting
            // the form IS the default way out of the step. Found by walking
            // the demo end to end — with `submitted` the walk found no edge,
            // marked the visit complete and silently went back to the start.
            ['capture_1', 'offer_1', 'default'],
            ['offer_1', 'upsell_1', 'accepted'],
            ['offer_1', 'auch-gut_1', 'declined'],
            ['upsell_1', 'danke_1', 'accepted'],
            ['upsell_1', 'danke_1', 'declined'],
            ['auch-gut_1', 'danke_1', 'default'],
        ]);

        // The membership year the pricing page sells, as its own short path.
        // One payment, like every funnel checkout: the membership is priced
        // by choir year, not by month.
        $funnel = $this->funnel('mitgliedschaft', 'Mitgliedschaft', true);

        // The telling steps are entries again; only the checkout names the
        // Kasse template; the shipped `statamic-funnels::step` renders naked,
        // without even an html skeleton, and a checkout that looks like a
        // foreign site reads as a foreign site.
        $this->schritte($funnel, [
            ['entry_1', 'entry', null, 'Mitgliedschaft', [
                'entry' => 'd28a90c7-64fb-4378-abab-84f06233b60b',
            ]],
            ['offer_1', 'offer', 'beitreten', 'Beitritt', [
                'template' => 'funnel/chorwerkstatt',
                'offer' => 'cw-mitgliedschaft-angebot',
                'headline' => 'Mitgliedschaft Chor, ein Jahr',
                'body' => 'Achtzehnhundert Euro, einmalig für ein Chorjahr.',
            ]],
            ['danke_1', 'finish', 'danke', 'Danke', [
                'entry' => '0a1f7e5e-d86d-4a0f-91ee-9bf68b7af0eb',
            ]],
        ]);

        $this->kanten($funnel, [
            ['entry_1', 'offer_1', 'default'],
            ['offer_1', 'danke_1', 'accepted'],
        ]);
    }

    /** A short one with a fixed deadline that is still ahead. */
    protected function halbmond(): void
    {
        $funnel = $this->funnel('vinyl', 'Die Platte', true);

        // Both buy paths of the band wear the band's clothes: dark ground,
        // poster type, the red moon. The telling steps as entries of the
        // band, the checkout as the band's Kasse.
        $this->schritte($funnel, [
            ['entry_1', 'entry', null, 'Die Platte', [
                'entry' => '03c15d34-d804-42dc-a2fb-3c64fec0942a',
            ]],
            ['offer_1', 'offer', 'bestellen', 'Bestellen', [
                'template' => 'funnel/halbmond',
                'offer' => 'hm-vinyl-angebot',
                'headline' => 'Die Platte, signiert',
                'body' => 'Solange sie da ist.',
                // A deadline everybody shares, still ahead: the plate ships on
                // 10 March 2027, so pre-orders close while there is still time
                // to press and number the run. The brand site's only buy
                // button ends here, and that has to be a live path.
                'countdown' => Countdown::FIXED,
                'countdown_until' => '2027-01-31 23:59:59',
            ]],
            ['danke_1', 'finish', 'danke', 'Danke', [
                'entry' => '6d257f79-c572-4e87-8317-f3ae69a1befa',
            ]],
        ]);

        $this->kanten($funnel, [
            ['entry_1', 'offer_1', 'default'],
            ['offer_1', 'danke_1', 'accepted'],
        ]);

        // The fanclub year the fan page sells. One payment: the checkout the
        // funnels have is a one-off checkout, so the site prices the club by
        // year, not by month, and the checkout repeats exactly that.
        $funnel = $this->funnel('fanclub', 'Fanclub', true);

        $this->schritte($funnel, [
            ['entry_1', 'entry', null, 'Fanclub', [
                'entry' => 'be842758-5c8a-4f88-83d3-5da83182f55f',
            ]],
            ['offer_1', 'offer', 'mitmachen', 'Mitmachen', [
                'template' => 'funnel/halbmond',
                'offer' => 'hm-fanclub-angebot',
                'headline' => 'Stufe Vollmond, ein Jahr',
                'body' => 'Hundertacht Euro für ein Jahr Fanclub.',
            ]],
            ['danke_1', 'finish', 'danke', 'Danke', [
                'entry' => '87aabd68-b9f1-416c-9a2e-ac211b6ee958',
            ]],
        ]);

        $this->kanten($funnel, [
            ['entry_1', 'offer_1', 'default'],
            ['offer_1', 'danke_1', 'accepted'],
        ]);
    }

    /** A draft, so the demo has something to preview before it goes live. */
    protected function lindhorst(): void
    {
        $funnel = $this->funnel('erstgespraech', 'Erstgespräch', false);

        $this->schritte($funnel, [
            ['entry_1', 'entry', null, 'Erstgespräch', [
                'headline' => 'Zwanzig Minuten, unverbindlich',
                'body' => 'Wir schauen, ob es passt. Mehr nicht.',
            ]],
            ['capture_1', 'capture', 'termin', 'Termin', [
                'headline' => 'Wann passt es dir?',
            ]],
            ['danke_1', 'finish', 'danke', 'Danke', [
                'headline' => 'Bis dann.',
                'redirect' => 'https://lindhorst.beispiel/danke',
            ]],
        ]);

        $this->kanten($funnel, [
            ['entry_1', 'capture_1', 'default'],
            // Same trap as above, caught before it could ship twice: a
            // capture leaves on `default`. One toggle in the CP and this
            // draft is live — it should not go live carrying the bug.
            ['capture_1', 'danke_1', 'default'],
        ]);

        // The five-session card the pricing page sells, live.
        $funnel = $this->funnel('fuenferkarte', 'Fünferkarte', true);

        // The practice's card, in the practice's clothes: one column, plenty
        // of air, sage buttons. The telling step as an entry, the checkout as
        // the practice's Kasse.
        $this->schritte($funnel, [
            ['entry_1', 'entry', null, 'Fünferkarte', [
                'entry' => '77077ebf-2bf8-4db7-a36d-e2425f39d00e',
            ]],
            ['offer_1', 'offer', 'kaufen', 'Kaufen', [
                'template' => 'funnel/lindhorst',
                'offer' => 'lh-karte-angebot',
                'headline' => 'Die Fünferkarte',
                'body' => 'Siebenhundert Euro, fünf Sitzungen.',
            ]],
            ['danke_1', 'finish', 'danke', 'Danke', [
                'entry' => '0fcb7d59-4e00-42cb-b529-519b97c3570b',
            ]],
        ]);

        $this->kanten($funnel, [
            ['entry_1', 'offer_1', 'default'],
            ['offer_1', 'danke_1', 'accepted'],
        ]);
    }

    /**
     * Every shape somebody draws by accident, in one graph.
     *
     * Not published, so nobody can walk into it — but the editor has to render
     * it, the stepper has to order it, and the statistics have to divide by it
     * without falling over.
     */
    protected function kaputt(): void
    {
        $funnel = $this->funnel('sackgassen', 'Sackgassen und Schleifen', false);

        $this->schritte($funnel, [
            ['entry_1', 'entry', null, 'Start', ['headline' => 'Start']],
            // Leads nowhere: the branch that simply stops.
            ['sackgasse_1', 'page', 'sackgasse', 'Sackgasse', ['headline' => 'Hier hört es auf']],
            // Nothing leads here: the orphan the preview exists to reveal.
            ['waise_1', 'page', 'waise', 'Waise', ['headline' => 'Wie kommt man hierher?']],
            // Points back at itself, twice over.
            ['schleife_a', 'page', 'schleife-a', 'Schleife A', ['headline' => 'A']],
            ['schleife_b', 'page', 'schleife-b', 'Schleife B', ['headline' => 'B']],
            // Switched off, and in the middle of the path.
            ['aus_1', 'page', 'abgeschaltet', 'Abgeschaltet', ['headline' => 'Aus']],
            // An offer that cannot be sold.
            ['leer_1', 'offer', 'ins-leere', 'Zeigt ins Leere', ['offer' => 'zeigt-ins-leere']],
            // A step whose slug looks like one of this addon's own paths.
            ['_preview_1', 'page', '_preview', 'Heißt wie ein Pfad', ['headline' => 'Slug wie ein Addon-Pfad']],
            // A label longer than any column budgeted for.
            ['lang_1', 'page', 'lang', DemoData::tooLong(220), ['headline' => DemoData::tooLong(220)]],
        ]);

        $this->kanten($funnel, [
            ['entry_1', 'sackgasse_1', 'default'],
            ['entry_1', 'schleife_a', 'default'],
            ['schleife_a', 'schleife_b', 'default'],
            ['schleife_b', 'schleife_a', 'default'],
            ['entry_1', 'aus_1', 'default'],
            ['aus_1', 'leer_1', 'default'],
            ['leer_1', '_preview_1', 'accepted'],
            ['_preview_1', 'lang_1', 'default'],
            // An edge to a step that is not in this funnel. The writer drops
            // it; if it ever lands, the canvas draws a line off the map.
            ['entry_1', 'gibt_es_nicht', 'default'],
        ]);

        $funnel->steps()->where('node_key', 'aus_1')->update(['disabled' => true]);
    }

    protected function funnel(string $handle, string $titel, bool $live): Funnel
    {
        return Funnel::updateOrCreate(
            ['handle' => $handle],
            ['title' => $titel, 'published' => $live],
        );
    }

    /** @param list<array{0:string,1:string,2:?string,3:string,4:array<string,mixed>}> $schritte */
    protected function schritte(Funnel $funnel, array $schritte): void
    {
        $behalten = array_column($schritte, 0);

        // Sweep first, insert second. A slug is unique within a funnel, so a
        // step left over from an older shape holds the slug the new one wants
        // and the insert fails on a constraint that is doing its job. Found by
        // running the seeder a second time, which is the point of running it a
        // second time.
        $funnel->steps()->whereNotIn('node_key', $behalten)->delete();

        foreach ($schritte as [$key, $typ, $slug, $label, $config]) {
            $funnel->steps()->updateOrCreate(
                ['node_key' => $key],
                ['type' => $typ, 'slug' => $slug, 'label' => $label, 'config' => $config, 'disabled' => false],
            );
        }
    }

    /** @param list<array{0:string,1:string,2:string}> $kanten */
    protected function kanten(Funnel $funnel, array $kanten): void
    {
        $funnel->edges()->delete();

        $vorhanden = $funnel->steps()->pluck('node_key')->all();

        foreach ($kanten as [$von, $nach, $ausgang]) {
            // An edge to a step that is not there would be a path leading off
            // the map. Dropped here, exactly as the real writer does.
            if (! in_array($von, $vorhanden, true) || ! in_array($nach, $vorhanden, true)) {
                continue;
            }

            $funnel->edges()->create([
                'from_node_key' => $von,
                'to_node_key' => $nach,
                'from_output' => $ausgang,
            ]);
        }
    }

    /**
     * A history to divide by.
     *
     * Numbers on a card only mean something against a spread, so the walks are
     * shaped like a real funnel: most people leave at the first step, a few buy,
     * and the split test has a winner that is not obvious from three rows.
     */
    protected function besuche(): void
    {
        $funnel = Funnel::where('handle', 'fruehlingskurs')->first();

        if (! $funnel) {
            return;
        }

        $funnel->visits()->delete();

        $wege = [
            // [wie oft, Schritte, Variante]
            [46, ['entry_1'], Split::A],
            [38, ['entry_1'], Split::B],
            [19, ['entry_1', 'capture_1'], Split::A],
            [22, ['entry_1', 'capture_1'], Split::B],
            [11, ['entry_1', 'capture_1', 'offer_1'], Split::A],
            [16, ['entry_1', 'capture_1', 'offer_1'], Split::B],
            [3, ['entry_1', 'capture_1', 'offer_1', 'auch-gut_1', 'danke_1'], Split::A],
            [4, ['entry_1', 'capture_1', 'offer_1', 'auch-gut_1', 'danke_1'], Split::B],
            [2, ['entry_1', 'capture_1', 'offer_1', 'upsell_1', 'danke_1'], Split::A],
            [6, ['entry_1', 'capture_1', 'offer_1', 'upsell_1', 'danke_1'], Split::B],
        ];

        $nummer = 0;

        foreach ($wege as [$wie_oft, $schritte, $variante]) {
            for ($i = 0; $i < $wie_oft; $i++) {
                $nummer++;
                $besuch = $funnel->visits()->create([
                    'token' => 'demo-'.str_pad((string) $nummer, 4, '0', STR_PAD_LEFT).'-'.str_repeat('x', 20),
                    'current_node_key' => end($schritte),
                    'created_at' => Carbon::now()->subDays(30)->addHours($nummer),
                ]);

                foreach ($schritte as $stelle => $key) {
                    FunnelStepEvent::create([
                        'visit_id' => $besuch->id,
                        'node_key' => $key,
                        'event' => FunnelStepEvent::ENTERED,
                        'payload' => $key === 'offer_1' ? ['variant' => $variante] : null,
                        'created_at' => Carbon::now()->subDays(30)->addHours($nummer)->addMinutes($stelle * 2),
                    ]);
                }
            }
        }
    }
}
