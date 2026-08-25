<?php

namespace App\Demo;

/**
 * The awkward values.
 *
 * A demo built from tidy data proves that tidy data works, which nobody
 * doubted. Everything in here is a shape that has broken software before:
 * characters that need escaping in four different places, lengths nobody
 * budgeted for, dates that do not exist in every year, and references that
 * point at nothing.
 *
 * Kept in one class rather than sprinkled through the seeders so that the list
 * can be read as what it is: a checklist of things this family claims to
 * survive.
 */
class DemoData
{
    /** Names that have to survive a form, a database, an email and a URL. */
    public const AWKWARD_NAMES = [
        'Sängerin\'s Ännchen',           // apostrophe inside a German possessive
        'Müller & Söhne <Chor>',         // ampersand and an angle bracket
        'Bärbel Öztürk-Weiß',            // umlauts and ß in a compound
        'Ana María Ñuñez',               // Spanish diacritics
        'Ægir Þórsson',                  // Icelandic, outside Latin-1
        '李 明',                          // CJK, and a space that is not a word break
        'Jean-Luc «Loup» Fabre',         // guillemets
        'O\'Brien-McDonagh',             // apostrophe at a word boundary
        '🎵 Der Taktstock',               // an emoji leading a name
        'A',                             // one character
    ];

    /** Addresses that are legal, ugly, or both. */
    public const AWKWARD_EMAILS = [
        'BÄRBEL.Öztürk@Beispiel.DE',      // uppercase and non-ASCII in the local part
        'plus+tag@beispiel.de',           // a plus address
        'sehr.langer.name.der.niemals.enden.will.und.trotzdem.gueltig.ist@ein-sehr-langer-domainname-den-niemand-tippt.beispiel.de',
        'a@b.de',                         // the shortest plausible one
        'doppelt@beispiel.de',            // deliberately used twice
        'DOPPELT@beispiel.de',            // the same address in another case
        'bounce@beispiel.invalid',        // will be suppressed
        'kein-name@beispiel.de',          // no first or last name anywhere
    ];

    /**
     * Dates that break arithmetic.
     *
     * The 31st is here because a month added to it lands in the month after
     * next, which this family measured and fixed. 29 February is here because
     * 2027 does not have one.
     */
    public static function awkwardDates(): array
    {
        return [
            'monatsende' => '2027-01-31 23:59:00',
            'schaltjahr' => '2028-02-29 12:00:00',
            'jahrhundert' => '1999-12-31 23:59:59',
            'weit_weg' => '2099-06-15 08:00:00',
            'sommerzeit' => '2027-03-28 02:30:00',   // the hour that does not exist in CET
            'winterzeit' => '2027-10-31 02:30:00',   // the hour that happens twice
            'mitternacht' => '2027-05-01 00:00:00',
        ];
    }

    /** A string that is longer than most `varchar(191)` columns. */
    public static function tooLong(int $length = 260): string
    {
        $satz = 'Ein Titel, der viel zu lang ist und deshalb genau das findet, was eine Spaltenbreite verschweigt. ';

        return mb_substr(str_repeat($satz, 10), 0, $length);
    }

    /** Amounts in minor units, including the ones that are not amounts. */
    public const AWKWARD_AMOUNTS = [
        'gratis' => 0,
        'ein_cent' => 1,
        'krumm' => 1999,
        'gross' => 99900,
        'sehr_gross' => 999999,
    ];
}
