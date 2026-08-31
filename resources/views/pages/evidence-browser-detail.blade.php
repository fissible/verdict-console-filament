<dl>
    @foreach ($record as $name => $value)
        @if ($value !== null)
            <dt>{{ str($name)->replace('_', ' ')->ucfirst() }}</dt>
            <dd>{{ $value }}</dd>
        @endif
    @endforeach
</dl>
