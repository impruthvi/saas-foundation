<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\ConsumePendingInvitation;
use App\Actions\CreatePersonalOrganization;
use App\Audit\AuditActor;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final readonly class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(
        private CreatePersonalOrganization $personalOrganizations,
        private ConsumePendingInvitation $pendingInvitations,
    ) {}

    /**
     * Invitation acceptance runs outside the transaction: an invitation that lapsed
     * mid-form must not cost somebody their account.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = DB::transaction(function () use ($input): User {
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->personalOrganizations->handle($user);

            return $user;
        });

        // The invitation is accepted before Fortify signs the user in, so the actor is
        // set explicitly.
        AuditActor::runAs(
            AuditActor::user($user),
            fn (): ?Invitation => $this->pendingInvitations->handle(session()->driver(), $user),
        );

        return $user;
    }
}
