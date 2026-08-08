<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The only way to appoint support staff.
 *
 * Deliberately not exposed in the interface: the ability to enter customer
 * accounts should be granted by someone with server access, and leave a trace
 * in the deployment history rather than in a form submission.
 */
#[Signature('support:staff {email} {--revoke : Remove support access instead of granting it}')]
#[Description('Grant or revoke permission to impersonate customers for troubleshooting')]
class ManageSupportStaff extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error("Não existe nenhuma conta com o email {$email}.");

            return self::FAILURE;
        }

        $granting = ! $this->option('revoke');

        if ($user->is_support_staff === $granting) {
            $this->components->info($granting
                ? "{$email} já tem acesso de apoio."
                : "{$email} já não tinha acesso de apoio.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_support_staff' => $granting])->save();

        activity('security')
            ->event($granting ? 'support-access-granted' : 'support-access-revoked')
            ->performedOn($user)
            ->log($granting
                ? 'support access granted'
                : 'support access revoked');

        $this->components->info($granting
            ? "{$email} passa a poder diagnosticar contas de clientes."
            : "{$email} deixa de poder diagnosticar contas de clientes.");

        return self::SUCCESS;
    }
}
