<?php

namespace App\Demo;

use Goldnead\Community\Events\CommentCreated;
use Goldnead\Messaging\Events\MessageSent;
use Goldnead\Messaging\Models\ConversationParticipant;
use Goldnead\Notifications\Facades\Notifications;
use Goldnead\Teams\Events\ClaimDecided;
use Goldnead\Teams\Models\TeamClaim;
use Goldnead\Teams\Support\Users;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Entry;

/**
 * Die drei Meldungen des Chorfinders, alle ueber die verzoegerte Zustellung
 * (`notifyDeferred`, statamic-notifications 1.11.0).
 *
 * Warum verzoegert und nicht gleich: eine Nachricht, ein Kommentar und eine
 * Entscheidung ueber einen Anspruch sind Fakten, die in einer Transaktion
 * entstehen. Die Mail darf spaeter und mit Wiederholung hinausgehen und die
 * Anfrage nicht ausbremsen, wenn der Mailserver klemmt. `notifications:dispatch-pending`
 * holt sich die Zeilen ab; die Chorfinder-Seite hat dafuer einen Knopf.
 *
 * Die Demo-Mails landen im Log (MAIL_MAILER=log), nie bei einer echten Adresse.
 *
 * Typen muessen in einem Provider stehen (README statamic-notifications), deshalb
 * ruft der AppServiceProvider `typenRegistrieren()` und `hoeren()`.
 */
class ChorfinderMeldungen
{
    public static function typenRegistrieren(): void
    {
        Notifications::registerType('claim.decided', function ($type) {
            $type->label('Entscheidung über einen Anspruch')
                ->defaultChannels(['in_app', 'mail'])
                ->renderUsing(fn ($item) => [
                    'message' => $item->message ?? 'Über deinen Anspruch wurde entschieden.',
                    'link' => $item->link ?? '/chorfinder/verzeichnis',
                ]);
        });

        Notifications::registerType('message.received', function ($type) {
            $type->label('Neue Nachricht')
                ->defaultChannels(['in_app', 'mail'])
                ->renderUsing(fn ($item) => [
                    'message' => $item->message ?? 'Du hast eine neue Nachricht.',
                    'link' => $item->link ?? '/chorfinder/nachrichten',
                ]);
        });

        Notifications::registerType('community.comment', function ($type) {
            $type->label('Neuer Kommentar')
                ->defaultChannels(['in_app', 'mail'])
                ->renderUsing(fn ($item) => [
                    'message' => $item->message ?? 'Jemand hat deinen Beitrag kommentiert.',
                    'link' => $item->link ?? '/chorfinder/community',
                ]);
        });
    }

    public static function hoeren(): void
    {
        if (class_exists(ClaimDecided::class)) {
            Event::listen(ClaimDecided::class, [self::class, 'anspruchEntschieden']);
        }

        if (class_exists(MessageSent::class)) {
            Event::listen(MessageSent::class, [self::class, 'nachricht']);
        }

        if (class_exists(CommentCreated::class)) {
            Event::listen(CommentCreated::class, [self::class, 'kommentar']);
        }
    }

    public static function anspruchEntschieden(ClaimDecided $event): void
    {
        $claim = $event->claim;
        $antragsteller = $claim->claimantUser();

        if ($antragsteller === null) {
            return;
        }

        $chor = Entry::find($claim->resource_key)?->get('title') ?? 'das Profil';
        $ja = $claim->status === TeamClaim::APPROVED;

        Notifications::notifyDeferred($antragsteller, 'claim.decided', [
            'message' => $ja
                ? "Dein Anspruch auf „{$chor}“ wurde angenommen. Das Profil gehört jetzt deinem Team."
                : "Dein Anspruch auf „{$chor}“ wurde abgelehnt.",
            'link' => '/chorfinder/verzeichnis/'.$claim->resource_key,
            'dedupe_key' => 'claim:'.$claim->id.':'.$claim->status,
        ]);
    }

    /**
     * Ohne Text und ohne den Namen der anonymen Seite: der Absender erscheint
     * so, wie die Konversation ihn dem Empfaenger zeigt (`displayName`).
     */
    public static function nachricht(MessageSent $event): void
    {
        $nachricht = $event->message;
        $konversation = $nachricht->conversation;

        if ($nachricht->sender_user_id === null) {
            return;
        }

        $empfaenger = ConversationParticipant::query()
            ->where('conversation_id', $konversation->id)
            ->where('user_id', '!=', $nachricht->sender_user_id)
            ->where('blocked', false)
            ->pluck('user_id');

        foreach ($empfaenger as $schluessel) {
            $nutzer = Users::find($schluessel);

            if ($nutzer === null) {
                continue;
            }

            $absender = $konversation->displayName($nachricht->sender_user_id, $nutzer);

            Notifications::notifyDeferred($nutzer, 'message.received', [
                'message' => "Neue Nachricht von {$absender}.",
                'link' => '/chorfinder/nachrichten/'.$konversation->id,
                'dedupe_key' => 'message:'.$nachricht->id,
            ]);
        }
    }

    public static function kommentar(CommentCreated $event): void
    {
        $kommentar = $event->comment;
        $beitrag = $kommentar->post;

        if ($beitrag === null || $beitrag->author_user_id === $kommentar->author_user_id) {
            return;
        }

        $autor = Users::find($beitrag->author_user_id);

        if ($autor === null) {
            return;
        }

        $wer = Users::name($kommentar->author_user_id) ?? 'Jemand';
        $titel = $beitrag->title !== '' ? $beitrag->title : 'deinen Beitrag';

        Notifications::notifyDeferred($autor, 'community.comment', [
            'message' => "{$wer} hat „{$titel}“ kommentiert.",
            'link' => '/chorfinder/community/beitrag/'.$beitrag->id,
            'dedupe_key' => 'comment:'.$kommentar->id,
        ]);
    }
}
