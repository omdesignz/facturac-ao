<?php

namespace App\Actions\Fortify;

use App\Actions\CreateWorkspaceForUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private readonly CreateWorkspaceForUser $createWorkspaceForUser) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $input['email'] = Str::lower(trim($input['email']));

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'workspace_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::query()->create([
                'name' => trim($input['name']),
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            $this->createWorkspaceForUser->execute($user, $input['workspace_name']);

            return $user;
        });
    }
}
