<?php

namespace App\Widgets;

use Goldnead\BrandContext\Models\Brand;
use Illuminate\Support\Facades\DB;
use Statamic\Widgets\Widget;

/**
 * Der Empfang des Schauraums.
 *
 * Ohne ihn ist das Erste, was jemand nach dem Anmelden sieht, ein leeres
 * Dashboard mit Statamics „Erste Schritte"-Karten — auf einer Installation, in
 * der 62 Tabellen voller Daten liegen. Das Demo startet auf der Agenturmarke,
 * und auf der liegt zu Recht wenig: die Kundendaten gehören den Kunden. Nur
 * weiß das niemand, der gerade erst hereingekommen ist.
 *
 * Der Wegweiser sagt deshalb, wo etwas zu sehen ist, und zählt live mit, damit
 * er nicht behauptet, was der Aufbau vielleicht nicht erzeugt hat.
 */
class DemoWegweiser extends Widget
{
    public function html()
    {
        return view('widgets.demo-wegweiser', [
            'marken' => $this->marken(),
            'gesamt' => $this->zahlen(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function marken(): array
    {
        $kunden = ['chorwerkstatt' => 'Kurse, Mitgliedschaft, Ausbildung in Raten',
            'halbmond' => 'Platten, Tickets, Fanclub',
            'lindhorst' => 'Erstgespräch, Fünferkarte, Begleitung'];

        return Brand::query()
            ->whereIn('handle', array_keys($kunden))
            ->get()
            ->map(fn (Brand $marke) => [
                'handle' => $marke->handle,
                'name' => $marke->name,
                'was' => $kunden[$marke->handle] ?? '',
                // Nur Zahlen, die wirklich je Marke getrennt sind. `payments`
                // hat keine `brand_id` — der ganze Handelsteil liegt in einem
                // Topf — und eine Spalte, die deshalb immer 0 anzeigt, ist
                // schlimmer als keine.
                'kontakte' => $this->zaehle('leadhub_contacts', $marke->getKey()),
                'abonnenten' => $this->zaehle('marketing_subscriptions', $marke->getKey()),
                'termine' => $this->zaehle('event_occurrences', $marke->getKey()),
                'url' => cp_route('dashboard').'?brand='.$marke->handle,
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function zahlen(): array
    {
        return [
            'kontakte' => $this->zaehle('leadhub_contacts'),
            'zahlungen' => $this->zaehle('payments'),
            'spuren' => $this->zaehle('activities'),
            'laeufe' => $this->zaehle('automation_runs'),
        ];
    }

    /**
     * Zählt ohne Markenbereich — der Wegweiser soll ja gerade zeigen, was auf
     * den *anderen* Marken liegt.
     */
    private function zaehle(string $tabelle, ?int $marke = null): int
    {
        try {
            $abfrage = DB::table($tabelle);

            if ($marke !== null) {
                $abfrage->where('brand_id', $marke);
            }

            return $abfrage->count();
        } catch (\Throwable) {
            // Eine Tabelle, die es nicht gibt, ist kein Grund, das Dashboard
            // mitzunehmen.
            return 0;
        }
    }
}
