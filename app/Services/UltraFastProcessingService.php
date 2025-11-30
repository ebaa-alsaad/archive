<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class UltraFastProcessingService
{
    private $tmpfsPath = '/tmp/ultrafast_processing';

    public function __construct()
    {
        // التأكد من وجود مجلد tmpfs
        if (!File::isDirectory($this->tmpfsPath)) {
            File::makeDirectory($this->tmpfsPath, 0755, true);
        }
    }

    /**
     * حفظ الملف في tmpfs للسرعة القصوى
     */
    public function storeInTmpfs($file)
    {
        $filename = 'ultrafast_' . uniqid() . '_' . $file->getClientOriginalName();
        $fullPath = $this->tmpfsPath . '/' . $filename;

        // نقل مباشر إلى tmpfs (أسرع من storage)
        move_uploaded_file($file->getPathname(), $fullPath);

        return [
            'path' => $fullPath,
            'filename' => $filename,
            'size' => filesize($fullPath)
        ];
    }

    /**
     * تنظيف الملف من tmpfs
     */
    public function cleanupTmpfs($path)
    {
        if (file_exists($path)) {
            unlink($path);
            return true;
        }
        return false;
    }

    /**
     * فحص مساحة tmpfs المتاحة
     */
    public function getTmpfsStatus()
    {
        $total = disk_total_space($this->tmpfsPath);
        $free = disk_free_space($this->tmpfsPath);
        $used = $total - $free;

        return [
            'total_mb' => round($total / 1024 / 1024, 2),
            'used_mb' => round($used / 1024 / 1024, 2),
            'free_mb' => round($free / 1024 / 1024, 2),
            'usage_percent' => round(($used / $total) * 100, 2)
        ];
    }

    /**
     * معالجة الدُفعات لتحسين الأداء
     */
    public function processBatch($files, $batchSize = 2, $callback)
    {
        $batches = array_chunk($files, $batchSize);
        $results = [];

        foreach ($batches as $batchIndex => $batch) {
            Log::info("Processing batch {$batchIndex}", [
                'batch_size' => count($batch),
                'files' => array_map(fn($f) => $f->getClientOriginalName(), $batch)
            ]);

            $batchResults = [];
            foreach ($batch as $file) {
                $batchResults[] = $callback($file);
            }

            $results = array_merge($results, $batchResults);

            // تنظيف الذاكرة بعد كل دفعة
            gc_collect_cycles();

            // إعطاء وقت لل system لاستعادة الموارد
            if ($batchIndex < count($batches) - 1) {
                usleep(100000); // 100ms
            }
        }

        return $results;
    }
}
