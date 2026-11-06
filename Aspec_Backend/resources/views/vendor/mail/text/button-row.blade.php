@props(['buttons'])
@foreach ($buttons as $button)
{{ $button['text'] }}: {{ $button['url'] }}

@endforeach
