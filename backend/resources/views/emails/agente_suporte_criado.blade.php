@extends('emails.layout')

@section('titulo', 'Acesso ao backoffice de suporte')
@section('preheader', 'Foi criado um acesso de agente de suporte Consulvolt.')

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
Foi criado um acesso de <strong>agente de suporte</strong> ao backoffice da plataforma Consulvolt — aqui só vê e responde a tickets dos clientes, isolado do resto da administração.
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#2563eb', 'fundo' => '#eff6ff',
    'slot' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        .'<tr><td style="padding-bottom:6px; color:#64748b; width:140px;">Utilizador</td><td style="font-weight:600; font-family:monospace; font-size:14px;">'.e($nomeUtilizador).'</td></tr>'
        .'<tr><td style="color:#64748b;">Password</td><td style="font-weight:600; font-family:monospace; font-size:14px;">'.e($palavraPasse).'</td></tr>'
        .'</table>',
])
</td></tr>

<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Entrar no backoffice de suporte'])</td></tr>

<tr><td style="padding-top:8px; font-size:13px; color:#64748b;">
Altere a password depois do primeiro acesso e nunca a partilhe.
</td></tr>
@endsection
