<?php

namespace App\Http\Controllers;

use App\Actions\FileComplaint;
use App\ComplaintCategory;
use App\Http\Requests\StoreComplaintRequest;
use App\Models\Complaint;
use App\Models\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Help and Complaints, as the customer sees it: how to reach us, and the form
 * that puts an entry in the complaints book.
 */
class HelpController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = PlatformSetting::values();
        $user = $request->user();

        return Inertia::render('Help/Index', [
            'channels' => [
                'support_email' => $settings['support_email'] ?? '',
                'support_phone' => $settings['support_phone'] ?? '',
                'support_whatsapp' => $settings['support_whatsapp'] ?? '',
                'support_hours' => $settings['support_hours'] ?? '',
                'complaints_email' => $settings['complaints_email'] ?? '',
                'response_days' => (int) ($settings['complaints_response_days'] ?? 5),
                'privacy_email' => $settings['data_protection_email'] ?? '',
            ],
            'consumerAuthority' => [
                'name' => (string) config('platform.consumer_authority.name'),
                'url' => (string) config('platform.consumer_authority.url'),
                'phone' => (string) config('platform.consumer_authority.phone'),
            ],
            'categories' => array_map(
                fn (ComplaintCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ],
                ComplaintCategory::cases(),
            ),
            'contact' => [
                'name' => $user->name ?? '',
                'email' => $user->email ?? '',
            ],
            'complaints' => $this->ownComplaints($request),
            'topics' => $this->topics(),
        ]);
    }

    public function store(
        StoreComplaintRequest $request,
        FileComplaint $fileComplaint,
    ): RedirectResponse {
        $complaint = $fileComplaint->execute(
            $request,
            $request->user(),
            [
                'category' => (string) $request->validated('category'),
                'subject' => (string) $request->validated('subject'),
                'body' => (string) $request->validated('body'),
                'contact_name' => (string) $request->validated('contact_name'),
                'contact_email' => (string) $request->validated('contact_email'),
                'contact_phone' => $request->validated('contact_phone'),
            ],
        );

        return back()->with(
            'success',
            "Reclamação registada com a referência {$complaint->reference}. Enviámos a confirmação por email.",
        );
    }

    /**
     * The complaints this account has filed, so someone can check where theirs
     * got to without having to ask.
     *
     * @return list<array<string, mixed>>
     */
    private function ownComplaints(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return array_values(Complaint::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn (Complaint $complaint): array => [
                'reference' => $complaint->reference,
                'subject' => $complaint->subject,
                'category_label' => $complaint->category->label(),
                'status' => $complaint->status->value,
                'status_label' => $complaint->status->label(),
                'resolution' => $complaint->resolution,
                'created_at' => $complaint->created_at?->toIso8601String(),
                'response_due_at' => $complaint->response_due_at->toIso8601String(),
                'resolved_at' => $complaint->resolved_at?->toIso8601String(),
                'overdue' => $complaint->isOverdue(),
            ])
            ->all());
    }

    /**
     * The questions support actually gets asked, answered inline so the common
     * case never becomes a ticket.
     *
     * @return list<array{question: string, answer: string}>
     */
    private function topics(): array
    {
        return [
            [
                'question' => 'Emiti uma factura com um erro. Como corrijo?',
                'answer' => 'Um documento comunicado à AGT não pode ser alterado nem anulado. Emita uma nota de crédito a referenciar a factura errada e, se for caso disso, emita a factura correcta a seguir.',
            ],
            [
                'question' => 'A factura ficou "por comunicar". O que faço?',
                'answer' => 'O documento é válido e fica em fila. Tentamos de novo automaticamente. Se passar de algumas horas, verifique a Ligação AGT — normalmente é a chave da série que expirou.',
            ],
            [
                'question' => 'Como exporto os meus dados?',
                'answer' => 'Os seus documentos, clientes e artigos são seus e podem ser exportados a qualquer momento. Peça-nos por esta página se precisar de um formato específico para o seu contabilista.',
            ],
            [
                'question' => 'Quem consegue ver os meus dados?',
                'answer' => 'Só quem tiver acesso ao seu espaço de trabalho. A nossa equipa de apoio pode entrar na sua conta para diagnosticar problemas, mas só com motivo registado, por tempo limitado, e é sempre avisado por email quando acontece.',
            ],
            [
                'question' => 'Esqueci-me da palavra-passe e perdi o telemóvel da autenticação.',
                'answer' => 'Use um código de recuperação, guardado quando activou a autenticação de dois factores. Sem esse código, e por segurança, o processo de reposição exige confirmação da titularidade da empresa.',
            ],
        ];
    }
}
