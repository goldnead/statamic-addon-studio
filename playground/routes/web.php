<?php

use App\Http\Controllers\ChorfinderController;
use Illuminate\Support\Facades\Route;

// Route::statamic('example', 'example-view', [
//    'title' => 'Example'
// ]);

// Chorfinder: eine erfundene Chorplattform auf statamic-directory,
// statamic-messaging, statamic-community, den Anspruechen von statamic-teams
// und den Zusagen von statamic-events. Drei dieser Addons haben bewusst keine
// oeffentlichen Routen, die Seiten hier sind die der Demo.
Route::prefix('chorfinder')->group(function () {
    Route::get('/', [ChorfinderController::class, 'start']);
    Route::post('anmelden', [ChorfinderController::class, 'anmelden'])->middleware('throttle:30,1');
    Route::post('abmelden', [ChorfinderController::class, 'abmelden']);

    Route::get('verzeichnis', [ChorfinderController::class, 'verzeichnis']);
    Route::get('verzeichnis/ansprueche', [ChorfinderController::class, 'ansprueche']);
    Route::get('verzeichnis/{id}', [ChorfinderController::class, 'chor'])->whereUuid('id');
    Route::post('verzeichnis/{id}/anspruch', [ChorfinderController::class, 'anspruchStellen'])->whereUuid('id')->middleware('throttle:20,1');
    Route::post('verzeichnis/{id}/nachricht', [ChorfinderController::class, 'neueNachricht'])->whereUuid('id')->middleware('throttle:20,1');
    Route::post('ansprueche/{id}/entscheiden', [ChorfinderController::class, 'anspruchEntscheiden'])->whereNumber('id');

    Route::get('nachrichten', [ChorfinderController::class, 'postfach']);
    Route::get('nachrichten/{id}', [ChorfinderController::class, 'verlauf'])->whereNumber('id');
    Route::post('nachrichten/{id}/antwort', [ChorfinderController::class, 'antworten'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('nachrichten/{id}/freigeben', [ChorfinderController::class, 'freigeben'])->whereNumber('id');

    Route::get('community', [ChorfinderController::class, 'community']);
    Route::get('community/beitrag/{id}', [ChorfinderController::class, 'beitragAnsehen'])->whereNumber('id');
    Route::post('community/beitrag/{id}/kommentar', [ChorfinderController::class, 'kommentieren'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('community/beitrag/{id}/ausblenden', [ChorfinderController::class, 'ausblenden'])->whereNumber('id');
    Route::post('community/reaktion/{typ}/{id}', [ChorfinderController::class, 'reagieren'])->whereNumber('id');
    Route::get('community/{slug}', [ChorfinderController::class, 'raum'])->where('slug', '[a-z0-9-]+');
    Route::post('community/{slug}/beitrag', [ChorfinderController::class, 'beitragSchreiben'])->where('slug', '[a-z0-9-]+')->middleware('throttle:20,1');

    Route::get('veranstaltungen', [ChorfinderController::class, 'veranstaltungen']);
    Route::post('veranstaltungen/{id}/zusage', [ChorfinderController::class, 'zusagen'])->whereNumber('id')->middleware('throttle:30,1');

    Route::get('meldungen', [ChorfinderController::class, 'meldungen']);
    Route::post('meldungen/zustellen', [ChorfinderController::class, 'zustellen']);
    Route::post('meldungen/mail', [ChorfinderController::class, 'mailEinstellung']);
});
