<!DOCTYPE html>
<html lang="pt-AO">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>@yield('titulo', 'Consulvolt ERP')</title>
</head>
{{-- Identidade visual: cores copiadas de frontend/src/estilos/tema.ts, para o email ser reconhecível como o mesmo
     sistema. Tudo em estilos inline (sem <style> nem classes): é a única forma fiável de manter o aspecto em
     clientes de email, que ignoram ou removem CSS externo/em bloco (Gmail, Outlook desktop, etc.). --}}
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
{{-- Pré-cabeçalho: texto de antevisão na caixa de entrada, escondido no corpo do email. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">@yield('preheader', '')</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:32px 16px;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#ffffff; border-radius:8px; border:1px solid #e2e8f0; overflow:hidden;">

{{-- Cabeçalho --}}
<tr>
<td style="background-color:#2563eb; padding:28px 32px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td style="font-size:20px; font-weight:700; letter-spacing:0.5px; color:#ffffff;">CONSULVOLT</td>
<td align="right" style="font-size:11px; font-weight:600; letter-spacing:1px; color:#dbe6fd; text-transform:uppercase;">ERP&nbsp;SaaS</td>
</tr>
</table>
</td>
</tr>

{{-- Conteúdo --}}
<tr>
<td style="padding:36px 32px 8px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="color:#1e293b; font-size:14px; line-height:1.65;">
@yield('conteudo')
</table>
</td>
</tr>

{{-- Rodapé --}}
<tr>
<td style="padding:28px 32px 32px;">
<div style="border-top:1px solid #e2e8f0; padding-top:20px;">
<p style="margin:0 0 6px; font-size:12px; color:#64748b;">
Este é um email automático da plataforma Consulvolt ERP{{ isset($nomeEmpresa) ? " — {$nomeEmpresa}" : '' }}. Não é necessário responder directamente a esta mensagem.
</p>
@hasSection('rodape_extra')
<p style="margin:0 0 6px; font-size:12px; color:#64748b;">@yield('rodape_extra')</p>
@endif
<p style="margin:12px 0 0; font-size:12px; color:#94a3b8;">
&copy; {{ date('Y') }} Consulvolt. Todos os direitos reservados.
@if(!empty($emailSuporte ?? null)) &middot; Suporte: <a href="mailto:{{ $emailSuporte }}" style="color:#2563eb; text-decoration:none;">{{ $emailSuporte }}</a>@endif
</p>
</div>
</td>
</tr>

</table>
</td>
</tr>
</table>
</body>
</html>
