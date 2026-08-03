<?php

namespace App\Http\Controllers;

use App\Actions\StageDataImport;
use App\DataImportRowStatus;
use App\DataImportSource;
use App\DataImportStatus;
use App\DataImportType;
use App\Http\Requests\StoreDataImportRequest;
use App\Imports\ImportSchema;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DataImportController extends Controller
{
    public function index(Request $request, ImportSchema $schema): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('viewAny', DataImport::class);
        Inertia::encryptHistory();

        $imports = DataImport::query()
            ->with('uploader:id,name')
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->latest('created_at')
            ->limit(25)
            ->get();
        $selectedPublicId = $request->string('import')->trim()->toString();
        $selected = $imports->firstWhere('public_id', $selectedPublicId)
            ?? $imports->first();
        $rows = $selected instanceof DataImport
            ? $selected->rows()
                ->orderByRaw(
                    'CASE WHEN status = ? THEN 0 ELSE 1 END',
                    [DataImportRowStatus::Invalid->value],
                )
                ->orderBy('row_number')
                ->limit(100)
                ->get()
            : collect();
        $user = $request->user();

        return Inertia::render('Imports/Index', [
            'company' => [
                'legal_name' => $legalEntity->legal_name,
                'tax_identification_number' => $legalEntity->tax_identification_number,
            ],
            'types' => collect(DataImportType::cases())->map(
                fn (DataImportType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'template_url' => route('imports.templates.show', $type),
                ],
            )->all(),
            'sources' => collect(DataImportSource::cases())->map(
                fn (DataImportSource $source): array => [
                    'value' => $source->value,
                    'label' => $source->label(),
                ],
            )->all(),
            'imports' => $imports->map(fn (DataImport $dataImport): array => $this->summaryProps($dataImport))->all(),
            'selected' => $selected instanceof DataImport
                ? [
                    ...$this->summaryProps($selected),
                    'headers' => $selected->headers ?? [],
                    'column_mapping' => $selected->column_mapping ?? [],
                    'sha256' => $selected->sha256,
                    'failure_code' => $selected->failure_code,
                    'failure_message' => $selected->failure_message,
                    'mapped_at' => $selected->mapped_at?->toIso8601String(),
                    'validated_at' => $selected->validated_at?->toIso8601String(),
                    'committed_at' => $selected->committed_at?->toIso8601String(),
                    'rows' => $rows->map(fn (DataImportRow $row): array => $this->rowProps($row, $selected))->all(),
                    'visible_row_limit' => 100,
                    'mapping_fields' => $schema->fields($selected->type),
                    'permissions' => [
                        'map' => $user instanceof User && $user->can('update', $selected),
                        'commit' => $user instanceof User
                            && $user->can('update', $selected)
                            && $selected->status === DataImportStatus::Ready,
                        'cancel' => $user instanceof User && $user->can('delete', $selected),
                    ],
                ]
                : null,
            'permissions' => [
                'create' => $user instanceof User && $user->can('create', DataImport::class),
            ],
            'guardrails' => [
                'maximum_file_size_mb' => 25,
                'maximum_rows' => StageDataImport::MaximumRows,
                'accepted_extensions' => ['.xlsx', '.xls', '.csv'],
                'source_files_private' => true,
                'source_deleted_after_commit' => true,
                'mfa_enabled' => $user?->hasEnabledTwoFactorAuthentication() === true,
                'saft_available' => false,
            ],
        ]);
    }

    public function store(
        StoreDataImportRequest $request,
        StageDataImport $stageDataImport,
    ): RedirectResponse {
        $legalEntity = $request->currentLegalEntity();
        $workspace = $request->attributes->get('currentWorkspace');
        $user = $request->user();

        abort_unless(
            $legalEntity instanceof LegalEntity
                && $workspace instanceof Workspace
                && $user instanceof User,
            404,
        );

        $file = $request->importFile();
        $realPath = $file->getRealPath();
        $sha256 = is_string($realPath) ? hash_file('sha256', $realPath) : false;

        if (! is_string($sha256)) {
            return back()->with('error', 'Não foi possível verificar a integridade do ficheiro.');
        }

        $storagePath = $file->store(
            "imports/{$workspace->public_id}/{$legalEntity->public_id}",
            'local',
        );

        if (! is_string($storagePath)) {
            return back()->with('error', 'Não foi possível guardar o ficheiro em armazenamento privado.');
        }

        $originalName = preg_replace(
            '/[\x00-\x1F\x7F]/u',
            '',
            basename(str_replace('\\', '/', $file->getClientOriginalName())),
        ) ?? 'importacao';
        $dataImport = null;

        try {
            $dataImport = DataImport::query()->create([
                'workspace_id' => $workspace->id,
                'legal_entity_id' => $legalEntity->id,
                'uploaded_by_user_id' => $user->id,
                'type' => $request->importType(),
                'source' => $request->importSource(),
                'status' => DataImportStatus::AwaitingMapping,
                'original_name' => Str::limit($originalName, 255, ''),
                'storage_disk' => 'local',
                'storage_path' => $storagePath,
                'file_extension' => mb_strtolower($file->getClientOriginalExtension()),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'file_size' => max(0, (int) $file->getSize()),
                'sha256' => $sha256,
            ]);
            $stageDataImport->execute($dataImport);
        } catch (DomainException $exception) {
            return redirect()
                ->route('imports.index', $dataImport instanceof DataImport
                    ? ['import' => $dataImport->public_id]
                    : [])
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            if (! $dataImport instanceof DataImport) {
                Storage::disk('local')->delete($storagePath);
            }

            return redirect()
                ->route('imports.index', $dataImport instanceof DataImport
                    ? ['import' => $dataImport->public_id]
                    : [])
                ->with('error', 'Não foi possível preparar a importação. Confirme o ficheiro e tente novamente.');
        }

        return redirect()
            ->route('imports.index', ['import' => $dataImport->public_id])
            ->with('success', 'Ficheiro protegido e analisado. Confirme agora a correspondência das colunas.');
    }

    /** @return array<string, mixed> */
    private function summaryProps(DataImport $dataImport): array
    {
        return [
            'public_id' => $dataImport->public_id,
            'type' => $dataImport->type->value,
            'type_label' => $dataImport->type->label(),
            'source' => $dataImport->source->value,
            'source_label' => $dataImport->source->label(),
            'status' => $dataImport->status->value,
            'status_label' => $dataImport->status->label(),
            'status_tone' => $this->statusTone($dataImport->status),
            'original_name' => $dataImport->original_name,
            'file_extension' => $dataImport->file_extension,
            'file_size' => $dataImport->file_size,
            'total_rows' => $dataImport->total_rows,
            'valid_rows' => $dataImport->valid_rows,
            'invalid_rows' => $dataImport->invalid_rows,
            'imported_rows' => $dataImport->imported_rows,
            'created_rows' => $dataImport->created_rows,
            'updated_rows' => $dataImport->updated_rows,
            'uploaded_by' => $dataImport->uploader?->name,
            'created_at' => $dataImport->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function rowProps(DataImportRow $row, DataImport $dataImport): array
    {
        $sourceValues = collect($dataImport->headers ?? [])
            ->mapWithKeys(function (string $header) use ($row): array {
                $value = $row->source_payload[$header] ?? null;

                return [$header => is_bool($value)
                    ? ($value ? 'Sim' : 'Não')
                    : Str::limit((string) ($value ?? ''), 160, '…')];
            })
            ->all();

        return [
            'row_number' => $row->row_number,
            'status' => $row->status->value,
            'status_label' => match ($row->status) {
                DataImportRowStatus::Pending => 'Por validar',
                DataImportRowStatus::Valid => 'Válida',
                DataImportRowStatus::Invalid => 'Com erros',
                DataImportRowStatus::Imported => 'Importada',
            },
            'source_values' => $sourceValues,
            'validation_errors' => $row->validation_errors ?? [],
        ];
    }

    private function statusTone(DataImportStatus $status): string
    {
        return match ($status) {
            DataImportStatus::Ready => 'info',
            DataImportStatus::Completed => 'success',
            DataImportStatus::AwaitingMapping, DataImportStatus::Importing => 'warning',
            DataImportStatus::HasErrors, DataImportStatus::Failed => 'danger',
            DataImportStatus::Cancelled => 'neutral',
        };
    }

    private function legalEntity(Request $request): ?LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $workspace->legalEntities()->oldest('id')->first();
    }
}
