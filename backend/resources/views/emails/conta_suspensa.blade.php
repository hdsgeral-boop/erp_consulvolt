@extends('emails.layout')

@section('titulo', 'A sua conta foi suspensa')
@section('preheader', "A conta {$nomeCliente} foi suspensa pela plataforma Consulvolt.")

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">Olá, {{ $nomeCompleto }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569;">
A conta <strong>{{ $nomeCliente }}</strong> foi suspensa pela equipa Consulvolt. O acesso ao sistema fica indisponível até a situação ser regularizada.
</td></tr>

<tr><td>
@include('emails.componentes.caixa', [
    'cor' => '#dc2626', 'fundo' => '#fef2f2',
    'slot' => '<strong style="color:#1e293b;">O que fazer agora</strong><br>'
        .'Contacte a nossa equipa de suporte para perceber o motivo e regularizar a sua conta'
        .(!empty($emailSuporte) ? ' através de <a href="mailto:'.e($emailSuporte).'" style="color:#2563eb;">'.e($emailSuporte).'</a>' : '').'.',
])
</td></tr>

<tr><td style="padding-top:8px; font-size:13px; color:#64748b;">
Os dados da sua empresa estão preservados e ficam disponíveis assim que a conta for reactivada.
</td></tr>
@endsection
