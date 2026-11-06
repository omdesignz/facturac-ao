<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateCollectionFollowUp
{
    /** @param array{revision: int, next_contact_on: string|null, promised_payment_on: string|null, note: string|null, completed: bool} $input */
    public function execute(Customer $customer, User $user, array $input): Customer
    {
        return DB::transaction(function () use ($customer, $user, $input): Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            Gate::forUser($user)->authorize('update', $locked);
            $same = $locked->collection_next_contact_on?->toDateString() === $input['next_contact_on']
                && $locked->collection_promised_payment_on?->toDateString() === $input['promised_payment_on']
                && $locked->collection_note === $input['note']
                && ($locked->collection_completed_at !== null) === $input['completed'];
            if ($same) {
                return $locked;
            }
            if ($locked->collection_revision !== $input['revision']) {
                throw ValidationException::withMessages(['revision' => 'Este seguimento foi alterado por outra pessoa. Actualize e reveja antes de guardar.']);
            }
            $locked->forceFill([
                'collection_next_contact_on' => $input['next_contact_on'],
                'collection_promised_payment_on' => $input['promised_payment_on'],
                'collection_note' => $input['note'],
                'collection_completed_at' => $input['completed'] ? now() : null,
                'collection_revision' => $locked->collection_revision + 1,
                'collection_updated_by' => $user->id,
                'collection_updated_at' => now(),
            ])->save();
            activity('collection')->performedOn($locked)->causedBy($user)
                ->event($input['completed'] ? 'follow-up-completed' : 'follow-up-updated')
                ->withProperties(['revision' => $locked->collection_revision, 'next_contact_on' => $input['next_contact_on'], 'promised_payment_on' => $input['promised_payment_on']])
                ->log('collection follow-up updated');

            return $locked;
        });
    }
}
