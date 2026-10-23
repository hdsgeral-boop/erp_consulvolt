@extends('emails.layout')

@php
$corPrioridade = ['URGENTE' => '#dc2626', 'ALTA' => '#f59e0b', 'NORMAL' => '#2563eb', 'BAIXA' => '#64748b'][$prioridade] ?? '#2563eb';
@endphp

@section('titulo', "Novo ticket #{$ticketId}")
@section('preheader', "{$nomeCliente}: {$assunto}")

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Novo ticket de suporte</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
Cliente <strong>{{ $nomeCliente }}</strong> abriu o ticket <strong>#{{ $ticketId }}</strong>.
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => $corPrioridade, 'fundo' => '#f8fafc',
    'slot' => '<strong style="color:#1e293b; font-size:15px;">'.e($assunto).'</strong><br>'
        .'<span style="color:#64748b; font-size:12px;">Prioridade: <strong>'.e($prioridade).'</strong> &middot; Categoria: <strong>'.e($categoria).'</strong></span>'
        .($descricao ? '<p style="margin:10px 0 0; white-space:pre-line;">'.e($descricao).'</p>' : ''),
])
</td></tr>

<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Responder no painel'])</td></tr>
@endsection
