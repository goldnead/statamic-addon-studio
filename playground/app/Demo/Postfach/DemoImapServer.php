<?php

namespace App\Demo\Postfach;

use DateTimeInterface;
use Goldnead\StatamicInbox\Contracts\MailboxClient;

/**
 * Ein IMAP-Server im Speicher, fuer statamic-inbox auf der Demo.
 *
 * Die Demo ist oeffentlich und hat kein echtes Postfach. Sie darf auch nie
 * eins erreichen: ein Postfach-Formular, das einen Server anspricht, waere auf
 * einer offenen Demo ein IMAP-Pruefer fuer jeden, der vorbeikommt. Deshalb
 * bindet der AppServiceProvider diese Klasse statt des echten ImapEngine-
 * Adapters, und zwar immer, nicht nur beim Seeden.
 *
 * Im Seeder (SeedsInbox) liegen hier die Mails, die der echte Abrufer dann
 * holt, parst, bereinigt und zu Gespraechen faedelt. Im Betrieb ist der
 * Server leer: `inbox:fetch` findet nichts, `check()` gelingt, eine Antwort
 * legt ihre Kopie fuer „Gesendet" hier ab und ist mit dem Request vergessen.
 *
 * Nachgebaut nach tests/Fakes/FakeMailboxClient.php des Addons (das nicht im
 * Release-Tag liegt, `/tests` ist export-ignore).
 */
class DemoImapServer implements MailboxClient
{
    /** @var array<string, array<int, string>> Ordner => [UID => RFC822] */
    public array $ordner = [];

    /** Eine Mail in einen Ordner legen, wie eine Zustellung. Gibt die UID zurueck. */
    public function zustellen(string $ordner, string $roh): int
    {
        $uids = array_keys($this->ordner[$ordner] ?? []);
        $uid = $uids === [] ? 1 : max($uids) + 1;

        $this->ordner[$ordner][$uid] = $roh;

        return $uid;
    }

    public function uidsAfter(string $folder, int $afterUid, ?DateTimeInterface $since = null): array
    {
        $uids = [];

        foreach ($this->ordner[$folder] ?? [] as $uid => $roh) {
            if ($uid <= $afterUid) {
                continue;
            }

            if ($since !== null && preg_match('/^Date:\s*(.+)$/mi', $roh, $treffer)) {
                $datum = strtotime(trim($treffer[1]));

                if ($datum !== false && date('Y-m-d', $datum) < $since->format('Y-m-d')) {
                    continue;
                }
            }

            $uids[] = $uid;
        }

        sort($uids);

        return $uids;
    }

    public function fetchRaw(string $folder, int $uid): string
    {
        return $this->ordner[$folder][$uid]
            ?? throw new \RuntimeException("Keine Mail mit UID {$uid} in {$folder}.");
    }

    public function append(string $folder, string $raw, array $flags = ['\\Seen']): void
    {
        $this->zustellen($folder, $raw);
    }

    public function detectSentFolder(): ?string
    {
        return 'Gesendet';
    }

    public function uidValidity(string $folder): ?int
    {
        return 1;
    }

    public function check(): void
    {
        // Kein Server, also nichts, das scheitern koennte.
    }
}
