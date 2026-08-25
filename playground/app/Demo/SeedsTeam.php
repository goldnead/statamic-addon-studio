<?php

namespace App\Demo;

use Goldnead\BrandContext\Models\Brand;
use Statamic\Facades\User;

/**
 * Das Team der Agentur.
 *
 * Warum das eigene Demo-Daten sind und keine Fußnote: mit einem einzigen
 * Superuser sieht jede Berechtigung gleich aus, nämlich erlaubt, und die
 * Markenzugehörigkeits-Seite von brand-context hat nichts zu zeigen. Deren
 * eigentliche Regel — wer nirgends zugeordnet ist, ist überall Mitglied, und
 * die erste Zuordnung verengt — braucht mindestens drei Leute in drei Lagen.
 *
 * Deshalb hier: eine, die nur einen Kunden sieht, einer mit zweien, eine ganz
 * ohne Zuordnung, und ein Konto in der Bösewicht-Marke, dessen Name die Zeichen
 * trägt, an denen eine Mail-Kopfzeile und ein HTML-Attribut zerbrechen.
 */
class SeedsTeam
{
    /** @return array<string, int> */
    public function run(): array
    {
        $marken = Brand::query()->get()->keyBy('handle');

        $leute = [
            [
                'email' => 'mira@nordlicht.beispiel',
                'name' => 'Mira Andresen',
                // Die Agenturinhaberin ist Superuser. Nicht aus Bequemlichkeit:
                // ohne ein Konto, das der Seeder selbst anlegt, kommt niemand
                // in ein frisch aufgebautes Demo hinein — die Zugangsdaten in
                // README und DEMO.md zeigten auf ein handgemachtes Konto, das
                // kein Schritt erzeugt und das nicht im Repo liegt.
                'super' => true,
                'roles' => ['agentur'],
                'groups' => ['team'],
                // Keine Zuordnung: sieht laut brand-context jede Marke. Das ist
                // die Agenturinhaberin, und die Regel ist hier keine Lücke.
                'marken' => [],
            ],
            [
                'email' => 'jonas@nordlicht.beispiel',
                'name' => 'Jonas Reineke',
                'roles' => ['redaktion'],
                'groups' => [],
                // Genau ein Kunde. Der Fall, den die Seite eigentlich erklärt.
                'marken' => ['chorwerkstatt'],
            ],
            [
                'email' => 'said@nordlicht.beispiel',
                'name' => 'Said Kübler',
                'roles' => ['redaktion'],
                'groups' => [],
                'marken' => ['halbmond', 'lindhorst'],
            ],
            [
                'email' => 'buchhaltung@nordlicht.beispiel',
                'name' => 'Ute Brand-Söllner',
                'roles' => ['buchhaltung'],
                'groups' => [],
                // Zahlen über alle Kunden hinweg, aber keine Inhalte.
                'marken' => [],
            ],
            [
                'email' => 'a@b.de',
                // Der Name, der eine Mail-Kopfzeile, eine URL und ein
                // HTML-Attribut zugleich angreift. Ein Konto braucht ihn
                // genauso wie ein Kontakt.
                'name' => 'Müller & Söhne <Chor>',
                'roles' => [],
                'groups' => [],
                'marken' => ['sonderzeichen'],
            ],
        ];

        $gezaehlt = 0;
        $zuordnungen = 0;

        foreach ($leute as $person) {
            $nutzer = User::findByEmail($person['email']);

            if (! $nutzer) {
                $nutzer = User::make()->email($person['email']);
                // Ein Kennwort, das in kein Produktionssystem passt und in
                // jedes Demo: alle fahren dasselbe.
                $nutzer->password('demo-local-password');
            }

            $nutzer->set('name', $person['name']);

            if ($person['super'] ?? false) {
                $nutzer->makeSuper();
            }
            $nutzer->save();

            // Rollen und Gruppen erst nach dem Speichern, sonst hat der Nutzer
            // noch keine Id, an der sie hängen könnten. `roles([...])` als
            // Setter schreibt nichts in die Datei — es muss `assignRole` sein,
            // sonst sieht der Nutzer im CP zwar eine Rolle (über die Gruppe),
            // hat aber keine eigene.
            foreach ($person['roles'] as $rolle) {
                $nutzer->assignRole($rolle);
            }

            foreach ($person['groups'] as $gruppe) {
                $nutzer->addToGroup($gruppe);
            }

            $nutzer->save();

            $gezaehlt++;
            $zuordnungen += $this->markenZuordnen($nutzer, $person['marken'], $marken);
        }

        return ['nutzer' => $gezaehlt, 'markenzuordnungen' => $zuordnungen];
    }

    /**
     * @param  list<string>  $handles
     * @param  \Illuminate\Support\Collection<string, Brand>  $marken
     */
    private function markenZuordnen(mixed $nutzer, array $handles, mixed $marken): int
    {
        $gesetzt = 0;

        foreach ($handles as $handle) {
            if (! $marke = $marken->get($handle)) {
                continue;
            }

            // Die Pivot-Tabelle von brand-context. Ein Statamic-Nutzer ist kein
            // Eloquent-Modell, deshalb steht hier die Id als Zeichenkette.
            \DB::table('brand_user')->updateOrInsert(
                ['brand_id' => $marke->getKey(), 'user_id' => (string) $nutzer->id()],
                [],
            );

            $gesetzt++;
        }

        return $gesetzt;
    }
}
