<?php

namespace App\Services\Integracoes\Cambios;

use App\Exceptions\ErroNegocio;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoCambiosBAI;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Câmbios do BAI automáticos (PROMPT_PROXIMOS_PASSOS §2, aceite pelo utilizador).
 *
 * A rotina manual (Configurações › Moedas e câmbios › «Câmbios do BAI», ServicoCambiosBAI) mantém-se. Acrescenta-se:
 *  - uma obtenção diária agendada (routes/console.php, de minuto a minuto verifica se está «devida») a partir de uma hora
 *    fixa configurável (configuracoes_sistema: cambios_bai_auto_ativo / cambios_bai_auto_hora), activável no ecrã por quem
 *    gere moedas (config_moedas_gerir); desligada por omissão;
 *  - o resultado fica SÓ em pré-visualização pendente (cambios_bai_pendentes): nada é gravado em taxas_cambio sem alguém
 *    validar. Uma nova obtenção substitui as pendentes anteriores (estado SUBSTITUIDO);
 *  - validar (escolhendo as moedas) grava os valores guardados na obtenção (lidos pelo servidor, nunca vindos do cliente),
 *    na data da cotação, pelas mesmas regras da gravação manual; rejeitar descarta-as. Ambas com auditoria de quem decidiu;
 *  - cada obtenção fica em execucoes_cambios_bai; a falha do BAI é registada, mostrada no ecrã e no /api/saude (informativa).
 *
 * Robustez do agendamento: se o scheduler estiver parado à hora marcada, a obtenção corre na primeira verificação seguinte do
 * mesmo dia; uma falha é repetida até 3 tentativas por dia, com 30 minutos de intervalo.
 */
final class ServicoCambiosBAIAutomaticos
{
    public const CHAVE_ATIVO = 'cambios_bai_auto_ativo';

    public const CHAVE_HORA = 'cambios_bai_auto_hora';

    public const HORA_PADRAO = '08:30';

    public const MAX_TENTATIVAS_DIA = 3;

    public const INTERVALO_TENTATIVAS_MIN = 30;

    public const PENDENTE = 'PENDENTE';

    public const VALIDADO = 'VALIDADO';

    public const REJEITADO = 'REJEITADO';

    public const SUBSTITUIDO = 'SUBSTITUIDO';

    public function __construct(
        private readonly ServicoCambiosBAI $bai,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @return array{ativo: bool, hora: string} */
    public function configuracao(): array
    {
        $v = DB::table('configuracoes_sistema')->whereIn('chave', [self::CHAVE_ATIVO, self::CHAVE_HORA])->pluck('valor', 'chave');
        $hora = (string) ($v[self::CHAVE_HORA] ?? '');

        return ['ativo' => ($v[self::CHAVE_ATIVO] ?? '0') === '1', 'hora' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) ? $hora : self::HORA_PADRAO];
    }

    /** @return array{ativo: bool, hora: string} */
    public function gravarConfiguracao(bool $ativo, string $hora): array
    {
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            throw new ErroNegocio('Hora inválida: use o formato HH:MM (ex.: 08:30).', 'BAI_HORA_INVALIDA', 422);
        }
        $antes = $this->configuracao();
        foreach ([self::CHAVE_ATIVO => $ativo ? '1' : '0', self::CHAVE_HORA => $hora] as $chave => $valor) {
            DB::table('configuracoes_sistema')->updateOrInsert(['chave' => $chave], ['valor' => $valor, 'atualizado_em' => now()]);
        }
        $depois = $this->configuracao();
        $this->auditoria->registar('Sistema/Moedas', 'Câmbios do BAI automáticos', $ativo
            ? "Obtenção automática diária dos câmbios do BAI activada às {$hora} (fica pendente de validação)."
            : 'Obtenção automática diária dos câmbios do BAI desactivada.', 'configuracoes_sistema', null, $antes, $depois);

        return $depois;
    }

    /**
     * Chamado pelo scheduler a cada minuto: obtém os câmbios se a rotina estiver activa, já tiver passado a hora configurada
     * e ainda não houver obtenção agendada com sucesso hoje (com o limite de tentativas). Nunca lança excepções.
     *
     * @return array<string, mixed>|null a execução feita, ou null se não era devida
     */
    public function executarSeDevido(?CarbonInterface $agora = null): ?array
    {
        $agora ??= now();
        $cfg = $this->configuracao();
        if (! $cfg['ativo'] || $agora->format('H:i') < $cfg['hora']) {
            return null;
        }
        $hoje = DB::table('execucoes_cambios_bai')->where('origem', 'AGENDADA')
            ->where('iniciado_em', '>=', $agora->copy()->startOfDay())->where('iniciado_em', '<=', $agora->copy()->endOfDay())
            ->orderByDesc('iniciado_em')->get(['estado', 'iniciado_em']);
        if ($hoje->contains('estado', 'SUCESSO') || $hoje->count() >= self::MAX_TENTATIVAS_DIA) {
            return null;
        }
        $ultima = $hoje->first();
        if ($ultima && $agora->diffInMinutes($ultima->iniciado_em, true) < self::INTERVALO_TENTATIVAS_MIN) {
            return null;
        }
        try {
            return $this->obter('AGENDADA');
        } catch (Throwable) {
            return $this->apresentarExecucao(DB::table('execucoes_cambios_bai')->orderByDesc('id')->first());
        }
    }

    /**
     * Lê a página do BAI e guarda as cotações como pendentes de validação (substituindo as pendentes anteriores).
     * Regista a execução; em caso de falha regista-a e volta a lançar o erro (o scheduler absorve-o em executarSeDevido).
     *
     * @return array<string, mixed> a execução
     */
    public function obter(string $origem = 'MANUAL'): array
    {
        $utilizador = Auth::user();
        $execId = DB::table('execucoes_cambios_bai')->insertGetId(['origem' => $origem, 'utilizador_id' => $utilizador?->getKey(), 'iniciado_em' => now(),
            'criado_em' => now(), 'atualizado_em' => now()]);
        try {
            $data = now()->toDateString();
            $previsao = $this->bai->compor($this->bai->obter(), $data);
            $disponiveis = array_values(array_filter($previsao['itens'], fn ($i) => $i['disponivel']));
            if (! $disponiveis) {
                throw new ErroNegocio('A página do BAI não tem cotação para nenhuma das moedas activas.', 'BAI_SEM_MOEDAS', 502);
            }
            DB::transaction(function () use ($disponiveis, $data, $execId) {
                DB::table('cambios_bai_pendentes')->where('estado', self::PENDENTE)
                    ->update(['estado' => self::SUBSTITUIDO, 'decidido_em' => now(), 'motivo' => 'Substituído por uma obtenção mais recente.', 'atualizado_em' => now()]);
                foreach ($disponiveis as $i) {
                    DB::table('cambios_bai_pendentes')->insert([
                        'execucao_cambio_bai_id' => $execId, 'data_cotacao' => $data, 'codigo_moeda' => $i['codigo_moeda'], 'nome_moeda' => $i['nome'],
                        'taxa_compra' => self::decimal($i['compra']), 'taxa_venda' => self::decimal($i['venda']), 'taxa_media' => self::decimal($i['media']),
                        'ultima_taxa' => isset($i['ultimo']) ? self::decimal($i['ultimo']['taxa']) : null, 'ultima_data' => $i['ultimo']['data_taxa'] ?? null,
                        'ultima_fonte' => $i['ultimo']['fonte_dados'] ?? null, 'variacao' => $i['variacao'], 'alerta' => $i['alerta'], 'estado' => self::PENDENTE,
                        'criado_em' => now(), 'atualizado_em' => now(),
                    ]);
                }
                DB::table('execucoes_cambios_bai')->where('id', $execId)->update(['estado' => 'SUCESSO', 'moedas' => count($disponiveis), 'concluido_em' => now(),
                    'mensagem' => count($disponiveis).' câmbio(s) obtido(s) e por validar.', 'atualizado_em' => now()]);
            });
            $alertas = array_column(array_filter($disponiveis, fn ($i) => $i['alerta']), 'codigo_moeda');
            $this->auditoria->registar('Sistema/Moedas', 'Câmbios do BAI obtidos', ($origem === 'AGENDADA' ? 'Obtenção agendada' : 'Obtenção manual')
                .' dos câmbios do BAI de '.$data.': '.implode(', ', array_column($disponiveis, 'codigo_moeda')).' por validar'
                .($alertas ? ' (variação acima de '.ServicoCambiosBAI::LIMIAR_VARIACAO.' %: '.implode(', ', $alertas).')' : '').'.', 'execucoes_cambios_bai', $execId);
        } catch (Throwable $e) {
            $codigo = $e instanceof ErroNegocio ? $e->codigo : 'BAI_ERRO';
            $mensagem = $e instanceof ErroNegocio ? $e->getMessage() : 'Erro inesperado ao obter os câmbios do BAI.';
            DB::table('execucoes_cambios_bai')->where('id', $execId)->update(['estado' => 'FALHA', 'codigo_erro' => $codigo, 'mensagem' => $mensagem,
                'concluido_em' => now(), 'atualizado_em' => now()]);
            Log::warning('Câmbios do BAI: obtenção falhou', ['origem' => $origem, 'codigo' => $codigo, 'erro' => $e->getMessage()]);
            $this->auditoria->registar('Sistema/Moedas', 'Câmbios do BAI: falha', "Falha na obtenção dos câmbios do BAI ({$codigo}): {$mensagem}", 'execucoes_cambios_bai', $execId);
            throw $e instanceof ErroNegocio ? $e : new ErroNegocio($mensagem, $codigo, 502);
        }

        return $this->apresentarExecucao(DB::table('execucoes_cambios_bai')->find($execId));
    }

    /**
     * Estado para o ecrã: configuração, câmbios pendentes (com o último câmbio registado AGORA, que pode ter mudado desde a
     * obtenção) e a última execução/falha.
     *
     * @return array<string, mixed>
     */
    public function estado(): array
    {
        $ultima = DB::table('execucoes_cambios_bai')->orderByDesc('iniciado_em')->orderByDesc('id')->first();
        $ultimaFalha = DB::table('execucoes_cambios_bai')->where('estado', 'FALHA')->orderByDesc('iniciado_em')->orderByDesc('id')->first();

        return $this->configuracao() + [
            'limiar_variacao' => ServicoCambiosBAI::LIMIAR_VARIACAO,
            'pendentes' => $this->pendentes(),
            'ultima_execucao' => $this->apresentarExecucao($ultima),
            // a falha só se mostra enquanto não houver uma obtenção com sucesso posterior
            'ultima_falha' => $ultimaFalha && (! $ultima || $ultima->estado === 'FALHA') ? $this->apresentarExecucao($ultimaFalha) : null,
            'historico' => DB::table('execucoes_cambios_bai')->orderByDesc('iniciado_em')->orderByDesc('id')->limit(10)->get()->map(fn ($e) => $this->apresentarExecucao($e))->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function pendentes(): array
    {
        return DB::table('cambios_bai_pendentes')->where('estado', self::PENDENTE)->orderBy('codigo_moeda')->get()->map(function ($p) {
            $data = substr((string) $p->data_cotacao, 0, 10);
            $ultimo = $this->bai->ultimoRegistado($p->codigo_moeda, $data);
            $media = (float) $p->taxa_media;
            $variacao = $ultimo ? ServicoCambiosBAI::variacao($media, $ultimo['taxa']) : null;

            return ['id' => (int) $p->id, 'data_cotacao' => $data, 'codigo_moeda' => $p->codigo_moeda, 'nome' => $p->nome_moeda,
                'compra' => $p->taxa_compra !== null ? (float) $p->taxa_compra : null, 'venda' => $p->taxa_venda !== null ? (float) $p->taxa_venda : null,
                'media' => $media, 'ultimo' => $ultimo, 'variacao' => $variacao, 'alerta' => $variacao !== null && abs($variacao) > ServicoCambiosBAI::LIMIAR_VARIACAO,
                'obtido_em' => $p->criado_em];
        })->all();
    }

    /**
     * Valida as moedas indicadas: grava em taxas_cambio os valores guardados na obtenção (âmbito «todas as empresas», data da
     * cotação). As pendentes não escolhidas continuam pendentes. Uma moeda cujo câmbio do dia já está usado em documentos com
     * outra taxa não é gravada (fica REJEITADA com o motivo).
     *
     * @param  list<string>  $moedas
     * @return array{novos: int, substituidos: int, bloqueados: list<string>, gravados: list<array{codigo_moeda: string, taxa: float}>}
     */
    public function validar(array $moedas): array
    {
        $moedas = array_values(array_unique(array_map('strtoupper', $moedas)));
        /** @var Utilizador|null $u */
        $u = Auth::user();

        return DB::transaction(function () use ($moedas, $u) {
            $linhas = DB::table('cambios_bai_pendentes')->where('estado', self::PENDENTE)->whereIn('codigo_moeda', $moedas)->lockForUpdate()->get();
            $faltam = array_diff($moedas, $linhas->pluck('codigo_moeda')->all());
            if ($faltam) {
                throw new ErroNegocio('Não há câmbio do BAI pendente para: '.implode(', ', $faltam).'. Actualize o ecrã.', 'BAI_PENDENTE_INEXISTENTE', 422, ['moedas' => array_values($faltam)]);
            }
            $total = ['novos' => 0, 'substituidos' => 0, 'bloqueados' => [], 'gravados' => []];
            foreach ($linhas->groupBy(fn ($l) => substr((string) $l->data_cotacao, 0, 10)) as $data => $grupo) {
                $porMoeda = $grupo->mapWithKeys(fn ($l) => [$l->codigo_moeda => ['compra' => $l->taxa_compra, 'venda' => $l->taxa_venda, 'media' => $l->taxa_media]])->all();
                $r = $this->bai->gravarCotacoes($porMoeda, (string) $data, array_keys($porMoeda), 'Validação dos câmbios do BAI');
                $total['novos'] += $r['novos'];
                $total['substituidos'] += $r['substituidos'];
                array_push($total['bloqueados'], ...$r['bloqueados']);
                array_push($total['gravados'], ...$r['gravados']);
            }
            $decisao = ['decidido_por_id' => $u?->getKey(), 'decidido_por' => $u?->nome_utilizador, 'decidido_em' => now(), 'atualizado_em' => now()];
            foreach ($linhas as $l) {
                $bloqueado = in_array($l->codigo_moeda, $total['bloqueados'], true);
                DB::table('cambios_bai_pendentes')->where('id', $l->id)->update($decisao + [
                    'estado' => $bloqueado ? self::REJEITADO : self::VALIDADO,
                    'motivo' => $bloqueado ? 'Não gravado: o câmbio desse dia já está usado em documentos com outra taxa.' : 'Validado e gravado nos câmbios.',
                ]);
            }

            return $total;
        });
    }

    /**
     * Rejeita as pendentes das moedas indicadas (todas, se a lista vier vazia). Nada é gravado nos câmbios.
     *
     * @param  list<string>  $moedas
     */
    public function rejeitar(array $moedas, ?string $motivo): int
    {
        /** @var Utilizador|null $u */
        $u = Auth::user();
        $moedas = array_values(array_unique(array_map('strtoupper', $moedas)));
        $q = DB::table('cambios_bai_pendentes')->where('estado', self::PENDENTE)->when($moedas, fn ($q) => $q->whereIn('codigo_moeda', $moedas));
        $codigos = (clone $q)->orderBy('codigo_moeda')->pluck('codigo_moeda')->all();
        if (! $codigos) {
            throw new ErroNegocio('Não há câmbios do BAI pendentes para rejeitar.', 'BAI_PENDENTE_INEXISTENTE', 422);
        }
        $motivo = trim((string) $motivo) ?: 'Rejeitado pelo utilizador.';
        $n = $q->update(['estado' => self::REJEITADO, 'decidido_por_id' => $u?->getKey(), 'decidido_por' => $u?->nome_utilizador, 'decidido_em' => now(),
            'motivo' => mb_substr($motivo, 0, 1000), 'atualizado_em' => now()]);
        $this->auditoria->registar('Sistema/Moedas', 'Câmbios do BAI rejeitados', 'Câmbios do BAI rejeitados ('.implode(', ', $codigos)."): {$motivo}");

        return $n;
    }

    /**
     * Informação para o /api/saude (nunca torna o serviço indisponível): DESACTIVADO, PENDENTE (há câmbios por validar),
     * OK ou FALHA (a última obtenção falhou).
     *
     * @return array{estado: string, detalhe: string}
     */
    public function informacaoSaude(): array
    {
        $cfg = $this->configuracao();
        $ultima = DB::table('execucoes_cambios_bai')->orderByDesc('iniciado_em')->orderByDesc('id')->first();
        $pendentes = DB::table('cambios_bai_pendentes')->where('estado', self::PENDENTE)->count();
        $quando = $ultima ? substr((string) $ultima->iniciado_em, 0, 16) : null;
        if ($ultima && $ultima->estado === 'FALHA') {
            return ['estado' => 'FALHA', 'detalhe' => "Última obtenção ({$quando}) falhou: {$ultima->codigo_erro}."];
        }
        $base = $cfg['ativo'] ? "Obtenção diária às {$cfg['hora']}" : 'Obtenção automática desactivada';
        $base .= $quando ? "; última em {$quando}" : '';

        return ['estado' => ! $cfg['ativo'] ? 'DESACTIVADO' : ($pendentes ? 'PENDENTE' : 'OK'), 'detalhe' => $base.($pendentes ? "; {$pendentes} câmbio(s) por validar." : '.')];
    }

    /** @return array<string, mixed>|null */
    private function apresentarExecucao(?object $e): ?array
    {
        return $e ? ['id' => (int) $e->id, 'origem' => $e->origem, 'estado' => $e->estado ?? 'EM_CURSO', 'codigo_erro' => $e->codigo_erro, 'mensagem' => $e->mensagem,
            'moedas' => $e->moedas !== null ? (int) $e->moedas : null, 'iniciado_em' => $e->iniciado_em, 'concluido_em' => $e->concluido_em] : null;
    }

    private static function decimal(float|int|string|null $v): ?string
    {
        return $v === null ? null : number_format((float) $v, 6, '.', '');
    }
}
