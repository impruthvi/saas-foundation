<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ProvisionStripeCatalog;
use App\Billing\PlanCatalog;
use App\Billing\StripeSecret;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Process;
use Laravel\Cashier\Cashier;
use RuntimeException;
use Stripe\Exception\ApiErrorException;

#[Description('Connect Stripe test mode: provision paid catalog prices and write the keys to .env')]
#[Signature('saas:stripe {key : A Stripe test secret key (sk_test_...)}')]
final class SaasStripeCommand extends Command
{
    public function handle(ProvisionStripeCatalog $provision, PlanCatalog $catalog): int
    {
        $key = (string) $this->argument('key');

        if (! StripeSecret::isTestKey($key)) {
            $this->components->error('Use a Stripe test secret key (sk_test_ or rk_test_). Live and publishable keys are refused.');

            return self::FAILURE;
        }

        $stripe = Cashier::stripe(['api_key' => $key]);
        $variables = ['STRIPE_SECRET' => $key];
        $priceCount = 0;

        foreach ($catalog->plans() as $plan) {
            foreach ($plan->prices as $price) {
                if ($price->amount === 0) {
                    continue;
                }

                try {
                    $variables[$price->environmentVariable()] = $provision->handle($stripe, $plan, $price);
                    $priceCount++;
                } catch (ApiErrorException $apiErrorException) {
                    $this->components->error("Stripe refused price [{$price->key}]: {$apiErrorException->getMessage()}");

                    return self::FAILURE;
                }
            }
        }

        $webhookSecret = $this->webhookSecret($key);

        if ($webhookSecret !== null) {
            $variables['STRIPE_WEBHOOK_SECRET'] = $webhookSecret;
        }

        try {
            Env::writeVariables($variables, $this->laravel->environmentFilePath(), overwrite: true);
        } catch (RuntimeException $runtimeException) {
            $this->components->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Stripe test mode is connected. {$priceCount} paid prices are configured.");

        if ($webhookSecret === null) {
            $this->components->warn('Setup is incomplete: webhooks cannot reach this app yet. Install the Stripe CLI (https://docs.stripe.com/stripe-cli) and run this command again.');
        } else {
            $this->components->info('composer dev now forwards Stripe webhooks to this app.');
        }

        if ($this->laravel->configurationIsCached()) {
            $this->components->warn('Configuration is cached. Run php artisan config:clear so the new keys are read.');
        }

        return self::SUCCESS;
    }

    /**
     * The CLI's signing secret is fixed per account, so the one printed here matches the
     * listener composer dev starts. Its exit code is unreliable, so the output is read.
     */
    private function webhookSecret(string $key): ?string
    {
        $output = Process::timeout(30)->run(['stripe', 'listen', '--api-key', $key, '--print-secret'])->output();

        return preg_match('/whsec_[A-Za-z0-9]+/', $output, $matches) === 1 ? $matches[0] : null;
    }
}
