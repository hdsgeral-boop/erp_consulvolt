<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoSaude;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * Fase 7 da extensão SaaS (segurança.txt, secção 16 "Monitorização Operacional"): a mesma verificação de
 * dependências do `GET /api/saude` (ServicoSaude), mas só para perfis de back-office (RBAC da Fase 4) e sempre com
 * o detalhe completo (já atrás de autenticação e permissão). Sem Horizon/Telescope — não se justifica à escala
 * actual (um só servidor Docker); este endpoint leve chega.
 */
final class MonitorizacaoController extends Controller
{
    public function __construct(private readonly ServicoSaude $saude) {}

    public function index(): JsonResponse
    {
        $this->exigir('plataforma_monitorizacao_ver');

        $verificacoes = $this->saude->verificacoes(versoes: true);
        $ok = collect($verificacoes)->every(fn ($v) => $v['estado'] === 'OK');

        return RespostaApi::sucesso([
            'estado' => $ok ? 'OK' : 'FALHA', 'versao' => $this->saude->versao(),
            'componentes' => $verificacoes, 'informacao' => $this->saude->informacao(),
        ], 'Estado dos serviços obtido com sucesso.');
    }
}
