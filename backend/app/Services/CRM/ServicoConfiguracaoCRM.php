<?php

namespace App\Services\CRM;

use App\Exceptions\ErroNegocio;
use App\Models\ConfiguracaoCRM;
use App\Models\FunilVendasCRM;
use App\Models\ModeloEmailCRM;
use App\Models\OportunidadeVendaCRM;
use App\Models\SequenciaCampanhaCRM;
use App\Support\Dados\VerificadorReferencias;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Configuração do CRM (crm_dados.js:45-117, 330-338, 362-372): definições (motivos de perda, origens, dias sem
 * actividade, prazo de pagamento), funis de vendas com etapas (probabilidade, dias de estagnação, tipo ABERTA|GANHA|PERDIDA,
 * cor, tarefas automáticas), modelos de email e sequências de email por etapa. Paridade:
 *   - primeira utilização: cria os 3 modelos de email e o funil «Vendas» com 6 etapas (a tarefa de email da etapa
 *     «Proposta enviada» usa o modelo «Envio de proposta»);
 *   - funil: nome; ≥ 1 etapa ABERTA e exactamente uma GANHA e uma PERDIDA; etapas removidas não podem ter oportunidades;
 *     não se elimina um funil com oportunidades nem o último;
 *   - sequência: nome, funil e etapa, ≥ 1 passo com modelo.
 * Correcções: a criação inicial é feita uma só vez, com lock (dois pedidos simultâneos criavam dois funis); não se
 * elimina um funil com sequências nem um modelo usado em actividades, etapas ou sequências (o legado deixava referências
 * órfãs); a etapa e os modelos de uma sequência têm de existir.
 */
final class ServicoConfiguracaoCRM
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly VerificadorReferencias $referencias,
    ) {}

    // ───────────── Definições ─────────────

    /** @return array{id: ?int, motivos_perda: list<string>, origens: list<string>, dias_sem_atividade: int, prazo_pagamento_dias: int} */
    public function obter(): array
    {
        $r = ConfiguracaoCRM::query()->orderBy('id')->first();

        return ['id' => $r?->id, 'motivos_perda' => $r?->motivos_perda ?: RegrasCRM::MOTIVOS_PADRAO, 'origens' => $r?->origens ?: RegrasCRM::ORIGENS_PADRAO,
            'dias_sem_atividade' => $r?->dias_sem_atividade ?? 7, 'prazo_pagamento_dias' => $r?->prazo_pagamento_dias ?? 30];
    }

    public function guardar(array $d): array
    {
        $lista = fn ($v) => array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), is_array($v) ? $v : explode("\n", (string) $v)), fn ($x) => $x !== '')));
        $reg = ['motivos_perda' => $lista($d['motivos_perda'] ?? []), 'origens' => $lista($d['origens'] ?? []),
            'dias_sem_atividade' => max(1, (int) ($d['dias_sem_atividade'] ?? 0) ?: 7), 'prazo_pagamento_dias' => max(0, (int) ($d['prazo_pagamento_dias'] ?? 30))];
        if (! $reg['motivos_perda']) {
            throw new ErroNegocio('Indique pelo menos um motivo de perda.', 'SEM_MOTIVOS', 422);
        }
        if (mb_strlen(json_encode($reg['origens'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 255) {
            throw new ErroNegocio('A lista de origens é demasiado longa (máximo 255 caracteres): encurte-a.', 'ORIGENS_LONGAS', 422);
        }
        $atual = ConfiguracaoCRM::query()->orderBy('id')->first();
        $atual ? $atual->update($reg) : ConfiguracaoCRM::create($reg);

        return $this->obter();
    }

    // ───────────── Funis de vendas ─────────────

    /** @return Collection<int, FunilVendasCRM> */
    public function funis(): Collection
    {
        if (! FunilVendasCRM::query()->exists()) {
            $this->criarIniciais();
        }

        return FunilVendasCRM::query()->orderBy('ordem')->orderBy('id')->get();
    }

    public function funil(int $id): FunilVendasCRM
    {
        $this->funis();

        return FunilVendasCRM::query()->findOrFail($id);
    }

    /** Modelos de email e funil de partida (editáveis em Configuração). */
    private function criarIniciais(): void
    {
        DB::transaction(function () {
            DB::table('empresas')->where('id', $this->contexto->obrigatorio())->lockForUpdate()->first();
            if (FunilVendasCRM::query()->exists()) {
                return;
            }
            if (! ModeloEmailCRM::query()->exists()) {
                $fecho = "\n\nCom os melhores cumprimentos,\n{{responsavel}}\n{{empresa}}";
                foreach ([
                    ['Apresentação', '{{empresa}} — apresentação', "Caro(a) {{contacto}},\n\nAgradecemos o interesse da {{cliente}}. Junto enviamos a apresentação dos nossos produtos e serviços.\n\nFicamos ao dispor para agendar uma reunião.{$fecho}"],
                    ['Envio de proposta', 'Proposta comercial — {{oportunidade}}', "Caro(a) {{contacto}},\n\nConforme combinado, segue a nossa proposta para {{oportunidade}}, no valor de {{valor}}.\n\nEstamos disponíveis para esclarecer qualquer questão.{$fecho}"],
                    ['Seguimento', 'Seguimento — {{oportunidade}}', "Caro(a) {{contacto}},\n\nGostaríamos de saber se teve oportunidade de analisar a nossa proposta ({{oportunidade}}). Podemos avançar até {{data_fecho}}?{$fecho}"],
                ] as [$nome, $assunto, $corpo]) {
                    ModeloEmailCRM::create(['nome' => $nome, 'assunto' => $assunto, 'corpo' => $corpo]);
                }
            }
            $proposta = ModeloEmailCRM::query()->where('nome', 'Envio de proposta')->value('id');
            $t = fn ($tipo, $titulo, $dias) => ['tipo' => $tipo, 'titulo' => $titulo, 'dias' => $dias] + ($tipo === 'EMAIL' && $proposta ? ['modelo_email_crm_id' => $proposta] : []);
            $e = fn ($nome, $prob, $estag, $tipo, $cor, $tarefas) => ['id' => RegrasCRM::novoId(), 'nome' => $nome, 'probabilidade' => $prob, 'dias_estagnacao' => $estag,
                'tipo' => $tipo, 'cor' => $cor, 'tarefas' => $tarefas];
            FunilVendasCRM::create(['nome' => 'Vendas', 'ordem' => 1, 'ativo' => true, 'etapas' => [
                $e('Lead', 10, 7, 'ABERTA', '#64748b', [$t('CHAMADA', 'Primeiro contacto com o cliente', 1)]),
                $e('Qualificação', 25, 10, 'ABERTA', '#0891b2', [$t('REUNIAO', 'Reunião de levantamento de necessidades', 3)]),
                $e('Proposta enviada', 50, 14, 'ABERTA', '#7c3aed', [$t('EMAIL', 'Enviar proposta comercial', 0), $t('CHAMADA', 'Seguimento da proposta', 3)]),
                $e('Negociação', 75, 10, 'ABERTA', '#d97706', [$t('REUNIAO', 'Reunião de negociação / fecho', 2)]),
                $e('Ganha', 100, 0, 'GANHA', '#15803d', [$t('TAREFA', 'Emitir documento comercial e agendar entrega', 0)]),
                $e('Perdida', 0, 0, 'PERDIDA', '#b91c1c', []),
            ]]);
        });
    }

    public function guardarFunil(array $d, ?FunilVendasCRM $funil = null): FunilVendasCRM
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Dê um nome ao funil.', 'NOME_OBRIGATORIO', 422);
        }
        $modelos = ModeloEmailCRM::query()->pluck('id')->flip();
        $etapas = array_map(fn ($e) => [
            'id' => trim((string) ($e['id'] ?? '')) ?: RegrasCRM::novoId(), 'nome' => trim((string) ($e['nome'] ?? '')),
            'probabilidade' => max(0, min(100, (float) ($e['probabilidade'] ?? 0))), 'dias_estagnacao' => max(0, (int) ($e['dias_estagnacao'] ?? 0)),
            'tipo' => in_array($e['tipo'] ?? null, RegrasCRM::TIPOS_ETAPA, true) ? $e['tipo'] : 'ABERTA', 'cor' => $e['cor'] ?? '#64748b',
            'tarefas' => array_values(array_map(fn ($t) => ['tipo' => isset(RegrasCRM::TIPOS_ATIVIDADE[$t['tipo'] ?? '']) ? $t['tipo'] : 'TAREFA', 'titulo' => trim((string) $t['titulo']),
                'dias' => max(0, (int) ($t['dias'] ?? 0)), 'modelo_email_crm_id' => ! empty($t['modelo_email_crm_id']) && isset($modelos[(int) $t['modelo_email_crm_id']]) ? (int) $t['modelo_email_crm_id'] : null],
                array_filter($e['tarefas'] ?? [], fn ($t) => trim((string) ($t['titulo'] ?? '')) !== ''))),
        ], array_values($d['etapas'] ?? []));
        if (collect($etapas)->contains(fn ($e) => $e['nome'] === '')) {
            throw new ErroNegocio('Todas as etapas precisam de nome.', 'ETAPA_SEM_NOME', 422);
        }
        if (count(array_unique(array_column($etapas, 'id'))) !== count($etapas)) {
            throw new ErroNegocio('Há etapas com o mesmo identificador.', 'ETAPA_DUPLICADA', 422);
        }
        $n = array_count_values(array_column($etapas, 'tipo'));
        if (($n['ABERTA'] ?? 0) < 1) {
            throw new ErroNegocio('O funil precisa de pelo menos uma etapa em aberto.', 'SEM_ETAPA_ABERTA', 422);
        }
        if (($n['GANHA'] ?? 0) !== 1 || ($n['PERDIDA'] ?? 0) !== 1) {
            throw new ErroNegocio('O funil precisa de exactamente uma etapa «Ganha» e uma «Perdida».', 'ETAPAS_FECHO', 422);
        }
        $reg = ['nome' => $nome, 'ordem' => (int) ($d['ordem'] ?? 0), 'ativo' => ($d['ativo'] ?? true) !== false, 'etapas' => $etapas];

        return DB::transaction(function () use ($funil, $reg, $etapas) {
            if (! $funil) {
                return FunilVendasCRM::create($reg);
            }
            $funil = FunilVendasCRM::query()->lockForUpdate()->findOrFail($funil->id);
            $removidas = collect($funil->etapas ?? [])->reject(fn ($e) => in_array($e['id'], array_column($etapas, 'id'), true));
            if ($removidas->isNotEmpty()) {
                $usadas = OportunidadeVendaCRM::query()->where('funil_vendas_crm_id', $funil->id)->whereIn('etapa_codigo', $removidas->pluck('id'))->count();
                if ($usadas) {
                    throw new ErroNegocio("Há {$usadas} oportunidade(s) nas etapas que removeu (".$removidas->pluck('nome')->implode(', ').'). Mova-as antes de remover.', 'ETAPA_EM_USO', 422);
                }
            }
            $funil->update($reg);

            return $funil;
        });
    }

    public function eliminarFunil(FunilVendasCRM $funil): void
    {
        DB::transaction(function () use ($funil) {
            if (OportunidadeVendaCRM::query()->where('funil_vendas_crm_id', $funil->id)->exists()) {
                throw new ErroNegocio('O funil tem oportunidades. Desactive-o em vez de eliminar.', 'FUNIL_COM_OPORTUNIDADES', 422);
            }
            if (FunilVendasCRM::query()->lockForUpdate()->pluck('id')->count() <= 1) {
                throw new ErroNegocio('É necessário pelo menos um funil.', 'ULTIMO_FUNIL', 422);
            }
            $this->referencias->exigirLivre('funis_vendas_crm', $funil->id, 'o funil', [], [], 'Elimine primeiro as sequências de email do funil.');
            $funil->delete();
        });
    }

    // ───────────── Modelos de email ─────────────

    public function guardarModelo(array $d, ?ModeloEmailCRM $m = null): ModeloEmailCRM
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Dê um nome ao modelo.', 'NOME_OBRIGATORIO', 422);
        }
        if (trim((string) ($d['assunto'] ?? '')) === '') {
            throw new ErroNegocio('Escreva o assunto.', 'ASSUNTO_OBRIGATORIO', 422);
        }
        $reg = ['nome' => $nome, 'assunto' => trim((string) $d['assunto']), 'corpo' => (string) ($d['corpo'] ?? '')];
        $m ? $m->update($reg) : $m = ModeloEmailCRM::create($reg);

        return $m;
    }

    public function eliminarModelo(ModeloEmailCRM $m): void
    {
        $usado = FunilVendasCRM::query()->get()->contains(fn ($f) => collect($f->etapas ?? [])->flatMap(fn ($e) => $e['tarefas'] ?? [])
            ->contains(fn ($t) => (int) ($t['modelo_email_crm_id'] ?? 0) === $m->id))
            || SequenciaCampanhaCRM::query()->get()->contains(fn ($s) => collect($s->passos ?? [])->contains(fn ($p) => (int) ($p['modelo_email_crm_id'] ?? 0) === $m->id));
        if ($usado) {
            throw new ErroNegocio('O modelo é usado em tarefas automáticas de etapas ou em sequências: retire-o primeiro.', 'MODELO_EM_USO', 422);
        }
        $this->referencias->exigirLivre('modelos_email_crm', $m->id, 'o modelo', [], [], 'O modelo foi usado em actividades registadas.');
        $m->delete();
    }

    // ───────────── Sequências de email ─────────────

    public function guardarSequencia(array $d, ?SequenciaCampanhaCRM $s = null): SequenciaCampanhaCRM
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Dê um nome à sequência.', 'NOME_OBRIGATORIO', 422);
        }
        $funil = empty($d['funil_vendas_crm_id']) ? null : FunilVendasCRM::query()->find($d['funil_vendas_crm_id']);
        if (! $funil || empty($d['etapa_codigo']) || ! RegrasCRM::etapa($funil->etapas, $d['etapa_codigo'])) {
            throw new ErroNegocio('Escolha o funil e a etapa que inicia a sequência.', 'ETAPA_OBRIGATORIA', 422);
        }
        $modelos = ModeloEmailCRM::query()->pluck('id')->flip();
        $passos = array_values(array_map(fn ($p) => ['dias' => max(0, (int) ($p['dias'] ?? 0)), 'modelo_email_crm_id' => (int) $p['modelo_email_crm_id'], 'tipo' => 'EMAIL'],
            array_filter($d['passos'] ?? [], fn ($p) => ! empty($p['modelo_email_crm_id']) && isset($modelos[(int) $p['modelo_email_crm_id']]))));
        if (! $passos) {
            throw new ErroNegocio('Acrescente pelo menos um passo com modelo de email.', 'SEM_PASSOS', 422);
        }
        if (mb_strlen(json_encode($passos)) > 255) {
            throw new ErroNegocio('A sequência tem passos a mais para o espaço disponível (máximo 5).', 'PASSOS_EXCEDIDOS', 422);
        }
        $reg = ['nome' => $nome, 'funil_vendas_crm_id' => $funil->id, 'etapa_codigo' => $d['etapa_codigo'], 'ativo' => ($d['ativo'] ?? true) !== false, 'passos' => $passos];
        $s ? $s->update($reg) : $s = SequenciaCampanhaCRM::create($reg);

        return $s;
    }

    public function eliminarSequencia(SequenciaCampanhaCRM $s): void
    {
        DB::transaction(function () use ($s) {
            // as actividades já agendadas mantêm-se (crm_ui_gestao.js:430): perdem a ligação à sequência
            DB::table('atividades_comerciais_crm')->where('empresa_id', $s->empresa_id)->where('sequencia_campanha_id', $s->id)->update(['sequencia_campanha_id' => null]);
            $s->delete();
        });
    }
}
