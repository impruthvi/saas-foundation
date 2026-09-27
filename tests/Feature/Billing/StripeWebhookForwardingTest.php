<?php

declare(strict_types=1);

use App\Billing\StripeWebhookForwarding;
use Illuminate\Foundation\DevCommands;

function withStripeCliOnPath(Closure $work): void
{
    $directory = sys_get_temp_dir().'/stripe-cli-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents($directory.'/stripe', "#!/bin/sh\n");
    chmod($directory.'/stripe', 0755);
    $path = (string) getenv('PATH');
    putenv("PATH={$directory}".PATH_SEPARATOR.$path);

    try {
        $work();
    } finally {
        putenv("PATH={$path}");
    }
}

/** @return list<string> */
function registeredDevCommands(): array
{
    return array_column(DevCommands::commands(), 'command');
}

it('adds stripe listen to composer dev for a test key when the CLI is installed', function (): void {
    config(['app.url' => 'http://saas.test', 'cashier.secret' => 'sk_test_forwarding']);

    withStripeCliOnPath(StripeWebhookForwarding::registerDevCommand(...));

    expect(registeredDevCommands())
        ->toContain("stripe listen --api-key 'sk_test_forwarding' --forward-to 'http://saas.test/stripe/webhook'");
});

it('never forwards webhooks with a live key', function (): void {
    config(['cashier.secret' => 'sk_live_forwarding']);

    withStripeCliOnPath(StripeWebhookForwarding::registerDevCommand(...));

    expect(implode("\n", registeredDevCommands()))->not->toContain('sk_live_forwarding');
});
