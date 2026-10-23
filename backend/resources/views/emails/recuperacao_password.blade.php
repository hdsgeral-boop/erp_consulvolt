@extends('emails.layout')

@section('titulo', 'Recuperação de password')
@section('preheader', 'Recebemos um pedido de recuperação de password para a sua conta.')

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
Recebemos um pedido de recuperação de password para a conta <strong>{{ $nomeUtilizador }}</strong>.
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#f59e0b', 'fundo' => '#fffbeb',
    'slot' => '<strong style="color:#1e293b;">Por segurança</strong><br>'
        .'Não redefinimos a password por email. Contacte o administrador da sua empresa — em <strong>Configurações &rarr; Utilizadores</strong>, '
        .'ele pode repor a sua password de imediato e as sessões antigas são terminadas automaticamente.',
])
</td></tr>

<tr><td style="padding-bottom:4px;">Se for o(a) administrador(a) da sua empresa e não tiver outro administrador para o ajudar, contacte o suporte Consulvolt.</td></tr>
<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Ir para o ERP'])</td></tr>

<tr><td style="padding-top:8px; font-size:13px; color:#64748b;">
Se não foi você a pedir esta recuperação, pode ignorar este email em segurança — nenhuma alteração foi feita na sua conta.
</td></tr>
@endsection
