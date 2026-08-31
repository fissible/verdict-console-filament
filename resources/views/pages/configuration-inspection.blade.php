<x-filament-panels::page>
    <section>
        <h2>Capabilities</h2>
        @foreach ($capabilities as $capability)
            <article>
                <p>{{ $capability->name }}</p>
                <p>{{ $capability->ability }}</p>
                <p>{{ $capability->configurationFingerprint }}</p>
                @if ($capability->confirmationReason)
                    <p>{{ $capability->confirmationReason }}</p>
                @endif
                @if ($capability->rateLimit)
                    <p>{{ $capability->rateLimit->name }}</p>
                    <p>{{ $capability->rateLimit->windowSeconds }}</p>
                @endif
            </article>
        @endforeach
    </section>

    <section>
        <h2>Rate limits</h2>
        @foreach ($rateLimits as $rateLimit)
            <article>
                <p>{{ $rateLimit->name }}</p>
                <p>{{ $rateLimit->limit }}</p>
                <p>{{ $rateLimit->windowSeconds }}</p>
                @if ($rateLimit->reason)
                    <p>{{ $rateLimit->reason }}</p>
                @endif
            </article>
        @endforeach
    </section>

    <section>
        <h2>Approval rules</h2>
        @if ($approvalRules->authorizer)
            <p>{{ $approvalRules->authorizer }}</p>
        @endif
        <p>{{ $approvalRules->gateAbility }}</p>
    </section>
</x-filament-panels::page>
