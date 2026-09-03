---
id: 5a6ec591-d84d-4079-9feb-0007656a20ed
blueprint: email_template
title: 'Abmeldung bestätigt'
subject: 'Du bist abgemeldet, {{ contact.first_name }}'
preview: 'Wir schreiben dir nicht mehr.'
description: 'Bestätigung nach der Abmeldung von der Liste.'
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
        text: 'deine Abmeldung ist eingegangen. Wir schreiben dir ab sofort nicht mehr.'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Die Termine stehen weiter öffentlich auf unserer Seite.'
brand: chorwerkstatt
---
