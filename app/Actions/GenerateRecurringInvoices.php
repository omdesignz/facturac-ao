<?php

namespace App\Actions;

use App\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Notifications\RecurringInvoiceGenerated;
use App\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Raises the documents that standing arrangements are due to produce.
 *
 * Catches up rather than skipping: if the scheduler was down for a week, a
 * weekly profile still produces the document it owed for each missed period,
 * dated to that period. Billing a customer once for four weeks of service is a
 * different arrangement from the one they agreed to.
 */
class GenerateRecurringInvoices
{
    /** A ceiling on catch-up, so a badly dated profile cannot flood the ledger. */
    private const MAX_CATCH_UP = 24;

    public function __construct(private ConvertRecurringProfile $convert) {}

    /**
     * @return array{generated: int, profiles: int, failed: int}
     */
    public function execute(?CarbonImmutable $on = null): array
    {
        return $this->generate($on);
    }

    /** @return array{generated: int, profiles: int, failed: int} */
    public function executeFor(LegalEntity $legalEntity, User $actor, ?CarbonImmutable $on = null): array
    {
        abort_unless($actor->hasVerifiedEmail() && WorkspaceMembership::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->whereIn('role', [WorkspaceRole::Owner, WorkspaceRole::Administrator, WorkspaceRole::Accountant, WorkspaceRole::Billing])
            ->exists(), 403);

        return $this->generate($on, $legalEntity);
    }

    /** @return array{generated: int, profiles: int, failed: int} */
    private function generate(?CarbonImmutable $on, ?LegalEntity $legalEntity = null): array
    {
        /*
         * Parsed from the Luanda calendar date rather than taken as an instant.
         * `now('Africa/Luanda')->startOfDay()` is 23:00 UTC the day before, so
         * comparing it against a date column parsed in the app timezone makes
         * today look like tomorrow and nothing ever runs.
         */
        $today = $on ?? CarbonImmutable::parse(
            CarbonImmutable::now('Africa/Luanda')->toDateString(),
        );

        $generated = 0;
        $profiles = 0;
        $failed = 0;

        RecurringInvoice::query()
            ->when($legalEntity !== null, fn ($query) => $query
                ->where('workspace_id', $legalEntity->workspace_id)
                ->where('legal_entity_id', $legalEntity->id))
            ->due($today)
            ->with(['customer', 'establishment', 'legalEntity'])
            ->chunkById(50, function ($due) use ($today, &$generated, &$profiles, &$failed): void {
                foreach ($due as $profile) {
                    $profiles++;

                    try {
                        $generated += $this->runProfile($profile, $today);
                    } catch (Throwable $exception) {
                        // One broken profile must not stop the rest of the run.
                        $failed++;
                        report($exception);
                    }
                }
            });

        $this->closeFinishedProfiles($today, $legalEntity);

        return ['generated' => $generated, 'profiles' => $profiles, 'failed' => $failed];
    }

    /**
     * Stands down arrangements whose end date has passed.
     *
     * They fall outside the due scope the moment they end, so without this they
     * would sit in the list claiming to be active forever while quietly never
     * billing again — the sort of thing nobody notices until a customer asks
     * why they stopped being invoiced.
     */
    private function closeFinishedProfiles(CarbonImmutable $today, ?LegalEntity $legalEntity): void
    {
        RecurringInvoice::query()
            ->when($legalEntity !== null, fn ($query) => $query
                ->where('workspace_id', $legalEntity->workspace_id)
                ->where('legal_entity_id', $legalEntity->id))
            ->where('is_active', true)
            ->whereNotNull('ends_on')
            ->whereDate('ends_on', '<', $today)
            ->update(['is_active' => false]);
    }

    /** Produces every document this profile still owes, up to today. */
    private function runProfile(RecurringInvoice $profile, CarbonImmutable $today): int
    {
        $generated = 0;

        while ($generated < self::MAX_CATCH_UP) {
            $completed = DB::transaction(function () use ($profile, $today): bool {
                $locked = RecurringInvoice::query()->whereKey($profile->id)
                    ->where('workspace_id', $profile->workspace_id)->where('legal_entity_id', $profile->legal_entity_id)
                    ->lockForUpdate()->firstOrFail();
                $runOn = CarbonImmutable::parse($locked->next_run_on->toDateString());
                if (! $locked->is_active || $runOn->greaterThan($today)
                    || ($locked->ends_on !== null && $runOn->greaterThan($locked->ends_on))) {
                    return false;
                }
                $document = $this->convert->execute($locked, $runOn);
                $locked->forceFill([
                    'next_run_on' => $locked->frequency->next($runOn)->toDateString(),
                    'last_run_at' => now(), 'generated_count' => $locked->generated_count + 1,
                ])->save();
                activity('recurring-invoice')->performedOn($locked)->event('generated')
                    ->withProperties(['actor_kind' => 'scheduled', 'automation_id' => $locked->public_id,
                        'workspace_id' => $locked->workspace_id, 'legal_entity_id' => $locked->legal_entity_id,
                        'idempotency_reference' => $locked->public_id.':'.$runOn->toDateString(),
                        'document_public_id' => $document->public_id, 'run_on' => $runOn->toDateString(),
                        'issued' => $document->status !== FiscalDocumentStatus::Draft])
                    ->log('recurring document generated');
                DB::afterCommit(function () use ($locked, $document): void {
                    $workspace = $locked->workspace;
                    if ($workspace instanceof Workspace) {
                        RecurringInvoiceGenerated::fromDocument($locked, $document)->sendToWorkspace($workspace);
                    }
                });

                return true;
            }, 5);
            if (! $completed) {
                break;
            }
            $generated++;
        }

        return $generated;
    }

    /** Documents already raised by this profile, for the interface to show. */
    public function documentsFor(RecurringInvoice $profile): int
    {
        return FiscalDocument::query()
            ->where('legal_entity_id', $profile->legal_entity_id)
            ->where('customer_id', $profile->customer_id)
            ->count();
    }
}
