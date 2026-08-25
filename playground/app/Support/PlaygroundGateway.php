<?php

namespace App\Support;

use Goldnead\StatamicPayments\Contracts\FollowUpGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;

/** Stands in for Mollie so the whole path can run without a live account. */
class PlaygroundGateway implements FollowUpGateway
{
    public function supportsFollowUp(): bool
    {
        return true;
    }

    public function rememberBuyer(array $buyer): string
    {
        $state = $this->state();
        $ref = 'cst_'.substr(md5((string) mt_rand()), 0, 8);
        $state['_mandates'][] = $ref;
        file_put_contents($this->file, json_encode($state));

        return $ref;
    }

    public function chargeAgain(string $customerReference, array $payload): RemotePayment
    {
        $state = $this->state();

        if (! in_array($customerReference, $state['_mandates'] ?? [], true)) {
            throw new \RuntimeException('kein Mandat');
        }

        $id = 'pg_folge_'.substr(md5((string) mt_rand()), 0, 8);
        $state[$id] = ['status' => 'open', 'metadata' => $payload['metadata'] ?? []];
        file_put_contents($this->file, json_encode($state));

        return new RemotePayment($id, 'open', $state[$id]['metadata']);
    }

    protected string $file = '/tmp/pg-gateway.json';

    public function provider(): string
    {
        return 'playground';
    }

    public function createPayment(array $payload): CheckoutSession
    {
        $state = $this->state();
        $id = 'pg_'.substr(md5((string) mt_rand()), 0, 8);
        $state[$id] = ['status' => 'open', 'metadata' => $payload['metadata'] ?? []];
        file_put_contents($this->file, json_encode($state));

        return new CheckoutSession($id, 'https://checkout.example/'.$id);
    }

    public function fetch(string $providerId): RemotePayment
    {
        $state = $this->state();

        if (! isset($state[$providerId])) {
            throw new \RuntimeException('no such payment');
        }

        return new RemotePayment(
            $providerId,
            $state[$providerId]['status'],
            $state[$providerId]['metadata'] ?? [],
            $state[$providerId]['email'] ?? null,
        );
    }

    public function mark(string $id, string $status): void
    {
        $state = $this->state();
        $state[$id]['status'] = $status;
        file_put_contents($this->file, json_encode($state));
    }

    protected function state(): array
    {
        return file_exists($this->file) ? (json_decode(file_get_contents($this->file), true) ?: []) : [];
    }
}
