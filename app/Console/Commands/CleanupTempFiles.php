<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupTempFiles extends Command
{
    protected $signature = 'cleanup:temp-files 
                          {--hours=24 : Delete files older than X hours}';

    protected $description = 'Cleanup temporary files';

    public function handle()
    {
        $hours = $this->option('hours');
        $tempDir = storage_path('app/temp');
        
        if (!file_exists($tempDir)) {
            $this->info("Temp directory does not exist.");
            return;
        }

        $deletedCount = 0;
        $cutoffTime = time() - ($hours * 3600);

        foreach (glob($tempDir . "/*") as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (@unlink($file)) {
                    $deletedCount++;
                }
            }
        }

        $this->info("Deleted {$deletedCount} temporary files older than {$hours} hours");
        Log::info("Temp files cleanup completed", ['deleted' => $deletedCount]);
    }
}