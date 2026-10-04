<?php

namespace HiEvents\Console;

use HiEvents\Jobs\Account\ProcessScheduledAccountDeletionsJob;
use HiEvents\Jobs\Message\SendScheduledMessagesJob;
use HiEvents\Jobs\Waitlist\ProcessExpiredWaitlistOffersJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new SendScheduledMessagesJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new ProcessExpiredWaitlistOffersJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new ProcessScheduledAccountDeletionsJob)->hourly()->withoutOverlapping();

        // PayRam settlement is poll-based until webhook endpoint registration
        // ships on our gateway build — this is what actually marks orders paid.
        $schedule->command('monno:payram-reconcile')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Watches for money that cannot move: a project on the wrong hot wallet,
        // or a failed sweep (PayRam reports low gas / missing hot wallet this
        // way). Exits non-zero, so the scheduler run is the alarm.
        $schedule->command('monno:payram-health')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->call(function (): void {
            $count = DB::table('failed_jobs')->count();
            if ($count > 0) {
                Log::warning('Failed jobs present in queue', ['count' => $count]);
            }
        })->everyFiveMinutes()->name('failed-jobs-monitor')->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        include base_path('routes/console.php');
    }
}
