@extends('emails.layout')

@section('titulo', 'A sua nova password')
@section('preheader', 'Recebemos um pedido de recuperação de password para a sua conta.')

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
Recebemos um pedido de recuperação de password para a conta <strong>{{ $nomeUtilizador }}</strong>. Gerámos uma nova password — as sessões antigas foram terminadas.
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#2563eb', 'fundo' => '#eff6ff',
    'slot' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        .'<tr><td style="padding-bottom:6px; color:#64748b; width:140px;">Utilizador</td><td style="font-weight:600; font-family:monospace; font-size:14px;">'.e($nomeUtilizador).'</td></tr>'
        .'<tr><td style="color:#64748b;">Nova password</td><td style="font-weight:600; font-family:monospace; font-size:14px;">'.e($palavraPasse).'</td></tr>'
        .'</table>',
])
</td></tr>

<tr><td style="padding-bottom:18px; color:#475569;">
Esta password é temporária: no próximo acesso, o sistema vai pedir-lhe para a trocar antes de poder continuar.
</td></tr>

<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Entrar no ERP'])</td></tr>

<tr><td style="padding-top:8px; font-size:13px; color:#64748b;">
Se não foi você a pedir esta recuperação, contacte imediatamente o administrador da sua empresa ou o suporte Consulvolt — a sua password já foi alterada.
</td></tr>
@endsection
