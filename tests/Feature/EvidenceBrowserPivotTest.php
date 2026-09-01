<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidencePage;
use Fissible\VerdictConsole\Evidence\EvidenceQueryResult;
use Fissible\VerdictConsole\Evidence\EvidenceRecord;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsoleFilament\Pages\EvidenceBrowser;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * #16: each non-null fingerprint the read boundary can pivot on becomes click-through forensics —
 * a control in the detail view that filters the browser THROUGH the boundary, never a local sieve.
 * Only the six fields verdict-console#102 gave EvidenceFilter may pivot; every other fingerprint
 * stays inert text, because a control that cannot ask the boundary would have to sieve locally.
 * Fixtures are this file's own.
 */
const PIVOT_EVIDENCE_STUBS = [
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

/** The boundary's pivot vocabulary: table filter name => EvidenceFilter constructor parameter. */
const PIVOT_FIELDS = [
    'actor_fingerprint' => 'actorFingerprint',
    'subject_fingerprint' => 'subjectFingerprint',
    'argument_fingerprint' => 'argumentFingerprint',
    'approval_receipt_fingerprint' => 'approvalReceiptFingerprint',
    'configuration_fingerprint' => 'configurationFingerprint',
    'execution_claim_fingerprint' => 'executionClaimFingerprint',
];

/** Fingerprints the detail view shows but the boundary cannot pivot on: they must offer no control. */
const PIVOTLESS_FIELDS = [
    'idempotency_key_fingerprint',
    'proposal_target_identity_fingerprint',
    'execution_target_identity_fingerprint',
    'rate_limit_key_fingerprint',
    'execution_claim_binding_fingerprint',
];

/** Delegates to the real boundary, recording every filter the browser sends through it. */
final class PivotRecordingEvidenceQuery implements EvidenceQuery
{
    /** @var list<EvidenceFilter> */
    public array $filters = [];

    public function __construct(private readonly EvidenceQuery $inner) {}

    public function search(EvidenceFilter $filter): EvidenceQueryResult
    {
        $this->filters[] = $filter;

        return $this->inner->search($filter);
    }

    public function searchPage(EvidenceFilter $filter, int $page, int $perPage): EvidencePage
    {
        $this->filters[] = $filter;

        return $this->inner->searchPage($filter, $page, $perPage);
    }

    public function lastFilter(): ?EvidenceFilter
    {
        return $this->filters === [] ? null : $this->filters[array_key_last($this->filters)];
    }
}

/** Scripted paged answers, one per read, recording each question asked — re-answering the last. */
final class PivotScriptedEvidenceQuery implements EvidenceQuery
{
    /** @var list<array{filter: EvidenceFilter, page: int}> */
    public array $asked = [];

    /** @param list<EvidencePage> $pages */
    public function __construct(private array $pages) {}

    public function search(EvidenceFilter $filter): EvidenceQueryResult
    {
        throw new LogicException('The evidence browser reads pages from the boundary.');
    }

    public function searchPage(EvidenceFilter $filter, int $page, int $perPage): EvidencePage
    {
        $this->asked[] = ['filter' => $filter, 'page' => $page];

        return count($this->pages) > 1 ? array_shift($this->pages) : $this->pages[0];
    }
}

/**
 * A record whose every fingerprint the boundary supports is set — except the ones named in
 * $nulls — and whose every boundary-unsupported fingerprint is set too.
 *
 * @param  list<string>  $nulls
 */
function pivotRecord(string $id, array $nulls = []): EvidenceRecord
{
    $print = fn (string $field): ?string => in_array($field, $nulls, true) ? null : 'sha256:'.str_replace('_', '-', $field).'-of-'.$id;

    return new EvidenceRecord(
        id: $id,
        capability: 'orders.cancel',
        stage: 'proposal',
        disposition: 'permit',
        claimType: null,
        recordDigest: 'canonicaljson-sha256:'.$id,
        argumentFingerprint: $print('argument_fingerprint'),
        idempotencyKeyFingerprint: $print('idempotency_key_fingerprint'),
        approvalReceiptFingerprint: $print('approval_receipt_fingerprint'),
        configurationFingerprint: $print('configuration_fingerprint'),
        actorFingerprint: $print('actor_fingerprint'),
        subjectFingerprint: $print('subject_fingerprint'),
        proposalTargetIdentityFingerprint: $print('proposal_target_identity_fingerprint'),
        executionTargetIdentityFingerprint: $print('execution_target_identity_fingerprint'),
        rateLimitKeyFingerprint: $print('rate_limit_key_fingerprint'),
        executionClaimFingerprint: $print('execution_claim_fingerprint'),
        executionClaimBindingFingerprint: $print('execution_claim_binding_fingerprint'),
        invocationId: null,
        rateLimitResetAt: null,
        recordedAt: new DateTimeImmutable('2026-08-31 12:00:00+00:00'),
    );
}

/** @param array<string, mixed> $attributes */
function insertPivotEvidence(array $attributes): void
{
    DB::table('console_pivot_evidence')->insert([
        'id' => $attributes['id'],
        'record_type' => $attributes['record_type'] ?? 'decision',
        'capability' => $attributes['capability'] ?? 'orders.cancel',
        'stage' => $attributes['stage'] ?? 'proposal',
        'disposition' => $attributes['disposition'] ?? 'permit',
        'record_digest' => $attributes['record_digest'] ?? null,
        'actor_fingerprint' => $attributes['actor_fingerprint'] ?? null,
        'subject_fingerprint' => $attributes['subject_fingerprint'] ?? null,
        'argument_fingerprint' => $attributes['argument_fingerprint'] ?? null,
        'approval_receipt_fingerprint' => $attributes['approval_receipt_fingerprint'] ?? null,
        'configuration_fingerprint' => $attributes['configuration_fingerprint'] ?? null,
        'execution_claim_fingerprint' => $attributes['execution_claim_fingerprint'] ?? null,
        'recorded_at' => $attributes['recorded_at'],
    ]);
}

function pivotBrowserPage(): Testable
{
    return Livewire::actingAs(new GenericUser(['id' => 'operator-1']))->test(EvidenceBrowser::class);
}

/** The [data-pivot] element's own wire:click binding — the control and its wiring on one element. */
function pivotControlBinding(string $html, string $field): ?string
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    foreach ((new DOMXPath($document))->query('//*[@data-pivot="'.$field.'"]') ?: [] as $node) {
        if ($node instanceof DOMElement) {
            return $node->getAttribute('wire:click') === '' ? null : $node->getAttribute('wire:click');
        }
    }

    return null;
}

beforeEach(function (): void {
    config()->set('verdict.evidence.table', 'console_pivot_evidence');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $migrations = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';

    foreach (PIVOT_EVIDENCE_STUBS as $stub) {
        (require $migrations.'/'.$stub)->up();
    }

    (require dirname(__DIR__, 2).'/vendor/fissible/verdict-console/database/migrations/create_verdict_console_conversation_invocations_table.php.stub')->up();

    $this->evidence = new PivotRecordingEvidenceQuery(app(EvidenceQuery::class));
    app()->instance(EvidenceQuery::class, $this->evidence);

    Filament::setCurrentPanel('testing');
});

afterEach(function (): void {
    Schema::dropIfExists('console_pivot_evidence');
    Schema::dropIfExists('verdict_console_conversation_invocations');
});

/** Same guard every evidence fixture carries: a new Verdict stub must not leave this file behind. */
it('builds its fixture from every evidence-table stub the installed Verdict publishes', function (): void {
    $published = array_map(basename(...), glob(dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/*verdict_evidence_table.php.stub') ?: []);

    expect(PIVOT_EVIDENCE_STUBS)->toEqualCanonicalizing($published);
});

/**
 * The detail view's pivot inventory is exactly the boundary's: a control for each non-null field
 * EvidenceFilter can carry — wired to the pivot method with that field's own value — none for a
 * null one, and none, however non-null, for a fingerprint the boundary cannot ask about, because
 * that control could only ever sieve locally.
 */
it('offers a wired pivot for each non-null boundary fingerprint and for nothing else', function (): void {
    app()->instance(EvidenceQuery::class, new PivotScriptedEvidenceQuery([
        new EvidencePage(EvidenceRecordingState::On, [pivotRecord('detailed')], 1, 1, 10),
    ]));

    $page = pivotBrowserPage()->mountAction(TestAction::make('view')->table('detailed'));

    foreach (array_keys(PIVOT_FIELDS) as $field) {
        // The control element carries its own Livewire binding — the wire:click with the method
        // and the record's own value ON the [data-pivot] element: a dead labelled control beside
        // a bound element elsewhere, static call-shaped text, or a wrong-value wiring all fail.
        // (Actually dispatching the click needs a browser; within this harness the attribute IS
        // the wiring, and the method's behavior is exercised through call() below.)
        expect(pivotControlBinding($page->html(), $field))
            ->toBe("pivotOnFingerprint('".$field."', 'sha256:".str_replace('_', '-', $field)."-of-detailed')", "The {$field} control must bind the pivot on its own element.");
    }

    foreach (PIVOTLESS_FIELDS as $field) {
        // The value renders — the detail view still shows the whole record — but no control does,
        // marked or not: no data-pivot marker and no binding naming the field anywhere.
        $page->assertSee('sha256:'.str_replace('_', '-', $field).'-of-detailed')
            ->assertDontSeeHtml('data-pivot="'.$field.'"')
            ->assertDontSeeHtml("pivotOnFingerprint('".$field."'");
    }
});

/** A null fingerprint offers no pivot — pinned for every one of the six, not a sampled field. */
it('offers no pivot for a null fingerprint, whichever field is null', function (): void {
    foreach (array_keys(PIVOT_FIELDS) as $nullField) {
        app()->instance(EvidenceQuery::class, new PivotScriptedEvidenceQuery([
            new EvidencePage(EvidenceRecordingState::On, [pivotRecord('detailed', nulls: [$nullField])], 1, 1, 10),
        ]));

        $page = pivotBrowserPage()->mountAction(TestAction::make('view')->table('detailed'));

        $page->assertDontSeeHtml('data-pivot="'.$nullField.'"')
            ->assertDontSeeHtml("pivotOnFingerprint('".$nullField."'");

        foreach (array_keys(PIVOT_FIELDS) as $field) {
            if ($field !== $nullField) {
                $page->assertSeeHtml('data-pivot="'.$field.'"');
            }
        }
    }
});

/**
 * Activating a pivot is a question put to the read boundary: the EvidenceFilter carries the value
 * on its matching field — for every one of the six — and the rendered rows are the boundary's
 * answer to it.
 */
it('pivots each boundary fingerprint through the boundary, never a local sieve', function (): void {
    foreach (PIVOT_FIELDS as $field => $parameter) {
        insertPivotEvidence(['id' => "shared-{$field}", $field => "sha256:{$field}-shared", 'record_digest' => "canonicaljson-sha256:shared-{$field}", 'recorded_at' => '2026-08-31 10:00:00']);
        insertPivotEvidence(['id' => "also-{$field}", $field => "sha256:{$field}-shared", 'recorded_at' => '2026-08-31 10:01:00']);
        insertPivotEvidence(['id' => "other-{$field}", $field => "sha256:{$field}-different", 'recorded_at' => '2026-08-31 10:02:00']);
    }

    foreach (PIVOT_FIELDS as $field => $parameter) {
        // An ordinary filter is already active when each pivot lands: the pivot must compose with
        // it in the one EvidenceFilter, never overwrite it.
        $page = pivotBrowserPage()
            ->filterTable('disposition', 'permit')
            ->call('pivotOnFingerprint', $field, "sha256:{$field}-shared");

        $page->assertCountTableRecords(2)
            ->assertSee("canonicaljson-sha256:shared-{$field}");

        $filter = test()->evidence->lastFilter();

        expect($filter?->{$parameter})->toBe("sha256:{$field}-shared", "The {$field} pivot must reach the boundary on {$parameter}.")
            ->and($filter?->disposition)->toBe('permit', "The {$field} pivot must not cost the active disposition filter.");
    }
});

/** Two pivots coexist in the one EvidenceFilter, and clearing one leaves the other asking. */
it('holds two pivots at once and clears them one at a time through the boundary', function (): void {
    insertPivotEvidence(['id' => 'both', 'actor_fingerprint' => 'sha256:actor-a', 'subject_fingerprint' => 'sha256:subject-s', 'recorded_at' => '2026-08-31 10:00:00']);
    insertPivotEvidence(['id' => 'actor-only', 'actor_fingerprint' => 'sha256:actor-a', 'recorded_at' => '2026-08-31 10:01:00']);

    $page = pivotBrowserPage()
        ->call('pivotOnFingerprint', 'actor_fingerprint', 'sha256:actor-a')
        ->call('pivotOnFingerprint', 'subject_fingerprint', 'sha256:subject-s')
        ->assertCountTableRecords(1);

    $filter = test()->evidence->lastFilter();

    expect($filter?->actorFingerprint)->toBe('sha256:actor-a')
        ->and($filter?->subjectFingerprint)->toBe('sha256:subject-s');

    $page->removeTableFilter('subject_fingerprint')->assertCountTableRecords(2);

    $filter = test()->evidence->lastFilter();

    expect($filter?->actorFingerprint)->toBe('sha256:actor-a', 'Clearing one pivot must not clear the other.')
        ->and($filter?->subjectFingerprint)->toBeNull();
});

/** The acceptance line: a pivot composes with already-active filters in the one EvidenceFilter. */
it('composes an activated pivot with the active disposition and capability filters', function (): void {
    insertPivotEvidence(['id' => 'all-match', 'capability' => 'orders.cancel', 'disposition' => 'deny', 'actor_fingerprint' => 'sha256:actor-a', 'record_digest' => 'canonicaljson-sha256:all-match', 'recorded_at' => '2026-08-31 10:00:00']);
    insertPivotEvidence(['id' => 'other-actor', 'capability' => 'orders.cancel', 'disposition' => 'deny', 'actor_fingerprint' => 'sha256:actor-y', 'recorded_at' => '2026-08-31 10:01:00']);
    insertPivotEvidence(['id' => 'other-disposition', 'capability' => 'orders.cancel', 'disposition' => 'permit', 'actor_fingerprint' => 'sha256:actor-a', 'recorded_at' => '2026-08-31 10:02:00']);
    insertPivotEvidence(['id' => 'other-capability', 'capability' => 'billing.refund', 'disposition' => 'deny', 'actor_fingerprint' => 'sha256:actor-a', 'recorded_at' => '2026-08-31 10:03:00']);

    pivotBrowserPage()
        ->filterTable('disposition', 'deny')
        ->filterTable('capability', 'orders.cancel')
        ->call('pivotOnFingerprint', 'actor_fingerprint', 'sha256:actor-a')
        ->assertCountTableRecords(1)
        ->assertSee('canonicaljson-sha256:all-match');

    $filter = test()->evidence->lastFilter();

    expect($filter?->disposition)->toBe('deny')
        ->and($filter?->capability)->toBe('orders.cancel')
        ->and($filter?->actorFingerprint)->toBe('sha256:actor-a');

    // Clearing the pivot clears the pivot alone: the ordinary filters keep restricting the view.
    pivotBrowserPage()
        ->filterTable('disposition', 'deny')
        ->filterTable('capability', 'orders.cancel')
        ->call('pivotOnFingerprint', 'actor_fingerprint', 'sha256:actor-a')
        ->removeTableFilter('actor_fingerprint')
        ->assertCountTableRecords(2);

    $filter = test()->evidence->lastFilter();

    expect($filter?->disposition)->toBe('deny', 'Removing the pivot must not cost the disposition filter.')
        ->and($filter?->capability)->toBe('orders.cancel', 'Removing the pivot must not cost the capability filter.')
        ->and($filter?->actorFingerprint)->toBeNull();
});

/**
 * The anti-sieve discipline extended to pivoted renders: the boundary's scripted answers contradict
 * any local sieve at every step, the active pivot is visible while no answered row carries its
 * value, and clearing it is a fresh question — not a cached restore.
 */
it('renders the boundarys answer for a pivot, shows it active, and clears it through the boundary', function (): void {
    insertPivotEvidence(['id' => 'db-row', 'actor_fingerprint' => 'sha256:actor-a', 'record_digest' => 'canonicaljson-sha256:db-row', 'recorded_at' => '2026-08-31 10:00:00']);

    $scripted = new PivotScriptedEvidenceQuery([
        new EvidencePage(EvidenceRecordingState::On, [pivotRecord('initial-answer')], 41, 1, 10),
        new EvidencePage(EvidenceRecordingState::On, [pivotRecord('page-two-answer')], 41, 2, 10),
        new EvidencePage(EvidenceRecordingState::On, [pivotRecord('pivoted-answer')], 7, 1, 10),
        new EvidencePage(EvidenceRecordingState::On, [pivotRecord('pivoted-page-two')], 7, 2, 10),
        new EvidencePage(EvidenceRecordingState::On, [pivotRecord('cleared-answer')], 41, 1, 10),
    ]);
    app()->instance(EvidenceQuery::class, $scripted);

    // The operator is deep in the feed when the pivot lands: narrowing from page 2 must ask the
    // boundary for page 1 of the pivoted view, never page 2 of a 7-row answer.
    $page = pivotBrowserPage()
        ->assertSee('canonicaljson-sha256:initial-answer')
        ->assertDontSee('sha256:pivot-target')
        ->call('gotoPage', 2)
        ->assertSee('canonicaljson-sha256:page-two-answer');

    // No scripted row carries the pivot value, so the labelled indicator is the only way this
    // string can render: the pivot is presented as active filter state, not as leftover text.
    $page->call('pivotOnFingerprint', 'actor_fingerprint', 'sha256:pivot-target')
        ->assertSee('canonicaljson-sha256:pivoted-answer')
        ->assertSee('Actor fingerprint: sha256:pivot-target')
        ->assertDontSee('canonicaljson-sha256:initial-answer')
        ->assertDontSee('canonicaljson-sha256:db-row');

    // And clearing from deep in the pivoted view lands back on page 1 of the unpivoted one.
    $page->call('gotoPage', 2)
        ->assertSee('canonicaljson-sha256:pivoted-page-two')
        ->removeTableFilter('actor_fingerprint')
        ->assertSee('canonicaljson-sha256:cleared-answer')
        ->assertDontSee('canonicaljson-sha256:pivoted-answer')
        ->assertDontSee('canonicaljson-sha256:db-row')
        ->assertDontSee('sha256:pivot-target');

    // The questions the boundary was actually asked, with the page each one requested.
    expect(array_map(fn (array $ask): array => [$ask['filter']->actorFingerprint, $ask['page']], $scripted->asked))
        ->toBe([
            [null, 1],
            [null, 2],
            ['sha256:pivot-target', 1],
            ['sha256:pivot-target', 2],
            [null, 1],
        ]);
});

/** The pivot method is the boundary vocabulary's, not an arbitrary-filter escape hatch. */
it('refuses to pivot on a fingerprint the boundary cannot ask about', function (): void {
    insertPivotEvidence(['id' => 'row', 'recorded_at' => '2026-08-31 10:00:00']);

    foreach ([...PIVOTLESS_FIELDS, 'recorded_at', 'not_a_fingerprint'] as $field) {
        // The refusal names the field: the exception message must carry it.
        expect(fn (): Testable => pivotBrowserPage()->call('pivotOnFingerprint', $field, 'sha256:whatever'))
            ->toThrow(InvalidArgumentException::class, $field);
    }

    // A refusal is a no-op: no question that reached the boundary — before or after the throw —
    // carried any pivot.
    expect(test()->evidence->filters)->not->toBe([]);

    foreach (test()->evidence->filters as $filter) {
        foreach (PIVOT_FIELDS as $parameter) {
            expect($filter->{$parameter})->toBeNull("A refused pivot must not leak into {$parameter}.");
        }
    }
});
