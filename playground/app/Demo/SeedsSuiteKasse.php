<?php

namespace App\Demo;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Support\Str;

/**
 * Der Funnel `suite-kasse` fuer statamic-funnels F1 bis F7.
 *
 * Eine Kasse mit zwei Zahlweisen und zwei Bumps, deren Regeln an der Zahlweise
 * haengen (F1): „Für die Stimmgruppe" kreuzt die Übe-Aufnahmen vor, das
 * Notenpaket verschwindet fuer Wiederkehrer. Der Kassenschritt ist ein
 * A/B-Test auf Kauf mit automatischem Gewinner ab 40 Besuchen (F2), mit 100
 * Besuchen dazu: Fassung A verkauft 5 von 50, Fassung B 21 von 50. Dazu
 * In-App-Hinweis (F3), Einbettung von der Demo-Adresse (F4), `SUITE10` als
 * Gutschein aus der Adresse (F5), Tracking-Schnipsel je Funnel und Schritt (F6)
 * und eine Meta-Pixel-ID als Platzhalter (F7). **Kein CAPI-Token**: die Demo
 * schickt nichts an Meta.
 *
 * Nach dem Muster aus `demo-funnels.md` des F-Baus. Wiederholbar: Angebote und
 * Funnel ueber den Handle, Schritte, Kanten und Besuche werden je Lauf neu
 * geschrieben, damit die A/B-Zahlen nicht bei jedem Lauf wachsen.
 */
class SeedsSuiteKasse
{
    public const DEMO_ORIGIN = 'https://demo.adriangoldner.dev';

    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken = []): array
    {
        $marke = (int) (($marken['chorwerkstatt'] ?? null)?->id
            ?? Brand::query()->where('handle', 'chorwerkstatt')->value('id'));

        $this->angebote($marke);

        Coupon::updateOrCreate(['code' => 'SUITE10'], [
            'name' => 'Suite-Probe', 'percent' => 10, 'active' => true, 'funnel_wide' => true,
        ]);

        $funnel = $this->funnel();
        $besuche = $this->abTest($funnel);

        $this->beispielseite();

        return ['kasse_besuche' => $besuche];
    }

    protected function angebote(int $marke): void
    {
        $bump = fn (string $handle, string $name, string $titel, int $cent) => Offer::query()->withoutGlobalScopes()->updateOrCreate(['handle' => $handle], [
            'brand_id' => $marke, 'name' => $name, 'headline' => $titel, 'product' => 'cw-workshop',
            'slot' => Offer::SLOT_BUMP, 'active' => true, 'amount_cent' => $cent,
        ]);

        $bump('sk-noten', 'Notenpaket als PDF', 'Notenpaket als PDF dazu', 900);
        $bump('sk-aufnahme', 'Übe-Aufnahmen je Stimme', 'Übe-Aufnahmen für jede Stimme im Team', 1900);

        Offer::query()->withoutGlobalScopes()->updateOrCreate(['handle' => 'sk-workshop'], [
            'brand_id' => $marke, 'name' => 'Stimmbildung im Chor, Workshop', 'headline' => 'Workshop: Stimmbildung im Chor',
            'product' => 'cw-workshop', 'slot' => Offer::SLOT_STANDALONE, 'active' => true, 'amount_cent' => 4900,
            'bumps' => ['sk-noten', 'sk-aufnahme'],
            'pricing_options' => [
                ['key' => 'einzeln', 'label' => 'Für mich allein', 'amount_cent' => 4900],
                ['key' => 'team', 'label' => 'Für die Stimmgruppe', 'amount_cent' => 14900],
            ],
            'country_mode' => 'only', 'countries' => ['DE', 'AT', 'CH'],
        ]);

        Offer::query()->withoutGlobalScopes()->updateOrCreate(['handle' => 'sk-spende'], [
            'brand_id' => $marke, 'name' => 'Aufzeichnung zum Selbstkostenpreis', 'headline' => 'Die Aufzeichnung, zu deinem Preis',
            'product' => 'cw-workshop', 'slot' => Offer::SLOT_STANDALONE, 'active' => true, 'amount_cent' => null,
            'price_mode' => 'pwyw', 'pwyw_min_cent' => 1000, 'pwyw_suggested_cent' => 2500, 'pwyw_max_cent' => 20000,
            'pwyw_thanks' => [
                ['from_cent' => 0, 'text' => 'Danke, dass du dabei bist.'],
                ['from_cent' => 5000, 'text' => 'Danke, du trägst die Aufzeichnung für andere mit.'],
            ],
        ]);
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::updateOrCreate(['handle' => 'suite-kasse'], [
            'title' => 'Suite: Kasse mit Regeln',
            'published' => true,
            'meta' => ['settings' => [
                'in_app_enabled' => true,
                // Gleiche Herkunft darf immer (`frame-ancestors 'self'`). Wer von
                // einer eigenen Seite aus einbetten will, traegt deren Herkunft
                // im CP unter den Funnel-Einstellungen nach (DEMO.md).
                'embed_domains' => [self::DEMO_ORIGIN],
                'tracking_head' => "<script>console.log('kopf-code');</script>",
                'tracking_thanks' => "<script>console.log('kauf', '{amount}', '{currency}', '{order_id}');</script>",
                // Platzhalter, kein echtes Pixel. Ohne Einwilligung in den
                // Dienst `meta_pixel` laedt ohnehin nichts.
                'meta_pixel_id' => '123456789012345',
            ]],
        ]);

        $funnel->steps()->delete();
        $funnel->edges()->delete();

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => ['headline' => 'Stimmbildung im Chor', 'body' => 'Ein Workshop für Chorsänger:innen.']],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung', 'config' => ['headline' => 'Wohin dürfen wir schreiben?']],
            ['node_key' => 'kasse_1', 'type' => 'offer', 'label' => 'Kasse', 'slug' => 'kasse', 'config' => [
                'offer' => 'sk-workshop', 'headline' => 'Fassung A: Workshop buchen',
                'split_share' => '50', 'variant_headline' => 'Fassung B: Deine Stimme im Chor',
                'split_goal' => 'purchase', 'split_auto' => true, 'split_min_visits' => '40',
                'bump_rules' => [
                    'sk-aufnahme' => ['options' => ['team'], 'preselected' => true, 'returning' => 'show', 'requires' => null],
                    'sk-noten' => ['options' => [], 'requires' => null, 'preselected' => false, 'returning' => 'hide'],
                ],
                'tracking_purchase' => "<script>console.log('workshop gekauft', '{order_id}');</script>",
            ]],
            ['node_key' => 'spende_1', 'type' => 'offer', 'label' => 'Aufzeichnung', 'slug' => 'aufzeichnung', 'config' => ['offer' => 'sk-spende']],
            ['node_key' => 'danke_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke', 'config' => ['headline' => 'Danke!']],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'kasse_1', 'from_output' => 'default'],
            ['from_node_key' => 'kasse_1', 'to_node_key' => 'spende_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'kasse_1', 'to_node_key' => 'danke_1', 'from_output' => 'declined'],
            ['from_node_key' => 'spende_1', 'to_node_key' => 'danke_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'spende_1', 'to_node_key' => 'danke_1', 'from_output' => 'declined'],
        ]);

        return $funnel;
    }

    /** F2: 50 Besuche je Fassung, A verkauft 5, B 21. */
    protected function abTest(Funnel $funnel): int
    {
        $funnel->visits()->delete();

        foreach (['a' => 5, 'b' => 21] as $fassung => $kaeufe) {
            for ($i = 0; $i < 50; $i++) {
                $besuch = FunnelVisit::create([
                    'funnel_id' => $funnel->id,
                    'token' => Str::random(32),
                    'meta' => ['variants' => ['kasse_1' => $fassung]],
                ]);

                $besuch->record('entry_1', FunnelStepEvent::ENTERED);
                $besuch->record('kasse_1', FunnelStepEvent::ENTERED, ['variant' => $fassung]);

                if ($i < $kaeufe) {
                    $besuch->record('kasse_1', FunnelStepEvent::ACCEPTED);
                    $besuch->record('spende_1', FunnelStepEvent::ENTERED);
                }
            }
        }

        return 100;
    }

    /**
     * F4: die Seite, die den Einbett-Schnipsel zeigt. Eine fremde Herkunft
     * kann die Demo selbst nicht stellen; die Seite zeigt den Schnipsel zum
     * Kopieren und fuehrt ihn darunter auf derselben Herkunft einmal vor.
     */
    protected function beispielseite(): void
    {
        DemoSeiten::seite('einbetten-beispiel', [
            'title' => 'Kasse einbetten',
            'template' => 'einbetten_beispiel',
        ]);
    }
}
