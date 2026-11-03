@extends('emails.layout')

@section('titulo', $titulo)
@section('preheader', $mensagem)

@section('conteudo')
<tr><td style="font-size:19px; font-weight:700; color:#0f172a; padding-bottom:4px;">{{ $titulo }}</td></tr>
<tr><td style="padding-bottom:18px; color:#475569; white-space:pre-line;">{{ $mensagem }}</td></tr>
<tr><td>@include('emails.componentes.botao', ['url' => $url, 'texto' => 'Abrir no ERP'])</td></tr>
@endsection
