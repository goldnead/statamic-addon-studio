---
id: c4e52ca1-f06a-44a9-9a20-c6acd897a3ee
blueprint: email_template
title: 'Workshop-Platz bestätigt'
subject: 'Dein Platz am {{ date }} steht, {{ contact.first_name }}'
preview: 'Anmeldung und Zahlung sind da.'
description: 'Beleg nach Anmeldung und Zahlung für einen Workshop.'
layout: transactional
body:
  -
    type: paragraph
    content:
      -
        type: text
        text: '{{ contact.salutation }},'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'dein Platz ist gebucht. Wir haben '
      -
        type: text
        text: "19,99\_EUR"
        marks:
          -
            type: bold
      -
        type: text
        text: ' am {{ date }} erhalten.'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Bring bequeme Kleidung und etwas zu trinken mit. Noten stellen wir.'
brand: chorwerkstatt
---
