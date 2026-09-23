<?php

namespace App\Demo;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Illuminate\Support\Carbon;

/**
 * Ein voller Steuermonat fuer den Rechnungsexport (statamic-invoices R1, R2).
 *
 * Die Rechnungen aus {@see SeedsInvoices} sind wenige und alle aus dem Inland
 * oder verweigert. Der Steuerbericht unter „Rechnungsexport" soll aber zeigen,
 * was er trennt: Inland 19 % und 7 %, OSS Oesterreich 20 %, OSS Finnland
 * 25,5 %, Reverse Charge Frankreich und eine Gutschrift, die den Monat
 * mindert. Deshalb fuenf Belege im August 2026 unter Chorwerkstatt Nord.
 *
 * Direkt als eingefrorene Zeilen, nicht ueber den Schreiber: der Schreiber
 * braucht eine Zahlung und vergaebe die naechste Nummer aus dem Zaehler. Diese
 * Belege tragen Nummern `NL2026-08-9xx` ausserhalb des Zaehlers, damit die
 * echte Reihe der Demo keine Luecke und keinen Sprung bekommt.
 *
 * Idempotent ueber die Nummer. `demo:seed --fresh` loescht Rechnungen samt
 * Zaehler vorher (siehe DemoSeed::wipe()), danach stehen sie wieder da.
 */
class SeedsInvoiceExports
{
    /**
     * @param  array<string, Brand>  $marken
     * @return array<string, int>
     */
    public function run(array $marken = []): array
    {
        $marke = (int) (($marken['chorwerkstatt'] ?? null)?->id
            ?? Brand::query()->where('handle', 'chorwerkstatt')->value('id'));

        // Satz bp, Netto, Steuer, Mechanismus, Leistungsort, Produkt, Name
        $this->beleg($marke, 'NL2026-08-901', '2026-08-04 10:00', [
            [1900, 20924, 3976, 'standard', 'DE'],
            [700, 1402, 98, 'standard', 'DE', 'noten', 'Notenpaket'],
        ]);
        $this->beleg($marke, 'NL2026-08-902', '2026-08-11 14:30', [[2000, 20750, 4150, 'standard', 'AT']], [
            'buyer_country' => 'AT', 'buyer_name' => 'Grete Huber', 'tax_reason' => 'Umsatzsteuer 20 % (Leistungsort AT).',
        ]);
        $this->beleg($marke, 'NL2026-08-903', '2026-08-19 09:15', [[0, 49900, 0, 'reverse_charge', 'FR']], [
            'buyer_country' => 'FR', 'buyer_name' => 'Chœur de Lyon SARL', 'buyer_vat_id' => 'FR12345678901',
            'tax_zone' => 'eu-b2b', 'tax_reason' => 'Steuerschuldnerschaft des Leistungsempfängers.',
        ]);
        $this->beleg($marke, 'NL2026-08-904', '2026-08-27 16:45', [[1900, 20924, 3976, 'standard', 'DE']], [
            'kind' => 'credit_note',
            'reverses_invoice_id' => Invoice::query()->withoutGlobalScopes()->where('number', 'NL2026-08-901')->value('id'),
            'meta' => ['reverses_number' => 'NL2026-08-901'],
        ]);
        $this->beleg($marke, 'NL2026-08-905', '2026-08-25 11:00', [[2550, 1514, 386, 'standard', 'FI']], [
            'buyer_country' => 'FI', 'buyer_name' => 'Aino Virtanen', 'tax_reason' => 'Umsatzsteuer 25,5 % (Leistungsort FI).',
        ]);

        return [
            'steuermonat_belege' => Invoice::query()->withoutGlobalScopes()->where('number', 'like', 'NL2026-08-9%')->count(),
        ];
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: string, 4: string, 5?: string, 6?: string}>  $zeilen
     * @param  array<string, mixed>  $werte
     */
    protected function beleg(int $marke, string $nummer, string $datum, array $zeilen, array $werte = []): void
    {
        if (Invoice::query()->withoutGlobalScopes()->where('number', $nummer)->exists()) {
            return;
        }

        $netto = array_sum(array_column($zeilen, 1));
        $steuer = array_sum(array_column($zeilen, 2));

        $rechnung = Invoice::create(array_merge([
            'brand_id' => $marke,
            'number' => $nummer,
            'kind' => 'invoice',
            'issued_at' => Carbon::parse($datum),
            'currency' => 'EUR',
            'buyer_name' => 'Chor Beispiel e. V.',
            'buyer_email' => 'kasse@example.org',
            'buyer_country' => 'DE',
            'net_cent' => $netto,
            'tax_cent' => $steuer,
            'gross_cent' => $netto + $steuer,
            'tax_reason' => 'Umsatzsteuer 19 %.',
            'seller' => ['name' => 'Chorwerkstatt Nord'],
        ], $werte));

        InvoiceItem::whileWriting(function () use ($rechnung, $zeilen) {
            foreach ($zeilen as $z) {
                $rechnung->items()->create([
                    'product' => $z[5] ?? 'kurs',
                    'name' => $z[6] ?? 'Chorleitungskurs',
                    'quantity' => 1,
                    'unit_net_cent' => $z[1],
                    'net_cent' => $z[1],
                    'tax_rate_bp' => $z[0],
                    'tax_cent' => $z[2],
                    'gross_cent' => $z[1] + $z[2],
                    'tax_mechanism' => $z[3],
                    'place_of_supply' => $z[4],
                ]);
            }
        });
    }
}
