<?php

namespace App\Demo;

use Goldnead\Activity\Models\Activity;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Models\MailingListRecord;
use Goldnead\Marketing\Models\Subscription as MarketingSubscription;
use Goldnead\Notifications\Models\NotificationItem;
use Goldnead\Suppression\Models\Suppression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The people, and everything that can be true about one.
 *
 * Everything here is brand-scoped, which is the reason for the shape of this
 * seeder: the same address exists under two brands, in two cases, with two
 * different states. If scoping is real, each brand sees its own; if a query
 * somewhere forgets to scope, this is what makes it obvious.
 */
class SeedsCrm
{
    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken): array
    {
        $this->listen($marken);
        $kontakte = $this->kontakte($marken);
        $this->abonnenten($marken);
        $this->sperrliste($marken);
        $this->freebies($marken);
        $this->zugaenge($marken);
        $this->meldungen($marken);
        $this->spuren($marken);

        return [
            'kontakte' => Contact::withoutGlobalScopes()->count(),
            'abonnenten' => MarketingSubscription::withoutGlobalScopes()->count(),
            'gesperrt' => Suppression::withoutGlobalScopes()->count(),
            'freebies' => Resource::withoutGlobalScopes()->count(),
            'zugaenge' => Entitlement::withoutGlobalScopes()->count(),
            'meldungen' => NotificationItem::withoutGlobalScopes()->count(),
            'spuren' => Activity::withoutGlobalScopes()->count(),
            '_kontakte' => $kontakte,
        ];
    }

    /** @param array<string, Brand> $marken */
    protected function listen(array $marken): void
    {
        $definitionen = [
            'chorwerkstatt' => [
                ['chorbrief', 'Der Chorbrief', 'Alle zwei Wochen, mit einer Übung zum Mitnehmen.', true],
                ['kursinfos', 'Kursinfos', 'Nur wenn ein Kurs startet.', false],
            ],
            'halbmond' => [
                ['tourmail', 'Tourmail', 'Wo wir spielen, und wann Karten weggehen.', true],
            ],
            'lindhorst' => [
                ['praxisbrief', 'Praxisbrief', 'Einmal im Monat, ruhig.', true],
            ],
        ];

        foreach ($definitionen as $marke => $eintraege) {
            foreach ($eintraege as [$handle, $name, $beschreibung, $doi]) {
                MailingListRecord::withoutGlobalScopes()->updateOrCreate(
                    ['handle' => $handle, 'brand_id' => $marken[$marke]->id],
                    ['name' => $name, 'description' => $beschreibung, 'double_opt_in' => $doi],
                );
            }
        }
    }

    /**
     * @param  array<string, Brand>  $marken
     * @return array<int, Contact>
     */
    protected function kontakte(array $marken): array
    {
        $namen = DemoData::AWKWARD_NAMES;
        $adressen = DemoData::AWKWARD_EMAILS;
        $kontakte = [];

        $zeilen = [
            // [Marke, Name-Index, Adress-Index, Status, Besonderheit]
            ['chorwerkstatt', 0, 0, 'customer', null],
            ['chorwerkstatt', 1, 1, 'lead', null],
            ['chorwerkstatt', 2, 2, 'lead', 'lange_adresse'],
            ['chorwerkstatt', 3, 3, 'customer', null],
            ['chorwerkstatt', 8, 6, 'lead', 'gesperrt'],
            ['halbmond', 4, 4, 'customer', 'doppelt'],
            ['halbmond', 5, 5, 'lead', 'doppelt_andere_schreibweise'],
            ['halbmond', 6, 7, 'lead', 'ohne_namen'],
            ['lindhorst', 7, 0, 'customer', 'gleiche_adresse_andere_marke'],
            ['lindhorst', 9, 3, 'lead', 'nur_ein_zeichen'],
        ];

        foreach ($zeilen as $i => [$marke, $n, $e, $status, $note]) {
            $name = $namen[$n];
            $teile = explode(' ', $name, 2);

            $kontakte[] = Contact::withoutGlobalScopes()->updateOrCreate(
                ['email_normalized' => mb_strtolower(trim($adressen[$e])), 'brand_id' => $marken[$marke]->id],
                [
                    'uuid' => (string) Str::uuid(),
                    'email' => $adressen[$e],
                    'first_name' => $note === 'ohne_namen' ? null : $teile[0],
                    'last_name' => $note === 'ohne_namen' ? null : ($teile[1] ?? null),
                    'full_name' => $note === 'ohne_namen' ? null : $name,
                    'status' => $status,
                    'source' => $note ?? 'demo',
                    'consent' => $note !== 'gesperrt',
                    'consent_at' => $note !== 'gesperrt' ? Carbon::now()->subDays(30 - $i) : null,
                    'do_not_contact' => $note === 'gesperrt',
                    'engagement_score' => [0, 5, 42, 99, 100][$i % 5],
                    'last_activity_at' => Carbon::now()->subDays($i * 3),
                    'created_at' => Carbon::now()->subDays(60 - $i * 4),
                ],
            );
        }

        return $kontakte;
    }

    /** @param array<string, Brand> $marken */
    protected function abonnenten(array $marken): void
    {
        $zeilen = [
            ['chorwerkstatt', 'chorbrief', 'bärbel.öztürk@beispiel.de', 'confirmed'],
            ['chorwerkstatt', 'chorbrief', 'plus+tag@beispiel.de', 'confirmed'],
            // Asked and never confirmed: the state a double opt-in list is
            // mostly made of, and the one a report usually forgets.
            ['chorwerkstatt', 'chorbrief', 'wartet@beispiel.de', 'pending'],
            ['chorwerkstatt', 'kursinfos', 'a@b.de', 'confirmed'],
            ['chorwerkstatt', 'chorbrief', 'weg@beispiel.de', 'unsubscribed'],
            ['halbmond', 'tourmail', 'doppelt@beispiel.de', 'confirmed'],
            // The same address again, in another case, on another brand's list.
            ['lindhorst', 'praxisbrief', 'DOPPELT@beispiel.de', 'confirmed'],
            ['halbmond', 'tourmail', 'bounce@beispiel.invalid', 'confirmed'],
        ];

        foreach ($zeilen as $i => [$marke, $liste, $email, $status]) {
            $normal = mb_strtolower(trim($email));

            MarketingSubscription::withoutGlobalScopes()->updateOrCreate(
                [
                    'list_handle' => $liste,
                    'email_normalized' => $normal,
                    'brand_id' => $marken[$marke]->id,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'email' => $email,
                    'uniqueness_key' => $marken[$marke]->id.':'.$liste.':'.$normal,
                    'status' => $status,
                    'token' => Str::random(32),
                    'source' => 'demo',
                    'subscribed_at' => Carbon::now()->subDays(50 - $i * 3),
                    'confirmed_at' => $status === 'confirmed' ? Carbon::now()->subDays(49 - $i * 3) : null,
                    'unsubscribed_at' => $status === 'unsubscribed' ? Carbon::now()->subDays(4) : null,
                ],
            );
        }
    }

    /** @param array<string, Brand> $marken */
    protected function sperrliste(array $marken): void
    {
        $zeilen = [
            ['halbmond', 'bounce@beispiel.invalid', 'hard_bounce', null],
            ['chorwerkstatt', 'beschwerde@beispiel.de', 'complaint', null],
            // Temporary, and already over: a suppression that should no longer
            // suppress. The row that tells you whether expiry is honoured.
            ['chorwerkstatt', 'abgelaufen@beispiel.de', 'soft_bounce', '-2 days'],
            // Released again by hand.
            ['lindhorst', 'freigegeben@beispiel.de', 'complaint', null],
        ];

        foreach ($zeilen as [$marke, $email, $grund, $laeuftAb]) {
            Suppression::withoutGlobalScopes()->updateOrCreate(
                ['email_normalized' => mb_strtolower($email), 'brand_id' => $marken[$marke]->id],
                [
                    'uuid' => (string) Str::uuid(),
                    'reason' => $grund,
                    'source' => 'demo',
                    'suppressed_at' => Carbon::now()->subDays(10),
                    'expires_at' => $laeuftAb ? Carbon::now()->sub(ltrim($laeuftAb, '-')) : null,
                    'released_at' => $email === 'freigegeben@beispiel.de' ? Carbon::now()->subDay() : null,
                    'released_by' => $email === 'freigegeben@beispiel.de' ? 'studio@local' : null,
                    'release_reason' => $email === 'freigegeben@beispiel.de' ? 'Kunde hat sich gemeldet' : null,
                ],
            );
        }
    }

    /** @param array<string, Brand> $marken */
    protected function freebies(array $marken): void
    {
        $zeilen = [
            ['chorwerkstatt', 'stimm-check', 'Der Stimm-Check als PDF', 'link', 'https://beispiel.de/stimm-check.pdf', true, 14, 3],
            ['chorwerkstatt', 'drei-uebungen', 'Drei Übungen für den nächsten Probenabend', 'link', 'https://beispiel.de/uebungen.pdf', false, null, null],
            ['halbmond', 'demo-track', 'Ein Stück, bevor es erscheint', 'link', 'https://beispiel.de/demo.mp3', false, 2, 1],
        ];

        foreach ($zeilen as [$marke, $handle, $titel, $art, $url, $bestaetigung, $tage, $max]) {
            $r = Resource::withoutGlobalScopes()->updateOrCreate(
                ['handle' => $handle, 'brand_id' => $marken[$marke]->id],
                [
                    'title' => $titel,
                    'description' => 'Kostenlos, gegen die Adresse.',
                    'delivery_type' => $art,
                    'link_url' => $url,
                    'requires_confirmation' => $bestaetigung,
                    'published' => true,
                    'grant_ttl_days' => $tage,
                    'max_downloads' => $max,
                    'marketing_list' => $marke === 'chorwerkstatt' ? 'chorbrief' : 'tourmail',
                ],
            );

            // One grant per resource, in a different state each.
            Grant::withoutGlobalScopes()->updateOrCreate(
                ['resource_id' => $r->id, 'email' => 'bärbel.öztürk@beispiel.de'],
                [
                    'brand_id' => $marken[$marke]->id,
                    'token_hash' => hash('sha256', Str::random(40)),
                    'requested_at' => Carbon::now()->subDays(5),
                    'delivered_at' => $handle === 'demo-track' ? null : Carbon::now()->subDays(5),
                    'download_count' => $handle === 'stimm-check' ? 2 : 0,
                ],
            );
        }
    }

    /** @param array<string, Brand> $marken */
    protected function zugaenge(array $marken): void
    {
        $zeilen = [
            // Live.
            ['chorwerkstatt', 'cw-kurs', 'active', null],
            // Ran out yesterday: the state that decides whether "has access"
            // asks about time or only about a row existing.
            ['chorwerkstatt', 'cw-mitgliedschaft', 'active', '-1 day'],
            // In its grace period after a failed charge.
            ['halbmond', 'hm-fanclub', 'active', '+3 days'],
            // Taken away.
            ['lindhorst', 'lh-fuenferkarte', 'revoked', null],
        ];

        foreach ($zeilen as $i => [$marke, $produkt, $status, $bis]) {
            $zugang = Entitlement::withoutGlobalScopes()->firstOrNew([
                'brand_id' => $marken[$marke]->id,
                'subject_type' => 'email',
                'subject_id' => DemoData::AWKWARD_EMAILS[$i],
                'product_slug' => $produkt,
            ]);

            // `status`, `brand_id` and `revoked_at` are deliberately not
            // fillable on this model: they are the columns that decide access,
            // and the addon keeps mass assignment away from them. A seeder is
            // no exception, so they are written explicitly.
            $zugang->forceFill([
                'brand_id' => $marken[$marke]->id,
                'subject_type' => 'email',
                'subject_id' => DemoData::AWKWARD_EMAILS[$i],
                'product_slug' => $produkt,
                'source' => 'payment',
                'source_ref' => 'demo_tr_'.($i + 1),
                'status' => $status,
                'starts_at' => Carbon::now()->subDays(30),
                'expires_at' => $bis
                    ? (str_starts_with($bis, '-')
                        ? Carbon::now()->sub(ltrim($bis, '-'))
                        : Carbon::now()->add(ltrim($bis, '+')))
                    : null,
                'revoked_at' => $status === 'revoked' ? Carbon::now()->subDays(2) : null,
                'revoked_reason' => $status === 'revoked' ? 'Rückerstattung' : null,
            ])->save();
        }
    }

    /** @param array<string, Brand> $marken */
    protected function meldungen(array $marken): void
    {
        $zeilen = [
            ['chorwerkstatt', 'payment.paid', 'Bärbel Öztürk-Weiß hat den Frühlingskurs gekauft.', false],
            ['chorwerkstatt', 'funnel.completed', '🎵 Der Taktstock ist durch den Funnel gelaufen.', false],
            ['halbmond', 'subscription.cancelled', 'Ein Fanclub-Abo wurde gekündigt.', true],
            ['lindhorst', 'booking.requested', 'Ein Erstgespräch wurde angefragt.', false],
            ['chorwerkstatt', 'payment.failed', 'Eine Zahlung über 450,00 EUR ist fehlgeschlagen.', false],
        ];

        foreach ($zeilen as $i => [$marke, $typ, $text, $gelesen]) {
            NotificationItem::withoutGlobalScopes()->updateOrCreate(
                ['brand_id' => $marken[$marke]->id, 'dedupe_key' => 'demo-'.$i],
                [
                    'type' => $typ,
                    'recipient_type' => 'user',
                    'message' => $text,
                    'read_at' => $gelesen ? Carbon::now()->subHours(2) : null,
                    'created_at' => Carbon::now()->subHours($i * 5 + 1),
                ],
            );
        }
    }

    /** @param array<string, Brand> $marken */
    protected function spuren(array $marken): void
    {
        $ereignisse = ['page.viewed', 'form.submitted', 'payment.paid', 'email.opened', 'email.clicked', 'funnel.step_entered'];

        foreach ($ereignisse as $i => $typ) {
            foreach (['chorwerkstatt', 'halbmond'] as $marke) {
                // `firstOrCreate`, not `updateOrCreate`: the activity addon
                // refuses updates on purpose, because a log somebody can edit
                // is not a log. A seeder is no exception, and finding that out
                // on the second run is exactly what a second run is for.
                Activity::withoutGlobalScopes()->firstOrCreate(
                    ['brand_id' => $marken[$marke]->id, 'dedupe_key' => 'demo-'.$marke.'-'.$i],
                    [
                        // Globally unique and required: the addon's first line
                        // of defence against the same event arriving twice from
                        // two different places.
                        // Derived from the brand and the index rather than random, so a
                        // second run of the seeder does not create a second copy.
                        'event_id' => sprintf('demo0000-0000-4000-8000-%012d', crc32($marke.$i) % 1000000000000),
                        'event_type' => $typ,
                        'source' => 'demo',
                        'properties' => ['pfad' => '/f/fruehlingskurs', 'variante' => $i % 2 ? 'a' : 'b'],
                        'occurred_at' => Carbon::now()->subHours($i * 7),
                        'received_at' => Carbon::now()->subHours($i * 7),
                    ],
                );
            }
        }
    }
}
