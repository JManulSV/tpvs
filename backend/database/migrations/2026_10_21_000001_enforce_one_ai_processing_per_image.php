<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_processings', function (Blueprint $table) {
            $table->unique('image_id', 'ai_processings_image_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ai_processings', function (Blueprint $table) {
            $table->dropUnique('ai_processings_image_id_unique');
        });
    }
};
