{{-- Botão de acção (CTA). Uso: @include('emails.componentes.botao', ['url' => $url, 'texto' => 'Entrar no ERP']) --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">
<tr>
<td style="border-radius:4px; background-color:{{ $cor ?? '#2563eb' }};">
<a href="{{ $url }}" target="_blank" style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:4px;">
{{ $texto }}
</a>
</td>
</tr>
</table>
