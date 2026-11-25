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
        Schema::create('record_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->string('affiliation')->nullable();
            $table->string('bank')->nullable();
            $table->decimal('income', 12, 2)->nullable();
            $table->unsignedInteger('order');
            $table->timestamps();

            $table->unique(['record_id', 'order']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE record_details
            ADD CONSTRAINT record_details_income_check
            CHECK (income >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE record_details
            ADD CONSTRAINT record_details_order_check
            CHECK ("order" > 0)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_details');
    }
};
