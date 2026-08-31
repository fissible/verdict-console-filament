<x-filament-panels::page>
    @if ($findings === [])
        <p>Every console precondition is satisfied.</p>
    @else
        @foreach ($findings as $finding)
            <article>
                <p>{{ $finding->code->value }}</p>
                <p>{{ $finding->severity->value }}</p>
                <p>{{ $finding->subject }}</p>
                <p>{{ $finding->summary }}</p>
                <p>{{ $finding->fix }}</p>
            </article>
        @endforeach
    @endif
</x-filament-panels::page>
