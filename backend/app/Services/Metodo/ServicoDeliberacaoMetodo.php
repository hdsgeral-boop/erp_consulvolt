<?php

namespace App\Services\Metodo;

use App\Exceptions\ErroNegocio;
use App\Models\AssuntoMetodoTrabalho;
use App\Models\Colaborador;
use App\Models\UnidadeOrganica;
use App\Models\Utilizador;
use App\Services\RH\ServicoDelegacoesChefia;
use App\Services\RH\ServicoEstruturaOrg;
use App\Services\Sistema\NotificadorRH;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Deliberação do assunto 5W1H pelo nível acima no organigrama (assuntos.js:185-266).
 * Paridade:
 *   - o aprovador é a chefia do colaborador ligado a quem submete (ServicoEstruturaOrg::chefiaDe — gestor explícito ou o
 *     primeiro responsável ao subir na árvore de unidades orgânicas); o utilizador do aprovador é o que está ligado a esse
 *     colaborador na empresa ou, na falta, o «utilizador responsável» da unidade que ele chefia;
 *   - sem colaborador ligado ou sem ninguém acima, a aprovação é automática e fica registada como tal;
 *   - aprovar põe o assunto em curso (e cria o pedido de compra / o projecto); `mt_aprovar` decide sempre.
 * Melhorias:
 *   - três decisões: aprovar; devolver (volta a rascunho para corrigir e ressubmeter — era o «recusar» do legado);
 *     recusar (o assunto fica cancelado com o motivo; pode ser reaberto). Devolver e recusar exigem nota;
 *   - quem criou o assunto nunca decide sobre ele (nem com `mt_aprovar`);
 *   - a ligação utilizador ↔ colaborador é só a da ficha (utilizador_empresa); o legado também procurava pelo nome;
 *   - sem colaborador ligado, a aprovação só é automática para quem tem `mt_aprovar`; os outros ficam a aguardar quem tem
 *     essa tarefa (ADR-071: fecha a auto-aprovação de quem não está no organigrama).
 * Fase K (ADR-079): o aprovador continua a ser o designado; o seu delegado (âmbito METODO) também decide no período e a decisão
 *   fica com `por_delegado_de` na deliberação e «em nome de X» no histórico. Notificações (NotificadorRH, isoladas): submetido →
 *   aprovador (e o seu delegado) ou os que têm `mt_aprovar`; decidido/devolvido/recusado → criador e responsável principal.
 */
final class ServicoDeliberacaoMetodo
{
    public const ESTADOS = ['PENDENTE', 'APROVADO', 'DEVOLVIDO', 'RECUSADO'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoAssuntosMetodo $assuntos,
        private readonly ServicoEstruturaOrg $estrutura,
        private readonly ServicoIntegracoesMetodo $integracoes,
        private readonly ServicoDelegacoesChefia $delegacoes,
        private readonly NotificadorRH $notificador,
    ) {}

    /**
     * Quem aprova o que o utilizador submete (aprovadorDe, :189-218).
     *
     * @return array{colaborador_id: ?int, nome: string, utilizador_id: ?int, utilizador: ?string, proprio: bool, motivo: string}
     */
    public function aprovadorDe(): array
    {
        $eu = $this->assuntos->colaboradorDoUtilizador();
        $u = $this->assuntos->nomeUtilizador();
        $chefe = $eu ? $this->estrutura->chefiaDe($eu) : null;
        if ($chefe && $chefe !== $eu) {
            $util = $this->utilizadorDoColaborador($chefe);

            return ['colaborador_id' => $chefe, 'nome' => (string) Colaborador::query()->whereKey($chefe)->value('nome_completo'),
                'utilizador_id' => $util?->id, 'utilizador' => $util?->nome_utilizador, 'proprio' => false,
                'motivo' => 'Responsável do nível acima no organigrama.'.($util ? '' : ' Não tem utilizador ligado: decide quem tem a tarefa de aprovação.')];
        }
        if (! $eu && ! Gate::allows('mt_aprovar')) {
            return ['colaborador_id' => null, 'nome' => 'Aprovador (tarefa «Aprovar assuntos»)', 'utilizador_id' => null, 'utilizador' => null, 'proprio' => false,
                'motivo' => 'Sem colaborador ligado ao utilizador no organigrama: decide quem tem a tarefa de aprovação.'];
        }
        $nome = $eu ? (string) Colaborador::query()->whereKey($eu)->value('nome_completo') : $u;

        return ['colaborador_id' => $eu, 'nome' => $nome ?: $u, 'utilizador_id' => (int) auth()->id() ?: null, 'utilizador' => $u, 'proprio' => true,
            'motivo' => $eu ? 'Quem submete é o responsável do nível mais acima — aprovação automática.'
                : 'Sem colaborador ligado ao utilizador no organigrama — aprovação automática.'];
    }

    /** Submeter a aprovação (submeterAprovacao, :221-242). Sendo o próprio o responsável, aprova-se já e o assunto avança. */
    public function submeter(AssuntoMetodoTrabalho $a): array
    {
        if (! $this->assuntos->permissoes($a)['submeter']) {
            throw new AccessDeniedHttpException('Não pode submeter este assunto.');
        }

        return DB::transaction(function () use ($a) {
            $a = AssuntoMetodoTrabalho::query()->lockForUpdate()->findOrFail($a->id);
            if ($a->estado !== 'RASCUNHO') {
                throw new ErroNegocio('Só os assuntos em rascunho é que se submetem a aprovação.', 'TRANSICAO_INVALIDA', 422);
            }
            if ((((array) $a->deliberacao)['estado'] ?? null) === 'PENDENTE') {
                throw new ErroNegocio('O assunto já aguarda aprovação.', 'JA_SUBMETIDO', 422);
            }
            $this->assuntos->exigirCompleto($a, 'Faltam dados para submeter:');
            $ap = $this->aprovadorDe();
            $agora = now()->toIso8601String();
            $u = $this->assuntos->nomeUtilizador();
            $del = ['estado' => $ap['proprio'] ? 'APROVADO' : 'PENDENTE', 'aprovador_colaborador_id' => $ap['colaborador_id'], 'aprovador_nome' => $ap['nome'],
                'aprovador_utilizador_id' => $ap['utilizador_id'], 'aprovador_utilizador' => $ap['utilizador'], 'motivo_aprovador' => $ap['motivo'],
                'automatica' => $ap['proprio'], 'pedida_em' => $agora, 'pedida_por' => $u]
                + ($ap['proprio'] ? ['decidida_em' => $agora, 'decidido_por' => $u] : []);
            $a->forceFill(['deliberacao' => $del, 'atualizado_por' => $u])->save();
            $this->assuntos->registar($a, $ap['proprio'] ? 'Aprovado automaticamente' : 'Submetido a aprovação',
                $ap['proprio'] ? $ap['motivo'] : "Aguarda {$ap['nome']}".($ap['utilizador'] ? " ({$ap['utilizador']})" : '').". {$ap['motivo']}");
            if ($ap['proprio']) {
                $this->integracoes->porEmCurso($a, 'Aprovação automática.');
            } else {
                $this->avisarAprovador($a, $ap);
            }

            return ['aprovado' => $ap['proprio'], 'aprovador' => $ap, 'assunto' => $a->refresh()];
        });
    }

    /** Decisão do aprovador (decidirAprovacao, :254-266): APROVAR | DEVOLVER | RECUSAR. */
    public function decidir(AssuntoMetodoTrabalho $a, string $accao, ?string $nota): AssuntoMetodoTrabalho
    {
        $nota = trim((string) $nota);
        if (! in_array($accao, ['APROVAR', 'DEVOLVER', 'RECUSAR'], true)) {
            throw new ErroNegocio('Decisão inválida (aprovar, devolver ou recusar).', 'DECISAO_INVALIDA', 422);
        }

        return DB::transaction(function () use ($a, $accao, $nota) {
            $a = AssuntoMetodoTrabalho::query()->lockForUpdate()->findOrFail($a->id);
            $d = (array) $a->deliberacao;
            if (($d['estado'] ?? null) !== 'PENDENTE') {
                throw new ErroNegocio('Este assunto não está à espera de aprovação.', 'SEM_APROVACAO_PENDENTE', 422);
            }
            $this->exigirPodeDecidir($a);
            $emNomeDe = $this->assuntos->emNomeDeDelegacao($a);   // só por delegação: o designado e o gestor decidem por si
            if ($accao !== 'APROVAR' && mb_strlen($nota) < 5) {
                throw new ErroNegocio($accao === 'DEVOLVER' ? 'Indique o que tem de ser corrigido.' : 'Indique o motivo da recusa.', 'NOTA_OBRIGATORIA', 422);
            }
            $u = $this->assuntos->nomeUtilizador();
            $estado = ['APROVAR' => 'APROVADO', 'DEVOLVER' => 'DEVOLVIDO', 'RECUSAR' => 'RECUSADO'][$accao];
            $del = ['estado' => $estado, 'decidida_em' => now()->toIso8601String(), 'decidido_por' => $u, 'nota' => $nota ?: null] + ($emNomeDe ? ['por_delegado_de' => $emNomeDe] : []) + $d;
            $campos = ['deliberacao' => $del, 'atualizado_por' => $u];
            if ($accao === 'RECUSAR') {
                $campos += ['estado' => 'CANCELADO', 'cancelado_em' => now(), 'cancelado_por' => $u, 'motivo_cancelamento' => 'Recusado: '.$nota];
            }
            $a->forceFill($campos)->save();
            $this->assuntos->registar($a, ['APROVAR' => 'Aprovado', 'DEVOLVER' => 'Devolvido para correcção', 'RECUSAR' => 'Recusado'][$accao],
                trim(($nota ?: '').($emNomeDe ? " (em nome de {$emNomeDe['nome']})" : '')) ?: null);
            $this->avisarDecisao($a, $accao, $nota, $emNomeDe);
            if ($accao === 'APROVAR') {
                $this->integracoes->porEmCurso($a, "Aprovado por {$u}.");
            } elseif ($accao === 'RECUSAR') {
                $this->assuntos->registar($a, 'Estado: Cancelado', 'Recusado na aprovação.');
            }

            return $a->refresh();
        });
    }

    /** Assunto submetido → o aprovador designado (e o seu delegado) ou, sem aprovador no organigrama, quem tem `mt_aprovar`. */
    private function avisarAprovador(AssuntoMetodoTrabalho $a, array $ap): void
    {
        $titulo = "Assunto por aprovar: {$a->numero}";
        $mensagem = "O assunto «{$a->titulo}» ({$a->numero}), submetido por ".$this->assuntos->nomeUtilizador().', aguarda a sua aprovação.';
        if ($ap['colaborador_id']) {
            $this->notificador->paraColaboradores(array_merge([(int) $ap['colaborador_id']], $this->delegacoes->substitutoDe((int) $ap['colaborador_id'], 'METODO')), $titulo, $mensagem, '/m/metodo/mt_assuntos', 'metodo', 'AVISO');
        } else {
            $this->notificador->paraPermissao('mt_aprovar', $titulo, $mensagem, '/m/metodo/mt_assuntos', 'metodo', 'AVISO');
        }
    }

    /** Assunto decidido, devolvido ou recusado → o criador e o responsável principal (nunca quem decidiu). */
    private function avisarDecisao(AssuntoMetodoTrabalho $a, string $accao, string $nota, ?array $emNomeDe): void
    {
        $verbo = ['APROVAR' => 'aprovado', 'DEVOLVER' => 'devolvido para correcção', 'RECUSAR' => 'recusado'][$accao];
        $principal = str_starts_with((string) $a->responsavel_principal, 'COLABORADOR:') ? (int) substr((string) $a->responsavel_principal, 12) : 0;
        $this->notificador->paraNomesEColaboradores([(string) $a->criado_por], [$principal], "Assunto {$verbo}: {$a->numero}",
            "O assunto «{$a->titulo}» ({$a->numero}) foi {$verbo} por ".$this->assuntos->nomeUtilizador().($emNomeDe ? " (em nome de {$emNomeDe['nome']})" : '').($nota !== '' ? ": {$nota}" : '.'),
            '/m/metodo/mt_assuntos', 'metodo', $accao === 'APROVAR' ? 'SUCESSO' : 'AVISO', [$this->assuntos->nomeUtilizador()]);
    }

    /** 422 se não há aprovação pendente; 403 para o criador e para quem não é o aprovador designado nem gestor. */
    public function exigirPodeDecidir(AssuntoMetodoTrabalho $a): void
    {
        $d = (array) $a->deliberacao;
        if (($d['estado'] ?? null) !== 'PENDENTE') {
            throw new ErroNegocio('Este assunto não está à espera de aprovação.', 'SEM_APROVACAO_PENDENTE', 422);
        }
        if ($this->assuntos->souCriador($a)) {
            throw new AccessDeniedHttpException('Quem criou o assunto não decide sobre ele.');
        }
        if (! $this->assuntos->podeDecidir($a)) {
            throw new AccessDeniedHttpException('A decisão é de '.($d['aprovador_nome'] ?? 'o responsável do nível acima').'.');
        }
    }

    /** Utilizador de um colaborador: o ligado na ficha (utilizador_empresa) ou o «utilizador responsável» da unidade que chefia. */
    private function utilizadorDoColaborador(int $colaborador): ?Utilizador
    {
        $empresa = $this->contexto->obrigatorio();
        $id = DB::table('utilizador_empresa')->where('empresa_id', $empresa)->where('colaborador_id', $colaborador)->value('utilizador_id');
        if ($id) {
            return Utilizador::query()->where('ativo', true)->find($id);
        }
        $nome = UnidadeOrganica::query()->where('colaborador_responsavel_id', $colaborador)->whereNotNull('utilizador_responsavel')
            ->where('utilizador_responsavel', '<>', '')->value('utilizador_responsavel');
        if (! $nome) {
            return null;
        }
        $u = Utilizador::query()->where('ativo', true)->whereRaw('lower(nome_utilizador) = lower(?)', [trim((string) $nome)])->first();

        return $u && ($u->acesso_todas_empresas || DB::table('utilizador_empresa')->where('empresa_id', $empresa)->where('utilizador_id', $u->id)->exists()) ? $u : null;
    }
}
