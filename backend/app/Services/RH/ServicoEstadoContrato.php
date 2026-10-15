<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\LinhaFolhaSalarial;
use App\Models\PeriodoProcessamentoSalarial;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Suspensão e cessação do contrato de trabalho (Versão Actual: js/modules/rh/contrato_estado.js; ecrã
 * contrato_estado_ui.js). Lei n.º 12/23, de 27 de Dezembro (Lei Geral do Trabalho). Paridade:
 *   - SUSPENDER (:136): início obrigatório, fim opcional ≥ início, fundamento do art. 267.º (trabalhador) ou 270.º
 *     (empregador); fica o histórico em suspensoes[]; um contrato cessado não se suspende;
 *   - REACTIVAR (:158): fecha a suspensão no histórico (fim = hoje se não tinha) e volta a ACTIVO;
 *   - CESSAR (:191): data ≥ início do contrato, fundamento (caducidade art. 277.º, revogação art. 280.º, resolução
 *     arts. 281.º-306.º), aviso prévio (30 dias na denúncia), compensação proposta (arts. 307.º, 308.º, 310.º) e
 *     decidida (editável); a data de fim do contrato passa a ser a da cessação; o colaborador passa a INACTIVO com a
 *     data da cessação (salvo indicação em contrário); os processamentos posteriores com lançamentos são mostrados;
 *   - ANULAR A CESSAÇÃO (:224): recusada se o colaborador já tiver outro contrato por cessar; histórico em
 *     cessacoes_anuladas[]; o colaborador volta a ACTIVO.
 *   - Antiguidade = anos completos, mais 1 se a fracção for ≥ 3 meses (art. 311.º); SB = soma mensal das remunerações.
 *   - A compensação NÃO gera lançamento salarial: fica registada como proposta e decisão.
 * Melhorias:
 *   - tudo no servidor, numa transacção, com o contrato bloqueado e auditoria (o legado gravava no navegador);
 *   - fundamento validado contra o catálogo (o legado aceitava qualquer código);
 *   - anular a cessação repõe a data de fim que o contrato tinha antes (o legado punha sempre «sem fim», 9999-12-31)
 *     e recusa se esse período voltar a sobrepor-se a outro contrato do colaborador;
 *   - compensação em bcmath; valor decidido ≥ 0; a suspensão não pode ter fim antes do início do contrato.
 */
final class ServicoEstadoContrato
{
    /** Art. 267.º — factos relativos ao trabalhador · Art. 270.º — causas relativas ao empregador (contrato_estado.js:37). */
    public const MOTIVOS_SUSPENSAO = [
        'MILITAR' => ['lado' => 'Trabalhador', 'artigo' => 'art. 267.º', 'nome' => 'Prestação de serviço militar ou serviço cívico de substituição'],
        'MATERNIDADE' => ['lado' => 'Trabalhador', 'artigo' => 'art. 267.º', 'nome' => 'Licença de maternidade'],
        'DOENCA' => ['lado' => 'Trabalhador', 'artigo' => 'art. 267.º', 'nome' => 'Doença ou acidente'],
        'PRISAO_PREVENTIVA' => ['lado' => 'Trabalhador', 'artigo' => 'art. 267.º', 'nome' => 'Prisão preventiva'],
        'PENA_PRISAO' => ['lado' => 'Trabalhador', 'artigo' => 'art. 267.º', 'nome' => 'Pena de prisão até um ano'],
        'SINDICAL' => ['lado' => 'Trabalhador', 'artigo' => 'art. 267.º', 'nome' => 'Exercício de funções sindicais a tempo inteiro'],
        'CARGO_PUBLICO' => ['lado' => 'Trabalhador', 'artigo' => 'art. 267.º', 'nome' => 'Exercício de cargo público por eleição'],
        'FORCA_MAIOR_TRAB' => ['lado' => 'Trabalhador', 'artigo' => 'art. 267.º', 'nome' => 'Força maior temporária'],
        'CONJUNTURAL' => ['lado' => 'Empregador', 'artigo' => 'art. 270.º, al. a)', 'nome' => 'Razões conjunturais, económicas ou tecnológicas de duração temporária'],
        'CALAMIDADE' => ['lado' => 'Empregador', 'artigo' => 'art. 270.º, al. b)', 'nome' => 'Calamidade, acidentes ou força maior'],
        'ENCERRAMENTO' => ['lado' => 'Empregador', 'artigo' => 'art. 270.º, al. c)', 'nome' => 'Encerramento temporário para obras, equipamentos ou por determinação das autoridades'],
        'OUTRA_LEGAL' => ['lado' => 'Empregador', 'artigo' => 'art. 270.º, al. d)', 'nome' => 'Outra situação prevista na lei'],
    ];

    /** Art. 274.º, n.º 2 — caducidade, revogação ou resolução; `compensacao` = regra proposta (contrato_estado.js:55). */
    public const MOTIVOS_CESSACAO = [
        'CAD_MORTE' => ['modalidade' => 'Caducidade', 'artigo' => 'art. 277.º', 'nome' => 'Morte do trabalhador', 'compensacao' => 'ART307'],
        'CAD_INCAPACIDADE' => ['modalidade' => 'Caducidade', 'artigo' => 'art. 277.º', 'nome' => 'Incapacidade permanente superior a 12 meses', 'compensacao' => 'ART307'],
        'CAD_REFORMA' => ['modalidade' => 'Caducidade', 'artigo' => 'art. 277.º', 'nome' => 'Reforma do trabalhador', 'compensacao' => 'ART307'],
        'CAD_EMPREGADOR' => ['modalidade' => 'Caducidade', 'artigo' => 'art. 277.º', 'nome' => 'Morte ou encerramento do empregador', 'compensacao' => 'ART307'],
        'CAD_INSOLVENCIA' => ['modalidade' => 'Caducidade', 'artigo' => 'art. 277.º', 'nome' => 'Insolvência do empregador', 'compensacao' => 'ART307'],
        'CAD_FORCA_MAIOR' => ['modalidade' => 'Caducidade', 'artigo' => 'art. 277.º', 'nome' => 'Caso fortuito ou força maior', 'compensacao' => 'ART307'],
        'CAD_TERMO' => ['modalidade' => 'Caducidade', 'artigo' => 'art. 277.º', 'nome' => 'Termo do contrato a termo certo ou incerto', 'compensacao' => 'ART307'],
        'REVOGACAO' => ['modalidade' => 'Revogação', 'artigo' => 'art. 280.º', 'nome' => 'Revogação por mútuo acordo', 'compensacao' => '',
            'nota' => 'Tem de ser escrita e assinada pelas duas partes, sob pena de nulidade. A compensação é a que for acordada.'],
        'DESP_DISCIPLINAR' => ['modalidade' => 'Resolução', 'artigo' => 'arts. 281.º e 282.º', 'nome' => 'Despedimento disciplinar por justa causa', 'compensacao' => '',
            'nota' => 'Exige processo disciplinar. Sem direito a compensação.'],
        'DESP_OBJECTIVO' => ['modalidade' => 'Resolução', 'artigo' => 'arts. 284.º a 289.º', 'nome' => 'Despedimento individual por causas objectivas', 'compensacao' => 'ART308'],
        'DESP_COLECTIVO' => ['modalidade' => 'Resolução', 'artigo' => 'art. 290.º', 'nome' => 'Despedimento colectivo (mais de cinco trabalhadores)', 'compensacao' => 'ART308'],
        'RESC_JUSTA_CAUSA' => ['modalidade' => 'Resolução', 'artigo' => 'art. 303.º', 'nome' => 'Rescisão pelo trabalhador com justa causa (despedimento indirecto)', 'compensacao' => 'ART310'],
        'RESC_ESTRANHA' => ['modalidade' => 'Resolução', 'artigo' => 'art. 304.º', 'nome' => 'Rescisão pelo trabalhador por causa estranha ao empregador', 'compensacao' => '',
            'nota' => 'Produz efeitos imediatos, sem responsabilidade para nenhuma das partes.'],
        'DENUNCIA' => ['modalidade' => 'Resolução', 'artigo' => 'art. 305.º', 'nome' => 'Denúncia pelo trabalhador sem justa causa', 'compensacao' => '', 'aviso_previo' => 30,
            'nota' => 'Aviso prévio escrito de 30 dias. Em falta, o trabalhador compensa o empregador pelo salário correspondente.'],
        'ABANDONO' => ['modalidade' => 'Resolução', 'artigo' => 'art. 306.º', 'nome' => 'Abandono do trabalho', 'compensacao' => '', 'nota' => 'Vale como denúncia sem aviso prévio.'],
    ];

    /** Regras de compensação (REGRAS_COMPENSACAO, contrato_estado.js:97). */
    public const REGRAS_COMPENSACAO = [
        'ART307' => ['artigo' => 'art. 307.º', 'texto' => '50% do salário base × anos de serviço'],
        'ART308' => ['artigo' => 'art. 308.º', 'texto' => 'salário base × anos até 5, mais 50% do salário base por cada ano além de 5'],
        'ART310' => ['artigo' => 'art. 310.º', 'texto' => 'salário base × anos de antiguidade, com o mínimo de três meses de salário base'],
    ];

    public function __construct(private readonly ServicoAuditoria $auditoria) {}

    /** Catálogos para o ecrã. */
    public function fundamentos(): array
    {
        $lista = fn (array $m) => array_map(fn ($k, $v) => ['codigo' => $k] + $v, array_keys($m), $m);

        return ['suspensao' => $lista(self::MOTIVOS_SUSPENSAO), 'cessacao' => $lista(self::MOTIVOS_CESSACAO), 'regras_compensacao' => self::REGRAS_COMPENSACAO];
    }

    // ───────────── Antiguidade e compensação ─────────────

    /** Art. 311.º: as fracções de três meses ou mais contam como um ano (antiguidadeAnos, contrato_estado.js:78). */
    public static function antiguidadeAnos(?string $inicio, string $fim): int
    {
        if (! $inicio || $fim < $inicio) {
            return 0;
        }
        [$ai, $mi, $di] = array_map('intval', explode('-', substr($inicio, 0, 10)));
        [$af, $mf, $df] = array_map('intval', explode('-', substr($fim, 0, 10)));
        $anos = $af - $ai;
        $meses = $mf - $mi;
        if ($df < $di) {
            $meses--;
        }
        if ($meses < 0) {
            $anos--;
            $meses += 12;
        }

        return max(0, $anos) + ($meses >= 3 ? 1 : 0);
    }

    /** Valor de uma regra de compensação (bcmath, 2 casas). */
    public static function valorCompensacao(string $regra, string $sb, int $anos): string
    {
        $meio = bcmul('0.5', $sb, 4);

        return bcadd(match ($regra) {
            'ART307' => bcmul($meio, (string) $anos, 4),
            'ART308' => bcadd(bcmul($sb, (string) min($anos, 5), 4), bcmul($meio, (string) max($anos - 5, 0), 4), 4),
            'ART310' => self::maior(bcmul($sb, (string) $anos, 4), bcmul($sb, '3', 4)),
            default => '0',
        }, '0', 2);
    }

    /**
     * Proposta de compensação para o contrato, o fundamento e a data (compensacao, contrato_estado.js:108).
     *
     * @return array{valor: string, anos: int, salario_base: string, artigo: string, formula: string, aplicavel: bool, nota: string}
     */
    public function compensacao(ContratoTrabalho $c, string $motivo, string $data): array
    {
        $m = self::MOTIVOS_CESSACAO[$motivo] ?? throw new ErroNegocio('Fundamento de cessação desconhecido.', 'FUNDAMENTO_INVALIDO', 422);
        $sb = RegrasContrato::salarioBase($c);
        $anos = self::antiguidadeAnos($c->data_inicio?->toDateString(), $data);
        $regra = self::REGRAS_COMPENSACAO[$m['compensacao']] ?? null;

        return ['valor' => $regra ? self::valorCompensacao($m['compensacao'], $sb, $anos) : '0.00', 'anos' => $anos, 'salario_base' => $sb,
            'artigo' => $regra['artigo'] ?? '', 'formula' => $regra['texto'] ?? '', 'aplicavel' => $regra !== null, 'nota' => $m['nota'] ?? ''];
    }

    // ───────────── Actos ─────────────

    /** @param  array{de: string, ate?: ?string, motivo_codigo: string, observacoes?: ?string}  $d */
    public function suspender(ContratoTrabalho $c, array $d): ContratoTrabalho
    {
        return DB::transaction(function () use ($c, $d) {
            $c = $this->bloquear($c);
            if ($c->estado === 'CESSADO') {
                throw new ErroNegocio('Este contrato está cessado. Para o suspender, anule primeiro a cessação.', 'CONTRATO_CESSADO', 422);
            }
            if ($c->estado === 'INACTIVO') {
                throw new ErroNegocio('Este contrato está inactivo: não pode ser suspenso.', 'CONTRATO_INACTIVO', 422);
            }
            $de = substr((string) $d['de'], 0, 10);
            $ate = ! empty($d['ate']) ? substr((string) $d['ate'], 0, 10) : null;
            if ($ate !== null && $ate < $de) {
                throw new ErroNegocio('A data de fim da suspensão é anterior à de início.', 'DATAS_INVALIDAS', 422);
            }
            if ($c->data_inicio && ($ate ?? $de) < $c->data_inicio->toDateString()) {
                throw new ErroNegocio('A suspensão termina antes do início do contrato.', 'DATAS_INVALIDAS', 422);
            }
            $m = self::MOTIVOS_SUSPENSAO[$d['motivo_codigo']] ?? throw new ErroNegocio('Escolha o fundamento da suspensão.', 'FUNDAMENTO_INVALIDO', 422);
            $registo = ['de' => $de, 'ate' => $ate, 'motivo_codigo' => $d['motivo_codigo'], 'motivo' => $m['nome'], 'artigo' => $m['artigo'], 'lado' => $m['lado'],
                'observacoes' => trim((string) ($d['observacoes'] ?? '')), 'registado_em' => now()->toIso8601String(), 'registado_por' => Auth::user()?->nome_utilizador];
            $historico = (array) ($c->suspensoes ?? []);
            if ($c->estado === 'SUSPENSO' && $historico) {   // nova suspensão sobre outra: a anterior fica fechada no histórico
                $ultimo = array_key_last($historico);
                $historico[$ultimo] += ['substituida_em' => now()->toIso8601String(), 'substituida_por' => Auth::user()?->nome_utilizador];
            }
            $historico[] = $registo;
            $c->forceFill(['estado' => 'SUSPENSO', 'suspensao' => $registo, 'suspensoes' => $historico])->save();
            $this->auditar('Suspender contrato', $c, "{$registo['motivo']} ({$de}".($ate ? " a {$ate}" : '').')');

            return $c->refresh();
        });
    }

    public function reactivar(ContratoTrabalho $c, ?string $observacoes): ContratoTrabalho
    {
        return DB::transaction(function () use ($c, $observacoes) {
            $c = $this->bloquear($c);
            if ($c->estado !== 'SUSPENSO') {
                throw new ErroNegocio('Este contrato não está suspenso.', 'CONTRATO_NAO_SUSPENSO', 422);
            }
            $historico = (array) ($c->suspensoes ?? []);
            if ($historico) {
                $k = array_key_last($historico);
                $historico[$k] = array_merge($historico[$k], ['terminada_em' => now()->toIso8601String(), 'terminada_por' => Auth::user()?->nome_utilizador,
                    'terminada_observacoes' => trim((string) $observacoes), 'ate' => ($historico[$k]['ate'] ?? null) ?: now()->toDateString()]);
            }
            $c->forceFill(['estado' => 'ACTIVO', 'suspensao' => null, 'suspensoes' => $historico])->save();
            $this->auditar('Reactivar contrato', $c, trim((string) $observacoes));

            return $c->refresh();
        });
    }

    /**
     * @param  array{data: string, motivo_codigo: string, aviso_previo_dias?: ?int, compensacao_valor?: mixed, observacoes?: ?string, inactivar_colaborador?: bool}  $d
     */
    public function cessar(ContratoTrabalho $c, array $d): ContratoTrabalho
    {
        return DB::transaction(function () use ($c, $d) {
            $c = $this->bloquear($c);
            if ($c->estado === 'CESSADO') {
                throw new ErroNegocio('Este contrato já está cessado.', 'CONTRATO_CESSADO', 422);
            }
            $data = substr((string) $d['data'], 0, 10);
            if ($c->data_inicio && $data < $c->data_inicio->toDateString()) {
                throw new ErroNegocio('A data da cessação é anterior ao início do contrato.', 'DATAS_INVALIDAS', 422);
            }
            $m = self::MOTIVOS_CESSACAO[$d['motivo_codigo']] ?? throw new ErroNegocio('Escolha o fundamento da cessação.', 'FUNDAMENTO_INVALIDO', 422);
            $comp = $this->compensacao($c, $d['motivo_codigo'], $data);
            $decidida = isset($d['compensacao_valor']) && $d['compensacao_valor'] !== '' && $d['compensacao_valor'] !== null
                ? number_format((float) $d['compensacao_valor'], 2, '.', '') : $comp['valor'];
            $registo = ['data' => $data, 'modalidade' => $m['modalidade'], 'motivo_codigo' => $d['motivo_codigo'], 'motivo' => $m['nome'], 'artigo' => $m['artigo'],
                'aviso_previo_dias' => isset($d['aviso_previo_dias']) && $d['aviso_previo_dias'] !== null ? (int) $d['aviso_previo_dias'] : ($m['aviso_previo'] ?? null),
                'observacoes' => trim((string) ($d['observacoes'] ?? '')), 'antiguidade_anos' => $comp['anos'], 'salario_base' => $comp['salario_base'],
                'compensacao_valor' => $decidida, 'compensacao_proposta' => $comp['valor'], 'compensacao_artigo' => $comp['artigo'], 'compensacao_formula' => $comp['formula'],
                'data_fim_anterior' => $c->data_fim?->toDateString(), 'estado_anterior' => $c->estado, 'suspensao_anterior' => $c->suspensao,
                'registado_em' => now()->toIso8601String(), 'registado_por' => Auth::user()?->nome_utilizador];
            $posteriores = $this->processamentosPosteriores((int) $c->colaborador_id, $data);
            $registo['processamentos_posteriores'] = $posteriores;
            $c->forceFill(['estado' => 'CESSADO', 'data_fim' => $data, 'cessacao' => $registo, 'suspensao' => null])->save();
            if (($d['inactivar_colaborador'] ?? true) && $c->colaborador_id) {
                Colaborador::query()->whereKey($c->colaborador_id)->first()?->forceFill(['estado' => 'INACTIVO', 'data_cessacao' => $data])->save();
            }
            $this->auditar('Cessar contrato', $c, "{$registo['motivo']} em {$data}; compensação {$decidida} (proposta {$comp['valor']})");

            return $c->refresh();
        });
    }

    public function anularCessacao(ContratoTrabalho $c, ?string $observacoes): ContratoTrabalho
    {
        return DB::transaction(function () use ($c, $observacoes) {
            $c = $this->bloquear($c);
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["contratos:{$c->colaborador_id}"]);   // o mesmo cadeado de ServicoContratosTrabalho::guardar
            if ($c->estado !== 'CESSADO') {
                throw new ErroNegocio('Este contrato não está cessado.', 'CONTRATO_NAO_CESSADO', 422);
            }
            $anterior = (array) ($c->cessacao ?? []);
            $fim = $anterior['data_fim_anterior'] ?? null;
            $outro = ContratoTrabalho::query()->where('colaborador_id', $c->colaborador_id)->whereKeyNot($c->id)->where('estado', '<>', 'CESSADO')
                ->where(fn ($q) => $q->whereNull('data_fim')->orWhere('data_fim', '>=', $c->data_inicio?->toDateString() ?? '0001-01-01'))
                ->where(fn ($q) => $q->whereNull('data_inicio')->orWhere('data_inicio', '<=', $fim ?? ServicoContratosTrabalho::SEM_FIM))->first();
            if ($outro) {
                throw new ErroNegocio("Este colaborador já tem outro contrato por cessar nesse período (#{$outro->id}). Cesse ou elimine esse contrato antes de anular esta cessação.",
                    'OUTRO_CONTRATO_ACTIVO', 422, ['contrato_id' => $outro->id]);
            }
            $historico = (array) ($c->cessacoes_anuladas ?? []);
            $historico[] = $anterior + ['anulada_em' => now()->toIso8601String(), 'anulada_por' => Auth::user()?->nome_utilizador, 'anulada_observacoes' => trim((string) $observacoes)];
            $estado = ($anterior['estado_anterior'] ?? 'ACTIVO') === 'SUSPENSO' && ! empty($anterior['suspensao_anterior']) ? 'SUSPENSO' : 'ACTIVO';
            $c->forceFill(['estado' => $estado, 'data_fim' => $fim === ServicoContratosTrabalho::SEM_FIM ? null : $fim, 'cessacao' => null, 'cessacoes_anuladas' => $historico,
                'suspensao' => $estado === 'SUSPENSO' ? $anterior['suspensao_anterior'] : null])->save();
            $colab = $c->colaborador_id ? Colaborador::query()->whereKey($c->colaborador_id)->first() : null;
            if ($colab && $colab->data_cessacao?->toDateString() === ($anterior['data'] ?? null)) {   // só se a inactivação veio desta cessação
                $colab->forceFill(['estado' => 'ACTIVO', 'data_cessacao' => null])->save();
            }
            $this->auditar('Anular cessação de contrato', $c, trim((string) $observacoes));

            return $c->refresh();
        });
    }

    /**
     * Processamentos de meses posteriores à data com lançamentos do colaborador (processamentosPosteriores,
     * contrato_estado.js:176): não impedem a cessação, mas são mostrados a quem decide.
     *
     * @return list<array{periodo: string, lancamentos: int, estado: ?string}>
     */
    public function processamentosPosteriores(int $colaborador, string $data): array
    {
        $mes = substr($data, 0, 7);
        $saida = [];
        foreach (PeriodoProcessamentoSalarial::query()->get(['id', 'mes_ano', 'estado']) as $p) {
            if (! preg_match('/^(\d{2})\/(\d{4})$/', (string) $p->mes_ano, $m) || "{$m[2]}-{$m[1]}" <= $mes) {
                continue;
            }
            $n = LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->where('colaborador_id', $colaborador)->count();
            if ($n > 0) {
                $saida[] = ['periodo' => $p->mes_ano, 'lancamentos' => $n, 'estado' => $p->estado];
            }
        }
        usort($saida, fn ($a, $b) => strcmp(substr($a['periodo'], 3).substr($a['periodo'], 0, 2), substr($b['periodo'], 3).substr($b['periodo'], 0, 2)));

        return $saida;
    }

    /** Estado e histórico do contrato (botão «Ver estado»). */
    public function estado(ContratoTrabalho $c): array
    {
        return ['contrato_id' => $c->id, 'estado' => $c->estado, 'suspensao' => $c->suspensao, 'suspensoes' => $c->suspensoes ?? [], 'cessacao' => $c->cessacao,
            'cessacoes_anuladas' => $c->cessacoes_anuladas ?? [], 'suspenso_hoje' => RegrasContrato::suspensoEm($c, now()->toDateString()),
            'salario_base' => RegrasContrato::salarioBase($c), 'antiguidade_anos' => self::antiguidadeAnos($c->data_inicio?->toDateString(), now()->toDateString())];
    }

    private function bloquear(ContratoTrabalho $c): ContratoTrabalho
    {
        return ContratoTrabalho::query()->lockForUpdate()->findOrFail($c->id);
    }

    private function auditar(string $accao, ContratoTrabalho $c, string $detalhe): void
    {
        $this->auditoria->registar('RH', $accao, "Contrato #{$c->id}: {$detalhe}", 'contratos_trabalho', $c->id);
    }

    private static function maior(string $a, string $b): string
    {
        return bccomp($a, $b, 4) >= 0 ? $a : $b;
    }
}
