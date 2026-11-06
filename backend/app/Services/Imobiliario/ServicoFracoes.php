<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\AndarEdificio;
use App\Models\Fracao;
use App\Models\HistoricoEstadoFracao;
use App\Models\Imovel;
use App\Models\PortaEdificio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Fracções (porte de gravarUnidade, eliminarUnidade, mudarEstadoUnidade, gerarFraccoesPorPiso, criarFraccaoUnica e
 * importarDoEdificio do legado). Correcções: o código vem do maior sufixo existente (o legado contava as irmãs e colidia após
 * eliminações), o limite de 500 conta os blocos, a importação das portas é idempotente por `porta_id` e cada mudança de estado
 * deixa trilho em `historico_estado_fracao` e obedece a CatalogoImobiliario::TRANSICOES. Esta onda grava fracções e
 * arrecadações; as vagas de estacionamento são do Parqueamento (colunas `park_*` já existem, ainda sem ecrã).
 */
final class ServicoFracoes
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** @return Collection<int, Fracao> */
    public function listar(Imovel $imovel): Collection
    {
        return Fracao::query()->where('imovel_id', $imovel->id)->orderBy('ordem')->orderByRaw('lower(codigo)')->get();
    }

    /** @return array<string, mixed> a ficha: dados, imóvel, porta do Activos e histórico de estados */
    public function ficha(Fracao $f): array
    {
        $imovel = Imovel::query()->find($f->imovel_id, ['id', 'codigo', 'nome', 'papel', 'matriz_predial']);
        $porta = $f->porta_id ? PortaEdificio::query()->find($f->porta_id, ['id', 'nome', 'numero']) : null;
        $historico = HistoricoEstadoFracao::query()->where('fracao_id', $f->id)->orderByDesc('em')->orderByDesc('id')->limit(200)->get();

        return $f->attributesToArray() + [
            'imovel' => $imovel?->only(['id', 'codigo', 'nome', 'papel', 'matriz_predial']),
            'porta' => $porta?->only(['id', 'nome', 'numero']),
            'historico' => $historico->map(fn ($h) => $h->only(['id', 'estado_de', 'estado_para', 'motivo', 'utilizador_id', 'em']))->all(),
        ];
    }

    /** @param  array<string, mixed>  $d */
    public function criar(Imovel $imovel, array $d): Fracao
    {
        return DB::transaction(function () use ($imovel, $d) {
            $reg = $this->normalizar($d, $imovel, null);
            $irmas = Fracao::query()->where('imovel_id', $imovel->id)->get(['id', 'codigo', 'designacao', 'ordem']);
            $reg['codigo'] ??= $this->proximoCodigo($imovel, $irmas);
            $reg['ordem'] = (int) ($d['ordem'] ?? 0) ?: ((int) $irmas->max('ordem') + 1);
            $this->conferirUnicos($reg, $irmas, null);

            return $this->gravarNova($imovel, $reg + ['estado' => $this->estadoInicial($d)]);
        });
    }

    /** @param  array<string, mixed>  $d  o estado não muda por aqui: usar mudarEstado (trilho e transições) */
    public function actualizar(Fracao $f, array $d): Fracao
    {
        return DB::transaction(function () use ($f, $d) {
            $imovel = Imovel::query()->findOrFail($f->imovel_id);
            $reg = $this->normalizar($d + $f->only(['designacao', 'natureza']), $imovel, $f);
            $irmas = Fracao::query()->where('imovel_id', $imovel->id)->get(['id', 'codigo', 'designacao', 'ordem']);
            $reg['codigo'] ??= $f->codigo;
            $reg['ordem'] = (int) ($d['ordem'] ?? 0) ?: $f->ordem;
            $this->conferirUnicos($reg, $irmas, $f);
            $f->update($reg + ['atualizado_por' => $this->utilizador()]);

            return $f->refresh();
        });
    }

    public function eliminar(Fracao $f): void
    {
        if (in_array($f->estado, CatalogoImobiliario::EXIGEM_MOTIVO_AO_SAIR, true)) {
            throw new ErroNegocio('A fracção está '.mb_strtolower(CatalogoImobiliario::ESTADOS_FRACAO[$f->estado]['rot']).'. Mude o estado antes de a apagar.', 'FRACCAO_OCUPADA');
        }
        if (Fracao::query()->where('park_fracao_id', $f->id)->exists()) {
            throw new ErroNegocio('Há vagas afectas a esta fracção. Liberte-as primeiro.', 'FRACCAO_COM_VAGAS');
        }
        try {
            DB::transaction(function () use ($f) {
                HistoricoEstadoFracao::query()->where('fracao_id', $f->id)->delete();
                $f->delete();
            });
        } catch (QueryException) {
            throw new ErroNegocio('A fracção tem registos ligados e não pode ser apagada. Ponha-a como indisponível.', 'FRACCAO_EM_USO');
        }
    }

    /**
     * Muda o estado com trilho. Recusa a mesma posição e as transições fora de TRANSICOES; sair de arrendada/vendida e ficar
     * indisponível exigem motivo.
     */
    public function mudarEstado(Fracao $f, string $estado, ?string $motivo): Fracao
    {
        if (! isset(CatalogoImobiliario::ESTADOS_FRACAO[$estado])) {
            throw new ErroNegocio('Estado desconhecido.', 'FRACCAO_ESTADO');
        }
        $motivo = Normaliza::texto($motivo);

        return DB::transaction(function () use ($f, $estado, $motivo) {
            $f = Fracao::query()->whereKey($f->id)->lockForUpdate()->firstOrFail();
            $de = $f->estado;
            if ($de === $estado) {
                throw new ErroNegocio('A fracção já está nesse estado.', 'FRACCAO_MESMO_ESTADO');
            }
            if (! in_array($estado, CatalogoImobiliario::TRANSICOES[$de] ?? [], true)) {
                throw new ErroNegocio('Uma fracção «'.mb_strtolower(CatalogoImobiliario::ESTADOS_FRACAO[$de]['rot']).'» só pode passar a disponível ou indisponível. Reverta primeiro, com motivo.', 'FRACCAO_TRANSICAO');
            }
            if ($motivo === null && (in_array($de, CatalogoImobiliario::EXIGEM_MOTIVO_AO_SAIR, true) || in_array($estado, CatalogoImobiliario::EXIGEM_MOTIVO_AO_CHEGAR, true))) {
                throw new ErroNegocio('Indique o motivo desta mudança de estado.', 'FRACCAO_MOTIVO');
            }
            $f->update(['estado' => $estado, 'estado_desde' => now()->toDateString(), 'estado_motivo' => $motivo, 'atualizado_por' => $this->utilizador()]);
            $this->registarHistorico($f, $de, $estado, $motivo);

            return $f->refresh();
        });
    }

    /**
     * Gera as fracções de um prédio por piso, bloco e posição. Ignora (e conta) as designações repetidas.
     *
     * @param  array<string, mixed>  $o  pisos, por_piso, piso_inicial, blocos, esquema, tipologia, finalidade, area_bruta, area_util, quartos, casas_banho, mobilada, renda_referencia
     * @return array{criadas: int, repetidas: int}
     */
    public function gerarPorPiso(Imovel $imovel, array $o): array
    {
        $pisos = max(1, Normaliza::inteiro($o['pisos'] ?? null) ?: $imovel->n_pisos);
        $porPiso = max(1, Normaliza::inteiro($o['por_piso'] ?? 1));
        $inicial = isset($o['piso_inicial']) && $o['piso_inicial'] !== '' ? Normaliza::inteiro($o['piso_inicial']) : $imovel->piso_inicial;
        $blocos = collect(is_array($o['blocos'] ?? null) ? $o['blocos'] : explode(',', (string) ($o['blocos'] ?? '')))->map(fn ($b) => trim((string) $b))->filter()->values()->all() ?: [''];
        if ($pisos * $porPiso * count($blocos) > CatalogoImobiliario::MAXIMO_GERAR) {
            throw new ErroNegocio('São mais de '.CatalogoImobiliario::MAXIMO_GERAR.' fracções de uma vez (contando os blocos). Gere por partes.', 'FRACCOES_DEMASIADAS');
        }
        $esquema = isset(CatalogoImobiliario::ESQUEMAS[$o['esquema'] ?? '']) ? $o['esquema'] : 'NUMERO';
        $molde = $this->normalizar(['designacao' => 'x', 'tipologia' => $o['tipologia'] ?? 'OUTRA', 'finalidade' => $o['finalidade'] ?? 'HABITACAO', 'area_bruta' => $o['area_bruta'] ?? 0,
            'area_util' => $o['area_util'] ?? 0, 'quartos' => $o['quartos'] ?? null, 'casas_banho' => $o['casas_banho'] ?? null, 'mobilada' => $o['mobilada'] ?? 'NAO',
            'renda_referencia' => $o['renda_referencia'] ?? null], $imovel, null);

        return DB::transaction(function () use ($imovel, $pisos, $porPiso, $inicial, $blocos, $esquema, $molde) {
            Imovel::query()->whereKey($imovel->id)->lockForUpdate()->first();
            $irmas = Fracao::query()->where('imovel_id', $imovel->id)->get(['id', 'codigo', 'designacao', 'ordem']);
            $usadas = $irmas->pluck('designacao')->map(fn ($x) => mb_strtolower($x))->flip()->all();
            $seq = $this->maiorSufixo($imovel, $irmas);
            $ordem = (int) $irmas->max('ordem');
            $criadas = 0;
            $repetidas = 0;
            foreach ($blocos as $bloco) {
                for ($i = 0; $i < $pisos; $i++) {
                    $piso = $inicial + $i;
                    for ($k = 1; $k <= $porPiso; $k++) {
                        $designacao = CatalogoImobiliario::designacaoGerada($esquema, $piso, $k, $bloco);
                        if (isset($usadas[mb_strtolower($designacao)])) {
                            $repetidas++;

                            continue;
                        }
                        $usadas[mb_strtolower($designacao)] = true;
                        $this->gravarNova($imovel, array_merge($molde, [
                            'codigo' => $imovel->codigo.'-'.str_pad((string) ++$seq, 2, '0', STR_PAD_LEFT), 'designacao' => $designacao, 'bloco' => $bloco !== '' ? $bloco : null,
                            'andar' => CatalogoImobiliario::nomePiso($piso), 'piso' => $piso, 'numero' => CatalogoImobiliario::numeroGerado($esquema, $piso, $k),
                            'ordem' => ++$ordem, 'estado' => 'DISPONIVEL',
                        ]));
                        $criadas++;
                    }
                }
            }

            return ['criadas' => $criadas, 'repetidas' => $repetidas];
        });
    }

    /** Cria a fracção «Única» (imóvel sem divisão: moradia, terreno, loja). Só se o imóvel ainda não tem fracções. */
    public function criarUnica(Imovel $imovel): Fracao
    {
        if (Fracao::query()->where('imovel_id', $imovel->id)->exists()) {
            throw new ErroNegocio('Este imóvel já tem fracções.', 'IMOVEL_JA_TEM_FRACCOES');
        }

        return $this->criar($imovel, ['designacao' => 'Única', 'tipologia' => $imovel->tipo === 'TERRENO' ? 'TERRENO' : 'OUTRA', 'finalidade' => 'HABITACAO',
            'area_bruta' => $imovel->area_bruta, 'area_util' => $imovel->area_util, 'estado' => 'DISPONIVEL']);
    }

    /**
     * Importa as portas do edifício do Activos como fracções. Idempotente: uma porta que já é fracção deste imóvel não se
     * repete (UNIQUE imovel+porta). Nada é escrito nos Activos.
     *
     * @return array{criadas: int, total: int}
     */
    public function importarPortas(Imovel $imovel): array
    {
        if (! $imovel->ativo_edificio_id) {
            throw new ErroNegocio('Este imóvel não está ligado a nenhum edifício do imobilizado.', 'IMOVEL_SEM_EDIFICIO');
        }
        $portas = PortaEdificio::query()->where('edificio_id', $imovel->ativo_edificio_id)->orderBy('ordem')->orderBy('id')->get();
        $andares = AndarEdificio::query()->where('edificio_id', $imovel->ativo_edificio_id)->get()->keyBy('id');

        return DB::transaction(function () use ($imovel, $portas, $andares) {
            Imovel::query()->whereKey($imovel->id)->lockForUpdate()->first();
            $irmas = Fracao::query()->where('imovel_id', $imovel->id)->get(['id', 'codigo', 'designacao', 'ordem', 'porta_id']);
            $jaImportadas = $irmas->pluck('porta_id')->filter()->flip()->all();
            $usadas = $irmas->pluck('designacao')->map(fn ($x) => mb_strtolower($x))->flip()->all();
            $seq = $this->maiorSufixo($imovel, $irmas);
            $ordem = (int) $irmas->max('ordem');
            $criadas = 0;
            foreach ($portas as $p) {
                if (isset($jaImportadas[$p->id])) {
                    continue;
                }
                $andar = $p->andar_id ? ($andares[$p->andar_id] ?? null) : null;
                $nomeAndar = trim((string) ($andar?->nome ?: $andar?->numero));
                $designacao = trim((string) $p->nome) !== '' ? trim((string) $p->nome) : ($nomeAndar !== '' ? $nomeAndar.' · ' : '').'Porta '.trim((string) $p->numero);
                if (isset($usadas[mb_strtolower($designacao)])) {
                    $designacao .= " (porta {$p->id})";
                }
                $usadas[mb_strtolower($designacao)] = true;
                $this->gravarNova($imovel, [
                    'codigo' => $imovel->codigo.'-'.str_pad((string) ++$seq, 2, '0', STR_PAD_LEFT), 'designacao' => mb_substr($designacao, 0, 120), 'natureza' => 'HABITACIONAL',
                    'porta_id' => $p->id, 'andar' => $nomeAndar !== '' ? mb_substr($nomeAndar, 0, 60) : null, 'numero' => Normaliza::texto($p->numero), 'tipologia' => 'OUTRA',
                    'finalidade' => 'HABITACAO', 'area_bruta' => 0, 'area_util' => 0, 'mobilada' => 'NAO', 'estado' => 'DISPONIVEL', 'ordem' => ++$ordem,
                    'notas' => Normaliza::texto($p->tipo) !== null ? 'Tipo na planta: '.$p->tipo : null,
                ]);
                $criadas++;
            }

            return ['criadas' => $criadas, 'total' => $portas->count()];
        });
    }

    /** @param  array<string, mixed>  $reg */
    private function gravarNova(Imovel $imovel, array $reg): Fracao
    {
        try {
            $f = Fracao::query()->create($reg + ['empresa_id' => $this->contexto->id(), 'imovel_id' => $imovel->id, 'estado_desde' => now()->toDateString(),
                'criado_por' => $this->utilizador(), 'atualizado_por' => $this->utilizador()]);
        } catch (UniqueConstraintViolationException) {
            throw new ErroNegocio('Já existe uma fracção com esse código, designação ou porta neste imóvel.', 'FRACCAO_DUPLICADA');
        }
        $this->registarHistorico($f, null, $f->estado, null);

        return $f;
    }

    private function registarHistorico(Fracao $f, ?string $de, string $para, ?string $motivo): void
    {
        HistoricoEstadoFracao::query()->create(['empresa_id' => $f->empresa_id, 'fracao_id' => $f->id, 'estado_de' => $de, 'estado_para' => $para, 'motivo' => $motivo,
            'utilizador_id' => Auth::id(), 'em' => now()]);
    }

    /** @param  array<string, mixed>  $d */
    private function estadoInicial(array $d): string
    {
        $e = (string) ($d['estado'] ?? 'DISPONIVEL');

        return isset(CatalogoImobiliario::ESTADOS_FRACAO[$e]) ? $e : 'DISPONIVEL';
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function normalizar(array $d, Imovel $imovel, ?Fracao $actual): array
    {
        $designacao = Normaliza::texto($d['designacao'] ?? null);
        if ($designacao === null) {
            throw new ErroNegocio('Dê uma designação à fracção (ex.: «1.º Esq.», «Loja A», «Única»).', 'FRACCAO_SEM_DESIGNACAO');
        }
        $natureza = (string) ($d['natureza'] ?? 'HABITACIONAL');
        if (! isset(CatalogoImobiliario::NATUREZAS[$natureza])) {
            throw new ErroNegocio('Escolha a natureza da fracção.', 'FRACCAO_NATUREZA');
        }
        if (! in_array($natureza, CatalogoImobiliario::NATUREZAS_ACTIVAS, true)) {
            throw new ErroNegocio('As vagas de estacionamento são geridas no Parqueamento, que ainda não está disponível. Registe aqui fracções e arrecadações.', 'FRACCAO_NATUREZA_INDISPONIVEL');
        }
        $bruta = Normaliza::r2($d['area_bruta'] ?? 0);
        $util = Normaliza::r2($d['area_util'] ?? 0);
        if ($bruta > 0 && $util > 0 && $util > $bruta) {
            throw new ErroNegocio('A área útil não pode ser maior do que a área bruta.', 'FRACCAO_AREAS');
        }
        $tipologia = (string) ($d['tipologia'] ?? 'OUTRA');
        $finalidade = (string) ($d['finalidade'] ?? 'HABITACAO');
        $mobilada = (string) ($d['mobilada'] ?? 'NAO');
        if (! isset(CatalogoImobiliario::TIPOLOGIAS[$tipologia]) || ! isset(CatalogoImobiliario::FINALIDADES[$finalidade]) || ! isset(CatalogoImobiliario::MOBILIA[$mobilada])) {
            throw new ErroNegocio('Tipologia, finalidade ou mobília inválidas.', 'FRACCAO_LISTA');
        }
        if ($actual !== null && CatalogoImobiliario::principal($actual->natureza) && ! CatalogoImobiliario::principal($natureza)
            && Fracao::query()->where('park_fracao_id', $actual->id)->exists()) {
            throw new ErroNegocio('Esta fracção tem vagas afectas. Liberte-as antes de mudar a natureza.', 'FRACCAO_COM_VAGAS');
        }
        $reg = [
            'codigo' => Normaliza::texto($d['codigo'] ?? null), 'designacao' => mb_substr($designacao, 0, 120), 'natureza' => $natureza,
            'bloco' => Normaliza::texto($d['bloco'] ?? null), 'andar' => Normaliza::texto($d['andar'] ?? null), 'numero' => Normaliza::texto($d['numero'] ?? null),
            'piso' => Normaliza::inteiro($d['piso'] ?? null, true), 'tipologia' => $tipologia, 'finalidade' => $finalidade, 'area_bruta' => $bruta, 'area_util' => $util,
            'quartos' => ($q = Normaliza::inteiro($d['quartos'] ?? null, true)) ? max(0, $q) : null,
            'casas_banho' => ($c = Normaliza::inteiro($d['casas_banho'] ?? null, true)) ? max(0, $c) : null, 'mobilada' => $mobilada,
            'matriz_predial' => Normaliza::texto($d['matriz_predial'] ?? null), 'valor_patrimonial' => Normaliza::r2($d['valor_patrimonial'] ?? null, true),
            'renda_referencia' => Normaliza::r2($d['renda_referencia'] ?? null, true), 'venda_referencia' => Normaliza::r2($d['venda_referencia'] ?? null, true),
            'notas' => Normaliza::texto($d['notas'] ?? null),
        ];
        foreach (['valor_patrimonial', 'renda_referencia', 'venda_referencia'] as $c2) {
            if ($reg[$c2] !== null && $reg[$c2] < 0) {
                throw new ErroNegocio('Os valores de referência não podem ser negativos.', 'FRACCAO_VALORES');
            }
        }
        if (array_key_exists('documentos', $d) || $actual === null) {
            $reg['documentos'] = Normaliza::documentos($d['documentos'] ?? []);
        }

        return $reg;
    }

    /**
     * @param  array<string, mixed>  $reg
     * @param  Collection<int, Fracao>  $irmas
     */
    private function conferirUnicos(array $reg, Collection $irmas, ?Fracao $actual): void
    {
        $outras = $actual ? $irmas->where('id', '!=', $actual->id) : $irmas;
        if ($outras->contains(fn ($u) => mb_strtolower($u->codigo) === mb_strtolower((string) $reg['codigo']))) {
            throw new ErroNegocio("Já existe uma fracção com o código {$reg['codigo']} neste imóvel.", 'FRACCAO_CODIGO');
        }
        if ($outras->contains(fn ($u) => mb_strtolower($u->designacao) === mb_strtolower($reg['designacao']))) {
            throw new ErroNegocio("Já existe a fracção «{$reg['designacao']}» neste imóvel.", 'FRACCAO_DESIGNACAO');
        }
    }

    /** @param  Collection<int, Fracao>  $irmas */
    private function proximoCodigo(Imovel $imovel, Collection $irmas): string
    {
        return $imovel->codigo.'-'.str_pad((string) ($this->maiorSufixo($imovel, $irmas) + 1), 2, '0', STR_PAD_LEFT);
    }

    /** Maior NN dos códigos `<imóvel>-NN` já usados (os que não seguem o padrão contam 0). @param  Collection<int, Fracao>  $irmas */
    private function maiorSufixo(Imovel $imovel, Collection $irmas): int
    {
        $prefixo = mb_strtolower($imovel->codigo).'-';
        $maior = 0;
        foreach ($irmas as $u) {
            $c = mb_strtolower((string) $u->codigo);
            if (str_starts_with($c, $prefixo) && ctype_digit(substr($c, strlen($prefixo)))) {
                $maior = max($maior, (int) substr($c, strlen($prefixo)));
            }
        }

        return $maior;
    }

    private function utilizador(): string
    {
        return mb_substr((string) (Auth::user()?->nome ?? 'sistema'), 0, 100);
    }
}
