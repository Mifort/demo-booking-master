<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Запрещает обратные интервалы и пересечения броней одной комнаты.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_ends_after_start CHECK (ends_at > starts_at)');

        // btree_gist даёт оператор = для bigint внутри GiST.
        // Границы [) не считают пересечением стык 10:00–11:00 и 11:00–12:00.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<'SQL'
            ALTER TABLE bookings
            ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (
                room_id WITH =,
                tsrange(starts_at, ends_at, '[)') WITH &&
            )
        SQL);
    }

    /**
     * Удаляет добавленные ограничения при откате миграции.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_overlap');
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_ends_after_start');
    }
};
