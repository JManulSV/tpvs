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
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('delivery_date');
            $table->string('delivered_by_name');
            $table->string('received_by_name')->nullable();
            $table->decimal('prepared_return_amount', 12, 2);
            $table->decimal('requested_cash_amount', 12, 2);
            $table->decimal('cash_commission_percentage', 5, 2);
            $table->decimal('cash_commission', 12, 2);
            $table->decimal('cash_amount', 12, 2);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT deliveries_delivered_by_name_check
            CHECK (length(btrim(delivered_by_name)) > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT deliveries_received_by_name_check
            CHECK (
                received_by_name IS NULL
                OR length(btrim(received_by_name)) > 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT deliveries_prepared_return_amount_check
            CHECK (prepared_return_amount >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT deliveries_requested_cash_amount_check
            CHECK (
                requested_cash_amount >= 0
                AND requested_cash_amount <= prepared_return_amount
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT deliveries_cash_commission_percentage_check
            CHECK (cash_commission_percentage >= 0 AND cash_commission_percentage <= 100)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT deliveries_cash_commission_check
            CHECK (cash_commission >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE deliveries
            ADD CONSTRAINT deliveries_cash_amount_check
            CHECK (cash_amount >= 0)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
