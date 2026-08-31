<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidenceQueryResult;
use Fissible\VerdictConsole\Evidence\EvidenceRecord;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsoleFilament\Pages\EvidenceBrowser;
use Fissible\VerdictConsoleFilament\VerdictConsoleFilamentPlugin;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * VC-29: the evidence browser is a rendering of the VC-13 read boundary and nothing else. Rows come
 * from real records in Verdict's published schema through the real DatabaseEvidenceQuery; the
 * recorder configuration drives the Off / On / Elsewhere states; and a recording decorator around
 * the container's own boundary pins that every filter travels through it as an EvidenceFilter —
 * a browser that filtered rows in memory would tell an auditor the boundary said something it was
 * never asked.
 */
const BROWSER_EVIDENCE_STUBS = [
    'create_verdict_evidence_table.php.stub',
    'add_provenance_to_verdict_evidence_table.php.stub',
    'add_invocation_id_to_verdict_evidence_table.php.stub',
    'add_tool_kind_to_verdict_evidence_table.php.stub',
    'add_configuration_fingerprint_to_verdict_evidence_table.php.stub',
    'add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub',
    'add_target_source_to_verdict_evidence_table.php.stub',
    'add_tool_description_fingerprints_to_verdict_evidence_table.php.stub',
    'add_record_identity_to_verdict_evidence_table.php.stub',
    'add_intent_id_to_verdict_evidence_table.php.stub',
];

/** Delegates to the real boundary, recording every filter the browser sends through it. */
final class BrowserEvidenceQuery implements EvidenceQuery
{
    /** @var list<EvidenceFilter> */
    public array $filters = [];

    public function __construct(private readonly EvidenceQuery $inner) {}

    public function search(EvidenceFilter $filter): EvidenceQueryResult
    {
        $this->filters[] = $filter;

        return $this->inner->search($filter);
    }

    public function lastFilter(): ?EvidenceFilter
    {
        return $this->filters === [] ? null : $this->filters[array_key_last($this->filters)];
    }
}

/** A canned read-boundary answer: the page must render this, whatever config or the database say. */
final class CannedEvidenceQuery implements EvidenceQuery
{
    public function __construct(private readonly EvidenceQueryResult $result) {}

    public function search(EvidenceFilter $filter): EvidenceQueryResult
    {
        return $this->result;
    }
}

/** Scripted boundary answers, one per search, recording each filter asked — re-answering the last. */
final class ScriptedEvidenceQuery implements EvidenceQuery
{
    /** @var list<EvidenceFilter> */
    public array $filters = [];

    /** @param list<EvidenceQueryResult> $results */
    public function __construct(private array $results) {}

    public function search(EvidenceFilter $filter): EvidenceQueryResult
    {
        $this->filters[] = $filter;

        return count($this->results) > 1 ? array_shift($this->results) : $this->results[0];
    }
}

function cannedRecord(string $id, ?string $recordDigest = null): EvidenceRecord
{
    return new EvidenceRecord(
        id: $id,
        capability: 'orders.cancel',
        stage: 'proposal',
        disposition: 'permit',
        claimType: null,
        recordDigest: $recordDigest,
        argumentFingerprint: null,
        idempotencyKeyFingerprint: null,
        approvalReceiptFingerprint: null,
        configurationFingerprint: null,
        actorFingerprint: null,
        subjectFingerprint: null,
        proposalTargetIdentityFingerprint: null,
        executionTargetIdentityFingerprint: null,
        rateLimitKeyFingerprint: null,
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        invocationId: null,
        rateLimitResetAt: null,
        recordedAt: new DateTimeImmutable('2026-08-30 12:00:00+00:00'),
    );
}

/** @param array<string, mixed> $attributes */
function insertBrowserEvidence(array $attributes): void
{
    DB::table('console_filament_evidence')->insert([
        'id' => $attributes['id'],
        'record_type' => $attributes['record_type'] ?? 'decision',
        'capability' => $attributes['capability'] ?? 'orders.cancel',
        'stage' => $attributes['stage'] ?? 'proposal',
        'disposition' => $attributes['disposition'] ?? 'permit',
        'claim_type' => $attributes['claim_type'] ?? null,
        'record_digest' => $attributes['record_digest'] ?? null,
        'argument_fingerprint' => $attributes['argument_fingerprint'] ?? null,
        'actor_fingerprint' => $attributes['actor_fingerprint'] ?? null,
        'approval_receipt_fingerprint' => $attributes['approval_receipt_fingerprint'] ?? null,
        'configuration_fingerprint' => $attributes['configuration_fingerprint'] ?? null,
        'rate_limit_key_fingerprint' => $attributes['rate_limit_key_fingerprint'] ?? null,
        'invocation_id' => $attributes['invocation_id'] ?? null,
        'recorded_at' => $attributes['recorded_at'],
    ]);
}

function browserPage(): Testable
{
    return Livewire::actingAs(new GenericUser(['id' => 'operator-1']))->test(EvidenceBrowser::class);
}

beforeEach(function (): void {
    config()->set('verdict.evidence.table', 'console_filament_evidence');
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    $migrations = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';

    foreach (BROWSER_EVIDENCE_STUBS as $stub) {
        (require $migrations.'/'.$stub)->up();
    }

    (require dirname(__DIR__, 2).'/vendor/fissible/verdict-console/database/migrations/create_verdict_console_conversation_invocations_table.php.stub')->up();

    $this->evidence = new BrowserEvidenceQuery(app(EvidenceQuery::class));
    app()->instance(EvidenceQuery::class, $this->evidence);

    Filament::setCurrentPanel('testing');
});

afterEach(function (): void {
    Schema::dropIfExists('console_filament_evidence');
    Schema::dropIfExists('verdict_console_conversation_invocations');
});

/** Same guard the core suites carry: a new Verdict evidence stub must not leave this fixture behind. */
it('builds its fixture from every evidence-table stub the installed Verdict publishes', function (): void {
    $published = array_map(basename(...), glob(dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/*verdict_evidence_table.php.stub') ?: []);

    expect(BROWSER_EVIDENCE_STUBS)->toEqualCanonicalizing($published);
});

it('registers the evidence browser page through the plugin', function (): void {
    expect(Filament::getCurrentPanel()->getPlugin('verdict-console'))
        ->toBeInstanceOf(VerdictConsoleFilamentPlugin::class)
        ->and(Filament::getCurrentPanel()->getPages())
        ->toContain(EvidenceBrowser::class);
});

/**
 * The design's hard constraint (§6.6): the default recorder is the null one, so a fresh install's
 * browser is blank BY CONFIG. It must say that, in the same words every console surface uses, and
 * must not render an empty table that reads as "nothing happened".
 */
it('says recording is off — blank by config — instead of rendering an empty table', function (): void {
    insertBrowserEvidence(['id' => 'invisible', 'recorded_at' => '2026-08-30 10:00:00']);

    browserPage()
        ->assertOk()
        ->assertSee('recording is off — blank by config.')
        ->assertDontSee('No decisions have been recorded.')
        ->assertDontSee('invisible');
});

it('names the writer when evidence is retained somewhere this browser cannot read', function (): void {
    config()->set('verdict.evidence.writer', 'App\\Evidence\\ExternalWriter');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    browserPage()
        ->assertOk()
        ->assertSee('App\\Evidence\\ExternalWriter')
        ->assertDontSee('recording is off')
        ->assertDontSee('No decisions have been recorded.');
});

/** Recording on with nothing recorded is a different fact from recording off, and reads differently. */
it('says nothing has been recorded when recording is on and the table is empty', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    browserPage()
        ->assertOk()
        ->assertSee('No decisions have been recorded.')
        ->assertDontSee('recording is off');
});

it('renders decision records newest first with claim type and record digest, never provenance rows', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertBrowserEvidence(['id' => 'older', 'recorded_at' => '2026-08-30 10:00:00', 'record_digest' => 'canonicaljson-sha256:older']);
    insertBrowserEvidence(['id' => 'newer', 'disposition' => 'deny', 'stage' => 'execution', 'claim_type' => 'policy.denied', 'record_digest' => 'canonicaljson-sha256:newer', 'recorded_at' => '2026-08-30 10:05:00']);
    insertBrowserEvidence(['id' => 'provenance-row', 'record_type' => 'provenance', 'stage' => 'input', 'disposition' => 'recorded', 'recorded_at' => '2026-08-30 10:06:00']);

    browserPage()
        ->assertOk()
        ->assertCountTableRecords(2)
        ->assertSeeInOrder(['canonicaljson-sha256:newer', 'canonicaljson-sha256:older'])
        ->assertTableColumnStateSet('disposition', 'deny', record: 'newer')
        ->assertTableColumnStateSet('stage', 'execution', record: 'newer')
        ->assertTableColumnStateSet('claim_type', 'policy.denied', record: 'newer')
        ->assertTableColumnStateSet('record_digest', 'canonicaljson-sha256:newer', record: 'newer')
        ->assertTableColumnStateSet('capability', 'orders.cancel', record: 'older')
        ->assertDontSee('provenance-row');
});

/**
 * The page is a consumer of the boundary, not a second implementation of it: state comes from the
 * boundary's answer even when config contradicts it, rows come from the boundary even when the
 * database disagrees, and the detail view shows the boundary's record — a browser that re-derived
 * any of these from config or SQL would break the host-replaceable read contract.
 */
it('renders the boundarys answer, not configs opinion or the databases contents', function (): void {
    // Config says database recording; the database holds a row; the bound boundary says Off.
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertBrowserEvidence(['id' => 'in-the-database', 'record_digest' => 'canonicaljson-sha256:in-db', 'recorded_at' => '2026-08-30 10:00:00']);
    app()->instance(EvidenceQuery::class, new CannedEvidenceQuery(new EvidenceQueryResult(EvidenceRecordingState::Off, [])));

    browserPage()
        ->assertOk()
        ->assertSee('recording is off — blank by config.')
        ->assertDontSee('canonicaljson-sha256:in-db');

    // And the reverse: the boundary answers On with a record the database has never held.
    app()->instance(EvidenceQuery::class, new CannedEvidenceQuery(new EvidenceQueryResult(
        EvidenceRecordingState::On,
        [cannedRecord('from-the-boundary', 'canonicaljson-sha256:boundary')],
    )));

    browserPage()
        ->assertOk()
        ->assertSee('canonicaljson-sha256:boundary')
        ->assertDontSee('canonicaljson-sha256:in-db');
});

it('shows the boundarys record in the detail view, not a re-query of the database', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    app()->instance(EvidenceQuery::class, new CannedEvidenceQuery(new EvidenceQueryResult(
        EvidenceRecordingState::On,
        [cannedRecord('boundary-only', 'canonicaljson-sha256:boundary-only')],
    )));

    // No such row exists in verdict_evidence: a detail view that re-queried by id would find
    // nothing to show.
    browserPage()
        ->mountAction(TestAction::make('view')->table('boundary-only'))
        ->assertSee('canonicaljson-sha256:boundary-only');
});

/**
 * The load-bearing property: a filter is a question put to the read boundary, not a sieve over
 * whatever the browser already fetched. The decorator proves the EvidenceFilter carried it.
 */
it('filters by disposition through the read boundary, not in memory', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertBrowserEvidence(['id' => 'permitted', 'disposition' => 'permit', 'recorded_at' => '2026-08-30 10:00:00']);
    insertBrowserEvidence(['id' => 'denied', 'disposition' => 'deny', 'record_digest' => 'canonicaljson-sha256:denied', 'recorded_at' => '2026-08-30 10:01:00']);

    browserPage()
        ->filterTable('disposition', 'deny')
        ->assertCountTableRecords(1)
        ->assertSee('canonicaljson-sha256:denied');

    expect(test()->evidence->lastFilter()?->disposition)->toBe('deny');
});

it('filters by capability through the read boundary, not in memory', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertBrowserEvidence(['id' => 'orders', 'capability' => 'orders.cancel', 'recorded_at' => '2026-08-30 10:00:00']);
    insertBrowserEvidence(['id' => 'billing', 'capability' => 'billing.refund', 'record_digest' => 'canonicaljson-sha256:billing', 'recorded_at' => '2026-08-30 10:01:00']);

    browserPage()
        ->filterTable('capability', 'billing.refund')
        ->assertCountTableRecords(1)
        ->assertSee('canonicaljson-sha256:billing');

    expect(test()->evidence->lastFilter()?->capability)->toBe('billing.refund');
});

it('carries both filters together through one EvidenceFilter', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertBrowserEvidence(['id' => 'orders-deny', 'capability' => 'orders.cancel', 'disposition' => 'deny', 'record_digest' => 'canonicaljson-sha256:orders-deny', 'recorded_at' => '2026-08-30 10:00:00']);
    insertBrowserEvidence(['id' => 'orders-permit', 'capability' => 'orders.cancel', 'disposition' => 'permit', 'recorded_at' => '2026-08-30 10:01:00']);
    insertBrowserEvidence(['id' => 'billing-deny', 'capability' => 'billing.refund', 'disposition' => 'deny', 'recorded_at' => '2026-08-30 10:02:00']);

    browserPage()
        ->filterTable('disposition', 'deny')
        ->filterTable('capability', 'orders.cancel')
        ->assertCountTableRecords(1)
        ->assertSee('canonicaljson-sha256:orders-deny');

    $filter = test()->evidence->lastFilter();

    expect($filter?->disposition)->toBe('deny')
        ->and($filter?->capability)->toBe('orders.cancel');
});

it('clears a filter back through the boundary rather than restoring cached rows', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertBrowserEvidence(['id' => 'permitted', 'disposition' => 'permit', 'recorded_at' => '2026-08-30 10:00:00']);
    insertBrowserEvidence(['id' => 'denied', 'disposition' => 'deny', 'recorded_at' => '2026-08-30 10:01:00']);

    browserPage()
        ->filterTable('disposition', 'deny')
        ->assertCountTableRecords(1)
        ->filterTable('disposition', null)
        ->assertCountTableRecords(2);

    expect(test()->evidence->lastFilter()?->disposition)->toBeNull();
});

/**
 * The escape the delegating decorator cannot see: a page could send the correct EvidenceFilter —
 * telemetry green — then ignore the boundary's answer and sieve a cached set locally, and against
 * a deterministic database the two coincide. Here the boundary's scripted answers contradict any
 * local sieve at every step: what renders is what was answered, or this test fails.
 */
it('renders the rows the boundary answered for each filter, never a local sieve', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertBrowserEvidence(['id' => 'db-deny', 'disposition' => 'deny', 'record_digest' => 'canonicaljson-sha256:db-deny', 'recorded_at' => '2026-08-30 10:00:00']);

    $scripted = new ScriptedEvidenceQuery([
        new EvidenceQueryResult(EvidenceRecordingState::On, [cannedRecord('initial-answer', 'canonicaljson-sha256:initial')]),
        new EvidenceQueryResult(EvidenceRecordingState::On, [cannedRecord('filtered-answer', 'canonicaljson-sha256:filtered')]),
        new EvidenceQueryResult(EvidenceRecordingState::On, [cannedRecord('cleared-answer', 'canonicaljson-sha256:cleared')]),
    ]);
    app()->instance(EvidenceQuery::class, $scripted);

    $page = browserPage()
        ->assertCountTableRecords(1)
        ->assertSee('canonicaljson-sha256:initial')
        ->assertDontSee('canonicaljson-sha256:db-deny');

    $page->filterTable('disposition', 'deny')
        ->assertCountTableRecords(1)
        ->assertSee('canonicaljson-sha256:filtered')
        ->assertDontSee('canonicaljson-sha256:initial')
        ->assertDontSee('canonicaljson-sha256:db-deny');

    // Exactly the cleared answer: not the filtered one, and no cached initial or database rows
    // merged back in.
    $page->filterTable('disposition', null)
        ->assertCountTableRecords(1)
        ->assertSee('canonicaljson-sha256:cleared')
        ->assertDontSee('canonicaljson-sha256:filtered')
        ->assertDontSee('canonicaljson-sha256:initial')
        ->assertDontSee('canonicaljson-sha256:db-deny');

    // And the filters really were the questions asked, in order.
    $asked = array_map(fn (EvidenceFilter $filter): ?string => $filter->disposition, $scripted->filters);

    expect($asked)->toBe([null, 'deny', null]);
});

/**
 * VC-29's infolist: the row's evidence vocabulary on demand. Five distinct fingerprints force a
 * projection over the record's fingerprint set rather than two hand-picked fields; each is a
 * distinct value so a swapped label cannot pass.
 */
it('shows a records digest, claim type, and fingerprints in its detail view', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertBrowserEvidence([
        'id' => 'detailed',
        'disposition' => 'deny',
        'claim_type' => 'policy.denied',
        'record_digest' => 'canonicaljson-sha256:detailed',
        'argument_fingerprint' => str_repeat('a', 64),
        'actor_fingerprint' => str_repeat('b', 64),
        'approval_receipt_fingerprint' => str_repeat('c', 64),
        'configuration_fingerprint' => str_repeat('d', 64),
        'rate_limit_key_fingerprint' => str_repeat('e', 64),
        'invocation_id' => 'invocation-9',
        'recorded_at' => '2026-08-30 10:00:00',
    ]);

    browserPage()
        ->mountAction(TestAction::make('view')->table('detailed'))
        ->assertSee('canonicaljson-sha256:detailed')
        ->assertSee('policy.denied')
        ->assertSee(str_repeat('a', 64))
        ->assertSee(str_repeat('b', 64))
        ->assertSee(str_repeat('c', 64))
        ->assertSee(str_repeat('d', 64))
        ->assertSee(str_repeat('e', 64))
        ->assertSee('invocation-9');
});

/** An audit surface mutates nothing: one read-only detail action, and not one other control. */
it('offers the detail view and nothing else — no bulk, no mutation, anywhere', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertBrowserEvidence(['id' => 'row', 'recorded_at' => '2026-08-30 10:00:00']);

    $table = browserPage()->instance()->getTable();
    $inventory = array_keys($table->getFlatActions());
    sort($inventory);

    expect($inventory)->toBe(['view'])
        ->and($table->getFlatBulkActions())->toBe([]);
});
