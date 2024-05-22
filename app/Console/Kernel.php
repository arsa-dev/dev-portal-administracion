<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\SendNotifications::class,
        \App\Console\Commands\ChangeStatusToInscription::class,
        \App\Console\Commands\ChangeStatusToDevelopment::class,
        \App\Console\Commands\SendEmailNotificationsAutomatic::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('app:send-notifications')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('app:change-status-to-development')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('app:change-status-to-inscription')->everyFiveMinutes()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
