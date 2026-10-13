<?php

namespace App\Http\Controllers;

use App\Fiscal\Agt\PreparedTaxpayerAssociation;
use App\Http\Requests\StoreTaxpayerAssociationRequest;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class TaxpayerAssociationController extends Controller
{
    public function __invoke(StoreTaxpayerAssociationRequest $request, PreparedTaxpayerAssociation $association): RedirectResponse
    {
        $entity = $request->currentLegalEntity();
        $user = $request->user();
        abort_unless($entity instanceof LegalEntity && $user instanceof User, 404);
        $association->associate($entity, $user);

        return to_route('agt.connection.show')->with('success', 'Acesso associado à empresa em ambiente de testes. Verifique agora a ligação; ainda não foi emitida qualquer factura.');
    }
}
