<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GrantOperator;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Description('Let a person into the admin console')]
#[Signature('operators:grant {email : The account to let into the admin console} {--reason= : Why they need it}')]
final class GrantOperatorCommand extends Command
{
    public function handle(GrantOperator $grant): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user instanceof User) {
            $this->components->error('No account uses that email address.');

            return self::FAILURE;
        }

        try {
            $grant->handle($user, (string) $this->option('reason'));
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->components->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$user->email} can now use the admin console.");

        return self::SUCCESS;
    }
}
