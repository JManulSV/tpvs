<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $conflicts = DB::select(<<<'SQL'
            SELECT
                LOWER(BTRIM(name)) AS normalized_name,
                STRING_AGG(id::text, ', ' ORDER BY id) AS company_ids,
                STRING_AGG(name, ', ' ORDER BY id) AS company_names
            FROM companies
            GROUP BY LOWER(BTRIM(name))
            HAVING COUNT(*) > 1
            ORDER BY LOWER(BTRIM(name))
        SQL);

        if ($conflicts !== []) {
            $details = array_map(
                static fn (object $conflict): string => sprintf(
                    'normalized name "%s" has company IDs [%s] and names [%s]',
                    $conflict->normalized_name,
                    $conflict->company_ids,
                    $conflict->company_names,
                ),
                $conflicts,
            );

            throw new RuntimeException(
                'Cannot enforce normalized company name uniqueness. Resolve these existing conflicts manually without merging records: '.implode('; ', $details),
            );
        }

        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_name_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX companies_normalized_name_unique
            ON companies (LOWER(BTRIM(name)))
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX companies_normalized_name_unique');
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_name_unique UNIQUE (name)');
    }
};
