<?php

namespace App\Services\CRM;

use App\Exceptions\ErroNegocio;
use App\Models\AtividadeComercialCRM;
use App\Models\ContaCRM;
use App\Models\ContactoCRM;
use App\Models\Empresa;
use App\Models\ModeloEmailCRM;
use App\Models\OportunidadeVendaCRM;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Actividades comerciais, agenda, emails e campanhas (crm_dados.js:232-252 e 329-359; crm_ui_gestao.js:204-214 e 331-381).
 * Paridade:
 *   - actividade CHAMADA|EMAIL|REUNIAO|TAREFA|NOTA (TAREFA por omissão; título por omissão = o do tipo); a NOTA nasce
 *     concluída; a conta vem da oportunidade quando não é indicada; mexer numa actividade de uma oportunidade actualiza
 *     `ultima_atividade_em`;
 *   - agenda: pendentes em atraso, de hoje e dos próximos N dias, por responsável;
 *   - email: o modelo é preenchido com os marcadores {{cliente}} {{contacto}} {{oportunidade}} {{valor}}
 *     {{responsavel}} {{empresa}} {{data_fecho}}; o destinatário é o contacto indicado, o da oportunidade, o principal
 *     ou o primeiro da conta (ou o email da conta); fica registado como actividade EMAIL concluída (ou conclui a
 *     actividade de email agendada);
 *   - campanha: contas por tipo e origem, opcionalmente só com oportunidade aberta numa etapa (a de maior valor),
 *     mensagem personalizada por destinatário; envio individual ou numa única mensagem em Bcc; tudo no histórico.
 * O envio passa pelo ponto de extensão CanalEmailCRM (por omissão não envia: devolve a ligação mailto:).
 * Correcções: o contacto, a oportunidade e o modelo têm de ser da empresa (e o contacto da conta); o resultado
 * guardado é limitado ao tamanho da coluna (50) — o legado gravava o assunto completo.
 */
final class ServicoAtividadesCRM
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    public function guardar(array $d, ?AtividadeComercialCRM $a = null): AtividadeComercialCRM
    {
        $tipo = isset(RegrasCRM::TIPOS_ATIVIDADE[$d['tipo'] ?? '']) ? $d['tipo'] : 'TAREFA';
        $o = empty($d['oportunidade_crm_id']) ? null : OportunidadeVendaCRM::query()->findOrFail($d['oportunidade_crm_id']);
        $contaId = ($d['conta_crm_id'] ?? null) ?: $o?->conta_crm_id;
        if ($contaId) {
            ContaCRM::query()->findOrFail($contaId);
        }
        if (! empty($d['contacto_crm_id']) && ! ContactoCRM::query()->whereKey($d['contacto_crm_id'])->when($contaId, fn ($q) => $q->where('conta_crm_id', $contaId))->exists()) {
            throw new ErroNegocio('O contacto não pertence à conta.', 'CONTACTO_INVALIDO', 422);
        }
        $reg = ['oportunidade_crm_id' => $o?->id, 'conta_crm_id' => $contaId, 'contacto_crm_id' => $d['contacto_crm_id'] ?? null, 'tipo' => $tipo,
            'titulo' => mb_substr(trim((string) ($d['titulo'] ?? '')) ?: RegrasCRM::TIPOS_ATIVIDADE[$tipo], 0, 255), 'descricao' => trim((string) ($d['descricao'] ?? '')) ?: null,
            'data_prevista' => ($d['data_prevista'] ?? null) ?: RegrasCRM::hoje(), 'responsavel' => ($d['responsavel'] ?? null) ?: ($a?->responsavel ?? Auth::user()?->nome_utilizador),
            'modelo_email_crm_id' => ! empty($d['modelo_email_crm_id']) ? ModeloEmailCRM::query()->findOrFail($d['modelo_email_crm_id'])->id : null];

        return DB::transaction(function () use ($a, $reg, $d, $tipo) {
            if ($a) {
                $a->update($reg);
            } else {
                $concluida = $tipo === 'NOTA' || ! empty($d['concluida']);
                $a = AtividadeComercialCRM::create($reg + ['concluida' => $concluida, 'concluida_em' => $concluida ? now() : null,
                    'resultado' => mb_substr(trim((string) ($d['resultado'] ?? '')), 0, 50) ?: null, 'automatica' => false, 'criado_por' => Auth::user()?->nome_utilizador]);
            }
            $this->tocar($a->oportunidade_crm_id);

            return $a;
        });
    }

    public function concluir(AtividadeComercialCRM $a, ?string $resultado): AtividadeComercialCRM
    {
        $a->update(['concluida' => true, 'concluida_em' => now(), 'resultado' => mb_substr(trim((string) $resultado), 0, 50) ?: null, 'concluida_por' => Auth::user()?->nome_utilizador]);
        $this->tocar($a->oportunidade_crm_id);

        return $a;
    }

    public function reabrir(AtividadeComercialCRM $a): AtividadeComercialCRM
    {
        $a->update(['concluida' => false, 'concluida_em' => null]);

        return $a;
    }

    /**
     * Agenda: pendentes em atraso, de hoje e dos próximos $dias dias.
     *
     * @return array{em_atraso: list<mixed>, hoje: list<mixed>, proximos: list<mixed>}
     */
    public function agenda(?string $responsavel, int $dias): array
    {
        $hoje = RegrasCRM::hoje();
        $ate = RegrasCRM::somarDias($hoje, $dias);
        $pend = AtividadeComercialCRM::query()->where(fn ($q) => $q->whereNull('concluida')->orWhere('concluida', false))
            ->when($responsavel, fn ($q, $r) => $q->where('responsavel', $r))->where('data_prevista', '<=', $ate)
            ->with(['contaCrm:id,nome', 'oportunidadeCrm:id,titulo'])->orderBy('data_prevista')->orderBy('id')->get();
        $d = fn ($a) => $a->data_prevista?->toDateString();

        return ['em_atraso' => $pend->filter(fn ($a) => $d($a) < $hoje)->values()->all(), 'hoje' => $pend->filter(fn ($a) => $d($a) === $hoje)->values()->all(),
            'proximos' => $pend->filter(fn ($a) => $d($a) > $hoje)->values()->all()];
    }

    /** Contexto dos marcadores e destinatário (contextoEmail, crm_dados.js:343-352). */
    public function contextoEmail(?int $contaId, ?int $contactoId, ?int $oportunidadeId): array
    {
        $o = $oportunidadeId ? OportunidadeVendaCRM::query()->findOrFail($oportunidadeId) : null;
        $conta = ($contaId ?: $o?->conta_crm_id) ? ContaCRM::query()->findOrFail($contaId ?: $o->conta_crm_id) : null;
        $cts = $conta ? ContactoCRM::query()->where('conta_crm_id', $conta->id)->orderBy('id')->get() : collect();
        $alvo = $contactoId ?: $o?->contacto_crm_id;
        $c = $alvo ? $cts->firstWhere('id', $alvo) : ($cts->firstWhere('principal', true) ?? $cts->first());
        $empresa = Empresa::query()->find($this->contexto->obrigatorio());

        return ['email' => $c?->email ?: $conta?->email ?: '', 'conta' => $conta, 'contacto' => $c, 'oportunidade' => $o, 'ctx' => [
            'cliente' => $conta?->nome ?? '', 'contacto' => $c?->nome ?? $conta?->nome ?? '', 'oportunidade' => $o?->titulo ?? '',
            'valor' => $o ? number_format((float) $o->valor, 2, ',', ' ').' Kz' : '', 'responsavel' => $o?->responsavel ?: Auth::user()?->nome_utilizador,
            'empresa' => $empresa?->nome ?? '', 'data_fecho' => $o?->data_fecho_prevista?->format('d/m/Y') ?? '',
        ]];
    }

    /** Mensagem preenchida a partir de um modelo (ou do assunto/corpo indicados). */
    public function preparar(array $d): array
    {
        $ctx = $this->contextoEmail($d['conta_crm_id'] ?? null, $d['contacto_crm_id'] ?? null, $d['oportunidade_crm_id'] ?? null);
        $m = empty($d['modelo_email_crm_id']) ? null : ModeloEmailCRM::query()->findOrFail($d['modelo_email_crm_id']);

        return ['para' => ($d['para'] ?? null) ?: $ctx['email'], 'assunto' => $m ? RegrasCRM::preencher($m->assunto, $ctx['ctx']) : (string) ($d['assunto'] ?? ''),
            'corpo' => $m ? RegrasCRM::preencher($m->corpo, $ctx['ctx']) : (string) ($d['corpo'] ?? ''), 'modelo_email_crm_id' => $m?->id,
            'conta_crm_id' => $ctx['conta']?->id, 'contacto_crm_id' => $ctx['contacto']?->id, 'oportunidade_crm_id' => $ctx['oportunidade']?->id];
    }

    /** Envia (pelo canal configurado) e regista no histórico (registarEmail, crm_dados.js:354-359). */
    public function enviarEmail(array $d, bool $massa = false): array
    {
        $msg = $this->preparar($d);
        if (trim($msg['assunto']) === '') {
            throw new ErroNegocio('Escreva o assunto.', 'ASSUNTO_OBRIGATORIO', 422);
        }
        if (! $massa && ($msg['para'] === '' || ! RegrasCRM::emailValido($msg['para']))) {
            throw new ErroNegocio('O destinatário não tem um email válido.', 'SEM_EMAIL', 422);
        }
        $envio = $this->canal()->enviar(['para' => $msg['para'], 'bcc' => $d['bcc'] ?? null, 'assunto' => $msg['assunto'], 'corpo' => $msg['corpo']]);
        $atividade = DB::transaction(function () use ($d, $msg, $envio, $massa) {
            if (! empty($d['atividade_id'])) {
                $a = AtividadeComercialCRM::query()->lockForUpdate()->findOrFail($d['atividade_id']);
                $a->update(['concluida' => true, 'concluida_em' => now(), 'resultado' => mb_substr("Email: {$msg['assunto']}", 0, 50), 'concluida_por' => Auth::user()?->nome_utilizador]);
            } else {
                $a = AtividadeComercialCRM::create(['oportunidade_crm_id' => $msg['oportunidade_crm_id'], 'conta_crm_id' => $msg['conta_crm_id'], 'contacto_crm_id' => $msg['contacto_crm_id'],
                    'tipo' => 'EMAIL', 'titulo' => mb_substr($msg['assunto'], 0, 255), 'descricao' => mb_substr((string) ($d['descricao_registo'] ?? $msg['corpo']), 0, 2000),
                    'data_prevista' => RegrasCRM::hoje(), 'concluida' => true, 'concluida_em' => now(), 'resultado' => mb_substr($massa ? 'Envio em massa' : $envio['resultado'], 0, 50),
                    'modelo_email_crm_id' => $msg['modelo_email_crm_id'], 'automatica' => false, 'criado_por' => Auth::user()?->nome_utilizador]);
            }
            $this->tocar($a->oportunidade_crm_id);

            return $a;
        });

        return ['mensagem' => $msg, 'envio' => $envio, 'atividade' => $atividade];
    }

    /** Destinatários de uma campanha, com a mensagem personalizada. */
    public function destinatarios(int $modeloId, array $f): array
    {
        $m = ModeloEmailCRM::query()->findOrFail($modeloId);
        $contas = ContaCRM::query()->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))
            ->when($f['origem'] ?? null, fn ($q, $o) => $q->where('origem_original', $o))->orderBy('nome')->get();
        $opps = OportunidadeVendaCRM::query()->where('estado', 'ABERTA')->when($f['etapa_codigo'] ?? null, fn ($q, $e) => $q->where('etapa_codigo', $e))
            ->orderByDesc('valor')->orderBy('id')->get()->groupBy('conta_crm_id');
        $r = [];
        foreach ($contas as $c) {
            $o = ($opps[$c->id] ?? collect())->first();
            if (! empty($f['etapa_codigo']) && ! $o) {
                continue;
            }
            $msg = $this->preparar(['conta_crm_id' => $c->id, 'oportunidade_crm_id' => $o?->id, 'modelo_email_crm_id' => $m->id]);
            $r[] = $msg + ['conta' => $c->only(['id', 'nome', 'tipo']), 'tem_email' => $msg['para'] !== ''];
        }

        return $r;
    }

    /**
     * Envio de uma campanha: um email por destinatário (INDIVIDUAL) ou uma mensagem única em Bcc (BCC); regista uma
     * actividade por conta.
     *
     * @param  list<array{conta_crm_id: int, contacto_crm_id?: ?int, oportunidade_crm_id?: ?int}>  $destinatarios
     */
    public function enviarCampanha(int $modeloId, array $destinatarios, string $modo): array
    {
        $m = ModeloEmailCRM::query()->findOrFail($modeloId);
        if ($modo === 'BCC') {
            $msgs = array_map(fn ($d) => $this->preparar($d + ['modelo_email_crm_id' => $m->id]), $destinatarios);
            $bcc = implode(',', array_unique(array_filter(array_column($msgs, 'para'))));
            $assunto = trim(preg_replace('/\{\{\s*\w+\s*\}\}/', '', $m->assunto));
            $corpo = preg_replace('/\{\{\s*\w+\s*\}\}/', '', preg_replace('/\{\{\s*contacto\s*\}\}/', 'Cliente', $m->corpo));
            $envio = $this->canal()->enviar(['para' => '', 'bcc' => $bcc, 'assunto' => $assunto, 'corpo' => $corpo]);
            foreach ($msgs as $msg) {
                $this->enviarRegisto($msg + ['assunto_fixo' => $m->assunto]);
            }

            return ['envio' => $envio, 'registadas' => count($msgs)];
        }
        $r = [];
        $semEmail = [];
        foreach ($destinatarios as $d) {
            if ($this->preparar($d + ['modelo_email_crm_id' => $m->id])['para'] === '') {
                $semEmail[] = (int) $d['conta_crm_id'];

                continue;
            }
            $r[] = $this->enviarEmail($d + ['modelo_email_crm_id' => $m->id], true);
        }

        return ['envios' => array_map(fn ($x) => ['conta_crm_id' => $x['mensagem']['conta_crm_id'], 'para' => $x['mensagem']['para'], 'envio' => $x['envio']], $r),
            'registadas' => count($r), 'sem_email' => $semEmail];
    }

    /** Registo de uma mensagem única em Bcc (sem corpo personalizado, como o legado). */
    private function enviarRegisto(array $msg): void
    {
        AtividadeComercialCRM::create(['conta_crm_id' => $msg['conta_crm_id'], 'contacto_crm_id' => $msg['contacto_crm_id'], 'tipo' => 'EMAIL',
            'titulo' => mb_substr($msg['assunto_fixo'], 0, 255), 'descricao' => '(mensagem única em Bcc)', 'data_prevista' => RegrasCRM::hoje(), 'concluida' => true,
            'concluida_em' => now(), 'resultado' => 'Envio em massa', 'modelo_email_crm_id' => $msg['modelo_email_crm_id'], 'automatica' => false,
            'criado_por' => Auth::user()?->nome_utilizador]);
    }

    private function canal(): CanalEmailCRM
    {
        return app()->bound(CanalEmailCRM::class) ? app(CanalEmailCRM::class) : new CanalEmailCRMRegisto;
    }

    private function tocar(?int $oportunidadeId): void
    {
        if ($oportunidadeId) {
            OportunidadeVendaCRM::query()->whereKey($oportunidadeId)->update(['ultima_atividade_em' => now()]);
        }
    }
}
