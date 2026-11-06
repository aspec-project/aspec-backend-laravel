<x-mail::message>
# {{ $greeting }}

@foreach ($blocks as $block)
@if (is_string($block))
{{ $block }}

@elseif ($block['type'] === 'event')
**{{ $block['title'] }}**

{{ $block['details'] }} <x-mail::calendar-link :url="$block['calendar_url']" />

@elseif ($block['type'] === 'buttons')
<x-mail::button-row :buttons="$block['buttons']" />

@else
<x-mail::button :url="$block['url']">
{{ $block['text'] }}
</x-mail::button>

@endif
@endforeach
{{ $salutation }}
</x-mail::message>
