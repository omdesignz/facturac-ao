<?php

namespace App\Http\Controllers;

use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The company's mark, as it appears at the head of its documents.
 *
 * Kept on the private disk and served through this controller rather than from
 * a public path. It is not a secret, but a public URL is one more thing that
 * outlives the account it belongs to.
 */
class CompanyLogoController extends Controller
{
    /** Where logos live, relative to the local disk. */
    public const DIRECTORY = 'logos';

    public function store(Request $request): RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);
        Gate::authorize('update', $legalEntity);

        $request->validate([
            /*
             * PNG or JPEG, and deliberately not SVG: mPDF rasterises what it
             * is handed, and an SVG is a document that can carry script and
             * remote references — not something to accept and then render.
             */
            'logo' => [
                'required',
                'image',
                'mimes:png,jpg,jpeg',
                'max:2048',
                'dimensions:max_width=2000,max_height=2000',
            ],
        ], [
            'logo.mimes' => 'Use um ficheiro PNG ou JPEG.',
            'logo.max' => 'O logótipo não pode passar de 2 MB.',
            'logo.dimensions' => 'A imagem não pode passar de 2000×2000 pixéis.',
        ]);

        $this->forget($legalEntity);

        $path = $request->file('logo')->store(self::DIRECTORY, 'local');

        $legalEntity->forceFill(['logo_path' => $path])->save();

        activity('legal-entity')
            ->causedBy($request->user())
            ->performedOn($legalEntity)
            ->log('company logo updated');

        return back()->with('success', 'Logótipo actualizado. Passa a aparecer nos documentos emitidos.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);
        Gate::authorize('update', $legalEntity);

        $this->forget($legalEntity);
        $legalEntity->forceFill(['logo_path' => null])->save();

        return back()->with('success', 'Logótipo removido. Os documentos passam a sair só com o nome da empresa.');
    }

    /** Serves the stored file so the app can show what is on the documents. */
    public function show(Request $request): StreamedResponse
    {
        $legalEntity = $this->legalEntity($request);
        Gate::authorize('view', $legalEntity);

        abort_if($legalEntity->logo_path === null, 404);
        abort_unless(Storage::disk('local')->exists($legalEntity->logo_path), 404);

        return Storage::disk('local')->response($legalEntity->logo_path);
    }

    private function forget(LegalEntity $legalEntity): void
    {
        if ($legalEntity->logo_path !== null) {
            Storage::disk('local')->delete($legalEntity->logo_path);
        }
    }

    private function legalEntity(Request $request): LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace, 404);

        $legalEntity = $workspace->legalEntities()->oldest('id')->first();
        abort_unless($legalEntity instanceof LegalEntity, 404);

        return $legalEntity;
    }
}
