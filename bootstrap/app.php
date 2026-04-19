<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
   ->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (AuthenticationException $e, $request) {
        // Si la requête attend du JSON (API)
        if ($request->expectsJson() || str_starts_with($request->path(), 'api')) {
            return response()->json([
                'message' => 'Unauthenticated. Please login first.'
            ], 401);
        }
        
        return redirect()->guest(route('login'));
    });
})
    ->create();