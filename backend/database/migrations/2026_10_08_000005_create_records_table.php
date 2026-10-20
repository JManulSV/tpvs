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
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 20);
            $table->string('status', 20)->default('PENDING');
            $table->unsignedSmallInteger('week')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->date('week_start_date')->nullable();
            $table->date('week_end_date')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['year', 'week']);
            $table->index('week_end_date');
            $table->index('source');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE records
            ADD CONSTRAINT records_source_check
            CHECK (source IN ('IMAGE', 'MANUAL'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE records
            ADD CONSTRAINT records_status_check
            CHECK (status IN ('PENDING', 'PROCESSING', 'NEEDS_REVIEW', 'ERROR', 'CONFIRMED', 'CANCELED'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE records
            ADD CONSTRAINT records_week_check
            CHECK (week BETWEEN 1 AND 53)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE records
            ADD CONSTRAINT records_year_check
            CHECK (year BETWEEN 1 AND 9999)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE records
            ADD CONSTRAINT records_week_dates_check
            CHECK (week_end_date >= week_start_date)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
