<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanyBankDetailsRequest;
use Illuminate\Http\RedirectResponse;

class CompanyBankDetailsController extends Controller
{
    public function update(UpdateCompanyBankDetailsRequest $request): RedirectResponse
    {
        $request->legalEntity()->update($request->validated());

        return to_route('onboarding')->with('success', 'Dados bancários guardados. Serão usados nos próximos documentos.');
    }
}
