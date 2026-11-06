<?php

namespace App\Http\Controllers;

use App\Actions\UpdateCollectionFollowUp;
use App\Http\Requests\UpdateCollectionFollowUpRequest;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;

class CustomerFollowUpController extends Controller
{
    public function update(UpdateCollectionFollowUpRequest $request, Customer $customer, UpdateCollectionFollowUp $update): RedirectResponse
    {
        $update->execute($customer, $request->user(), $request->followUp());

        return redirect()->route('customers.show', $customer)->with('success', 'Seguimento de cobrança guardado.');
    }
}
