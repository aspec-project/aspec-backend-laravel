@props(['url'])
@if (filled(config('mail.calendar_icon_url')))
<a href="{{ $url }}" title="Adicionar ao calendário" aria-label="Adicionar ao calendário" target="_blank" rel="noopener"><img src="{{ config('mail.calendar_icon_url') }}" width="24" height="24" alt="Adicionar ao calendário" style="display:inline-block;vertical-align:middle;border:0"></a>
@else
<a href="{{ $url }}" title="Adicionar ao calendário" aria-label="Adicionar ao calendário" target="_blank" rel="noopener" style="font-size:13px">Calendário</a>
@endif
