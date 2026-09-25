<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RevokeOperator;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Take a person out of the admin console, ending anything they are doing as another user')]
#[Signature('operators:revoke {email : The account to remove from the admin console}')]
final class RevokeOperatorCommand extends Command
{
    public function handle(RevokeOperator $revoke): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user instanceof User || ! $revoke->handle($user)) {
            $this->components->error('That address is not an operator.');

            return self::FAILURE;
        }

        $this->components->info("{$user->email} can no longer use the admin console.");

        return self::SUCCESS;
    }
}
