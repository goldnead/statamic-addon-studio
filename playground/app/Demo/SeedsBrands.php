<?php

namespace App\Demo;

use Goldnead\BrandContext\Models\Brand;

/**
 * Three clients of one small agency.
 *
 * Brands are the scoping root for most of this family: contacts, subscribers,
 * webhooks, notifications and sender identities all hang off one. Seeding three
 * rather than one is the only way to find out whether the scoping is real or
 * whether something quietly reads across all of them.
 */
class SeedsBrands
{
    /** @return array<string, Brand> */
    public function run(): array
    {
        $marken = [];

        foreach ($this->definitionen() as $handle => $daten) {
            $marken[$handle] = Brand::updateOrCreate(
                ['handle' => $handle],
                [
                    'name' => $daten['name'],
                    'is_default' => $daten['default'] ?? false,
                    'settings' => [
                        'mail' => [
                            'from_address' => $daten['from'],
                            'from_name' => $daten['name'],
                            'reply_to' => $daten['reply_to'] ?? $daten['from'],
                        ],
                        'url' => $daten['url'],
                        'colour' => $daten['colour'],
                    ],
                ],
            );
        }

        return $marken;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function definitionen(): array
    {
        return [
            // The agency itself. Default, so anything that forgets to pick a
            // brand lands here rather than in a client's data.
            'nordlicht' => [
                'name' => 'Nordlicht Studio',
                'from' => 'post@nordlicht.beispiel',
                'url' => 'https://nordlicht.beispiel',
                'colour' => '#1d3a5f',
                'default' => true,
            ],
            'chorwerkstatt' => [
                'name' => 'Chorwerkstatt Nord',
                'from' => 'hallo@chorwerkstatt.beispiel',
                'reply_to' => 'antwort@chorwerkstatt.beispiel',
                'url' => 'https://chorwerkstatt.beispiel',
                'colour' => '#7a4a1e',
            ],
            'halbmond' => [
                'name' => 'Kollektiv Halbmond',
                'from' => 'crew@halbmond.beispiel',
                'url' => 'https://halbmond.beispiel',
                'colour' => '#12121a',
            ],
            'lindhorst' => [
                'name' => 'Praxis Lindhorst',
                'from' => 'praxis@lindhorst.beispiel',
                'url' => 'https://lindhorst.beispiel',
                'colour' => '#3f6b5e',
            ],
            // Deliberately awkward: a brand whose name carries the characters
            // that break a mail header, a URL and an HTML attribute. Nothing
            // sells through it; it exists to be rendered.
            'sonderzeichen' => [
                'name' => 'Müller & Söhne <Chor> „Ännchen"',
                'from' => 'ännchen@müller-söhne.beispiel',
                'url' => 'https://müller-söhne.beispiel',
                'colour' => '#8a1f3c',
            ],
        ];
    }
}
