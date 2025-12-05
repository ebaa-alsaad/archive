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
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('tus_path')->nullable();
            $table->integer('total_pages')->default(0);
            $table->enum('status', ['queued','processing','completed','failed'])->default('queued');
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->index('status');
            $table->index('user_id');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
