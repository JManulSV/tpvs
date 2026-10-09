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
        Schema::create('delivery_cash_denominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 10, 2);
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['delivery_id', 'value']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE delivery_cash_denominations
            ADD CONSTRAINT delivery_cash_denominations_value_check
            CHECK (value IN (1000.00, 500.00, 200.00, 100.00, 50.00, 20.00, 10.00, 5.00, 2.00, 1.00, 0.50))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE delivery_cash_denominations
            ADD CONSTRAINT delivery_cash_denominations_quantity_check
            CHECK (quantity > 0)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_cash_denominations');
    }
};
