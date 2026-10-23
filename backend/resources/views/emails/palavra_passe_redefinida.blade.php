@extends('emails.layout')

@section('titulo', 'A sua password foi alterada')
@section('preheader', 'Um administrador da sua empresa redefiniu a sua password.')

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
Um administrador da empresa <strong>{{ $nomeEmpresa }}</strong> redefiniu a password da sua conta (<strong>{{ $nomeUtilizador }}</strong>).
As suas sessões abertas foram terminadas por segurança.
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
