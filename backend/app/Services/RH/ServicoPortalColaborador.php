<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\AusenciaFaltaColaborador;
use App\Models\Colaborador;
use App\Models\DependenteColaborador;
use App\Models\PedidoPortalColaborador;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PlanoFeriasColaborador;
use App\Models\ResultadoFolhaSalarial;
use App\Models\UtilizadorEmpresa;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Portal do colaborador (js/modules/rh/portal_dados.js). Paridade: pedidos de FÉRIAS, AUSÊNCIA, DOCUMENTO e
 * AGREGADO; circuito CHEFIA → RH nas férias e ausências (chefia DISPENSADA se não houver chefia directa ou se esta
 * não tiver utilizador), só RH nos documentos e agregado; recusa com nota; o próprio não decide; só o requerente
 * cancela enquanto pendente; documentos emitidos automaticamente quando o modelo o permite.
 * Correcções (ADR-040):
 *   - decisões com todos os efeitos (férias, ausência, agregado) numa única transacção — no legado a decisão de uma
 *     ausência falhava (tabela fora da transacção Dexie) e era desfeita;
 *   - a mesma pessoa não aprova as duas etapas (chefia e RH);
 *   - a ligação utilizador ↔ colaborador lê-se sempre da base de dados, exige permissão e fica auditada;
 *   - agregado: a aprovação é recusada se os dependentes foram alterados desde o pedido (o legado sobrepunha as
 *     alterações do RH em silêncio);
 *   - a passagem chefia → RH não marca a ausência como decidida;
 *   - recibos do portal a partir da FOTOGRAFIA do processamento (o legado recalculava com os dados actuais).
 */
final class ServicoPortalColaborador
{
    public const TIPOS = ['FERIAS', 'AUSENCIA', 'DOCUMENTO', 'AGREGADO'];

    private const PARENTESCOS = ['FILHO' => 'FILHO', 'FILHA' => 'FILHO', 'FILHO(A)' => 'FILHO', 'CONJUGE' => 'CONJUGE', 'CÔNJUGE' => 'CONJUGE', 'ESPOSA' => 'CONJUGE',
        'MARIDO' => 'CONJUGE', 'COMPANHEIRO(A)' => 'CONJUGE', 'PAI' => 'PAI', 'MAE' => 'MAE', 'MÃE' => 'MAE'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoEstruturaOrg $estrutura,
        private readonly ServicoFerias $ferias,
        private readonly ServicoAusencias $ausencias,
        private readonly ServicoDocumentosRH $documentos,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Ligação utilizador ↔ colaborador ─────────────

    public function colaboradorDe(?int $utilizador = null): ?int
    {
        $id = DB::table('utilizador_empresa')->where('utilizador_id', $utilizador ?? Auth::id())->where('empresa_id', $this->contexto->obrigatorio())->value('colaborador_id');

        return $id ? (int) $id : null;
    }

    public function exigirColaborador(): Colaborador
    {
        $id = $this->colaboradorDe() ?? throw new ErroNegocio('O seu utilizador não está ligado a um colaborador desta empresa.', 'SEM_COLABORADOR', 403);

        return Colaborador::query()->findOrFail($id);
    }

    /** Um utilizador por colaborador em cada empresa; nulo retira a ligação. */
    public function ligar(int $utilizador, ?int $colaborador): void
    {
        $empresa = $this->contexto->obrigatorio();
        DB::transaction(function () use ($utilizador, $colaborador, $empresa) {
            $pivot = UtilizadorEmpresa::query()->where('utilizador_id', $utilizador)->where('empresa_id', $empresa)->first()
                ?? throw new ErroNegocio('O utilizador não tem acesso a esta empresa.', 'UTILIZADOR_SEM_EMPRESA', 422);
            if ($colaborador) {
                Colaborador::query()->findOrFail($colaborador);
                if (UtilizadorEmpresa::query()->where('empresa_id', $empresa)->where('colaborador_id', $colaborador)->where('utilizador_id', '<>', $utilizador)->exists()) {
                    throw new ErroNegocio('Esse colaborador já está ligado a outro utilizador.', 'COLABORADOR_JA_LIGADO', 422);
                }
            }
            $antes = $pivot->colaborador_id;
            UtilizadorEmpresa::query()->where('utilizador_id', $utilizador)->where('empresa_id', $empresa)->update(['colaborador_id' => $colaborador]);
            $this->auditoria->registar('Portal do Colaborador', 'Ligar utilizador a colaborador', null, 'utilizador_empresa', $utilizador,
                ['colaborador_id' => $antes], ['colaborador_id' => $colaborador]);
        });
    }

    // ───────────── Pedidos ─────────────

    /** Circuito de aprovação no momento do pedido. */
    public function etapasPara(Colaborador $c, string $tipo): array
    {
        if (! in_array($tipo, ['FERIAS', 'AUSENCIA'], true)) {
            return [['nivel' => 'RH', 'estado' => 'PENDENTE']];
        }
        $chefe = $this->estrutura->chefiaDe($c->id);
        $comUtilizador = $chefe && DB::table('utilizador_empresa')->where('empresa_id', $this->contexto->obrigatorio())->where('colaborador_id', $chefe)->exists();
        if (! $chefe || ! $comUtilizador) {
            return [['nivel' => 'CHEFIA', 'estado' => 'DISPENSADA', 'aprovador_colaborador_id' => $chefe,
                'nota' => $chefe ? 'A chefia directa não tem utilizador no sistema.' : 'Sem chefia directa definida na Estrutura Orgânica.'], ['nivel' => 'RH', 'estado' => 'PENDENTE']];
        }

        return [['nivel' => 'CHEFIA', 'estado' => 'PENDENTE', 'aprovador_colaborador_id' => $chefe, 'aprovador_nome' => Colaborador::query()->find($chefe)?->nome_completo],
            ['nivel' => 'RH', 'estado' => 'AGUARDA']];
    }

    /** @param  array<string, mixed>  $dados */
    public function criar(string $tipo, array $dados): PedidoPortalColaborador
    {
        $c = $this->exigirColaborador();

        return DB::transaction(function () use ($c, $tipo, $dados) {
            $etapas = $this->etapasPara($c, $tipo);
            $estado = collect($etapas)->firstWhere('estado', 'PENDENTE')['nivel'] === 'CHEFIA' ? 'PENDENTE_CHEFIA' : 'PENDENTE_RH';
            $p = new PedidoPortalColaborador(['colaborador_id' => $c->id, 'tipo' => $tipo, 'etapas' => $etapas, 'estado' => $estado, 'criado_por' => Auth::user()?->nome_utilizador]);
            match ($tipo) {
                'FERIAS' => $this->prepararFerias($p, $c, $dados),
                'AUSENCIA' => $this->prepararAusencia($p, $c, $dados, $estado),
                'DOCUMENTO' => $this->prepararDocumento($p, $c, $dados),
                'AGREGADO' => $this->prepararAgregado($p, $c, $dados),
            };
            $p->save();
            if ($p->plano_ferias_colaborador_id) {
                PlanoFeriasColaborador::query()->whereKey($p->plano_ferias_colaborador_id)->update(['pedido_portal_colaborador_id' => $p->id]);
            }
            if ($p->ausencia_falta_id) {
                AusenciaFaltaColaborador::query()->whereKey($p->ausencia_falta_id)->update(['pedido_portal_colaborador_id' => $p->id]);
            }

            return $p->refresh();
        });
    }

    /** Pode o utilizador actual decidir este pedido? Devolve a etapa ou lança o motivo. */
    public function etapaADecidir(PedidoPortalColaborador $p): int
    {
        if (! str_starts_with((string) $p->estado, 'PENDENTE')) {
            throw new ErroNegocio("O pedido está {$p->estado}: não há decisão pendente.", 'ESTADO_INVALIDO', 422);
        }
        $eu = $this->colaboradorDe();
        if ($eu && $eu === (int) $p->colaborador_id) {
            throw new ErroNegocio('Não pode decidir um pedido seu.', 'AUTO_APROVACAO', 403);
        }
        $i = collect($p->etapas)->search(fn ($e) => $e['estado'] === 'PENDENTE');
        $etapa = $p->etapas[$i];
        if ($etapa['nivel'] === 'CHEFIA' && self::aprovador($etapa) !== (int) $eu) {
            throw new ErroNegocio('Este pedido aguarda a decisão da chefia directa do colaborador.', 'SEM_PERMISSAO_ETAPA', 403);
        }
        if ($etapa['nivel'] === 'RH') {
            if (! Gate::any(['rh_portal_aprovar'])) {
                throw new ErroNegocio('Sem permissão para decidir pedidos do portal (RH).', 'SEM_PERMISSAO_ETAPA', 403);
            }
            $chefia = collect($p->etapas)->firstWhere('nivel', 'CHEFIA');
            if ($chefia && ($chefia['estado'] ?? null) === 'APROVADO' && ($chefia['por_id'] ?? null) === Auth::id()) {
                throw new ErroNegocio('Já aprovou este pedido como chefia: a etapa do RH tem de ser decidida por outra pessoa.', 'SEGREGACAO_ETAPAS', 403);
            }
        }

        return $i;
    }

    public function decidir(PedidoPortalColaborador $p, string $decisao, ?string $nota, ?string $remunerada = null): PedidoPortalColaborador
    {
        return DB::transaction(function () use ($p, $decisao, $nota, $remunerada) {
            $p = PedidoPortalColaborador::query()->lockForUpdate()->findOrFail($p->id);
            $i = $this->etapaADecidir($p);
            if ($decisao === 'RECUSADO' && mb_strlen(trim((string) $nota)) < 3) {
                throw new ErroNegocio('Indique o motivo da recusa.', 'NOTA_EM_FALTA', 422);
            }
            if ($decisao === 'APROVADO' && $p->tipo === 'DOCUMENTO') {
                throw new ErroNegocio('Os pedidos de documentos aprovam-se emitindo o documento.', 'USAR_EMISSAO', 422);
            }
            $etapas = $p->etapas;
            $etapas[$i] = array_merge($etapas[$i], ['estado' => $decisao, 'por' => Auth::user()?->nome_utilizador, 'por_id' => Auth::id(), 'em' => now()->toIso8601String(), 'nota' => $nota]);
            $seguinte = $decisao === 'APROVADO' ? collect($etapas)->search(fn ($e, $k) => $k > $i && in_array($e['estado'], ['AGUARDA', 'PENDENTE'], true)) : false;
            if ($seguinte !== false) {
                $etapas[$seguinte]['estado'] = 'PENDENTE';
            }
            $estado = $decisao === 'RECUSADO' ? 'RECUSADO' : ($seguinte !== false ? 'PENDENTE_'.$etapas[$seguinte]['nivel'] : 'APROVADO');
            $this->efeitos($p, $estado, $remunerada, $nota);
            $p->update(['etapas' => $etapas, 'estado' => $estado, 'decidido_em' => in_array($estado, ['APROVADO', 'RECUSADO'], true) ? now() : null]);
            $this->auditoria->registar('Portal do Colaborador', $decisao === 'APROVADO' ? 'Aprovar pedido' : 'Recusar pedido', "{$p->tipo} #{$p->id} → {$estado}", 'pedidos_portal_colaborador', $p->id);

            return $p->refresh();
        });
    }

    public function cancelar(PedidoPortalColaborador $p): PedidoPortalColaborador
    {
        return DB::transaction(function () use ($p) {
            $p = PedidoPortalColaborador::query()->lockForUpdate()->findOrFail($p->id);
            if ((int) $p->colaborador_id !== (int) $this->colaboradorDe()) {
                throw new ErroNegocio('Só o próprio colaborador cancela o seu pedido.', 'SEM_PERMISSAO', 403);
            }
            if (! str_starts_with((string) $p->estado, 'PENDENTE')) {
                throw new ErroNegocio('Só se cancelam pedidos pendentes.', 'ESTADO_INVALIDO', 422);
            }
            if ($p->plano_ferias_colaborador_id) {
                PlanoFeriasColaborador::query()->whereKey($p->plano_ferias_colaborador_id)->update(['estado' => 'CANCELADO']);
            }
            if ($p->ausencia_falta_id && ($a = AusenciaFaltaColaborador::query()->find($p->ausencia_falta_id))) {
                $this->ausencias->cancelar($a);
            }
            $p->update(['estado' => 'CANCELADO', 'cancelado_em' => now()]);

            return $p->refresh();
        });
    }

    /**
     * Emissão de um documento pedido (etapa RH). Sem texto indicado usa a proposta do modelo; a emissão
     * automática só acontece se o modelo o permitir, não houver variáveis em falta e houver assinante.
     *
     * @param  array{texto?: ?string, titulo?: ?string, assinante?: ?string, cargo_assinante?: ?string, local?: ?string}  $d
     * @return array{emitido: bool, pedido?: PedidoPortalColaborador, proposta?: array<string, mixed>}
     */
    public function emitir(PedidoPortalColaborador $p, array $d = []): array
    {
        if ($p->tipo !== 'DOCUMENTO') {
            throw new ErroNegocio('Só os pedidos de documentos se emitem.', 'TIPO_INVALIDO', 422);
        }

        return DB::transaction(function () use ($p, $d) {
            $p = PedidoPortalColaborador::query()->lockForUpdate()->findOrFail($p->id);
            $this->etapaADecidir($p);
            $prop = $this->documentos->proposta($p);
            $manual = ! empty($d['texto']);
            $texto = trim((string) ($d['texto'] ?? $prop['texto']));
            $assinante = trim((string) (($d['assinante'] ?? '') ?: $prop['assinante']));
            if (! $manual && (! $prop['auto_emitir'] || $prop['faltas'] || $assinante === '')) {
                return ['emitido' => false, 'proposta' => $prop];   // o RH revê e completa o texto
            }
            if (mb_strlen($texto) < 20 || array_intersect(ServicoDocumentosRH::VARIAVEIS, $this->marcadores($texto))) {   // campos [Rótulo] por preencher
                throw new ErroNegocio('O texto do documento está incompleto (há campos por preencher).', 'TEXTO_INCOMPLETO', 422);
            }
            if ($assinante === '') {
                throw new ErroNegocio('Indique quem assina o documento.', 'ASSINANTE_EM_FALTA', 422);
            }
            $etapas = $p->etapas;
            $i = collect($etapas)->search(fn ($e) => $e['estado'] === 'PENDENTE');
            $etapas[$i] = array_merge($etapas[$i], ['estado' => 'APROVADO', 'por' => Auth::user()?->nome_utilizador, 'por_id' => Auth::id(), 'em' => now()->toIso8601String()]);
            $documento = ['numero' => $this->documentos->proximoNumero(), 'titulo' => trim((string) (($d['titulo'] ?? '') ?: $prop['titulo'])), 'texto' => $texto,
                'local' => ($d['local'] ?? '') ?: $prop['local'], 'data' => now()->toDateString(), 'assinante' => $assinante,
                'cargo_assinante' => ($d['cargo_assinante'] ?? '') ?: $prop['cargo_assinante'], 'modelo' => $prop['modelo'], 'automatico' => ! $manual,
                'emitido_por' => Auth::user()?->nome_utilizador, 'emitido_em' => now()->toIso8601String()];
            $p->update(['etapas' => $etapas, 'estado' => 'EMITIDO', 'documento' => $documento, 'decidido_em' => now()]);
            $this->auditoria->registar('Portal do Colaborador', 'Emitir documento', "{$documento['numero']} (pedido #{$p->id})", 'pedidos_portal_colaborador', $p->id);

            return ['emitido' => true, 'pedido' => $p->refresh()];
        });
    }

    // ───────────── Consultas do colaborador ─────────────

    public function resumo(): array
    {
        $c = $this->exigirColaborador();
        $ano = (int) now()->format('Y');
        $ferias = collect($this->ferias->resumo($ano))->firstWhere('colaborador_id', $c->id)
            ?? ['direito' => $this->ferias->direito($c->id, $ano), 'marcados' => 0, 'saldo' => $this->ferias->direito($c->id, $ano)];

        return ['colaborador' => $c->only(['id', 'nome_completo', 'nif', 'estado', 'cargo_funcao_id', 'unidade_organica_id', 'data_admissao']),
            'chefia_colaborador_id' => $this->estrutura->chefiaDe($c->id), 'ferias' => $ferias, 'ano' => $ano,
            'faltas_por_justificar' => AusenciaFaltaColaborador::query()->where('colaborador_id', $c->id)->where('estado', 'POR_JUSTIFICAR')->count(),
            'pedidos_pendentes' => PedidoPortalColaborador::query()->where('colaborador_id', $c->id)->where('estado', 'like', 'PENDENTE%')->count(),
            'aprovacoes_para_mim' => count($this->pendentesParaMim())];
    }

    /** Pedidos à espera do utilizador actual (chefia directa ou, com a permissão, RH). */
    public function pendentesParaMim(): array
    {
        $eu = $this->colaboradorDe();
        $rh = Gate::any(['rh_portal_aprovar']);

        return PedidoPortalColaborador::query()->where('estado', 'like', 'PENDENTE%')->orderBy('id')->get()->filter(function ($p) use ($eu, $rh) {
            if ($eu && (int) $p->colaborador_id === $eu) {
                return false;
            }
            $e = collect($p->etapas)->firstWhere('estado', 'PENDENTE');

            return $e && (($e['nivel'] === 'CHEFIA' && $eu && self::aprovador($e) === $eu) || ($e['nivel'] === 'RH' && $rh));
        })->values()->all();
    }

    /** Recibos: períodos VALIDADOS com fotografia para o colaborador. */
    public function recibos(): array
    {
        $c = $this->exigirColaborador();
        $validos = PeriodoProcessamentoSalarial::query()->where('estado', 'VALIDADO')->pluck('mes_ano', 'id');

        return ResultadoFolhaSalarial::query()->where('colaborador_id', $c->id)->whereIn('periodo_processamento_salarial_id', $validos->keys())->get()
            ->map(fn ($r) => $r->toArray() + ['mes_ano' => $validos[$r->periodo_processamento_salarial_id],
                'numero_recibo' => vsprintf('%2$s%1$s-%3$04d', [...explode('/', $validos[$r->periodo_processamento_salarial_id]), $c->id])])
            ->sortByDesc(fn ($r) => substr($r['mes_ano'], 3).substr($r['mes_ano'], 0, 2))->values()->all();
    }

    // ───────────── Preparação dos pedidos ─────────────

    private function prepararFerias(PedidoPortalColaborador $p, Colaborador $c, array $d): void
    {
        $f = $this->ferias->criarPedido($c->id, substr((string) $d['data_inicio'], 0, 10), substr((string) $d['data_fim'], 0, 10), $d['observacoes'] ?? null);
        $p->fill(['plano_ferias_colaborador_id' => $f->id, 'dados' => ['ano' => $f->ano, 'dias' => $f->dias, 'data_inicio' => $f->data_inicio->toDateString(),
            'data_fim' => $f->data_fim->toDateString(), 'observacoes' => $d['observacoes'] ?? '']]);
    }

    private function prepararAusencia(PedidoPortalColaborador $p, Colaborador $c, array $d, string $estado): void
    {
        $base = ['tipo' => $d['tipo'], 'motivo' => $d['motivo'] ?? null, 'documento_url' => $d['documento_url'] ?? null];
        if (! empty($d['ausencia_id'])) {   // justificar uma falta detectada
            $a = AusenciaFaltaColaborador::query()->where('colaborador_id', $c->id)->findOrFail($d['ausencia_id']);
            $a = $this->ausencias->justificar($a, $base, $estado);
        } else {
            $a = $this->ausencias->criar($base + ['colaborador_id' => $c->id, 'data_inicio' => $d['data_inicio'], 'data_fim' => $d['data_fim'], 'horas' => $d['horas'] ?? null], $estado);
        }
        $p->fill(['ausencia_falta_id' => $a->id, 'dados' => $base + ['data_inicio' => $a->data_inicio->toDateString(), 'data_fim' => $a->data_fim->toDateString(),
            'detectada' => (bool) $a->detectada, 'avisos' => $a->avisos ?? []]]);
    }

    private function prepararDocumento(PedidoPortalColaborador $p, Colaborador $c, array $d): void
    {
        $codigo = (string) ($d['documento'] ?? '');
        $m = $this->documentos->modelo($codigo);
        if (! $m || ! ($m['ativo'] ?? true)) {
            throw new ErroNegocio('Modelo de documento inexistente ou inactivo.', 'MODELO_INVALIDO', 422);
        }
        if (mb_strlen(trim((string) ($d['finalidade'] ?? ''))) < 3) {
            throw new ErroNegocio('Indique a finalidade do documento.', 'FINALIDADE_EM_FALTA', 422);
        }
        if ($codigo === 'OUTRO' && mb_strlen(trim((string) ($d['observacoes'] ?? ''))) < 5) {
            throw new ErroNegocio('Descreva o documento pretendido nas observações.', 'OBSERVACOES_EM_FALTA', 422);
        }
        if ($codigo !== 'OUTRO' && PedidoPortalColaborador::query()->where('colaborador_id', $c->id)->where('tipo', 'DOCUMENTO')->where('estado', 'like', 'PENDENTE%')
            ->where('dados->documento', $codigo)->exists()) {
            throw new ErroNegocio('Já tem um pedido pendente deste documento.', 'PEDIDO_DUPLICADO', 422);
        }
        $p->dados = ['documento' => $codigo, 'finalidade' => trim($d['finalidade']), 'destinatario' => trim((string) ($d['destinatario'] ?? '')),
            'observacoes' => trim((string) ($d['observacoes'] ?? ''))];
    }

    private function prepararAgregado(PedidoPortalColaborador $p, Colaborador $c, array $d): void
    {
        if (PedidoPortalColaborador::query()->where('colaborador_id', $c->id)->where('tipo', 'AGREGADO')->where('estado', 'like', 'PENDENTE%')->exists()) {
            throw new ErroNegocio('Já tem um pedido de actualização do agregado pendente.', 'PEDIDO_DUPLICADO', 422);
        }
        $depois = array_map(function ($x) {
            if (trim((string) ($x['nome'] ?? '')) === '') {
                throw new ErroNegocio('Indique o nome de cada dependente.', 'DEPENDENTE_INVALIDO', 422);
            }
            if (! empty($x['data_nascimento']) && $x['data_nascimento'] > now()->toDateString()) {
                throw new ErroNegocio('A data de nascimento não pode ser futura.', 'DEPENDENTE_INVALIDO', 422);
            }

            return ['nome' => trim($x['nome']), 'parentesco' => self::parentesco((string) ($x['parentesco'] ?? '')), 'data_nascimento' => $x['data_nascimento'] ?? null,
                'sexo' => $x['sexo'] ?? null, 'dependente_fiscal' => (bool) ($x['dependente_fiscal'] ?? false)];
        }, array_values($d['dependentes'] ?? []));
        $antes = $this->dependentesActuais($c->id);
        if ($antes === $depois) {
            throw new ErroNegocio('A proposta é igual aos dados actuais.', 'SEM_ALTERACOES', 422);
        }
        $p->dados = ['antes' => $antes, 'depois' => $depois, 'observacoes' => $d['observacoes'] ?? ''];
    }

    // ───────────── Efeitos das decisões ─────────────

    private function efeitos(PedidoPortalColaborador $p, string $estado, ?string $remunerada, ?string $nota): void
    {
        if ($p->tipo === 'FERIAS' && $p->plano_ferias_colaborador_id && in_array($estado, ['APROVADO', 'RECUSADO'], true)) {
            PlanoFeriasColaborador::query()->whereKey($p->plano_ferias_colaborador_id)->update(['estado' => $estado === 'APROVADO' ? 'APROVADO' : 'CANCELADO']);
        }
        if ($p->tipo === 'AUSENCIA' && $p->ausencia_falta_id && ($a = AusenciaFaltaColaborador::query()->lockForUpdate()->find($p->ausencia_falta_id))) {
            $this->ausencias->aplicarDecisaoPortal($a, $estado, $remunerada, $nota);
        }
        if ($p->tipo === 'AGREGADO' && $estado === 'APROVADO') {
            if ($this->dependentesActuais($p->colaborador_id) !== self::normalizarLista($p->dados['antes'] ?? [])) {   // pedidos migrados: rótulos do legado
                throw new ErroNegocio('Os dependentes foram alterados depois do pedido: recuse-o e peça ao colaborador um novo pedido.', 'PEDIDO_DESACTUALIZADO', 422);
            }
            DependenteColaborador::query()->where('colaborador_id', $p->colaborador_id)->delete();
            foreach ($p->dados['depois'] as $n => $x) {
                DependenteColaborador::create($x + ['colaborador_id' => $p->colaborador_id, 'ordem' => $n + 1, 'origem' => 'PORTAL']);
            }
        }
    }

    private function dependentesActuais(int $colaborador): array
    {
        return DependenteColaborador::query()->where('colaborador_id', $colaborador)->orderBy('ordem')->orderBy('id')->get()
            ->map(fn ($x) => ['nome' => (string) $x->nome, 'parentesco' => self::parentesco((string) $x->parentesco), 'data_nascimento' => $x->data_nascimento?->toDateString(),
                'sexo' => $x->sexo, 'dependente_fiscal' => (bool) $x->dependente_fiscal])->all();
    }

    /** Aprovador da etapa (os pedidos migrados guardam a chave do legado aprovador_employee_id). */
    private static function aprovador(array $etapa): int
    {
        return (int) ($etapa['aprovador_colaborador_id'] ?? $etapa['aprovador_employee_id'] ?? 0);
    }

    /** Lista de dependentes no formato de comparação (parentesco do domínio, data AAAA-MM-DD). */
    public static function normalizarLista(array $lista): array
    {
        return array_map(fn ($x) => ['nome' => trim((string) ($x['nome'] ?? '')), 'parentesco' => self::parentesco((string) ($x['parentesco'] ?? '')),
            'data_nascimento' => ! empty($x['data_nascimento']) ? substr((string) $x['data_nascimento'], 0, 10) : null, 'sexo' => $x['sexo'] ?? null,
            'dependente_fiscal' => (bool) ($x['dependente_fiscal'] ?? false)], array_values($lista));
    }

    public static function parentesco(string $v): string
    {
        return self::PARENTESCOS[mb_strtoupper(trim($v))] ?? 'OUTRO';
    }

    /** @return list<string> rótulos entre [ ] no texto */
    private function marcadores(string $texto): array
    {
        preg_match_all('/\[([^\]]+)\]/u', $texto, $m);

        return $m[1];
    }
}
