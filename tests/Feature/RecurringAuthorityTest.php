<?php

use App\Actions\ApproveRecurringInvoice;
use App\Actions\ConvertRecurringProfile;
use App\AgtEnvironment;
use App\Analytics\ReceivablesQuery;
use App\Fiscal\ExecutionContext;
use App\FiscalDocumentStatus;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\RecurringInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

function authorityProfile(): RecurringInvoice
{
    $user = User::factory()->withWorkspace()->create();
    $user->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);

    return RecurringInvoice::factory()->autoIssuing()->create([
        'workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id,
        'establishment_id' => Establishment::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id])->id,
        'customer_id' => Customer::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id])->id,
        'created_by_user_id' => $user->id,
    ]);
}

test('recurring approval binds exact configuration and renewal revokes prior authority', function () {
    $profile = authorityProfile();
    $approve = app(ApproveRecurringInvoice::class);
    $first = $approve->execute($profile, $profile->createdBy, AgtEnvironment::Homologation, CarbonImmutable::now()->addDays(1));
    $hash = ApproveRecurringInvoice::fingerprint($profile);
    $profile->update(['notes' => 'Material change']);
    expect($profile->configuration_revision)->toBe(2)->and(ApproveRecurringInvoice::fingerprint($profile))->not->toBe($hash);
    $second = $approve->execute($profile, $profile->createdBy, AgtEnvironment::Homologation, CarbonImmutable::now()->addDays(1));
    expect($second)->not->toBe($first)->and(DB::table('recurring_approvals')->where('id', $first)->value('revoked_at'))->not->toBeNull();
    $approve->revoke($profile, $profile->createdBy);
    expect(DB::table('recurring_approvals')->whereNull('revoked_at')->count())->toBe(0);
});

test('missing expired revoked and changed recurring grants leave a single recoverable draft', function (string $condition) {
    Notification::fake();
    $profile = authorityProfile();
    if ($condition !== 'missing') {
        $id = app(ApproveRecurringInvoice::class)->execute($profile, $profile->createdBy, AgtEnvironment::Homologation, CarbonImmutable::now()->addDay());
        if ($condition === 'expired') {
            DB::table('recurring_approvals')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
        } elseif ($condition === 'revoked') {
            app(ApproveRecurringInvoice::class)->revoke($profile, $profile->createdBy);
        } elseif ($condition === 'changed') {
            $profile->update(['notes' => 'Changed']);
        } elseif ($condition === 'customer-changed') {
            $profile->customer->update(['tax_identification_number' => '5412222222']);
        }
    }
    $action = app(ConvertRecurringProfile::class);
    $document = $action->execute($profile, CarbonImmutable::now());
    $replay = $action->execute($profile, CarbonImmutable::now());
    expect($document->status->value)->toBe('draft')->and($replay->id)->toBe($document->id)
        ->and(DB::table('recurring_occurrences')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'automatic-issuance-refused')->count())->toBe(1);
})->with(['missing', 'expired', 'revoked', 'changed', 'customer-changed']);

test('occurrence and document roll back together and retry produces exactly one result', function () {
    $profile = authorityProfile();
    $action = app(ConvertRecurringProfile::class);
    try {
        DB::transaction(function () use ($action, $profile): void {
            $action->execute($profile, CarbonImmutable::now());
            throw new RuntimeException('worker failure');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('worker failure');
    }
    expect(FiscalDocument::query()->count())->toBe(0)->and(DB::table('recurring_occurrences')->count())->toBe(0);
    $action->execute($profile, CarbonImmutable::now());
    expect(FiscalDocument::query()->count())->toBe(1)->and(DB::table('recurring_occurrences')->count())->toBe(1);
});

test('explicit execution rejects a foreign actor and impersonation regardless of browser context', function () {
    $profile = authorityProfile();
    $other = authorityProfile();
    expect(fn () => ExecutionContext::resolve($other->createdBy, $profile->legalEntity, AgtEnvironment::Homologation))->toThrow(HttpException::class);
    Context::add('impersonator_id', $other->created_by_user_id);
    expect(fn () => ExecutionContext::resolve($profile->createdBy, $profile->legalEntity, AgtEnvironment::Homologation))->toThrow(HttpException::class);
});

test('customer changes retain subject tenant and effective real actor attribution', function () {
    $profile = authorityProfile();
    $this->actingAs($profile->createdBy);
    Context::add(['workspace_id' => 999999, 'impersonator_id' => 123, 'impersonation_session' => 'support-session']);
    $profile->customer->update(['name' => 'Changed customer']);
    $entry = Activity::query()->where('log_name', 'customer')->where('event', 'updated')->latest('id')->firstOrFail();
    expect($entry->properties['workspace_id'])->toBe($profile->workspace_id)
        ->and($entry->properties['legal_entity_id'])->toBe($profile->legal_entity_id)
        ->and($entry->properties['real_actor_id'])->toBe(123)
        ->and($entry->properties['effective_actor_id'])->toBe($profile->created_by_user_id)
        ->and($entry->attribute_changes['attributes']['name'])->toBe('[redacted]');
});

test('company reporting explicitly excludes other native currencies without conversion', function () {
    $profile = authorityProfile();
    $attributes = ['workspace_id' => $profile->workspace_id, 'legal_entity_id' => $profile->legal_entity_id,
        'establishment_id' => $profile->establishment_id, 'status' => FiscalDocumentStatus::Issued];
    $aoa = FiscalDocument::factory()->create([...$attributes, 'currency_code' => 'AOA', 'gross_total_minor' => 10000]);
    $usd = FiscalDocument::factory()->create([...$attributes, 'currency_code' => 'USD', 'gross_total_minor' => 5000]);
    $query = app(ReceivablesQuery::class)->forLegalEntity($profile->legalEntity);
    expect($query->pluck('id')->all())->toBe([$aoa->id])
        ->and(app(ReceivablesQuery::class)->summarise($query)['billed_minor'])->toBe(10000)
        ->and($usd->fresh()->currency_code)->toBe('USD');
});

test('fiscal numbers belong to legal entities and remain unique within an entity', function () {
    $first = authorityProfile();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $first->workspace_id]);
    $establishment = Establishment::factory()->create(['workspace_id' => $first->workspace_id, 'legal_entity_id' => $entity->id]);
    $one = FiscalDocument::factory()->create(['workspace_id' => $first->workspace_id, 'legal_entity_id' => $first->legal_entity_id,
        'establishment_id' => $first->establishment_id, 'document_no' => 'FT COMMON/1']);
    FiscalDocument::factory()->create(['workspace_id' => $first->workspace_id, 'legal_entity_id' => $entity->id,
        'establishment_id' => $establishment->id, 'document_no' => 'FT COMMON/1']);
    expect(FiscalDocument::where('document_no', 'FT COMMON/1')->count())->toBe(2);
    expect(fn () => FiscalDocument::factory()->create(['workspace_id' => $first->workspace_id, 'legal_entity_id' => $first->legal_entity_id,
        'establishment_id' => $first->establishment_id, 'document_no' => $one->document_no]))->toThrow(QueryException::class);
});

test('customer mutation rolls back if its audit cannot persist', function () {
    $profile = authorityProfile();
    $this->actingAs($profile->createdBy)->withoutExceptionHandling();
    Activity::creating(function (Activity $activity): void {
        if ($activity->log_name === 'customer') {
            throw new RuntimeException('audit unavailable');
        }
    });
    try {
        $this->delete(route('customers.destroy', $profile->customer));
        $this->fail('The audit failure must propagate');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('audit unavailable');
    }
    expect($profile->customer->fresh()->is_active)->toBeTrue();
});

test('migration rollback refuses to discard recurring approval evidence', function () {
    $profile = authorityProfile();
    app(ApproveRecurringInvoice::class)->execute($profile, $profile->createdBy, AgtEnvironment::Homologation, CarbonImmutable::now()->addDay());
    $migration = require database_path('migrations/2026_10_07_104140_add_recurring_authority_and_occurrence_integrity.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(DB::table('recurring_approvals')->count())->toBe(1);
});

test('disabled recurring profiles cannot generate a new occurrence', function () {
    $profile = authorityProfile();
    $profile->update(['is_active' => false]);
    expect(fn () => app(ConvertRecurringProfile::class)->execute($profile, CarbonImmutable::now()))->toThrow(HttpException::class);
    expect(DB::table('recurring_occurrences')->count())->toBe(0)->and(FiscalDocument::count())->toBe(0);
});
