<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SeedDemoJourney;
use App\Exceptions\DemoRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Seed the ten-minute journey up to the Free plan limit')]
#[Signature('saas:demo {--fresh : Rebuild the database before seeding}')]
final class SaasDemoCommand extends Command
{
    public function handle(SeedDemoJourney $demo): int
    {
        try {
            SeedDemoJourney::refuseOutsideLocal();

            if ($this->option('fresh')) {
                $this->call('migrate:fresh', ['--force' => true]);
            }

            $seeded = $demo->handle();
        } catch (DemoRefused $demoRefused) {
            $this->components->error($demoRefused->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The demo is ready. Sign in at '.url('/login').' with a password printed below; it is not shown again.');

        $this->table(['Who', 'Email', 'Password'], [
            ['Ada, owner', $seeded['owner']->email, $seeded['passwords']['owner']],
            ['Grace, member', $seeded['teammate']->email, $seeded['passwords']['teammate']],
        ]);

        $this->components->bulletList([
            'Run composer dev. It starts the server and the queue worker that applies a new plan.',
            'Run php artisan saas:stripe sk_test_YOUR_KEY to connect Stripe test mode.',
            "Sign in as Ada and open /projects: {$seeded['organization']->name} has used 2 of its 2 projects.",
            /* @chisel-admin-console */
            'Ada is an operator: open /admin to inspect the entitlement, the usage and the Stripe event that set it.',
            /* @end-chisel-admin-console */
        ]);

        return self::SUCCESS;
    }
}
