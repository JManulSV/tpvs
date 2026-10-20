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
            $table->string('operation_number')->nullable();
            $table->string('affiliation')->nullable();
            $table->string('branch')->nullable();
            $table->decimal('income', 12, 2)->nullable();
            $table->decimal('commission_percentage', 5, 2)->nullable();
            $table->decimal('commission', 12, 2)->nullable();
            $table->decimal('return_amount', 12, 2)->nullable();
            $table->unsignedInteger('order');
            $table->timestamps();

            $table->unique(['record_id', 'order']);
            $table->index('operation_number');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE record_details
            ADD CONSTRAINT record_details_income_check
            CHECK (income >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE record_details
            ADD CONSTRAINT record_details_commission_percentage_check
            CHECK (
                commission_percentage IS NULL
                OR commission_percentage BETWEEN 0 AND 100
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE record_details
            ADD CONSTRAINT record_details_commission_check
            CHECK (commission >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE record_details
            ADD CONSTRAINT record_details_return_amount_check
            CHECK (return_amount >= 0)
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
