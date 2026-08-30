<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Demo läuft hinter Cloudflare und Caddy; ohne Proxy-Vertrauen
        // sieht die Anwendung http statt https. (Lokal ohne Proxy harmlos.)
        $middleware->trustProxies(at: '*');

        // Welche Marke eine Website-Anfrage betrifft, beantwortet
        // brand-context selbst — seit 1.11.0 über `brand-context.paths`.
        // Vorher lag hier eine eigene Middleware, weil das Addon die
        // öffentliche Seite gar nicht auflöste und die Site deshalb leer war.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->expectsJson(),
        );
    })->create();
