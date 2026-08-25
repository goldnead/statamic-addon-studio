---
id: 5a019cb7-8451-4b62-8e44-e8edb30121ff
blueprint: email_template
title: 'Abo gekündigt'
subject: 'Schade, {{ contact.first_name }} — dein Abo endet'
preview: 'Deine Kündigung ist eingegangen.'
description: 'Bestätigung nach einer Abo-Kündigung.'
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
        text: 'deine Kündigung ist eingegangen. Dein Zugang bleibt bis zum Ende des bezahlten Zeitraums bestehen, danach ist Schluss.'
  -
    type: paragraph
    content:
      -
        type: text
        text: 'Wenn du zurückwillst, weißt du, wo wir sind.'
brand: nordlicht
---
