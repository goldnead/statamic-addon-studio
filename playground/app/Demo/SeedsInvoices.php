<?php

namespace App\Demo;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Invoices\Exceptions\InvoiceNotWritten;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Refunds;
use Illuminate\Support\Collection;

/**
 * Rechnungen zu den bezahlten Zahlungen des Demos.
 *
 * Interessant sind hier die Fälle, die **keine** Rechnung bekommen. Ein
 * Rechnungs-Addon, das für jede Zahlung brav ein Dokument ausspuckt, zeigt
 * nichts — die Aussage ist, dass es sich weigert, wenn es raten müsste, und das
 * ist nur zu sehen, wenn es etwas zu verweigern gibt.
 *
 * Deshalb steht am Ende bewusst eine Handvoll Zahlungen ohne Rechnung, jede aus
 * einem anderen Grund, und `php artisan invoices:pending` zählt sie auf.
 */
class SeedsInvoices
{
    /** @return array<string, int> */
    public function run(): array
    {
        $marken = Brand::query()->get()->keyBy('handle');
        $geschrieben = 0;
        $verweigert = 0;

        foreach ($this->bezahlteZahlungen() as $zahlung) {
            $marke = $marken->get('chorwerkstatt');

            try {
                $rechnung = BrandContext::runFor(
                    $marke,
                    fn () => app(InvoiceWriter::class)->forPayment($zahlung->loadMissing('items')),
                );

                $geschrieben += $rechnung ? 1 : 0;
            } catch (InvoiceNotWritten) {
                // Genau der Punkt. Kein Land, kein Produkt-Merkmal, keine
                // Anschrift über der Kleinbetragsgrenze — jedes davon ist eine
                // Entscheidung, die ein Mensch trifft, und keine, die ein
                // Seeder wegraten darf.
                $verweigert++;
            }
        }

        return [
            'rechnungen' => $geschrieben,
            'ohne_rechnung' => $verweigert,
            'stornorechnungen' => $this->einStorno($marken),
        ];
    }

    /**
     * Ein Storno, damit die Reihe beide Dokumentarten trägt.
     *
     * Über eine echte Erstattung, nicht über den Schreiber direkt: die
     * Stornorechnung soll dort entstehen, wo sie im Betrieb entsteht — im
     * Listener auf `PaymentRefunded`.
     *
     * @param  Collection<string, Brand>  $marken
     */
    protected function einStorno($marken): int
    {
        $rechnung = Invoice::query()
            ->where('kind', Invoice::KIND_INVOICE)
            ->whereNotNull('payment_id')
            ->orderBy('id')
            ->first();

        if ($rechnung === null) {
            return 0;
        }

        $zahlung = Payment::withoutGlobalScopes()->find($rechnung->payment_id);

        if ($zahlung === null || $zahlung->refunded_cent > 0) {
            return (int) ($zahlung?->refunded_cent > 0);
        }

        BrandContext::runFor(
            $marken->get('chorwerkstatt'),
            fn () => app(Refunds::class)->record($zahlung, $zahlung->amount_cent, 'demo-storno-1'),
        );

        return Invoice::query()->where('kind', Invoice::KIND_CREDIT_NOTE)->count();
    }

    /**
     * Die bezahlten Zahlungen ohne Rechnung — bis auf die Menge.
     *
     * `SeedsInsights` schreibt zweihundert Zahlungen, damit die Berichte einen
     * Verlauf haben. Die gehoeren hier nicht hinein: eine Rechnung ist ein
     * Dokument mit einer fortlaufenden Nummer, und zweihundert davon je Lauf
     * waeren eine Nummernreihe, die niemand bestellt hat — und sie wuerden die
     * Handvoll Verweigerungen zudecken, um die es auf diesem Bildschirm geht.
     *
     * @return Collection<int, Payment>
     */
    protected function bezahlteZahlungen()
    {
        $query = Payment::withoutGlobalScopes()->where('status', Payment::STATUS_PAID);

        foreach (DemoData::MENGEN_PRAEFIXE as $praefix) {
            $query->where('provider_id', 'not like', $praefix.'%');
        }

        return $query
            ->whereNotIn('id', Invoice::query()->whereNotNull('payment_id')->pluck('payment_id'))
            ->with('items')
            ->orderBy('id')
            ->get();
    }
}
