@extends('emails.layout')

@section('titulo', 'O seu acesso ao MEU ERP')
@section('preheader', "Foi criado um acesso para si no MEU ERP {$nomeSistema}.")

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
Foi criado um acesso para si no MEU ERP <strong>«{{ $nomeSistema }}»</strong>@if(count($empresas) === 1), na empresa <strong>{{ $empresas[0] }}</strong>@elseif(count($empresas) > 1), nas empresas <strong>{{ implode(', ', array_slice($empresas, 0, -1)) }}</strong> e <strong>{{ end($empresas) }}</strong>@endif.
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#2563eb', 'fundo' => '#eff6ff',
    'slot' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        .'<tr><td style="padding-bottom:6px; color:#64748b; width:140px;">Utilizador</td><td style="font-weight:600; font-family:monospace; font-size:14px;">'.e($nomeUtilizador).'</td></tr>'
        .($palavraPasse ? '<tr><td style="color:#64748b; width:140px;">Palavra-passe</td><td style="font-weight:600; font-family:monospace; font-size:14px;">'.e($palavraPasse).'</td></tr>' : '')
        .'</table>',
])
</td></tr>

<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Entrar no ERP'])</td></tr>

<tr><td style="padding-top:8px; font-size:13px; color:#64748b;">
@if($palavraPasse)
Esta palavra-passe é provisória: no primeiro acesso o sistema pede-lhe para a alterar. Pode entrar com o nome de utilizador ou com este e-mail.
@else
A palavra-passe é-lhe comunicada pelo administrador. Também a pode definir em <strong>«Esqueceu a senha?»</strong> na página de entrada.
@endif
</td></tr>
@endsection
