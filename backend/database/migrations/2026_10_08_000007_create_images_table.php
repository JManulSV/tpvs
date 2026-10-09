<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('original_file_name');
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE images
            ADD CONSTRAINT images_original_file_name_check
            CHECK (length(btrim(original_file_name)) > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE images
            ADD CONSTRAINT images_file_path_check
            CHECK (length(btrim(file_path)) > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE images
            ADD CONSTRAINT images_mime_type_check
            CHECK (length(btrim(mime_type)) > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE images
            ADD CONSTRAINT images_file_size_check
            CHECK (file_size > 0)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
