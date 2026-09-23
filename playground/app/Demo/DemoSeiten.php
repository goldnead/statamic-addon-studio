<?php

namespace App\Demo;

use Ramsey\Uuid\Uuid;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Eine Seite in `pages` anlegen und an die richtige Stelle im Baum haengen.
 *
 * `pages` ist strukturiert. Zwei Dinge sind dabei am 23.09.2026 schiefgegangen
 * und stehen deshalb hier fest:
 *
 * - **Feste id statt Suche ueber den Slug.** Die Suche fand die Seite im
 *   zweiten Lauf nicht wieder; jeder Lauf legte eine neue id an, die Datei
 *   wurde ueberschrieben und der Baum behielt die alte. Die id ist jetzt eine
 *   UUID v5 aus dem Slug, jeder Lauf trifft dieselbe Seite.
 * - **Statamic haengt eine neue Seite beim Speichern selbst an die Wurzel.**
 *   Eine Seite, die unter eine Markenseite gehoert (das Demo trennt Marken am
 *   ersten Pfadsegment), stand dann unter `/partner` statt
 *   `/chorwerkstatt/partner`, und die Pruefung „schon im Baum?" liess sie dort.
 *   Deshalb wird sie verschoben, wenn sie am falschen Ort steht.
 */
class DemoSeiten
{
    /**
     * `$unter`: Slug der Elternseite, fuer Seiten, deren Daten einer Marke
     * gehoeren.
     *
     * @param  array<string, mixed>  $daten
     */
    public static function seite(string $slug, array $daten, ?string $unter = null): string
    {
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'nordlicht-demo:pages:'.$slug)->toString();

        // Aeltere Laeufe haben dieselbe Seite unter anderer id angelegt. Die
        // muessen weg, sonst schreibt Statamic die neue als `<slug>.1.md`
        // daneben, und zwei Seiten streiten um eine Adresse. Gefiltert in PHP,
        // weil `where('slug', …)` genau diese Seiten nicht fand.
        Entry::query()->where('collection', 'pages')->get()
            ->filter(fn ($e) => $e->slug() === $slug && $e->id() !== $id)
            ->each(fn ($e) => $e->delete());

        $eintrag = Entry::find($id) ?? Entry::make()->collection('pages')->id($id);
        $eintrag->slug($slug)->data(array_merge(['blueprint' => 'pages'], $daten))->published(true)->save();

        $baum = Collection::find('pages')?->structure()?->in(Site::default()->handle());

        if ($baum === null) {
            return $id;
        }

        $eltern = $unter === null ? null
            : Entry::query()->where('collection', 'pages')->where('slug', $unter)->first()?->id();

        // Knoten ohne Eintrag raus: die Reste der Laeufe mit wechselnder id.
        foreach (self::ids($baum->tree()) as $knoten) {
            if (Entry::find($knoten) === null) {
                $baum->remove($knoten);
            }
        }

        if ($baum->find($id) === null) {
            $eltern !== null ? $baum->appendTo($eltern, $eintrag) : $baum->append($eintrag);
        } else {
            $baum->move($id, $eltern);
        }

        $baum->save();

        return $id;
    }

    /**
     * @param  array<int, array<string, mixed>>  $zweige
     * @return list<string>
     */
    protected static function ids(array $zweige): array
    {
        $ids = [];

        foreach ($zweige as $zweig) {
            if (isset($zweig['entry'])) {
                $ids[] = (string) $zweig['entry'];
            }

            $ids = [...$ids, ...self::ids($zweig['children'] ?? [])];
        }

        return $ids;
    }
}
