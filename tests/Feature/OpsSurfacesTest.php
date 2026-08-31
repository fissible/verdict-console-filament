<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\VerdictConsole\Configuration\ApprovalRules;
use Fissible\VerdictConsole\Configuration\CapabilityInspection;
use Fissible\VerdictConsole\Contracts\ConfigurationInspection as ConfigurationInspectionContract;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Incidents\Incident;
use Fissible\VerdictConsole\Incidents\IncidentStore;
use Fissible\VerdictConsoleFilament\Pages\ConfigurationInspection;
use Fissible\VerdictConsoleFilament\Pages\ConsoleDoctor;
use Fissible\VerdictConsoleFilament\Pages\ExecutionClaims;
use Fissible\VerdictConsoleFilament\Widgets\AnomalyAlarms;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * VC-30: the ops surfaces render three console contracts that already exist headless — the
 * execution-claim service (the one queue where a human's action is genuinely required), the
 * doctor's findings model, and the incident ledger — plus the read-only configuration inspection.
 * Everything here runs the real services over real schema; the only decision surface, claim
 * resolution, is driven end to end with the operator's authority and a required reason.
 */
function opsPage(string $component): Testable
{
    return Livewire::actingAs(new GenericUser(['id' => 'operator-1']))->test($component);
}

/** @param array<string, mixed> $attributes */
function insertClaim(array $attributes): void
{
    DB::table('verdict_execution_claims')->insert([
        'id' => $attributes['id'],
        'capability' => $attributes['capability'] ?? 'orders.refund',
        'policy' => $attributes['policy'] ?? 'refund-once',
        'binding_fingerprint' => $attributes['binding_fingerprint'] ?? hash('sha256', 'binding-'.$attributes['id']),
        'status' => $attributes['status'] ?? 'indeterminate',
        'attempt_count' => $attributes['attempt_count'] ?? 1,
        'claimed_at' => $attributes['claimed_at'] ?? now()->subMinutes(10),
        'indeterminate_at' => ($attributes['status'] ?? 'indeterminate') === 'indeterminate' ? now()->subMinutes(9) : null,
        'completed_at' => ($attributes['status'] ?? null) === 'completed' ? now()->subMinutes(8) : null,
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(9),
    ]);
}

function inspectionNeverRuns(): Closure
{
    return fn (): never => throw new LogicException('Inspection and doctor surfaces must not invoke capability code.');
}

beforeEach(function (): void {
    config()->set('verdict.approvals.authorizer', AllowAllApprovalAuthorizer::class);
    Gate::define('resolve-verdict-execution-claim', fn (): bool => true);

    $verdict = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';
    (require $verdict.'/create_verdict_execution_claims_table.php.stub')->up();
    (require $verdict.'/create_verdict_approval_receipts_table.php.stub')->up();
    (require $verdict.'/add_proposal_provenance_to_verdict_approval_receipts_table.php.stub')->up();
    (require $verdict.'/add_approval_context_to_verdict_approval_receipts_table.php.stub')->up();

    $console = dirname(__DIR__, 2).'/vendor/fissible/verdict-console/database/migrations';

    foreach ([
        'create_verdict_console_pending_approvals_table.php.stub',
        'add_operational_state_to_verdict_console_pending_approvals_table.php.stub',
        'add_approval_context_to_verdict_console_pending_approvals_table.php.stub',
        'create_verdict_console_approval_notifications_table.php.stub',
        'create_verdict_console_approval_reconciliations_table.php.stub',
        'create_verdict_console_incidents_table.php.stub',
        'create_verdict_console_conversation_invocations_table.php.stub',
    ] as $migration) {
        (require $console.'/'.$migration)->up();
    }

    (require dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php')->up();

    Filament::setCurrentPanel('testing');
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_execution_claims');
});

it('registers all four ops surfaces through the plugin', function (): void {
    $panel = Filament::getCurrentPanel();

    expect($panel->getPages())
        ->toContain(ExecutionClaims::class)
        ->toContain(ConsoleDoctor::class)
        ->toContain(ConfigurationInspection::class)
        ->and($panel->getWidgets())->toContain(AnomalyAlarms::class);
});

// --- execution-claim queue -----------------------------------------------------------------------

it('lists unresolved claims with their vocabulary and leaves resolved ones out', function (): void {
    insertClaim(['id' => 'claim-indeterminate', 'binding_fingerprint' => hash('sha256', 'raw-binding-material')]);
    insertClaim(['id' => 'claim-completed', 'status' => 'completed']);

    opsPage(ExecutionClaims::class)
        ->assertOk()
        ->assertSee('claim-indeterminate')
        ->assertSee('orders.refund')
        ->assertSee('refund-once')
        ->assertSee('indeterminate')
        // The item's display fingerprint is a hash of the id, never the raw binding (ADR 0008).
        ->assertSee(hash('sha256', 'claim-indeterminate'))
        ->assertDontSee(hash('sha256', 'raw-binding-material'))
        ->assertDontSee('claim-completed');
});

it('says there is nothing to reconcile instead of rendering an empty table', function (): void {
    opsPage(ExecutionClaims::class)
        ->assertOk()
        ->assertSee('No unresolved Verdict execution claims.');
});

it('resolves an indeterminate claim as completed with the operators authority and reason', function (): void {
    insertClaim(['id' => 'claim-1']);

    opsPage(ExecutionClaims::class)
        ->callAction(
            TestAction::make('resolve')->table('claim-1'),
            ['resolution' => 'completed', 'reason' => 'Executor receipt located in the payment log.'],
        )
        ->assertNotified();

    $row = DB::table('verdict_execution_claims')->where('id', 'claim-1')->first();

    expect($row->status)->toBe('completed')
        ->and($row->resolved_by)->toBe('operator-1')
        ->and($row->resolution_reason)->toBe('Executor receipt located in the payment log.');
});

it('resolves an indeterminate claim as retryable, releasing it', function (): void {
    insertClaim(['id' => 'claim-2']);

    opsPage(ExecutionClaims::class)
        ->callAction(
            TestAction::make('resolve')->table('claim-2'),
            ['resolution' => 'retryable', 'reason' => 'No side effect found; safe to retry.'],
        )
        ->assertNotified();

    expect(DB::table('verdict_execution_claims')->where('id', 'claim-2')->value('status'))->toBe('released');
});

it('refuses to resolve without a reason', function (): void {
    insertClaim(['id' => 'claim-3']);

    opsPage(ExecutionClaims::class)
        ->callAction(TestAction::make('resolve')->table('claim-3'), ['resolution' => 'completed', 'reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect(DB::table('verdict_execution_claims')->where('id', 'claim-3')->value('status'))->toBe('indeterminate');
});

it('refuses an operator the Gate denies before the claim can transition', function (): void {
    $this->withoutExceptionHandling();
    insertClaim(['id' => 'claim-4']);
    Gate::define('resolve-verdict-execution-claim', fn (): bool => false);

    $failure = null;

    try {
        opsPage(ExecutionClaims::class)->callAction(
            TestAction::make('resolve')->table('claim-4'),
            ['resolution' => 'completed', 'reason' => 'should never land'],
        );
    } catch (Throwable $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(AuthorizationException::class)
        ->and($failure->getMessage())->toBe('This operator may not resolve this execution claim.')
        ->and(DB::table('verdict_execution_claims')->where('id', 'claim-4')->value('status'))->toBe('indeterminate')
        ->and(DB::table('verdict_execution_claims')->where('id', 'claim-4')->value('resolved_by'))->toBeNull();
});

it('lists a still-claimed claim but refuses to resolve it without force', function (): void {
    insertClaim(['id' => 'claim-active', 'status' => 'claimed']);

    opsPage(ExecutionClaims::class)
        ->assertSee('claim-active')
        ->callAction(
            TestAction::make('resolve')->table('claim-active'),
            ['resolution' => 'completed', 'reason' => 'Wrongly assuming an active claim is stuck.'],
        )
        ->assertNotified();

    $row = DB::table('verdict_execution_claims')->where('id', 'claim-active')->first();

    // The service refuses a non-forced resolution of an active claim; a page driving the
    // claim manager directly would have transitioned this row.
    expect($row->status)->toBe('claimed')
        ->and($row->resolved_by)->toBeNull();
});

it('refuses an anonymous resolution attempt and leaves the claim untouched', function (): void {
    $this->withoutExceptionHandling();
    insertClaim(['id' => 'claim-anon']);

    $failure = null;

    try {
        Livewire::test(ExecutionClaims::class)->callAction(
            TestAction::make('resolve')->table('claim-anon'),
            ['resolution' => 'completed', 'reason' => 'should never land'],
        );
    } catch (Throwable $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(AuthorizationException::class)
        ->and($failure->getMessage())->toBe('This operator may not resolve this execution claim.')
        ->and(DB::table('verdict_execution_claims')->where('id', 'claim-anon')->value('status'))->toBe('indeterminate');
});

it('offers resolve and nothing else on the claims queue — no bulk, no mutation', function (): void {
    insertClaim(['id' => 'claim-5']);

    $table = opsPage(ExecutionClaims::class)->instance()->getTable();
    $inventory = array_keys($table->getFlatActions());
    sort($inventory);

    expect($inventory)->toBe(['resolve'])
        ->and($table->getFlatBulkActions())->toBe([]);
});

// --- doctor --------------------------------------------------------------------------------------

it('reports every console precondition satisfied when the wiring is clean', function (): void {
    opsPage(ConsoleDoctor::class)
        ->assertOk()
        ->assertSee('Every console precondition is satisfied.');
});

it('renders a finding with its severity, subject, and fix when the wiring is broken', function (): void {
    app(ResumableAgents::class)->register(
        'broken@v1',
        fn (): never => throw new RuntimeException('resolver gone'),
        fn (object $agent): bool => false,
    );

    opsPage(ConsoleDoctor::class)
        ->assertOk()
        ->assertDontSee('Every console precondition is satisfied.')
        ->assertSee('resolver_key_unresolvable')
        ->assertSee('error')
        ->assertSee('broken@v1');
});

it('renders a dead confirmation gate finding from the capability inspector', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.cancel', 'update', inspectionNeverRuns())
            ->requiresConfirmation(inspectionNeverRuns(), reason: 'Cancels move money.', ttlSeconds: 600)
            ->executeUsing(inspectionNeverRuns()),
    );

    opsPage(ConsoleDoctor::class)
        ->assertOk()
        ->assertDontSee('Every console precondition is satisfied.')
        ->assertSee('confirmation_gate_cannot_pause')
        ->assertSee('orders.cancel');
});

// --- anomaly alarms ------------------------------------------------------------------------------

it('renders the incident ledger newest first with source and cause', function (): void {
    app(IncidentStore::class)->record('evidence_write_failed', 'connection refused', ['capability' => 'orders.refund']);
    app(IncidentStore::class)->record('approval_decision_refused', 'unauthorized');
    Incident::query()->where('source', 'evidence_write_failed')->update(['observed_at' => now()->subMinutes(5)]);

    opsPage(AnomalyAlarms::class)
        ->assertOk()
        ->assertSeeInOrder(['approval_decision_refused', 'evidence_write_failed'])
        ->assertSee('connection refused')
        ->assertSee('unauthorized');
});

it('says no incidents have been recorded when the ledger is empty', function (): void {
    opsPage(AnomalyAlarms::class)
        ->assertOk()
        ->assertSee('No incidents recorded.');
});

// --- configuration inspection --------------------------------------------------------------------

it('renders registered capabilities with their postures, fingerprints, and approval rules', function (): void {
    $registry = app(CapabilityRegistry::class);
    $registry->register(
        Capability::usingPolicy('orders.refund', 'update', inspectionNeverRuns())
            ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(name: 'refund-target', identityUsing: inspectionNeverRuns()))
            ->requiresConfirmation(inspectionNeverRuns(), reason: 'Refunds move money.', ttlSeconds: 600)
            ->rateLimit(RateLimitPolicy::fixedWindow('refund-rate', 5, 3600, inspectionNeverRuns(), 'Five refunds an hour.'))
            ->atMostOnce(ExecutionClaimPolicy::named('refund-once', inspectionNeverRuns()))
            ->executeUsing(inspectionNeverRuns()),
    );
    $registry->register(Capability::usingPolicy('billing.read', 'view', inspectionNeverRuns()));

    $fingerprint = $registry->get('orders.refund')->configuration()->fingerprint;

    // Rendering must never execute capability code: every staged closure throws on invocation, so
    // an implementation that evaluates a resolver fails loudly here.
    opsPage(ConfigurationInspection::class)
        ->assertOk()
        ->assertSee('orders.refund')
        ->assertSee('billing.read')
        ->assertSee($fingerprint)
        ->assertSee('Refunds move money.')
        ->assertSee('refund-rate')
        ->assertSee('3600')
        // Approval rules: the configured authorizer and the console's own gate ability.
        ->assertSee(AllowAllApprovalAuthorizer::class)
        ->assertSee('approve-verdict-action');
});

it('renders whatever the configuration-inspection boundary reports, not the registry', function (): void {
    app(CapabilityRegistry::class)->register(Capability::usingPolicy('registry.only', 'view', inspectionNeverRuns()));

    app()->instance(ConfigurationInspectionContract::class, new class implements ConfigurationInspectionContract
    {
        public function capabilities(): array
        {
            return [new CapabilityInspection(
                name: 'boundary.only',
                ability: 'view',
                configurationFingerprint: 'boundary-fingerprint',
                configurationVersion: null,
                confirmationRequired: false,
                confirmationReason: null,
                confirmationTtlSeconds: null,
                executionTargetPolicy: null,
                executionTargetStrategy: null,
                rateLimit: null,
                executionClaimPolicy: null,
                requiresIntentRecord: null,
                consequential: false,
            )];
        }

        public function rateLimits(): array
        {
            return [];
        }

        public function approvalRules(): ApprovalRules
        {
            return new ApprovalRules(ttlSeconds: null, authorizer: null, strictProvenance: false, gateAbility: 'boundary-gate-ability');
        }
    });

    // The page must read the console's inspection boundary; a page walking the registry itself
    // would render the registry capability and miss the boundary's.
    opsPage(ConfigurationInspection::class)
        ->assertOk()
        ->assertSee('boundary.only')
        ->assertSee('boundary-fingerprint')
        ->assertDontSee('registry.only');
});
