<?php

use App\Console\Commands\RecurringRefreshCommand;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Extends active recurring occurrence horizons. Does not post expenses.
        // Production still needs an external cron invoking `php artisan schedule:run`.
        $schedule->command(RecurringRefreshCommand::SIGNATURE_NAME)
            ->dailyAt('02:15')
            ->timezone('UTC')
            ->withoutOverlapping(60);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
