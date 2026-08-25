{{--
    Kampagnen-Hülle.

    Für Newsletter und Sequenzen: dieselbe Merge-Variablen-Mechanik, aber mit
    Kopfband, Vorschautext-Raum und einer Abmelde-Zeile im Fuß. Der Body kommt
    über @yield('content'), der Betreff als $title.

    In config/email-templates.php als 'kampagne' => 'mail.kampagne' verdrahtet.

    Merge-Variablen ({{ contact.first_name }} usw.) gehören in den Body, nicht in
    diese Hülle: die Ersetzung läuft über MergeVariables::apply() auf dem Body,
    bevor er hier eingesetzt wird. Ein {{ ... }} hier wäre eine Blade-Ausgabe,
    keine Merge-Variable. Die Abmelde-Zeile steht deshalb im Template-Body.
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? '' }}</title>
</head>
<body style="margin:0; padding:0; background:#fafaf9; font-family:Georgia,'Times New Roman',serif; color:#1c1917;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafaf9; padding:0;">
        <tr>
            <td style="background:#1c1917; padding:20px 0;" align="center">
                <span style="color:#fafaf9; font-size:18px; font-weight:700; letter-spacing:0.02em;">{{ $title ?? '' }}</span>
            </td>
        </tr>
        <tr>
            <td align="center" style="padding:0 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%;">
                    <tr>
                        <td style="padding:32px 40px; font-size:17px; line-height:1.7;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 40px 40px 40px; border-top:1px solid #e7e5e4; font-size:13px; line-height:1.6; color:#78716c;">
                            Du bekommst diese Mail, weil du dich eingetragen hast. Der Abmelde-Link steht am Ende des Textes.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
