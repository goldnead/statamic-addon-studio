---
id: 25f3765e-8d7d-4e94-8064-2685d59de3af
blueprint: email_template
title: Anmelde-Link
subject: 'Dein Anmelde-Link, {{ contact.first_name }}'
preview: 'Ein Klick, und du bist drin.'
description: 'Passwortloser Login. Trägt absichtlich zwei unbekannte Merge-Variablen, um "unbekannte Tags bleiben stehen" zu zeigen.'
layout: transactional
body:
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Hallo {{ contact.first_name }},'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'klick auf den Link, dann bist du angemeldet:'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Jetzt anmelden'
        marks:
          -
            type: link
            attrs:
              href: '{{ login.magic_url }}'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Der Link läuft in {{ login.expires_in }} ab. Wenn du das nicht warst, ignorier diese Mail.'
brand: nordlicht
---
