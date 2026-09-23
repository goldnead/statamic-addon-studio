<?php

namespace App\Demo;

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Eine Seite in `pages` anlegen und in den Baum haengen.
 *
 * `pages` ist strukturiert: ein Eintrag, der nicht im Baum steht, hat keine
 * Adresse und antwortet mit 404, obwohl die Datei da ist. Deshalb beides in
 * einem Schritt. Wiederholbar: gefunden wird ueber den Slug, eingehaengt nur,
 * was noch nicht im Baum steht.
 */
class DemoSeiten
{
    /**
     * `$unter`: Slug der Elternseite. Das Demo trennt die Marken nach dem ersten
     * Pfadsegment (`brand-context.paths`); eine Seite, deren Daten einer Marke
     * gehoeren, muss deshalb unter deren Seite haengen, sonst sieht sie der
     * Besucher in der Agenturmarke und findet nichts.
     *
     * @param  array<string, mixed>  $daten
     */
    public static function seite(string $slug, array $daten, ?string $unter = null): string
    {
        $eintrag = Entry::query()->where('collection', 'pages')->where('slug', $slug)->first()
            ?? Entry::make()->collection('pages')->slug($slug);

        $eintrag->data(array_merge(['blueprint' => 'pages'], $daten))->published(true)->save();

        $baum = Collection::find('pages')?->structure()?->in(Site::default()->handle());

        if ($baum !== null && $baum->find($eintrag->id()) === null) {
            $eltern = $unter === null ? null
                : Entry::query()->where('collection', 'pages')->where('slug', $unter)->first();

            $eltern !== null
                ? $baum->appendTo($eltern->id(), $eintrag)->save()
                : $baum->append($eintrag)->save();
        }

        return (string) $eintrag->id();
    }
}
