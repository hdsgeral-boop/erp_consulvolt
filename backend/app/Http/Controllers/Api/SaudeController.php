<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoSaude;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/saude — estado das dependências, para healthchecks e monitorização (docs/PRODUCAO.md).
 *
 * Componentes: base de dados (PostgreSQL), Redis, filas (tamanho de cada fila e trabalhos falhados) e
 * armazenamento (storage/ gravável). Responde 503 se algum componente falhar. É pública: as mensagens
 * de erro só são mostradas em modo de depuração e nunca há segredos na resposta.
 *
 * `informacao` (só na resposta de sucesso) traz estados meramente informativos que nunca tornam o serviço indisponível:
 * a obtenção automática dos câmbios do BAI (DESACTIVADO, OK, PENDENTE ou FALHA da última obtenção).
 * e o outbox transaccional (OK, ou AVISO se o evento pendente mais antigo tem mais de 10 minutos ou há eventos MORTOS; ADR-080).
 */
final class SaudeController extends Controller
{
    public function __construct(private readonly ServicoSaude $saude) {}

    public function __invoke(): JsonResponse
    {
        // OWASP A05: a rota é pública — as versões exactas do PostgreSQL/Redis (úteis para procurar CVE) só aparecem em
        // depuração ou a um perfil de back-office (ePrivilegiado): um utilizador comum de uma empresa cliente autenticado
        // não tem nenhuma razão para ver internals de infraestrutura (Fase 7 da extensão SaaS, segurança.txt secção 16).
        $utilizador = auth('sanctum')->user();
        $versoes = config('app.debug') || ($utilizador instanceof Utilizador && $utilizador->ePrivilegiado());

        $verificacoes = $this->saude->verificacoes($versoes);
        $ok = collect($verificacoes)->every(fn ($v) => $v['estado'] === 'OK');

        return $ok
            ? RespostaApi::sucesso(['estado' => 'OK', 'versao' => $this->saude->versao(), 'componentes' => $verificacoes, 'informacao' => $this->saude->informacao()], 'Todos os serviços estão operacionais.')
            : RespostaApi::erro('Um ou mais serviços estão indisponíveis.', 503, 'SERVICO_INDISPONIVEL', $verificacoes);
    }
}
