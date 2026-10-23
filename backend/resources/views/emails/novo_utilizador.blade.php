@extends('emails.layout')

@section('titulo', 'O seu acesso ao ERP Consulvolt')
@section('preheader', 'Foi criado um acesso para si no ERP Consulvolt.')

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
Foi criado um acesso para si no ERP Consulvolt, na empresa <strong>{{ $nomeEmpresa }}</strong>.
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

<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Entrar no ERP'])</td></tr>

<tr><td style="padding-top:8px; font-size:13px; color:#64748b;">
Por segurança, recomendamos que altere a sua password depois do primeiro acesso em <strong>Minha conta &rarr; Alterar password</strong>.
Não partilhe estas credenciais com mais ninguém.
</td></tr>
@endsection
