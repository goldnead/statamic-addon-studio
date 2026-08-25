---
id: 6ff1f4fc-b431-44f1-861e-f0c0db96cc86
blueprint: email_template
title: 'Zahlung bestätigt'
subject: 'Zahlung bestätigt, {{ contact.first_name }}'
preview: 'Wir haben deine Zahlung erhalten.'
description: 'Beleg nach erfolgreicher Zahlung.'
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
        text: 'wir haben deine Zahlung über '
      -
        type: text
        text: "19,99\_EUR"
        marks:
          -
            type: bold
      -
        type: text
        text: ' am {{ date }} erhalten. Danke.'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Dein Zugang steht dir ab sofort offen.'
brand: nordlicht
---
