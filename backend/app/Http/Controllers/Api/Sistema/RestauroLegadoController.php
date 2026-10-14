<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\RestauroLegado;
use App\Services\Sistema\ServicoRestauroLegado;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restauro da plataforma a partir do backup do legado (Configurações › Manutenção de dados). As regras (super-administrador,
 * palavra-passe, segregação, simulação obrigatória, confirmação) estão em ServicoRestauroLegado.
 */
final class RestauroLegadoController extends Controller
{
    public function __construct(private readonly ServicoRestauroLegado $servico) {}

    /** GET /api/sistema/restauros-legado */
    public function index(Request $r): JsonResponse
    {
        $this->exigirSuperAdministrador($r);

        return RespostaApi::sucesso([
            'pode' => $r->user()->eSuperAdministrador(),
            'utilizador_id' => $r->user()->id,
            'pedidos' => array_map(fn (RestauroLegado $x) => $this->dados($x), $this->servico->listar()),
        ], 'Restauros obtidos com sucesso.');
    }

    /** POST /api/sistema/restauros-legado (multipart: ficheiro, palavra_passe) */
    public function store(Request $r): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $d = $r->validate([
            'ficheiro' => ['required', 'file', 'max:102400', 'mimetypes:application/json,text/plain'],
            'palavra_passe' => ['required', 'string', 'max:255'],
        ], ['ficheiro.max' => 'O ficheiro tem de ter no máximo 100 MB.', 'ficheiro.mimetypes' => 'O ficheiro tem de ser o JSON do backup do sistema antigo.']);
        $x = $this->servico->carregar($r->file('ficheiro'), $d['palavra_passe'], $r->user());

        return RespostaApi::criado($this->dados($x), "Backup carregado: a simulação do restauro #{$x->id} está a correr em segundo plano.");
    }

    /** POST /api/sistema/restauros-legado/{id}/aprovar */
    public function aprovar(Request $r, int $id): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $d = $r->validate(['palavra_passe' => ['required', 'string', 'max:255'], 'nota' => ['nullable', 'string', 'max:255']]);

        return RespostaApi::sucesso($this->dados($this->servico->aprovar($id, $d['palavra_passe'], $d['nota'] ?? null, $r->user())), 'Restauro aprovado (válido por 24 h).');
    }

    /** POST /api/sistema/restauros-legado/{id}/executar */
    public function executar(Request $r, int $id): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $d = $r->validate(['palavra_passe' => ['required', 'string', 'max:255'], 'confirmacao' => ['required', 'string', 'max:50']]);

        return RespostaApi::sucesso($this->dados($this->servico->executar($id, $d['palavra_passe'], $d['confirmacao'], $r->user())),
            'Restauro em execução: no fim terá de entrar de novo (os utilizadores passam a ser os do backup).');
    }

    /** POST /api/sistema/restauros-legado/{id}/cancelar */
    public function cancelar(Request $r, int $id): JsonResponse
    {
        $this->exigirSuperAdministrador($r);

        return RespostaApi::sucesso($this->dados($this->servico->cancelar($id, $r->user())), 'Restauro cancelado.');
    }

    /** Antes de qualquer validação (OWASP A01): o restauro, o histórico e os relatórios são só do super-administrador. */
    private function exigirSuperAdministrador(Request $r): void
    {
        if (! $r->user()?->eSuperAdministrador()) {
            throw new ErroNegocio('Só um super-administrador pode ver ou executar restauros da plataforma.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
    }

    /** @return array<string, mixed> */
    private function dados(RestauroLegado $x): array
    {
        return [
            'id' => $x->id, 'estado' => $x->estado, 'nome_ficheiro' => $x->nome_ficheiro, 'sha256' => $x->sha256, 'tamanho_bytes' => $x->tamanho_bytes,
            'pedido_por' => $x->pedido_por, 'pedido_em' => $x->pedido_em?->toAtomString(), 'simulacao' => $x->simulacao, 'simulado_em' => $x->simulado_em?->toAtomString(),
            'aprovado_por' => $x->aprovado_por, 'aprovado_em' => $x->aprovado_em?->toAtomString(), 'expira_em' => $x->expira_em?->toAtomString(),
            'executado_por' => $x->executado_por, 'iniciado_em' => $x->iniciado_em?->toAtomString(), 'concluido_em' => $x->concluido_em?->toAtomString(),
            'tem_copia_seguranca' => $x->copia_seguranca !== null, 'copia_seguranca' => $x->copia_seguranca ? basename($x->copia_seguranca) : null,
            'relatorio' => $x->relatorio ? array_diff_key($x->relatorio, ['saida' => true]) : null, 'saida' => $x->relatorio['saida'] ?? null,
            'erro' => $x->erro, 'historico' => $x->historico ?? [],
        ];
    }
}
