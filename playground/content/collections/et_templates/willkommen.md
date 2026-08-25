---
id: 71732d48-00ec-4c08-ad10-23c7de3890e4
blueprint: email_template
title: Willkommen
subject: 'Willkommen bei {{ sender.name }}, {{ contact.first_name }}'
preview: 'Schön, dass du da bist.'
description: 'Begrüßung nach der Eintragung. Kampagnen-Hülle.'
layout: kampagne
body:
  -
    type: heading
    attrs:
      level: 2
    content:
      -
        type: text
        text: 'Hallo {{ contact.first_name }},'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'schön, dass du dich eingetragen hast. Wir schreiben dir als {{ contact.full_name }} an {{ contact.email }}.'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Alle zwei Wochen eine Übung zum Mitnehmen, mehr nicht.'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Wenn es doch zu viel wird, hier abmelden.'
        marks:
          -
            type: link
            attrs:
              href: '{{ unsubscribe_url }}'
brand: nordlicht
---
