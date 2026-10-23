@extends('emails.layout')

@section('titulo', 'A sua licença expirou')
@section('preheader', "A licença de {$nomeEmpresa} expirou e a empresa está em modo só-leitura.")

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
A licença da empresa <strong>{{ $nomeEmpresa }}</strong> expirou hoje.
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#dc2626', 'fundo' => '#fef2f2',
    'slot' => '<strong style="color:#1e293b;">Modo só-leitura</strong><br>'
        .'A empresa continua acessível e todos os dados estão guardados, mas não é possível criar nem alterar documentos até a licença ser renovada.',
])
</td></tr>

<tr><td style="padding-bottom:4px;">Renove agora para retomar a operação normal.</td></tr>
<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Renovar licença', 'cor' => '#dc2626'])</td></tr>
@endsection
