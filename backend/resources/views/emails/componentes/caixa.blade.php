{{-- Caixa de destaque (credenciais, avisos). Uso: @include('emails.componentes.caixa', ['cor' => '#f59e0b', 'fundo' => '#fffbeb', 'slot' => '...html...']) --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0; background-color:{{ $fundo ?? '#f8fafc' }}; border-left:3px solid {{ $cor ?? '#2563eb' }}; border-radius:4px;">
<tr>
<td style="padding:16px 18px; font-size:13.5px; color:#1e293b; line-height:1.6;">
{!! $slot !!}
</td>
</tr>
</table>
