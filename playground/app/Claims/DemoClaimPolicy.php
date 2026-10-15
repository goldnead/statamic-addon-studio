<?php

namespace App\Claims;

use Goldnead\Teams\Contracts\ClaimPolicy;
use Goldnead\Teams\Exceptions\TeamsException;
use Statamic\Contracts\Auth\User;

/**
 * Wer auf der Chorfinder-Demo einen Anspruch stellen und wer ihn entscheiden darf.
 *
 * statamic-teams bringt bewusst keine eigene Regel mit (die Vorgabe verweigert
 * alles), die Policy gehoert dem Host. Hier zwei Saetze:
 *
 * - Beantragen darf jedes Konto mit bestaetigter Adresse. Die Demo-Konten
 *   tragen `email_verified_at`, ein frisch angelegtes ohne den Wert nicht.
 * - Entscheiden darf ein Superuser oder die Rolle `chorfinder_moderation`.
 *   Dass jemand den eigenen Antrag nicht entscheiden kann, regelt das Addon.
 */
class DemoClaimPolicy implements ClaimPolicy
{
    public function assertCanRequest(User $actor): void
    {
        if (blank($actor->get('email_verified_at'))) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }
    }

    public function assertCanReview(User $actor): void
    {
        if (! $actor->isSuper() && ! $actor->hasRole('chorfinder_moderation')) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }
    }
}
