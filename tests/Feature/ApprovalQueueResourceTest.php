<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\VerdictConsole\Approvals\ApprovalSurfaceContract;
use Fissible\VerdictConsole\Approvals\ApprovalVerb;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsoleFilament\Resources\PendingApprovalResource;
use Fissible\VerdictConsoleFilament\Resources\PendingApprovalResource\Pages\ListPendingApprovals;
use Fissible\VerdictConsoleFilament\VerdictConsoleFilamentPlugin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;

/**
 * VC-28: the approval queue Resource is a rendering of the console's headless contracts — the
 * scoped index, the status-reader view, the verb resolver — and a relay to the real
 * ApprovalResolutionService. The status reader is faked per receipt exactly as the core's own inbox
 * suite fakes it (this suite proves the Resource, not Verdict); everything downstream of the
 * decision — the Verdict receipt transition, the agent continuation, the operational state — is
 * real. Recorded reads pin that the Resource stays on the supported live-read boundary.
 */
final class QueueStatuses implements ApprovalStatusReader
{
    /** @var list<string> every read made through this seam, in order, as "method:key" */
    public array $reads = [];

    /** @var array<string, ApprovalStatusView|null> */
    private array $byReceiptId = [];

    /** @var array<string, ApprovalStatusView|null> */
    private array $byToolCall = [];

    public function with(string $receiptId, ?ApprovalStatusView $view): self
    {
        $this->byReceiptId[$receiptId] = $view;

        return $this;
    }

    public function withToolCall(string $toolCallId, ?ApprovalStatusView $view): self
    {
        $this->byToolCall[$toolCallId] = $view;

        return $this;
    }

    public function statusFor(string $receiptId): ?ApprovalStatusView
    {
        $this->reads[] = 'statusFor:'.$receiptId;

        return $this->byReceiptId[$receiptId] ?? null;
    }

    public function statusForToolCall(string $toolCallId): ?ApprovalStatusView
    {
        $this->reads[] = 'statusForToolCall:'.$toolCallId;

        return $this->byToolCall[$toolCallId] ?? null;
    }

    public function pendingWithin(array $scope): array
    {
        return [];
    }
}

/** Records the exact continuation the resolution service drives, without any gateway. */
final class QueueRecordingAgent implements Agent, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    /** @var list<string> */
    public array $continuations = [];

    public ?Decisions $decisions = null;

    public function instructions(): Stringable|string
    {
        return 'Queue recording agent.';
    }

    #[Override]
    public function continue(string $conversationId, ?object $as = null): static
    {
        $this->continuations[] = $conversationId;

        return $this;
    }

    #[Override]
    public function prompt(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        if ($prompt instanceof Decisions) {
            $this->decisions = $prompt;
        }

        return new AgentResponse('queue-recording-invocation', '', new Usage, new Meta);
    }
}

final readonly class QueueConversationScope implements ApprovalScope
{
    public function __construct(private string $conversationId) {}

    public function apply(Builder $query): Builder
    {
        return $query->where('conversation_id', $this->conversationId);
    }
}

/** A second, materially different scope, so a queue hard-coded to one column cannot pass both. */
final readonly class QueueToolCallScope implements ApprovalScope
{
    public function __construct(private string $toolCallId) {}

    public function apply(Builder $query): Builder
    {
        return $query->where('tool_call_id', $this->toolCallId);
    }
}

function queueView(
    string $toolCallId,
    ApprovalReceiptStatus $status = ApprovalReceiptStatus::Pending,
    string $expiresAt = '+1 hour',
): ApprovalStatusView {
    return new ApprovalStatusView(
        receiptId: 'receipt-'.$toolCallId,
        toolCallId: $toolCallId,
        capability: 'orders.cancel',
        status: $status,
        reason: 'Cancelling an order needs confirmation.',
        expiresAt: new DateTimeImmutable($expiresAt),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: new DateTimeImmutable('-5 minutes'),
        approvalContext: null,
    );
}

/**
 * A drivable console row plus, unless declined, its live receipt in Verdict's real store — the two
 * records an operator's decision genuinely spans.
 */
function queueRow(
    string $toolCallId,
    ?ApprovalReceiptStatus $status = ApprovalReceiptStatus::Pending,
    string $expiresAt = '+1 hour',
    string $conversationId = 'conversation-1',
    Resumability $resumability = Resumability::Drivable,
    ?UnresumableReason $unresumableReason = null,
    ?array $presentation = ['tool' => 'CancelOrderTool', 'capability' => 'orders.cancel'],
): PendingApproval {
    $approval = app(PendingApprovalStore::class)->ingest(
        $toolCallId,
        conversationId: $conversationId,
        receiptId: 'receipt-'.$toolCallId,
        resolverKey: $resumability === Resumability::Drivable ? 'queue@v1' : null,
        presentation: $presentation,
        resumability: $resumability,
        unresumableReason: $unresumableReason,
    );

    if ($status !== null) {
        seedReceipt($toolCallId, $status, $expiresAt);
        test()->statuses->with('receipt-'.$toolCallId, queueView($toolCallId, $status, $expiresAt));
    }

    return $approval;
}

function seedReceipt(string $toolCallId, ApprovalReceiptStatus $status, string $expiresAt, ?string $decidedBy = null): void
{
    DB::table('verdict_approval_receipts')->updateOrInsert(['id' => 'receipt-'.$toolCallId], [
        'tool_call_id' => $toolCallId,
        'capability' => 'orders.cancel',
        'binding_fingerprint' => str_repeat('a', 64),
        'status' => $status->value,
        'reason' => 'Cancelling an order needs confirmation.',
        'expires_at' => now()->modify($expiresAt),
        'approved_by' => $status === ApprovalReceiptStatus::Approved ? $decidedBy : null,
        'rejected_by' => $status === ApprovalReceiptStatus::Rejected ? $decidedBy : null,
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);
}

function queuePage(): Testable
{
    return Livewire::actingAs(new GenericUser(['id' => 'operator-1']))->test(ListPendingApprovals::class);
}

/**
 * The verbs the page actually offers for a row, extracted by probing the shipped assertions — the
 * set ApprovalSurfaceContract judges, independent of any expectation written in this file.
 *
 * @return list<ApprovalVerb>
 */
function visibleQueueVerbs(Testable $page, PendingApproval $row): array
{
    return array_values(array_filter(
        ApprovalVerb::cases(),
        function (ApprovalVerb $verb) use ($page, $row): bool {
            try {
                $page->assertActionVisible(TestAction::make($verb->value)->table($row));

                return true;
            } catch (AssertionFailedError) {
                return false;
            }
        },
    ));
}

beforeEach(function (): void {
    config()->set('verdict.approvals.authorizer', AllowAllApprovalAuthorizer::class);
    Gate::define('approve-verdict-action', fn (): bool => true);

    $consoleMigrations = dirname(__DIR__, 2).'/vendor/fissible/verdict-console/database/migrations';

    foreach ([
        'create_verdict_console_pending_approvals_table.php.stub',
        'add_operational_state_to_verdict_console_pending_approvals_table.php.stub',
        'add_approval_context_to_verdict_console_pending_approvals_table.php.stub',
        'create_verdict_console_approval_notifications_table.php.stub',
        'create_verdict_console_approval_reconciliations_table.php.stub',
        'create_verdict_console_incidents_table.php.stub',
    ] as $migration) {
        (require $consoleMigrations.'/'.$migration)->up();
    }

    $verdictMigrations = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';

    foreach ([
        'create_verdict_approval_receipts_table.php.stub',
        'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        'add_approval_context_to_verdict_approval_receipts_table.php.stub',
    ] as $migration) {
        (require $verdictMigrations.'/'.$migration)->up();
    }

    $this->statuses = new QueueStatuses;
    app()->instance(ApprovalStatusReader::class, $this->statuses);

    $this->agent = new QueueRecordingAgent;
    app(ResumableAgents::class)->register(
        'queue@v1',
        fn (): QueueRecordingAgent => $this->agent,
        fn (Agent $agent): bool => $agent instanceof QueueRecordingAgent,
    );

    Filament::setCurrentPanel('testing');
});

it('registers the approval queue Resource through the plugin', function (): void {
    expect(Filament::getCurrentPanel()->getPlugin('verdict-console'))
        ->toBeInstanceOf(VerdictConsoleFilamentPlugin::class)
        ->and(Filament::getCurrentPanel()->getResources())
        ->toContain(PendingApprovalResource::class);
});

it('lists only rows the host scope exposes, whatever the scope constrains on', function (): void {
    $first = queueRow('call_first');
    $second = queueRow('call_second', conversationId: 'another-tenant');

    app()->instance(ApprovalScope::class, new QueueConversationScope('conversation-1'));
    queuePage()
        ->assertOk()
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second]);

    // A materially different scope: a queue that hard-coded the conversation column would pass the
    // first binding and fail this one.
    app()->instance(ApprovalScope::class, new QueueToolCallScope('call_second'));
    queuePage()
        ->assertCanSeeTableRecords([$second])
        ->assertCanNotSeeTableRecords([$first]);
});

it('cannot decide a row the host scope hides, even when addressed directly', function (): void {
    $foreign = queueRow('call_foreign', conversationId: 'another-tenant');
    app()->instance(ApprovalScope::class, new QueueConversationScope('conversation-1'));

    $failure = null;

    try {
        queuePage()->callAction(TestAction::make('approve')->table($foreign));
    } catch (Throwable $e) {
        $failure = $e;
    }

    // Whether the table refuses to find the record or the service refuses its visibility, the
    // receipt is what must not move.
    expect($failure)->not->toBeNull()
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_foreign')->value('status'))->toBe('pending')
        ->and(test()->agent->continuations)->toBe([])
        ->and($foreign->fresh()->resume_attempts)->toBe(0);
});

it('lists newest pauses first, with the id tie-break the core index specifies', function (): void {
    $older = queueRow('call_older');
    PendingApproval::query()->whereKey($older->getKey())->update(['created_at' => now()->subMinutes(10)]);
    $tieA = queueRow('call_tie_a');
    $tieB = queueRow('call_tie_b');
    $tied = now()->subMinutes(5);
    PendingApproval::query()->whereKey($tieA->getKey())->update(['created_at' => $tied]);
    PendingApproval::query()->whereKey($tieB->getKey())->update(['created_at' => $tied]);

    // Equal timestamps resolve by id ascending (PendingApprovalStore::visible()), so two rows from
    // the same instant cannot swap places between polls. The expected pair order is computed from
    // the keys, not assumed from insertion order.
    [$tieFirst, $tieSecond] = strcmp((string) $tieA->getKey(), (string) $tieB->getKey()) < 0
        ? [$tieA, $tieB]
        : [$tieB, $tieA];

    queuePage()->assertCanSeeTableRecords(
        [$tieFirst->fresh(), $tieSecond->fresh(), $older->fresh()],
        inOrder: true,
    );
});

it('renders each receipt state distinctly, with the presentation the host captured', function (): void {
    $pending = queueRow('call_pending');
    $lapsed = queueRow('call_lapsed', expiresAt: '-1 minute');
    $decided = queueRow('call_decided', status: ApprovalReceiptStatus::Approved);
    $unavailable = queueRow('call_unavailable', status: null);

    queuePage()
        ->assertTableColumnStateSet('tool', 'CancelOrderTool', record: $pending)
        ->assertTableColumnStateSet('state', 'pending', record: $pending)
        ->assertTableColumnStateSet('state', 'lapsed_undecided', record: $lapsed)
        ->assertTableColumnStateSet('state', 'already_decided', record: $decided)
        ->assertTableColumnStateSet('state', 'receipt_unavailable', record: $unavailable);
});

it('makes one status read per row per render, as the item contract promises', function (): void {
    queueRow('call_single');

    queuePage();

    // ApprovalItem's own contract: "An inbox makes one status read per row." Four columns and a
    // verb set all deriving from the same item must not become four or five live reads — the
    // status view is Verdict state, and a queue that multiplies reads per row multiplies load on
    // the store every poll.
    expect(array_count_values(test()->statuses->reads)['statusFor:receipt-call_single'] ?? 0)->toBe(1);
});

it('renders a row whose host presenter captured nothing', function (): void {
    $bare = queueRow('call_bare', presentation: null);

    // A presenter failure stores a null presentation without changing drivability (core design
    // §6.3); the queue must render the row rather than throw or invent a tool name.
    queuePage()
        ->assertOk()
        ->assertCanSeeTableRecords([$bare]);
});

it('marks a row this console cannot drive instead of offering a decision on it', function (): void {
    $unresumable = queueRow(
        'call_unresumable',
        resumability: Resumability::Unresumable,
        unresumableReason: UnresumableReason::AgentUnresolvable,
    );

    $page = queuePage()
        ->assertTableColumnStateSet('resumability', 'unresumable', record: $unresumable)
        ->assertTableColumnStateSet('unresumable_reason', 'agent_unresolvable', record: $unresumable);

    expect(visibleQueueVerbs($page, $unresumable))->toBe([]);
});

/**
 * ADR 0001 §2 rendered as row actions. The expected sets are stated by hand per staged state — an
 * oracle independent of the resolver — and the set the page actually offers is separately extracted
 * and judged by ApprovalSurfaceContract, the assertion every rendering surface must answer to.
 */
it('offers exactly the verb set the live receipt state admits', function (): void {
    $pending = queueRow('call_pending');
    $lapsed = queueRow('call_lapsed', expiresAt: '-1 minute');
    $decided = queueRow('call_decided', status: ApprovalReceiptStatus::Rejected);
    $unavailable = queueRow('call_unavailable', status: null);

    $page = queuePage();

    $expectations = [
        'call_pending' => [$pending, [ApprovalVerb::Approve, ApprovalVerb::Reject]],
        'call_lapsed' => [$lapsed, [ApprovalVerb::Close]],
        'call_decided' => [$decided, [ApprovalVerb::Close]],
        'call_unavailable' => [$unavailable, [ApprovalVerb::Close]],
    ];

    $contract = app(ApprovalSurfaceContract::class);

    foreach ($expectations as $toolCallId => [$row, $verbs]) {
        $rendered = visibleQueueVerbs($page, $row);

        expect($rendered)->toEqualCanonicalizing($verbs, 'for '.$toolCallId);

        $view = test()->statuses->statusFor('receipt-'.$toolCallId);
        $contract->assertRendered($rendered, $row, $view?->toolCallId === $row->tool_call_id ? $view : null);
    }
});

it('reads a receiptless row through the tool-call lookup and still offers its live verbs', function (): void {
    // A pause ingested before its receipt was knowable carries no receipt id; the supported read
    // for it is statusForToolCall(), the boundary an implementation could quietly skip while every
    // receipt-backed test stays green.
    $receiptless = app(PendingApprovalStore::class)->ingest(
        'call_receiptless',
        conversationId: 'conversation-1',
        resolverKey: 'queue@v1',
        presentation: ['tool' => 'CancelOrderTool', 'capability' => 'orders.cancel'],
        resumability: Resumability::Drivable,
    );
    test()->statuses->withToolCall('call_receiptless', queueView('call_receiptless'));

    $page = queuePage()
        ->assertTableColumnStateSet('state', 'pending', record: $receiptless);

    expect(visibleQueueVerbs($page, $receiptless))->toEqualCanonicalizing([ApprovalVerb::Approve, ApprovalVerb::Reject])
        ->and(test()->statuses->reads)->toContain('statusForToolCall:call_receiptless');

    // The lookup's result must control the rendering, not merely be fetched: the same row with a
    // decided view collapses to close, exactly as a receipt-backed row would.
    test()->statuses->withToolCall('call_receiptless', queueView('call_receiptless', ApprovalReceiptStatus::Rejected));

    $page = queuePage()
        ->assertTableColumnStateSet('state', 'already_decided', record: $receiptless);

    expect(visibleQueueVerbs($page, $receiptless))->toBe([ApprovalVerb::Close]);
});

it('offers nothing on a status view that answers for a different tool call', function (): void {
    $row = queueRow('call_mismatch', status: null);
    test()->statuses->with('receipt-call_mismatch', queueView('call_other'));

    // A view that does not name this tool call proves nothing about this receipt. The core factory
    // and resolver both discard it; a queue trusting it would offer a decision on the wrong receipt.
    $page = queuePage()
        ->assertTableColumnStateSet('state', 'receipt_unavailable', record: $row);

    expect(visibleQueueVerbs($page, $row))->toBe([]);
});

it('offers no edit, delete, create, or bulk decision anywhere', function (): void {
    $pending = queueRow('call_pending');

    // Decision::edit() is not admitted into Verdict's execution context, and modified arguments
    // break the receipt binding; bulk approval is deferred until per-row authorization and partial
    // failure are specified. Neither may exist even as a hidden control.
    queuePage()
        ->assertActionDoesNotExist(TestAction::make('edit')->table($pending))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($pending))
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->assertActionDoesNotExist(TestAction::make('approve')->table()->bulk())
        ->assertActionDoesNotExist(TestAction::make('reject')->table()->bulk())
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

    // Exhaustive, not enumerated-negatives: the table's whole action inventory is the three verbs
    // and its bulk inventory is empty. A rogue control — an "approve anyway", a bulk anything under
    // any name — fails here by existing, even hidden.
    $table = queuePage()->instance()->getTable();
    $inventory = array_keys($table->getFlatActions());
    sort($inventory);

    expect($inventory)->toBe(['approve', 'close', 'reject'])
        ->and($table->getFlatBulkActions())->toBe([])
        ->and(PendingApprovalResource::canCreate())->toBeFalse();
});

it('filters the queue to rows this console can drive', function (): void {
    $drivable = queueRow('call_drivable');
    $unresumable = queueRow(
        'call_unresumable',
        resumability: Resumability::Unresumable,
        unresumableReason: UnresumableReason::ConversationAbsent,
    );

    queuePage()
        ->assertCanSeeTableRecords([$drivable, $unresumable])
        ->filterTable('resumability', Resumability::Drivable->value)
        ->assertCanSeeTableRecords([$drivable])
        ->assertCanNotSeeTableRecords([$unresumable]);
});

it('approves through the real resolution service: receipt transitioned, exact conversation resumed', function (): void {
    $row = queueRow('call_approve');

    $page = queuePage()
        ->callAction(TestAction::make('approve')->table($row))
        ->assertNotified('Approved');

    $receipt = DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_approve')->first();

    expect($receipt->status)->toBe('approved')
        ->and($receipt->approved_by)->toBe('operator-1')
        ->and(test()->agent->continuations)->toBe(['conversation-1'])
        ->and(test()->agent->decisions)->not->toBeNull()
        // Operational state only the resolution service writes: the one observable that separates
        // driving the console's contract from re-implementing its outcome against Verdict directly.
        ->and($row->fresh()->resume_attempts)->toBe(1)
        // The supported live-read boundary, not challengeForToolCall and not a tool-call fallback.
        ->and(test()->statuses->reads)->toContain('statusFor:receipt-call_approve');

    $decisions = test()->agent->decisions->all();

    expect(array_keys($decisions))->toBe(['call_approve'])
        ->and($decisions['call_approve']->isApproved())->toBeTrue();

    // The decision the operator just made must collapse the row on the same component: a verb set
    // remembered from before the click is a stale approve button on a spent receipt.
    test()->statuses->with('receipt-call_approve', queueView('call_approve', ApprovalReceiptStatus::Approved));

    $page->assertActionHidden(TestAction::make('approve')->table($row))
        ->assertActionHidden(TestAction::make('reject')->table($row))
        ->assertActionVisible(TestAction::make('close')->table($row));
});

it('rejects through the real resolution service with a tool-call-keyed refusal', function (): void {
    $row = queueRow('call_reject');

    $page = queuePage()
        ->callAction(TestAction::make('reject')->table($row))
        ->assertNotified('Rejected');

    expect(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_reject')->value('status'))->toBe('rejected')
        ->and(array_keys(test()->agent->decisions->all()))->toBe(['call_reject'])
        ->and(test()->agent->decisions->all()['call_reject']->isRejected())->toBeTrue()
        ->and($row->fresh()->resume_attempts)->toBe(1);

    // The collapse must follow either decision, not only approval.
    test()->statuses->with('receipt-call_reject', queueView('call_reject', ApprovalReceiptStatus::Rejected));

    $page->assertActionHidden(TestAction::make('approve')->table($row))
        ->assertActionHidden(TestAction::make('reject')->table($row))
        ->assertActionVisible(TestAction::make('close')->table($row));
});

it('closes a lapsed row without deciding its receipt', function (): void {
    $row = queueRow('call_close', expiresAt: '-1 minute');

    queuePage()
        ->callAction(TestAction::make('close')->table($row))
        ->assertNotified('Closed');

    // Close is a workflow exit, never an authorization act: the lapsed receipt stays exactly as
    // Verdict left it while the stranded turn receives a keyed refusal.
    expect(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_close')->value('status'))->toBe('pending')
        ->and(test()->agent->continuations)->toBe(['conversation-1'])
        ->and(array_keys(test()->agent->decisions->all()))->toBe(['call_close'])
        ->and(test()->agent->decisions->all()['call_close']->isRejected())->toBeTrue()
        ->and($row->fresh()->resume_attempts)->toBe(1);
});

it('reports a decision that lapsed between render and click instead of pretending it happened', function (): void {
    $row = queueRow('call_gone');

    $page = queuePage();
    test()->statuses->with('receipt-call_gone', queueView('call_gone', expiresAt: '-1 second'));

    // Not the success notice: an operator whose decision lapsed must read that it did not happen.
    $page->callAction(TestAction::make('approve')->table($row))
        ->assertNotified('No longer actionable');

    expect(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_gone')->value('status'))->toBe('pending')
        ->and(test()->agent->continuations)->toBe([])
        ->and($row->fresh()->resume_attempts)->toBe(0);
});

it('reports a receipt another operator resolved behind a stale pending read', function (string $verb): void {
    $row = queueRow('call_raced');

    // The fake still answers pending; Verdict's store already holds the other operator's decision.
    // The manager's transition refuses, the service returns that refusal, and this console must
    // surface it without resuming, without spending, and without recording a resume attempt —
    // and never as the verb's own success title, whichever verb lost the race.
    seedReceipt('call_raced', ApprovalReceiptStatus::Approved, '+1 hour', decidedBy: 'other-operator');

    queuePage()
        ->callAction(TestAction::make($verb)->table($row))
        ->assertNotified('No longer actionable');

    $receipt = DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_raced')->first();

    expect($receipt->status)->toBe('approved')
        ->and($receipt->approved_by)->toBe('other-operator')
        ->and(test()->agent->continuations)->toBe([])
        ->and($row->fresh()->resume_attempts)->toBe(0);
})->with(['approve', 'reject']);

it('refuses an approver the Gate denies before anything can transition', function (): void {
    // Without this, Laravel's handler answers the refusal inside the Livewire request and the
    // testing macro then trips over its own torn-down action state, masking the real exception.
    $this->withoutExceptionHandling();
    $row = queueRow('call_denied');
    Gate::define('approve-verdict-action', fn (): bool => false);

    $page = queuePage();
    $failure = null;

    try {
        $page->callAction(TestAction::make('approve')->table($row));
    } catch (Throwable $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(AuthorizationException::class)
        // The same non-disclosing refusal the console core raises at every authorization boundary.
        ->and($failure->getMessage())->toBe('This approver may not resolve this approval.')
        ->and(DB::table('verdict_approval_receipts')->where('tool_call_id', 'call_denied')->value('status'))->toBe('pending')
        ->and(test()->agent->continuations)->toBe([])
        ->and($row->fresh()->resume_attempts)->toBe(0);

    $page->assertNotNotified();
});

it('offers the new verb sets on the next render when receipts change underneath idle rows', function (): void {
    $shifting = queueRow('call_shifting');
    $lapsing = queueRow('call_lapsing');

    $page = queuePage();

    expect(visibleQueueVerbs($page, $shifting))->toEqualCanonicalizing([ApprovalVerb::Approve, ApprovalVerb::Reject])
        ->and(visibleQueueVerbs($page, $lapsing))->toEqualCanonicalizing([ApprovalVerb::Approve, ApprovalVerb::Reject]);

    // Another operator decides one receipt and the other lapses; nobody acts on this component.
    // The state column already reads live — every idle row's verbs must move with it on the very
    // next render, and that render is an ordinary table update, not a bespoke refresh hook.
    seedReceipt('call_shifting', ApprovalReceiptStatus::Rejected, '+1 hour', decidedBy: 'other-operator');
    test()->statuses->with('receipt-call_shifting', queueView('call_shifting', ApprovalReceiptStatus::Rejected));
    test()->statuses->with('receipt-call_lapsing', queueView('call_lapsing', expiresAt: '-1 minute'));

    $page->filterTable('resumability', Resumability::Drivable->value)
        ->assertTableColumnStateSet('state', 'already_decided', record: $shifting)
        ->assertTableColumnStateSet('state', 'lapsed_undecided', record: $lapsing);

    $renderedShifting = visibleQueueVerbs($page, $shifting);
    $renderedLapsing = visibleQueueVerbs($page, $lapsing);

    expect($renderedShifting)->toBe([ApprovalVerb::Close])
        ->and($renderedLapsing)->toBe([ApprovalVerb::Close]);

    // The same judgment every rendering surface answers to, on the second render's verb sets.
    $contract = app(ApprovalSurfaceContract::class);
    $contract->assertRendered($renderedShifting, $shifting->fresh(), test()->statuses->statusFor('receipt-call_shifting'));
    $contract->assertRendered($renderedLapsing, $lapsing->fresh(), test()->statuses->statusFor('receipt-call_lapsing'));
});
