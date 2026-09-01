<dl>
    @foreach ($record as $name => $value)
        @if ($value !== null)
            @php($field = str($name)->snake()->toString())
            <dt>{{ str($name)->replace('_', ' ')->ucfirst() }}</dt>
            @if (in_array($field, [
                'actor_fingerprint',
                'subject_fingerprint',
                'argument_fingerprint',
                'approval_receipt_fingerprint',
                'configuration_fingerprint',
                'execution_claim_fingerprint',
            ], true))
                <dd>
                    <button type="button" data-pivot="{{ $field }}" wire:click="pivotOnFingerprint('{{ $field }}', '{{ $value }}')">
                        {{ $value }}
                    </button>
                </dd>
            @else
                <dd>{{ $value }}</dd>
            @endif
        @endif
    @endforeach
</dl>
