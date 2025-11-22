<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\ProcessPdfQueue::class,
        \App\Console\Commands\CleanupTempFiles::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        // تنظيف الملفات المؤقتة كل ساعة
        $schedule->command('cleanup:temp-files --hours=1')
                 ->hourly()
                 ->withoutOverlapping();

        // إعادة تشغيل الـ worker كل 12 ساعة لمنع تسرب الذاكرة
        $schedule->command('queue:restart')
                 ->twiceDaily(1, 13);
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}