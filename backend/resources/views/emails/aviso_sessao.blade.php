@extends('emails.layout')

@section('titulo', $tipo === 'token_reutilizado' ? 'Sessão terminada por segurança' : 'Novo dispositivo na sua conta')
@section('preheader', $tipo === 'token_reutilizado' ? 'Detectámos uma utilização suspeita de uma sessão.' : 'A sua conta foi aberta num dispositivo novo.')

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
@if ($tipo === 'token_reutilizado')
Detectámos uma tentativa de reutilizar uma credencial de sessão que já tinha sido substituída. Por precaução, terminámos a sessão desse dispositivo.
@else
A sua conta foi aberta num dispositivo que ainda não conhecíamos.
@endif
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#2563eb', 'fundo' => '#eff6ff',
    'slot' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        .'<tr><td style="padding-bottom:6px; color:#64748b; width:140px;">Dispositivo</td><td style="font-weight:600;">'.e($rotulo).'</td></tr>'
        .'<tr><td style="padding-bottom:6px; color:#64748b;">Localização (IP)</td><td style="font-weight:600;">'.e($ip ?? 'desconhecida').'</td></tr>'
        .'<tr><td style="color:#64748b;">Quando</td><td style="font-weight:600;">'.e($quando).'</td></tr>'
        .'</table>',
])
</td></tr>

<tr><td style="padding-bottom:18px; color:#475569;">
Se foi você, não precisa de fazer nada. Se não reconhece esta actividade, altere a sua palavra-passe e termine as outras sessões em «Minha Conta › As minhas sessões».
</td></tr>

<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Abrir o ERP'])</td></tr>
@endsection
