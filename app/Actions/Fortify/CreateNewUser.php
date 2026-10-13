<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // role e is_active no son fillable: nadie se los asigna desde el formulario
        $isAdmin = strcasecmp($input['email'], config('docbrainer.admin_email')) === 0;
        $user->role = $isAdmin ? User::ROLE_ADMIN : User::ROLE_USER;
        $user->is_active = $isAdmin;
        $user->save();

        return $user;
    }
}
