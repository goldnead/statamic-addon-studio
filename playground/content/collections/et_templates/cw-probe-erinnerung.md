---
id: 93678daa-7d52-4281-af41-751c9895fd49
blueprint: email_template
title: 'Erinnerung an den Probentag'
subject: 'Übermorgen, {{ contact.first_name }}'
preview: 'Kurze Erinnerung an deinen Termin.'
description: 'Erinnerung zwei Tage vor dem Workshop.'
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
        text: 'am {{ date }} sehen wir uns. Wir fangen pünktlich an, also plan ein paar Minuten Puffer ein.'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Wenn du doch nicht kannst, sag kurz Bescheid. Dann rückt jemand von der Warteliste nach.'
brand: chorwerkstatt
---
