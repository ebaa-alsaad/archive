<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->bigInteger('file_size')->nullable()->after('total_pages');
            $table->integer('chunks_total')->nullable()->after('file_size');
            $table->integer('chunks_received')->default(0)->after('chunks_total');
             $table->enum('status', [
                'pending',
                'uploading',
                'queued',
                'processing',
                'completed',
                'failed'
            ])->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
             $table->dropColumn(['file_size', 'chunks_total', 'chunks_received']);
             $table->enum('status', [
                'pending',
                'uploading',
                'processing',
                'completed',
                'failed'
            ])->default('pending')->change();
        });
    }
};
