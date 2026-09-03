---
id: 2c7e15c0-19f7-49cb-a09b-c68d0e3aef19
blueprint: email_template
title: 'Willkommen in der Chorwerkstatt'
subject: 'Willkommen in der Chorwerkstatt, {{ contact.first_name }}'
preview: 'Was dich erwartet und wann wir schreiben.'
description: 'Begrüßung nach der Eintragung. Kampagnen-Hülle, Marke Chorwerkstatt.'
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
        text: 'du stehst jetzt auf der Liste von {{ sender.name }}. Wir schreiben dir an {{ contact.email }}, wenn ein neuer Workshoptermin steht.'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Einmal im Monat, dazu eine Übung, die auch ohne Chor funktioniert. Mehr kommt nicht.'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Wenn es zu viel wird, hier abmelden.'
        marks:
          -
            type: link
            attrs:
              href: '{{ unsubscribe_url }}'
brand: chorwerkstatt
---
