<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProcessPdfQueue extends Command
{
    protected $signature = 'queue:process-pdf 
                          {--timeout=1800 : Timeout in seconds}
                          {--tries=3 : Number of retry attempts}
                          {--sleep=3 : Sleep time between jobs}';

    protected $description = 'Process PDF queue with custom settings';

    public function handle()
    {
        $this->info('Starting PDF queue worker...');
        $this->info('Queue: pdf-processing');
        $this->info('Timeout: ' . $this->option('timeout') . 's');
        $this->info('Tries: ' . $this->option('tries'));

        $this->call('queue:work', [
            '--queue' => 'pdf-processing',
            '--timeout' => $this->option('timeout'),
            '--tries' => $this->option('tries'),
            '--sleep' => $this->option('sleep'),
            '--stop-when-empty' => false,
        ]);
    }
}