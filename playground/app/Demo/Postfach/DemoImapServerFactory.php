<?php

namespace App\Demo\Postfach;

use Goldnead\StatamicInbox\Contracts\MailboxClient;
use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Models\Mailbox;

/**
 * Je Postfach ein {@see DemoImapServer}, dieselbe Instanz bei jedem Aufruf
 * im selben Prozess. Baut nie eine Verbindung auf, egal welcher Host im
 * Postfach steht.
 */
class DemoImapServerFactory implements MailboxClientFactory
{
    /** @var array<int|string, DemoImapServer> */
    protected array $server = [];

    public function for(Mailbox $mailbox): MailboxClient
    {
        return $this->server($mailbox);
    }

    public function server(Mailbox $mailbox): DemoImapServer
    {
        return $this->server[$mailbox->getKey()] ??= new DemoImapServer;
    }
}
