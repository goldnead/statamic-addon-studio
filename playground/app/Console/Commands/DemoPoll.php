<?php

namespace App\Console\Commands;

use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Illuminate\Console\Command;

/**
 * Ask the provider about payments it was never able to tell us about.
 *
 * A demo runs on `localhost`, and a provider checks that a webhook URL is
 * reachable from its own side before it will create a payment at all. So the
 * demo turns the webhook off (`statamic-payments.webhook_url => false`) and
 * pulls instead.
 *
 * Nothing here is a shortcut. `Fulfilment::handle()` is the *same* method the
 * webhook route calls, and it does the same thing it always does: fetch the
 * status from the provider and believe only that. The difference is who started
 * the conversation, which is the one thing the design was careful never to make
 * matter.
 *
 * **Not a production pattern.** A buyer who closes the tab is followed up by a
 * webhook and by nothing else. This exists so a laptop can demo a real payment.
 */
class DemoPoll extends Command
{
    protected $signature = 'demo:poll {payment? : One payment id, or all that are still open}';

    protected $description = 'Pull the status of open payments from the provider, as the webhook would have pushed it.';

    public function handle(Fulfilment $fulfilment): int
    {
        $zahlungen = $this->argument('payment')
            ? Payment::query()->whereKey($this->argument('payment'))->get()
            : Payment::query()
                ->whereIn('status', [Payment::STATUS_OPEN, Payment::STATUS_INITIATED])
                ->where('provider', '!=', 'free')
                // Anything the provider never acknowledged has no id to ask about.
                ->where('provider_id', 'not like', 'pending-%')
                ->get();

        if ($zahlungen->isEmpty()) {
            $this->components->info('Nichts offen.');

            return self::SUCCESS;
        }

        foreach ($zahlungen as $zahlung) {
            $vorher = $zahlung->status;

            $fulfilment->handle($zahlung->provider_id);

            $nachher = $zahlung->fresh()?->status ?? '?';

            $this->components->twoColumnDetail(
                sprintf('#%s %s', $zahlung->id, $zahlung->product),
                $vorher === $nachher ? $nachher : "{$vorher} → <fg=green>{$nachher}</>",
            );
        }

        return self::SUCCESS;
    }
}
