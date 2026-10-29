@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- WebP : l'écu en SVG n'est pas affiché par Gmail ni Outlook. --}}
<img src="{{ asset('logo/mibeko-128.webp') }}" width="48" height="48" class="logo" alt="Écu de Mibeko">
<span class="brand">{!! trim($slot) !!}</span>
</a>
</td>
</tr>
