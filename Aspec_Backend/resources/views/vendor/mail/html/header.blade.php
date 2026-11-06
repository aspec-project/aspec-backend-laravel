@props(['url'])
{{-- bgcolor e width/height como atributos porque o Outlook ignora estes valores quando vêm só em CSS; o alt branco aparece sobre o navy quando as imagens estão bloqueadas. --}}
<tr>
<td class="header" bgcolor="#0D1F35">
<a href="{{ $url }}" style="display: inline-block;">
@if (filled(config('mail.logo_url')))
<img src="{{ config('mail.logo_url') }}" width="240" height="80" alt="{{ config('app.name') }}" style="display: block; border: 0; width: 240px; height: 80px; color: #FFFFFF; font-size: 19px; font-weight: bold;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
