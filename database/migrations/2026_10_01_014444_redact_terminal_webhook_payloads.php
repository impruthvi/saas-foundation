<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('webhook_events')
            ->whereNotIn('outcome', ['unplaceable', 'errored'])
            ->orWhereNotNull('applied_at')
            ->update(['payload' => '[]']);
    }

    /** Payloads cannot be recovered after redaction. */
    public function down(): void {}
};
