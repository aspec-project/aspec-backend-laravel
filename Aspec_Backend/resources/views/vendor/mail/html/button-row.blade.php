@props(['buttons'])
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
@foreach ($buttons as $button)
<td style="padding: 0 6px;">
<a href="{{ $button['url'] }}" class="button button-primary" target="_blank" rel="noopener">{{ $button['text'] }}</a>
</td>
@endforeach
</tr>
</table>
</td>
</tr>
</table>
