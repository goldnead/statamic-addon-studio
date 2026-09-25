<?php

namespace App\Demo\Postfach;

use Goldnead\StatamicInbox\Contracts\TransportFactory;
use Goldnead\StatamicInbox\Models\Mailbox;
use Illuminate\Mail\Transport\LogTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Der Postausgang von statamic-inbox auf der Demo: das Systemlog.
 *
 * statamic-inbox schickt Antworten NICHT ueber den Mailer der Site
 * (`MAIL_MAILER=log` griffe hier nicht), sondern ueber den SMTP-Server des
 * Postfachs selbst. Ohne diese Bindung waere jede Antwort auf der oeffentlichen
 * Demo ein Verbindungsversuch zu dem Host, der im Postfach steht. Mit ihr geht
 * die fertige Mail samt Kopfzeilen ins Log, wie der Magic-Link der Kasse.
 */
class DemoSmtp implements TransportFactory
{
    public function for(Mailbox $mailbox): TransportInterface
    {
        return new LogTransport(app('log'));
    }
}
