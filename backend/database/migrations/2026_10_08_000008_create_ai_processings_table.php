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
        Schema::create('ai_processings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('image_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_processings
            ADD CONSTRAINT ai_processings_status_check
            CHECK (status IN ('PENDING', 'PROCESSING', 'NEEDS_REVIEW', 'ERROR', 'COMPLETED'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_processings
            ADD CONSTRAINT ai_processings_dates_check
            CHECK (
                completed_at IS NULL
                OR started_at IS NULL
                OR completed_at >= started_at
            )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_processings');
    }
};
