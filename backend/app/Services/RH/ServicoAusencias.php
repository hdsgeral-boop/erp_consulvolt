<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\AusenciaFaltaColaborador;
use App\Models\Colaborador;
use App\Models\PlanoFeriasColaborador;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ausências e faltas (js/modules/rh/ausencias.js), catálogo da Lei Geral do Trabalho (Lei 12/23, art.os 221.º-230.º).
 * Paridade: tipos, unidades, limites (seguidos = erro; mês/ano = aviso), prova obrigatória (salvo «Outra»),
 * pré-aviso de 7 dias e suspensão acima de 30 dias (avisos), sobreposição recusada, justificar faltas detectadas
 * só com o processamento do mês aberto, cancelar uma justificação devolve a falta a POR_JUSTIFICAR.
 * Correcções (ADR-038):
 *   - o RH passa a poder registar e justificar ausências (no legado só o colaborador, pelo portal: quem não tinha
 *     utilizador ficava com as faltas por justificar);
 *   - a sobreposição verifica também as férias (a mensagem do legado dizia-o, o código não);
 *   - nos tipos «remunerada a critério do empregador» a aprovação exige a decisão (SIM/NAO) — o legado tratava-as
 *     como pagas sem decisão registada;
 *   - ninguém decide a sua própria ausência.
 */
final class ServicoAusencias
{
    /** unidade: DIAS_CALENDARIO | DIAS_UTEIS | HORAS; remunerada: SIM | NAO | EMPREGADOR */
    public const CATALOGO = [
        'CASAMENTO' => ['nome' => 'Casamento do trabalhador', 'artigo' => '222.º/1 a)', 'unidade' => 'DIAS_CALENDARIO', 'max_seguidos' => 8, 'remunerada' => 'SIM'],
        'CASAMENTO_FAMILIAR' => ['nome' => 'Casamento de parente da linha recta ou irmão', 'artigo' => '222.º/1 k)', 'unidade' => 'DIAS_CALENDARIO', 'max_seguidos' => 1, 'remunerada' => 'NAO'],
        'FALECIMENTO_PROXIMO' => ['nome' => 'Falecimento de cônjuge, companheiro, pais, filhos, irmãos ou membro do agregado', 'artigo' => '223.º/1 a)', 'unidade' => 'DIAS_UTEIS', 'max_seguidos' => 8, 'remunerada' => 'SIM'],
        'FALECIMENTO_OUTROS' => ['nome' => 'Falecimento de avós, netos, tios, primos, sobrinhos, sogros, genros ou noras', 'artigo' => '223.º/1 b)', 'unidade' => 'DIAS_UTEIS', 'max_seguidos' => 3, 'remunerada' => 'SIM'],
        'FALECIMENTO_OUTRA_PESSOA' => ['nome' => 'Funeral de outra pessoa (presença indispensável)', 'artigo' => '223.º/3 e 4', 'unidade' => 'DIAS_UTEIS', 'remunerada' => 'EMPREGADOR'],
        'OBRIGACOES_LEGAIS' => ['nome' => 'Cumprimento de obrigações legais ou militares', 'artigo' => '224.º', 'unidade' => 'DIAS_UTEIS', 'max_mes' => 2, 'max_ano' => 8, 'remunerada' => 'SIM'],
        'PROVAS_ESCOLARES' => ['nome' => 'Provas escolares (frequência e exame)', 'artigo' => '225.º', 'unidade' => 'DIAS_UTEIS', 'remunerada' => 'SIM'],
        'FORMACAO' => ['nome' => 'Formação, aperfeiçoamento ou reconversão autorizada', 'artigo' => '222.º/1 e)', 'unidade' => 'DIAS_UTEIS', 'remunerada' => 'SIM'],
        'DOENCA' => ['nome' => 'Doença ou acidente do trabalhador', 'artigo' => '222.º/1 f) e 226.º/1', 'unidade' => 'DIAS_CALENDARIO', 'remunerada' => 'SIM'],
        'ASSISTENCIA_FAMILIA' => ['nome' => 'Assistência inadiável a cônjuge, pais ou filhos até aos 18 anos', 'artigo' => '226.º/3 e 4', 'unidade' => 'DIAS_UTEIS', 'max_ano' => 8, 'remunerada' => 'SIM'],
        'CULTURAL_DESPORTIVA' => ['nome' => 'Actividade cultural ou desportiva oficial', 'artigo' => '222.º/1 g) e 227.º', 'unidade' => 'DIAS_UTEIS', 'max_ano' => 8, 'remunerada' => 'SIM'],
        'SINDICAL_DIRIGENTE' => ['nome' => 'Actividade sindical — membro de órgão executivo do sindicato', 'artigo' => '228.º/1 a)', 'unidade' => 'DIAS_UTEIS', 'max_mes' => 4, 'remunerada' => 'SIM'],
        'SINDICAL_DELEGADO' => ['nome' => 'Actividade sindical — delegado ou órgão representativo (horas)', 'artigo' => '228.º/1 b)', 'unidade' => 'HORAS', 'max_mes_horas' => 5, 'remunerada' => 'SIM'],
        'PRE_POS_NATAL' => ['nome' => 'Consulta pré-natal ou pós-natal (até 12 meses após o parto)', 'artigo' => '229.º', 'unidade' => 'DIAS_UTEIS', 'max_mes' => 1, 'remunerada' => 'SIM'],
        'ELEICOES' => ['nome' => 'Candidatura a eleições gerais ou autárquicas', 'artigo' => '222.º/1 j)', 'unidade' => 'DIAS_UTEIS', 'remunerada' => 'SIM'],
        'OUTRA' => ['nome' => 'Outro motivo (a autorizar pelo empregador)', 'artigo' => '222.º/3 e 4', 'unidade' => 'DIAS_CALENDARIO', 'remunerada' => 'EMPREGADOR', 'prova_opcional' => true],
    ];

    private const PENDENTES = ['PENDENTE_CHEFIA', 'PENDENTE_RH'];

    public function __construct(
        private readonly ServicoCalendarioRH $calendario,
        private readonly ServicoAssiduidade $assiduidade,
    ) {}

    /**
     * Validação segundo a Lei 12/23.
     *
     * @param  array<string, mixed>  $d  colaborador_id, tipo, data_inicio, data_fim, horas?, motivo, documento_url?
     * @return array{avisos: list<string>, dias: int, dias_uteis: int, horas: ?float, remunerada: string}
     */
    public function validar(array $d, ?AusenciaFaltaColaborador $ignorar = null): array
    {
        $t = self::CATALOGO[$d['tipo']] ?? throw new ErroNegocio('Tipo de ausência desconhecido.', 'TIPO_INVALIDO', 422);
        [$ini, $fim] = [substr($d['data_inicio'], 0, 10), substr($d['data_fim'], 0, 10)];
        if ($fim < $ini) {
            throw new ErroNegocio('A data de fim não pode ser anterior à de início.', 'DATAS_INVALIDAS', 422);
        }
        if (trim((string) ($d['motivo'] ?? '')) === '') {
            throw new ErroNegocio('Indique o motivo da ausência.', 'MOTIVO_EM_FALTA', 422);
        }
        $url = trim((string) ($d['documento_url'] ?? ''));
        if ($url === '' && empty($t['prova_opcional'])) {
            throw new ErroNegocio('Este tipo de ausência exige prova ('.($t['artigo']).'): indique a ligação do documento.', 'PROVA_EM_FALTA', 422);
        }
        if ($url !== '' && (! preg_match('#^https?://#i', $url) || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS))) {
            throw new ErroNegocio('A ligação do documento tem de ser http(s), sem credenciais.', 'URL_INVALIDA', 422);
        }
        $cfg = $this->calendario->config();
        $dias = count(ServicoCalendarioRH::dias($ini, $fim));
        $uteis = $this->calendario->diasUteis($ini, $fim, $cfg);
        $horas = $t['unidade'] === 'HORAS' ? (float) ($d['horas'] ?? 0) : null;
        $unidades = match ($t['unidade']) {
            'HORAS' => $horas, 'DIAS_UTEIS' => $uteis, default => $dias
        };
        if ($unidades <= 0) {
            throw new ErroNegocio($t['unidade'] === 'HORAS' ? 'Indique as horas de ausência.' : 'O período não tem dias a justificar.', 'SEM_UNIDADES', 422);
        }
        if (isset($t['max_seguidos']) && $unidades > $t['max_seguidos']) {
            throw new ErroNegocio("{$t['nome']}: no máximo {$t['max_seguidos']} ".($t['unidade'] === 'DIAS_UTEIS' ? 'dias úteis' : 'dias')." seguidos (art.º {$t['artigo']}).",
                'LIMITE_EXCEDIDO', 422);
        }
        $colab = (int) $d['colaborador_id'];
        $sobreposta = AusenciaFaltaColaborador::query()->where('colaborador_id', $colab)->whereNotIn('estado', ['CANCELADO', 'RECUSADO', 'POR_JUSTIFICAR'])
            ->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar->id))->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $ini)->exists();
        $ferias = PlanoFeriasColaborador::query()->where('colaborador_id', $colab)->where('estado', '<>', 'CANCELADO')
            ->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $ini)->exists();
        if ($sobreposta || $ferias) {
            throw new ErroNegocio('O período sobrepõe-se a '.($sobreposta ? 'outra ausência' : 'férias').' do colaborador.', 'SOBREPOSICAO', 422);
        }
        $avisos = [];
        foreach (['max_mes' => [substr($ini, 0, 7).'-01', date('Y-m-t', strtotime($ini)), 'no mês'], 'max_ano' => [substr($ini, 0, 4).'-01-01', substr($ini, 0, 4).'-12-31', 'no ano']] as $lim => [$de, $ate, $txt]) {
            if (! isset($t[$lim])) {
                continue;
            }
            $usado = (int) AusenciaFaltaColaborador::query()->where('colaborador_id', $colab)->where('tipo', $d['tipo'])->whereIn('estado', ['APROVADO', ...self::PENDENTES])
                ->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar->id))->whereBetween('data_inicio', [$de, $ate])->sum($t['unidade'] === 'DIAS_UTEIS' ? 'dias_uteis' : 'dias');
            if ($usado + $unidades > $t[$lim]) {
                $avisos[] = "Excede o limite de {$t[$lim]} dias {$txt} (art.º {$t['artigo']}): o excesso pode não ser remunerado.";
            }
        }
        if (isset($t['max_mes_horas'])) {
            $usado = (float) AusenciaFaltaColaborador::query()->where('colaborador_id', $colab)->where('tipo', $d['tipo'])->whereIn('estado', ['APROVADO', ...self::PENDENTES])
                ->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar->id))->whereBetween('data_inicio', [substr($ini, 0, 7).'-01', date('Y-m-t', strtotime($ini))])->sum('horas');
            if ($usado + $horas > $t['max_mes_horas']) {
                $avisos[] = "Excede o limite de {$t['max_mes_horas']} horas no mês (art.º {$t['artigo']}).";
            }
        }
        $hoje = now()->toDateString();
        if ($ini < $hoje) {
            $avisos[] = 'Ausência já ocorrida: comunicação posterior (art.º 221.º/2).';
        } elseif ((strtotime($ini) - strtotime($hoje)) / 86400 < 7) {
            $avisos[] = 'Comunicada com menos de 7 dias de antecedência (art.º 221.º/1).';
        }
        if ($dias > 30) {
            $avisos[] = 'Mais de 30 dias de calendário: o contrato fica suspenso (art.º 222.º/5).';
        }
        if ($t['remunerada'] === 'NAO') {
            $avisos[] = 'Ausência justificada mas não remunerada: as horas são descontadas.';
        } elseif ($t['remunerada'] === 'EMPREGADOR') {
            $avisos[] = 'A remuneração fica a critério do empregador (decidida na aprovação).';
        }

        return ['avisos' => $avisos, 'dias' => $dias, 'dias_uteis' => $uteis, 'horas' => $horas, 'remunerada' => $t['remunerada']];
    }

    /** Registo pelo RH (fica a aguardar a decisão do RH). */
    public function criar(array $d, string $estado = 'PENDENTE_RH'): AusenciaFaltaColaborador
    {
        Colaborador::query()->findOrFail($d['colaborador_id']);
        $v = $this->validar($d);

        return AusenciaFaltaColaborador::create(['colaborador_id' => $d['colaborador_id'], 'tipo' => $d['tipo'], 'data_inicio' => $d['data_inicio'], 'data_fim' => $d['data_fim'],
            'dias' => $v['dias'], 'dias_uteis' => $v['dias_uteis'], 'horas' => $v['horas'], 'horas_falta' => $v['horas'], 'motivo' => $d['motivo'],
            'documento_url' => $d['documento_url'] ?? null, 'remunerada' => $v['remunerada'], 'avisos' => $v['avisos'], 'estado' => $estado,
            'detectada' => false, 'mes' => substr($d['data_inicio'], 0, 7), 'criado_por' => Auth::user()?->nome_utilizador]);
    }

    /** Justificar uma falta detectada: fica a aguardar decisão; só com o processamento do mês aberto. */
    public function justificar(AusenciaFaltaColaborador $a, array $d, string $estado = 'PENDENTE_RH'): AusenciaFaltaColaborador
    {
        if (! $a->detectada || $a->estado !== 'POR_JUSTIFICAR') {
            throw new ErroNegocio('Só se justificam faltas detectadas que estejam por justificar.', 'ESTADO_INVALIDO', 422);
        }
        if (! $this->assiduidade->periodoSalarialAberto((string) $a->mes)) {
            throw new ErroNegocio('O processamento salarial do mês já está encerrado: a falta já não pode ser justificada.', 'PERIODO_SALARIAL_ENCERRADO', 422);
        }
        $v = $this->validar(['colaborador_id' => $a->colaborador_id, 'data_inicio' => $a->data_inicio->toDateString(), 'data_fim' => $a->data_fim->toDateString(),
            'horas' => $a->horas_falta] + $d, $a);
        $a->update(['tipo' => $d['tipo'], 'motivo' => $d['motivo'], 'documento_url' => $d['documento_url'] ?? null, 'remunerada' => $v['remunerada'], 'avisos' => $v['avisos'],
            'estado' => $estado, 'justificada_em' => now(), 'justificada_por' => Auth::user()?->nome_utilizador]);

        return $a->refresh();
    }

    /** Decisão do RH: APROVADO ou RECUSADO; nos tipos «a critério do empregador» a aprovação diz se é remunerada. */
    public function decidir(AusenciaFaltaColaborador $a, string $decisao, ?string $remunerada, ?string $nota): AusenciaFaltaColaborador
    {
        return DB::transaction(function () use ($a, $decisao, $remunerada, $nota) {
            $a = AusenciaFaltaColaborador::query()->lockForUpdate()->findOrFail($a->id);
            if (! in_array($a->estado, self::PENDENTES, true)) {
                throw new ErroNegocio("A ausência está {$a->estado}: não há decisão pendente.", 'ESTADO_INVALIDO', 422);
            }
            $proprio = DB::table('utilizador_empresa')->where('utilizador_id', Auth::id())->where('empresa_id', $a->empresa_id)->value('colaborador_id');
            if ($proprio && (int) $proprio === (int) $a->colaborador_id) {
                throw new ErroNegocio('Não pode decidir a sua própria ausência.', 'AUTO_APROVACAO', 403);
            }
            $dados = ['estado' => $decisao, 'decidido_em' => now(), 'decidido_por' => Auth::user()?->nome_utilizador, 'nota_decisao' => $nota];
            if ($decisao === 'APROVADO' && $a->remunerada === 'EMPREGADOR') {
                if (! in_array($remunerada, ['SIM', 'NAO'], true)) {
                    throw new ErroNegocio('Indique se esta ausência é remunerada (decisão do empregador).', 'DECISAO_REMUNERACAO', 422);
                }
                $dados['remunerada'] = $remunerada;
            }
            $a->update($dados);

            return $a->refresh();
        });
    }

    /**
     * Reflexo da decisão de um pedido do portal: passagem da chefia para o RH (sem data de decisão — ainda não é
     * final; o legado preenchia-a), aprovação final (com a decisão de remuneração nos tipos a critério do empregador)
     * ou recusa.
     */
    public function aplicarDecisaoPortal(AusenciaFaltaColaborador $a, string $estado, ?string $remunerada = null, ?string $nota = null): void
    {
        if ($estado === 'PENDENTE_RH') {
            $a->update(['estado' => 'PENDENTE_RH']);

            return;
        }
        $dados = ['estado' => $estado, 'decidido_em' => now(), 'decidido_por' => Auth::user()?->nome_utilizador, 'nota_decisao' => $nota];
        if ($estado === 'APROVADO' && $a->remunerada === 'EMPREGADOR') {
            if (! in_array($remunerada, ['SIM', 'NAO'], true)) {
                throw new ErroNegocio('Indique se esta ausência é remunerada (decisão do empregador).', 'DECISAO_REMUNERACAO', 422);
            }
            $dados['remunerada'] = $remunerada;
        }
        $a->update($dados);
    }

    /** Cancelar um pedido pendente; numa falta detectada a justificação é retirada (volta a POR_JUSTIFICAR). */
    public function cancelar(AusenciaFaltaColaborador $a): AusenciaFaltaColaborador
    {
        if (! in_array($a->estado, self::PENDENTES, true)) {
            throw new ErroNegocio('Só se cancelam ausências a aguardar decisão.', 'ESTADO_INVALIDO', 422);
        }
        $a->update($a->detectada
            ? ['estado' => 'POR_JUSTIFICAR', 'tipo' => null, 'motivo' => null, 'documento_url' => null, 'remunerada' => null, 'avisos' => null, 'justificada_em' => null, 'justificada_por' => null]
            : ['estado' => 'CANCELADO']);

        return $a->refresh();
    }
}
