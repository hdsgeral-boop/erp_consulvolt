<?php

namespace App\Http\Controllers\Api\Autenticacao;

use App\Http\Controllers\Controller;
use App\Mail\NovoUtilizadorMail;
use App\Models\Tenant;
use App\Services\Plataforma\ServicoPlataformaInicial;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** Primeiro acesso da plataforma (público): ver ServicoPlataformaInicial para o risco da janela inicial. */
final class PlataformaInicialController extends Controller
{
    public function __construct(private readonly ServicoPlataformaInicial $servico) {}

    /** GET /api/plataforma-inicial/estado */
    public function estado(Request $request): JsonResponse
    {
        $precisa = $this->servico->disponivel($request);

        return RespostaApi::sucesso([
            'precisa_criar_admin' => $precisa,
            'exige_segredo' => $precisa && $this->servico->exigeSegredo(),
        ], 'Estado obtido com sucesso.');
    }

    /**
     * POST /api/plataforma-inicial/criar-admin — não inicia sessão. A palavra-passe NÃO é escolhida no pedido: é gerada pelo
     * servidor e enviada por e-mail (troca obrigatória no primeiro acesso); o administrador entra a seguir pelo login normal.
     */
    public function criarAdmin(Request $request): JsonResponse
    {
        $d = $request->validate([
            'nome_utilizador' => ['required', 'string', 'max:100'],
            'nome_completo' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:200'],
        ]);

        // O e-mail com a palavra-passe é enfileirado (Mailable em fila); é o único canal — nunca vai na resposta.
        ['utilizador' => $admin, 'palavra_passe' => $palavraPasse] = $this->servico->criar($request, $d);
        $nomePlataforma = Tenant::query()->whereKey($admin->tenant_id)->value('nome') ?? 'Plataforma';
        Mail::to($admin->email)->send(new NovoUtilizadorMail($admin->nome_completo ?: $admin->nome_utilizador, $admin->nome_utilizador, $palavraPasse, $nomePlataforma));

        return RespostaApi::criado(['nome_utilizador' => $admin->nome_utilizador], 'Administrador da plataforma criado. A palavra-passe foi enviada por e-mail.');
    }
}
