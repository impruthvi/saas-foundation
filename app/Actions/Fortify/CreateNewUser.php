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
     * Validate and create a newly registered user.
     *
     * The personal organization is created in the same transaction so a
     * partially completed registration cannot leave an account owning nothing.
     *
     * Invitation acceptance runs outside the transaction and may fail. An
     * invitation that lapsed while the form was being filled in must not cost
     * somebody their account — they can ask for a new one; they cannot ask for
     * their registration back.
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

        // The invitation is accepted before Fortify signs the new user in, so the
        // request still looks like a guest's. The actor is known here, though.
        AuditActor::runAs(
            AuditActor::user($user),
            fn (): ?Invitation => $this->pendingInvitations->handle(session()->driver(), $user),
        );

        return $user;
    }
}
