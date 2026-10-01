<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\Moeda;
use App\Models\TaxaCambio;
use App\Support\Dados\VerificadorReferencias;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Moedas e câmbios (renderMoedasCambios / guardarMoeda / gravarCambio / eliminarCambio / importarCambiosExcel,
 * js/moedas.js:173-618). A leitura do câmbio válido numa data continua em ServicoCambios.
 *
 * Paridade:
 *  - taxa = Kz por 1 unidade da moeda; AOA é a base (sempre activa, sem câmbio);
 *  - âmbito TODAS (empresa_id nulo; no legado scope_company_id = 0) ou EMPRESA (só a empresa activa — prevalece);
 *  - um câmbio por (âmbito, moeda, data): gravar sobre um existente exige confirmação ("substituir");
 *  - um câmbio já usado em documentos não muda de data, moeda, taxa ou âmbito, nem se elimina;
 *  - importação (Data | Moeda | Taxa | Origem | Âmbito): linhas inválidas rejeitadas com o motivo; existentes
 *    IGNORAR (mantém) ou ACTUALIZAR; nunca duplica; os usados em documentos com taxa diferente não mudam.
 *
 * Correcções face ao legado:
 *  - o índice único (empresa_id, codigo_moeda, data_taxa) não protege os câmbios de TODAS (empresa_id nulo é
 *    "distinto" no PostgreSQL): a unicidade é garantida aqui com um lock transaccional por (âmbito, moeda, data);
 *  - um câmbio de outra empresa não pode ser alterado nem eliminado a partir da empresa activa (o legado permitia);
 *  - "em uso" é lido das chaves estrangeiras reais (taxa_cambio_id), não de uma lista de 10 tabelas mantida à mão.
 */
final class ServicoMoedas
{
    public const AMBITO_TODAS = 'TODAS';

    public const AMBITO_EMPRESA = 'EMPRESA';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly VerificadorReferencias $referencias,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @return list<array<string, mixed>> AOA primeiro, depois as padrão do legado, depois por código */
    public function moedas(bool $apenasAtivas = false): array
    {
        $ordem = ['AOA', 'USD', 'EUR', 'ZAR', 'CNY'];

        return Moeda::query()->when($apenasAtivas, fn ($q) => $q->where('ativo', true))->get()
            ->sortBy(fn ($m) => sprintf('%02d%s', ($p = array_search($m->codigo, $ordem, true)) === false ? 99 : $p, $m->codigo))->values()
            ->map(fn (Moeda $m) => ['id' => $m->id, 'codigo' => $m->codigo, 'nome' => $m->nome, 'simbolo' => $m->simbolo,
                'casas_decimais' => $m->casas_decimais ?? 2, 'ativo' => (bool) $m->ativo, 'base' => $m->codigo === ServicoCambios::BASE])->all();
    }

    /** @param  array{codigo: string, nome: string, simbolo: string, casas_decimais?: int}  $d */
    public function criarMoeda(array $d): Moeda
    {
        $codigo = strtoupper(trim($d['codigo']));
        if (Moeda::query()->where('codigo', $codigo)->exists()) {
            throw new ErroNegocio("A moeda {$codigo} já existe.", 'MOEDA_DUPLICADA', 422);
        }
        $m = Moeda::create(['codigo' => $codigo, 'nome' => trim($d['nome']), 'simbolo' => trim($d['simbolo']), 'casas_decimais' => $d['casas_decimais'] ?? 2, 'ativo' => true]);
        $this->auditoria->registar('Sistema/Moedas', 'Criar moeda', "Moeda {$codigo} criada.", 'moedas', $m->id, null, $m->only(['codigo', 'nome', 'simbolo']));

        return $m;
    }

    /** guardarMoeda: nome, símbolo e estado (a moeda base fica sempre activa). */
    public function atualizarMoeda(Moeda $m, array $d): Moeda
    {
        $antes = $m->only(['nome', 'simbolo', 'ativo']);
        if ($m->codigo === ServicoCambios::BASE) {
            $d['ativo'] = true;
        }
        $m->update(array_intersect_key($d, array_flip(['nome', 'simbolo', 'ativo'])));
        $this->auditoria->registar('Sistema/Moedas', 'Alterar moeda', "Moeda {$m->codigo} alterada.", 'moedas', $m->id, $antes, $m->only(['nome', 'simbolo', 'ativo']));

        return $m;
    }

    /** @param  array<string, mixed>  $f  codigo_moeda, de, ate, ambito (TODAS|EMPRESA), por_pagina */
    public function listarCambios(array $f): LengthAwarePaginator
    {
        $empresa = $this->contexto->obrigatorio();
        $pagina = TaxaCambio::query()
            ->where(fn ($q) => match ($f['ambito'] ?? null) {
                self::AMBITO_TODAS => $q->whereNull('empresa_id'),
                self::AMBITO_EMPRESA => $q->where('empresa_id', $empresa),
                default => $q->whereNull('empresa_id')->orWhere('empresa_id', $empresa),
            })
            ->when($f['codigo_moeda'] ?? null, fn ($q, $m) => $q->where('codigo_moeda', $m))
            ->when($f['de'] ?? null, fn ($q, $d) => $q->where('data_taxa', '>=', $d))
            ->when($f['ate'] ?? null, fn ($q, $d) => $q->where('data_taxa', '<=', $d))
            ->orderByDesc('data_taxa')->orderBy('codigo_moeda')->orderBy('id')
            ->paginate(min((int) ($f['por_pagina'] ?? 100), 1000));
        $pagina->setCollection($pagina->getCollection()->map(fn (TaxaCambio $t) => $this->apresentarCambio($t)));

        return $pagina;
    }

    /** @return array<string, mixed> */
    public function apresentarCambio(TaxaCambio $t): array
    {
        return ['id' => $t->id, 'codigo_moeda' => $t->codigo_moeda, 'data_taxa' => $t->data_taxa?->toDateString(), 'taxa' => (float) $t->taxa,
            'ambito' => $t->empresa_id ? self::AMBITO_EMPRESA : self::AMBITO_TODAS, 'empresa_id' => $t->empresa_id, 'fonte_dados' => $t->fonte_dados,
            'taxa_compra_bai' => $t->taxa_compra_bai !== null ? (float) $t->taxa_compra_bai : null, 'taxa_venda_bai' => $t->taxa_venda_bai !== null ? (float) $t->taxa_venda_bai : null,
            'criado_por' => $t->criado_por, 'atualizado_por' => $t->atualizado_por];
    }

    /**
     * Grava um câmbio (gravarCambio). Com $existente (edição) aplica as regras de "em uso".
     *
     * @param  array{data_taxa: string, codigo_moeda: string, taxa: float|string, ambito: string, fonte_dados?: ?string, substituir?: bool, taxa_compra_bai?: mixed, taxa_venda_bai?: mixed}  $d
     */
    public function gravarCambio(array $d, ?TaxaCambio $existente = null): TaxaCambio
    {
        return DB::transaction(function () use ($d, $existente) {
            $empresa = $this->contexto->obrigatorio();
            $moeda = strtoupper((string) $d['codigo_moeda']);
            $data = substr((string) $d['data_taxa'], 0, 10);
            $taxa = $this->taxa($d['taxa']);
            $ambitoEmpresa = ($d['ambito'] ?? self::AMBITO_TODAS) === self::AMBITO_EMPRESA ? $empresa : null;
            $this->exigirMoedaEstrangeiraAtiva($moeda);
            $this->bloquear($ambitoEmpresa, $moeda, $data);
            $utilizador = Auth::user()?->nome_utilizador ?? 'sistema';

            if ($existente) {
                $existente = TaxaCambio::query()->lockForUpdate()->findOrFail($existente->id);
                $this->exigirDaEmpresaActiva($existente);
                $mudou = bccomp((string) $existente->taxa, $taxa, 6) !== 0 || $existente->data_taxa->toDateString() !== $data
                    || $existente->codigo_moeda !== $moeda || $existente->empresa_id !== $ambitoEmpresa;
                if ($mudou && $this->emUso($existente->id)) {
                    throw new ErroNegocio('Este câmbio já está a ser usado em documentos: não é possível alterar a data, a moeda, a taxa ou o âmbito. Registe um novo câmbio, se necessário.',
                        'CAMBIO_EM_USO', 422, ['utilizacoes' => $this->referencias->emUso('taxas_cambio', $existente->id)]);
                }
            }
            $igual = TaxaCambio::query()->where('codigo_moeda', $moeda)->whereDate('data_taxa', $data)
                ->when($ambitoEmpresa, fn ($q) => $q->where('empresa_id', $ambitoEmpresa), fn ($q) => $q->whereNull('empresa_id'))
                ->when($existente, fn ($q) => $q->whereKeyNot($existente->id))->lockForUpdate()->first();
            $dados = ['fonte_dados' => $d['fonte_dados'] ?? null] + array_intersect_key($d, array_flip(['taxa_compra_bai', 'taxa_venda_bai']));

            if ($igual) {
                if (bccomp((string) $igual->taxa, $taxa, 6) !== 0 && $this->emUso($igual->id)) {
                    throw new ErroNegocio("Já existe um câmbio {$moeda} em {$data} usado em documentos: não pode ser substituído.", 'CAMBIO_EM_USO', 422);
                }
                if (! ($d['substituir'] ?? false)) {
                    throw new ErroNegocio("Já existe um câmbio {$moeda} em {$data} (".$this->formatar($igual->taxa).' Kz). Confirme para o substituir.',
                        'CAMBIO_EXISTENTE', 422, ['cambio' => $this->apresentarCambio($igual)]);
                }
                $antes = $this->apresentarCambio($igual);
                $igual->update(['taxa' => $taxa, 'atualizado_por' => $utilizador] + $dados);
                if ($existente) {
                    $existente->delete();
                }
                $this->auditoria->registar('Sistema/Moedas', 'Substituir câmbio', "Câmbio {$moeda} de {$data} substituído.", 'taxas_cambio', $igual->id, $antes, $this->apresentarCambio($igual));

                return $igual;
            }
            if ($existente) {
                $antes = $this->apresentarCambio($existente);
                $existente->update(['empresa_id' => $ambitoEmpresa, 'codigo_moeda' => $moeda, 'data_taxa' => $data, 'taxa' => $taxa, 'atualizado_por' => $utilizador] + $dados);
                $this->auditoria->registar('Sistema/Moedas', 'Alterar câmbio', "Câmbio {$moeda} de {$data} alterado.", 'taxas_cambio', $existente->id, $antes, $this->apresentarCambio($existente));

                return $existente;
            }
            $novo = TaxaCambio::create(['empresa_id' => $ambitoEmpresa, 'codigo_moeda' => $moeda, 'data_taxa' => $data, 'taxa' => $taxa, 'criado_por' => $utilizador] + $dados);
            $this->auditoria->registar('Sistema/Moedas', 'Registar câmbio', "Câmbio {$moeda} de {$data}: ".$this->formatar($taxa).' Kz.', 'taxas_cambio', $novo->id, null, $this->apresentarCambio($novo));

            return $novo;
        });
    }

    public function eliminarCambio(TaxaCambio $t): void
    {
        DB::transaction(function () use ($t) {
            $t = TaxaCambio::query()->lockForUpdate()->findOrFail($t->id);
            $this->exigirDaEmpresaActiva($t);
            $uso = $this->referencias->emUso('taxas_cambio', $t->id);
            if ($uso) {
                throw new ErroNegocio('Este câmbio não pode ser eliminado: já está a ser usado em documentos.', 'CAMBIO_EM_USO', 422, ['utilizacoes' => $uso]);
            }
            $antes = $this->apresentarCambio($t);
            $t->delete();
            $this->auditoria->registar('Sistema/Moedas', 'Eliminar câmbio', "Câmbio {$antes['codigo_moeda']} de {$antes['data_taxa']} eliminado.", 'taxas_cambio', $antes['id'], $antes, null);
        });
    }

    /**
     * Importação (analisarLinhasImportacao + importarCambiosExcel). Cada linha: data, moeda, taxa, origem?, ambito?
     * (TODAS por omissão; aceita "EMPRESA..."). Números e datas nos formatos do legado (912,50; 1.050; dd/mm/aaaa).
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return array{novos: int, existentes: int, bloqueados: list<array>, rejeitadas: list<array{linha: int, motivo: string}>, importados: int, actualizados: int, mantidos: int}
     */
    public function importarCambios(array $linhas, string $decisao, bool $simular): array
    {
        $empresa = $this->contexto->obrigatorio();
        $ativas = Moeda::query()->where('ativo', true)->pluck('codigo')->all();
        $validas = [];
        $rejeitadas = [];
        foreach ($linhas as $i => $l) {
            $n = $i + 2;
            $campos = [];
            foreach ($l as $k => $v) {
                $campos[ServicoPermissoes::norm((string) $k)] = $v;
            }
            $valor = function (string ...$nomes) use ($campos) {
                foreach ($nomes as $nome) {
                    if (isset($campos[$nome]) && $campos[$nome] !== '') {
                        return $campos[$nome];
                    }
                }

                return '';
            };
            $bruto = [$valor('data', 'date', 'data_taxa'), $valor('moeda', 'currency', 'codigo_moeda'), $valor('taxa', 'cambio', 'rate')];
            if ($bruto === ['', '', '']) {
                continue;
            }
            $data = self::lerData($bruto[0]);
            $moeda = strtoupper(trim((string) $bruto[1]));
            $taxa = self::lerNumero($bruto[2]);
            $ambito = str_starts_with(ServicoPermissoes::norm((string) $valor('ambito', 'scope')), 'empresa') ? self::AMBITO_EMPRESA : self::AMBITO_TODAS;
            $motivo = match (true) {
                $data === null => "data inválida ({$bruto[0]})",
                $moeda === '' || $moeda === ServicoCambios::BASE => 'moeda inválida ('.($bruto[1] !== '' ? $bruto[1] : 'vazia').')',
                ! in_array($moeda, $ativas, true) => "moeda {$moeda} não existe ou está inactiva",
                $taxa === null || $taxa <= 0 => "taxa inválida ({$bruto[2]})",
                default => null,
            };
            if ($motivo) {
                $rejeitadas[] = ['linha' => $n, 'motivo' => $motivo];

                continue;
            }
            $validas[($ambito === self::AMBITO_EMPRESA ? $empresa : 0)."|{$moeda}|{$data}"] = ['linha' => $n, 'data_taxa' => $data, 'codigo_moeda' => $moeda,
                'taxa' => number_format($taxa, 6, '.', ''), 'ambito' => $ambito, 'fonte_dados' => trim((string) $valor('origem', 'fonte', 'source', 'fonte_dados')) ?: null];
        }

        $analise = [];
        foreach ($validas as $v) {
            $ex = TaxaCambio::query()->where('codigo_moeda', $v['codigo_moeda'])->whereDate('data_taxa', $v['data_taxa'])
                ->when($v['ambito'] === self::AMBITO_EMPRESA, fn ($q) => $q->where('empresa_id', $empresa), fn ($q) => $q->whereNull('empresa_id'))->first();
            $analise[] = ['v' => $v, 'ex' => $ex, 'em_uso' => $ex && bccomp((string) $ex->taxa, $v['taxa'], 6) !== 0 && $this->emUso($ex->id)];
        }
        $bloqueados = array_values(array_filter($analise, fn ($a) => $a['em_uso']));
        $existentes = array_values(array_filter($analise, fn ($a) => $a['ex'] && ! $a['em_uso']));
        $novos = array_values(array_filter($analise, fn ($a) => ! $a['ex']));
        $r = ['novos' => count($novos), 'existentes' => count($existentes),
            'bloqueados' => array_map(fn ($a) => ['linha' => $a['v']['linha'], 'codigo_moeda' => $a['v']['codigo_moeda'], 'data_taxa' => $a['v']['data_taxa']], $bloqueados),
            'rejeitadas' => $rejeitadas, 'importados' => 0, 'actualizados' => 0, 'mantidos' => 0];
        if ($simular) {
            return $r;
        }
        DB::transaction(function () use ($novos, $existentes, $decisao, &$r) {
            foreach ($novos as $a) {
                $this->gravarCambio($a['v']);
                $r['importados']++;
            }
            foreach ($existentes as $a) {
                if ($decisao === 'ACTUALIZAR') {
                    $this->gravarCambio($a['v'] + ['substituir' => true]);
                    $r['actualizados']++;
                } else {
                    $r['mantidos']++;
                }
            }
        });

        return $r;
    }

    public function emUso(int $taxaId): bool
    {
        return $this->referencias->emUso('taxas_cambio', $taxaId) !== [];
    }

    /** lerNumero do legado (js/moedas.js:132-141): 912,50 · 1.050,25 · 1,050.25 · 1.050 (milhares). */
    public static function lerNumero(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[\s\x{00A0}]/u', '', trim((string) $v));
        if ($s === '') {
            return null;
        }
        $lc = strrpos($s, ',');
        $ld = strrpos($s, '.');
        if ($lc !== false && $ld !== false) {
            $s = $lc > $ld ? str_replace(',', '.', str_replace('.', '', $s)) : str_replace(',', '', $s);
        } elseif ($lc !== false) {
            $s = str_replace(',', '.', $s);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    /** lerData do legado (js/moedas.js:143-155): aaaa-mm-dd ou dd/mm/aaaa (também com - ou .), validada. */
    public static function lerData(mixed $v): ?string
    {
        $s = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            [$a, $me, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $s, $m)) {
            [$a, $me, $d] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } else {
            return null;
        }

        return checkdate($me, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $me, $d) : null;
    }

    private function exigirMoedaEstrangeiraAtiva(string $moeda): void
    {
        if ($moeda === ServicoCambios::BASE) {
            throw new ErroNegocio('Escolha uma moeda estrangeira: o Kwanza é a moeda base (câmbio 1).', 'MOEDA_BASE', 422);
        }
        if (! Moeda::query()->where('codigo', $moeda)->where('ativo', true)->exists()) {
            throw new ErroNegocio("A moeda {$moeda} não existe ou está inactiva.", 'MOEDA_INEXISTENTE', 422);
        }
    }

    private function exigirDaEmpresaActiva(TaxaCambio $t): void
    {
        if ($t->empresa_id !== null && $t->empresa_id !== $this->contexto->obrigatorio()) {
            throw new ErroNegocio('Câmbio não encontrado.', 'NAO_ENCONTRADO', 404);
        }
    }

    /** Lock transaccional por (âmbito, moeda, data): garante a unicidade também para os câmbios de todas as empresas. */
    private function bloquear(?int $empresa, string $moeda, string $data): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('taxas_cambio|'.($empresa ?? 0)."|{$moeda}|{$data}")]);
    }

    private function taxa(mixed $v): string
    {
        $n = self::lerNumero($v);
        if ($n === null || $n <= 0) {
            throw new ErroNegocio('Indique uma taxa válida, maior que zero (ex.: 912,50).', 'TAXA_INVALIDA', 422);
        }

        return number_format($n, 6, '.', '');
    }

    private function formatar(mixed $taxa): string
    {
        return number_format((float) $taxa, 2, ',', '.');
    }
}
