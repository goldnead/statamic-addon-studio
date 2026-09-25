<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auf der Demo legt niemand ein Postfach an, aendert eins oder testet eine
 * Verbindung (statamic-inbox).
 *
 * Das Demo-Konto ist Superuser, und ein Superuser hat jede Berechtigung, auch
 * `manage inbox mailboxes`. Ein Recht zu entziehen reicht deshalb nicht. Ohne
 * diese Sperre koennte jeder Besucher echte Zugangsdaten in eine oeffentliche
 * Datenbank schreiben, und mit dem echten Adapter waere die Demo ein offener
 * IMAP-Pruefer und SMTP-Relay. Die Adapter sind ohnehin ersetzt
 * (App\Demo\Postfach); das hier ist die zweite Tuer.
 *
 * Ansehen bleibt erlaubt: Liste und Formular zeigen, was es gibt.
 */
class PostfaecherSchreibgeschuetzt
{
    /** Routen, die ein Postfach schreiben oder einen Server ansprechen wuerden. */
    public const GESPERRT = [
        'statamic.cp.inbox.mailboxes.store',
        'statamic.cp.inbox.mailboxes.update',
        'statamic.cp.inbox.mailboxes.test',
        'statamic.cp.inbox.mailboxes.test-new',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->route()?->getName(), self::GESPERRT, true)) {
            return response()->json([
                'message' => 'In der Demo sind die Postfächer schreibgeschützt. Hier wird kein echtes Postfach verbunden.',
            ], 403);
        }

        return $next($request);
    }
}
