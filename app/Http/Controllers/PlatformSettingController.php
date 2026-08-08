<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets support staff change the contact details customers are shown, without a
 * deploy.
 */
class PlatformSettingController extends Controller
{
    /**
     * Longer free text that would be cramped in a single-line input.
     *
     * @var list<string>
     */
    private const MULTILINE = ['company_address', 'support_hours'];

    public function edit(): Response
    {
        return Inertia::render('Support/Settings', [
            'settings' => PlatformSetting::values(),
            'fields' => $this->fields(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var array<string, string> $defaults */
        $defaults = config('platform.settings', []);

        $rules = [];

        foreach (array_keys($defaults) as $key) {
            $rules[$key] = match (true) {
                str_ends_with($key, '_email') => ['required', 'email', 'max:255'],
                $key === 'complaints_response_days' => ['required', 'integer', 'min:1', 'max:30'],
                default => ['required', 'string', 'max:500'],
            };
        }

        /** @var array<string, string> $validated */
        $validated = array_map(
            fn (mixed $value): string => (string) $value,
            $request->validate($rules),
        );

        PlatformSetting::put($validated, $request->user());

        return back()->with('success', 'Definições actualizadas.');
    }

    /**
     * @return list<array{key: string, label: string, help: string, multiline: bool}>
     */
    private function fields(): array
    {
        $labels = [
            'support_email' => ['Email de apoio', 'Mostrado na página de Ajuda e nos documentos legais.'],
            'support_phone' => ['Telefone de apoio', 'Número que aparece como ligação directa.'],
            'support_whatsapp' => ['WhatsApp', 'Usado para gerar a ligação wa.me.'],
            'support_hours' => ['Horário de atendimento', 'Texto livre, mostrado por baixo dos canais.'],
            'complaints_email' => ['Email de reclamações', 'Para onde o cliente escreve se preferir email.'],
            'complaints_response_days' => ['Prazo de resposta (dias úteis)', 'O prazo que assumimos publicamente para responder.'],
            'company_legal_name' => ['Denominação social', 'Aparece nos documentos legais.'],
            'company_nif' => ['NIF', 'Contribuinte da empresa que explora o serviço.'],
            'company_address' => ['Morada', 'Sede, para efeitos legais.'],
            'data_protection_email' => ['Email de privacidade', 'Contacto para exercício de direitos sobre dados pessoais.'],
        ];

        /** @var array<string, string> $defaults */
        $defaults = config('platform.settings', []);

        return array_map(
            fn (string $key): array => [
                'key' => $key,
                'label' => $labels[$key][0] ?? $key,
                'help' => $labels[$key][1] ?? '',
                'multiline' => in_array($key, self::MULTILINE, true),
            ],
            array_keys($defaults),
        );
    }
}
