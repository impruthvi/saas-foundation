<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\CreatePersonalOrganization;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final readonly class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(private CreatePersonalOrganization $personalOrganizations) {}

    /**
     * Validate and create a newly registered user.
     *
     * A user without an organization is a state this application does not have
     * (D1), so the organization is created in the same transaction rather than
     * by an event listener firing afterwards: a registration that half-succeeds
     * would leave an account that can log in and own nothing.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->personalOrganizations->handle($user);

            return $user;
        });
    }
}
