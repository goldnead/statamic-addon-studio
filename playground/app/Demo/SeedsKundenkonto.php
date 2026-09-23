<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Settings\SettingsManager;

/**
 * Markeneinstellungen fuer statamic-payments P7 und P9.
 *
 * Kundenkonto der Chorwerkstatt: eine Begruessung, Pausieren und Wechseln
 * erlaubt (die Abos dazu stehen in {@see SeedsAbos}, pausierbar und wechselbar
 * macht sie der Katalog in {@see SeedsCommerce}). Kassenschutz: eine
 * Sperrliste mit einer Wegwerf-Domain zum Vorfuehren. Captcha bleibt aus, es
 * braucht echte Schluessel.
 *
 * Ueber die Einstellungsschicht von brand-context, nicht in die Config: das
 * ist derselbe Weg wie das Formular unter Einstellungen → Zahlungen, und
 * `demo:seed` schreibt die Config-Datei ohnehin nur im Produktblock.
 * Wiederholbar: gespeichert wird ein Wert, kein Zaehler.
 *
 * Kein Logo: die Marke hat keins als Datei. Das Feld zeigt sich, sobald
 * eines unter `portal.logo_url` steht.
 */
class SeedsKundenkonto
{
    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken = []): array
    {
        $marke = $marken['chorwerkstatt'] ?? Brand::query()->where('handle', 'chorwerkstatt')->firstOrFail();

        BrandContext::runFor($marke, fn () => app(SettingsManager::class)->for('payments')->save([
            'portal.greeting' => 'Hier siehst du deine Mitgliedschaft und deine Käufe. Pausieren und Wechseln geht direkt hier.',
            'portal.allow_pause' => true,
            'portal.allow_switch' => true,
            'protection.blocklist.domains' => ['wegwerf.example'],
        ]));

        return ['kundenkonto_einstellungen' => 4];
    }
}
