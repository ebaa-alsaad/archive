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
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('code'); // رقم القيد أو رقم السند إذا لم يوجد قيد
            $table->integer('pages_count')->default(0);
            $table->string('pdf_path')->nullable(); // مسار PDF النهائي بعد التجميع
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('upload_id')->constrained()->onDelete('cascade');

            $table->index('code');
            $table->index('user_id');
            $table->index('upload_id');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
