{{--
    Schlanke transaktionale Hülle.

    Für Bestätigungen, Belege, Magic-Links: eine Nachricht, die ankommen muss und
    nichts verkaufen will. Der gerenderte Body kommt über @yield('content'), der
    Betreff als $title (siehe BrandedBodyRenderer::wrap()). Absichtlich karg —
    Kopf, Body, ein Fußzeilen-Hinweis, keine Marketing-Zutaten.

    In config/email-templates.php als 'transactional' => 'mail.transactional'
    verdrahtet und dort 'default_layout'. Wird von der CP-Live-Vorschau und vom
    echten Versand über denselben Pfad gerendert.
    Wie die Kampagnen-Hülle nimmt auch diese die Farbe der aktuellen Marke, hier
    zurückhaltender: eine Kante oben und der Markenname über dem Betreff. Ohne
    gesetzte Farbe bleibt das Bild genau wie bisher.
--}}
@php
    $marke = \Goldnead\BrandContext\Facades\BrandContext::current();
    $akzent = $marke->settings['colour'] ?? null;
    $absender = $marke->name ?? '';
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? '' }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; border-top:3px solid {{ $akzent ?? '#e4e4e7' }};">
                    <tr>
                        <td style="padding:28px 32px 8px 32px; border-bottom:1px solid #f4f4f5;">
                            @if ($absender !== '')
                                <div style="font-size:12px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:{{ $akzent ?? '#71717a' }}; margin-bottom:6px;">{{ $absender }}</div>
                            @endif
                            <span style="font-size:13px; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; color:#71717a;">{{ $title ?? '' }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 8px 32px; font-size:15px; line-height:1.6;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 28px 32px; border-top:1px solid #f4f4f5; font-size:12px; line-height:1.5; color:#a1a1aa;">
                            Diese Nachricht wurde automatisch verschickt. Antworten landen bei einem Menschen.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
