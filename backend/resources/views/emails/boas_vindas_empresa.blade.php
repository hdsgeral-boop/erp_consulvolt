@extends('emails.layout')

@section('titulo', 'Bem-vindo à Consulvolt')
@section('preheader', 'A sua empresa já está criada e o período de demonstração começou.')

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Bem-vindo(a), {{ $nomeAdministrador }}! 👋</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">A empresa <strong>{{ $nomeEmpresa }}</strong> já está criada no ERP Consulvolt.</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#2563eb', 'fundo' => '#eff6ff',
    'slot' => '<strong style="color:#1e293b;">Período de demonstração activo</strong><br>'
        .'Tem <strong>'.$diasTeste.' dias</strong> de acesso gratuito a todas as funcionalidades do plano '
        .($nomePlano ? '<strong>'.e($nomePlano).'</strong>' : 'escolhido').
        ', com 1 empresa e 1 utilizador. Pode subscrever um plano pago a qualquer momento para aumentar estes limites.',
])
</td></tr>

<tr><td style="padding-bottom:4px;">Já pode entrar e começar a configurar a sua empresa — faturação, stock, tesouraria e muito mais.</td></tr>
<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Entrar no ERP'])</td></tr>

<tr><td style="padding-top:8px; font-size:13px; color:#64748b;">
Se tiver alguma dúvida durante a configuração, a nossa equipa de suporte está disponível através do centro de suporte dentro do sistema.
</td></tr>
@endsection
