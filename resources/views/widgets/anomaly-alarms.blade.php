<div>
    @if ($incidents === [])
        <p>No incidents recorded.</p>
    @else
        <table>
            <thead>
                <tr><th>Source</th><th>Cause</th><th>Observed</th></tr>
            </thead>
            <tbody>
                @foreach ($incidents as $incident)
                    <tr>
                        <td>{{ $incident->source }}</td>
                        <td>{{ $incident->cause }}</td>
                        <td>{{ $incident->observed_at?->utc()->format(DATE_ATOM) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
