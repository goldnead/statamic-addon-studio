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

        // Auch hier in PHP gefiltert: auf der Demo fand `where('slug', …)` die
        // Markenseite nach einem frischen Stache nicht, die Seite blieb an der
        // Wurzel (23.09.2026, zweimal live als 404 unter /chorwerkstatt/partner).
        $eltern = $unter === null ? null
            : Entry::query()->where('collection', 'pages')->get()
                ->first(fn ($e) => $e->slug() === $unter)?->id();

        // Knoten ohne Eintrag raus: die Reste der Laeufe mit wechselnder id.
        foreach (self::ids($baum->tree()) as $knoten) {
            if (Entry::find($knoten) === null) {
                $baum->remove($knoten);
            }
        }

        // Direkt am Baum-Array, nicht ueber `move()`/`appendTo()`: beide liessen
        // die Seite in einem strukturierten Baum mit Wurzelseite an der Wurzel
        // stehen (nachgestellt am 23.09.2026, Elternseite danach weiter die
        // Startseite). Erst ueberall herausnehmen, dann genau einmal einsetzen.
        $zweige = self::ohne($baum->tree(), $id);
        $knoten = ['entry' => $id];

        if ($eltern === null) {
            $zweige[] = $knoten;
        } else {
            $zweige = self::unter($zweige, $eltern, $knoten);
        }

        $baum->tree($zweige)->save();

        return $id;
    }

    /**
     * @param  array<int, array<string, mixed>>  $zweige
     * @return list<array<string, mixed>>
     */
    protected static function ohne(array $zweige, string $id): array
    {
        $rest = [];

        foreach ($zweige as $zweig) {
            if (($zweig['entry'] ?? null) === $id) {
                continue;
            }

            if (isset($zweig['children'])) {
                $zweig['children'] = self::ohne($zweig['children'], $id);

                if ($zweig['children'] === []) {
                    unset($zweig['children']);
                }
            }

            $rest[] = $zweig;
        }

        return $rest;
    }

    /**
     * Unter der Elternseite anhaengen; steht sie nirgends im Baum, an die Wurzel.
     *
     * @param  array<int, array<string, mixed>>  $zweige
     * @param  array<string, mixed>  $knoten
     * @return list<array<string, mixed>>
     */
    protected static function unter(array $zweige, string $eltern, array $knoten, bool $oben = true): array
    {
        $gefunden = false;

        $zweige = array_map(function (array $zweig) use ($eltern, $knoten, &$gefunden) {
            if (! $gefunden && ($zweig['entry'] ?? null) === $eltern) {
                $zweig['children'] = [...($zweig['children'] ?? []), $knoten];
                $gefunden = true;
            } elseif (! $gefunden && isset($zweig['children'])) {
                $vorher = $zweig['children'];
                $zweig['children'] = self::unter($vorher, $eltern, $knoten, false);
                $gefunden = $zweig['children'] !== $vorher;
            }

            return $zweig;
        }, array_values($zweige));

        if (! $gefunden && $oben) {
            $zweige[] = $knoten;
        }

        return $zweige;
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
