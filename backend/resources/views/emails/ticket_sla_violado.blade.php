@extends('emails.layout')

@php
$corPrioridade = ['URGENTE' => '#dc2626', 'ALTA' => '#f59e0b', 'NORMAL' => '#2563eb', 'BAIXA' => '#64748b'][$prioridade] ?? '#2563eb';
@endphp

@section('titulo', "SLA violado — ticket #{$ticketId}")
@section('preheader', "{$nomeCliente}: {$assunto}")

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Ticket com SLA violado</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
O ticket <strong>#{{ $ticketId }}</strong> do cliente <strong>{{ $nomeCliente }}</strong> excedeu o prazo de SLA e ainda não foi resolvido.
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => $corPrioridade, 'fundo' => '#fef2f2',
    'slot' => '<strong style="color:#1e293b; font-size:15px;">'.e($assunto).'</strong><br>'
        .'<span style="color:#64748b; font-size:12px;">Prioridade: <strong>'.e($prioridade).'</strong> &middot; Prazo: <strong>'.e($prazoSla).'</strong></span>',
])
</td></tr>

<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Ver ticket no painel'])</td></tr>
@endsection
