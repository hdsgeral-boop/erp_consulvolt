@extends('emails.layout')

@section('titulo', "Nova resposta — ticket #{$ticketId}")
@section('preheader', "{$nomeAutor} respondeu ao ticket #{$ticketId}.")

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeDestinatario }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
Há uma resposta nova no ticket <strong>#{{ $ticketId }}</strong> — {{ $assunto }}.
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#2563eb', 'fundo' => '#eff6ff',
    'slot' => '<strong style="color:#1e293b;">'.e($nomeAutor).'</strong><p style="margin:8px 0 0; white-space:pre-line;">'.e($mensagem).'</p>',
])
</td></tr>

<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Ver conversa'])</td></tr>
@endsection
