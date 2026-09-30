<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds no account: one needs the organization that only registration or saas:demo
 * creates, and a factory user would be refused on every tenant-scoped screen.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->command->info('Nothing to seed. Run php artisan saas:demo for a demo organization.');
    }
}
