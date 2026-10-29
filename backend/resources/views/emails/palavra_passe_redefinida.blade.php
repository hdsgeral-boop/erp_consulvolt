@extends('emails.layout')

@section('titulo', 'A sua password foi redefinida')
@section('preheader', 'Um administrador da sua empresa redefiniu a sua password.')

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
Um administrador da empresa <strong>{{ $nomeEmpresa }}</strong> redefiniu a password da sua conta (<strong>{{ $nomeUtilizador }}</strong>).
As suas sessões abertas foram terminadas por segurança.
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

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#dc2626', 'fundo' => '#fef2f2',
    'slot' => '<strong style="color:#1e293b;">Não foi você?</strong><br>'
        .'Se não esperava esta alteração, contacte imediatamente outro administrador da sua empresa ou o suporte Consulvolt.',
])
</td></tr>

<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Entrar no ERP'])</td></tr>
@endsection
