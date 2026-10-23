@extends('emails.layout')

@section('titulo', 'A sua licença está a expirar')
@section('preheader', "A licença de {$nomeEmpresa} expira em {$diasRestantes} dia(s).")

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
A licença da empresa <strong>{{ $nomeEmpresa }}</strong> expira em <strong>{{ $dataFim }}</strong>.
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#f59e0b', 'fundo' => '#fffbeb',
    'slot' => '<strong style="color:#1e293b;">Faltam '.$diasRestantes.' dia(s)</strong><br>'
        .'Depois da data de expiração, a empresa passa a modo só-leitura: continua a ver os dados, mas não pode criar nem alterar documentos até renovar.',
])
</td></tr>

<tr><td style="padding-bottom:4px;">Renove a tempo para não interromper a sua operação.</td></tr>
<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Renovar licença', 'cor' => '#f59e0b'])</td></tr>
@endsection
