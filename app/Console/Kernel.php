<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // شغّل notifications أولاً ثم default بنفس العملية
      /*  $schedule->command('queue:work database --queue=notifications,default --sleep=3 --tries=1 --timeout=60 --stop-when-empty')
            ->everyMinute()
            ->withoutOverlapping(10) // يمنع التداخل لو حدث تأخير
            ->runInBackground();     // خليه يرجع للـ scheduler بسرعة
*/
        // لو عندك طوابير تانية، ممكن تضيف أوامر مشابهة
        // $schedule->command('queue:work database --queue=emails --stop-when-empty')->everyMinute()->withoutOverlapping()->runInBackground();
    }
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
