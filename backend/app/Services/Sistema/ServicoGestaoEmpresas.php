<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\Moeda;
use App\Models\TipoOrganizacaoRH;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gestão de empresas (config_empresas: renderEmpresas / saveCompany / editCompany, js/app_v2.js:2854-2960 e 3629-3712;
 * regras das horas extra gravarRegrasHorasExtra, :9168-9180; moeda funcional guardarMoedaFuncional, js/moedas.js:357-374).
 * O acesso (quem vê que empresa) continua em ServicoEmpresas.
 *
 * Paridade:
 *  - nome e NIF obrigatórios; contactos, CRC e rodapé dos documentos; taxas de INSS (por omissão 8% / 3%);
 *  - logótipo em data URI (base64), mantido quando a ficha é gravada sem nova imagem; "remover" = enviar vazio;
 *  - empresa nova recebe as rubricas e os tipos de organização por omissão (seedDefaultInfotypes, app_v2.js:2962-2987);
 *  - horas extra: percentagens e limite ≥ 0 (o motor usa 50% / 30 h / 75% quando vazios);
 *  - moeda funcional só muda enquanto a empresa não tiver lançamentos.
 *
 * Correcções face ao legado:
 *  - "parseFloat(...) || 8" transformava uma taxa de INSS de 0% em 8% (app_v2.js:2922-2923): 0 é aceite;
 *  - NIF único entre empresas activas (índice da base) com mensagem clara, em vez de erro técnico;
 *  - o logótipo aceita só imagens raster (PNG, JPEG, GIF, WEBP) até 1 MB — o legado aceitava qualquer ficheiro;
 *  - eliminar uma empresa deixou de ser imediato: passa pela Manutenção de dados (aprovação dupla, ELIMINAR_EMPRESA);
 *    aqui só se activa/desactiva;
 *  - a rubrica por omissão "Subsídio de comusúnicação" (gralha) é criada como "Subsídio de comunicação";
 *  - quem cria a empresa sem acesso a todas fica ligado a ela (no legado a lista vazia dava acesso a tudo);
 *  - holding: uma empresa com movimentos não passa a holding e uma holding com consolidações não deixa de o ser
 *    (a consolidação em si é do módulo de consolidação).
 */
final class ServicoGestaoEmpresas
{
    public const TAMANHO_MAXIMO_LOGOTIPO = 1048576;

    /** Rubricas por omissão de uma empresa nova (app_v2.js:2963-2977). */
    private const RUBRICAS_OMISSAO = [
        ['VENCIMENTO', 'Salário Base', true, 'true'], ['VENCIMENTO', 'Subsídio de transporte', true, 'conditional_30k'],
        ['VENCIMENTO', 'Subsídio de alimentação', true, 'conditional_30k'], ['VENCIMENTO', 'Subsídio de férias', false, 'true'],
        ['VENCIMENTO', 'Subsídio de comunicação', true, 'true'], ['VENCIMENTO', 'Retroativos', true, 'true'],
        ['VENCIMENTO', 'Subsídio de atavio', true, 'true'], ['VENCIMENTO', 'Subsídio de chefia', true, 'true'],
        ['VENCIMENTO', 'Horas Extras', true, 'true'], ['VENCIMENTO', 'Subsídio de produtividade', true, 'true'],
        ['DESCONTO', 'Falta injustificada', false, 'false'], ['DESCONTO', 'Descontos de Adiantamento', false, 'false'],
        ['DESCONTO', 'Outros Descontos', false, 'false'],
    ];

    private const CAMPOS = [
        'nome', 'nif', 'endereco', 'provincia', 'municipio', 'comuna', 'telefone', 'email', 'website', 'numero_registo_comercial',
        'rodape_documento', 'taxa_inss_patronal', 'taxa_inss_trabalhador', 'regras_ia', 'he_percentagem_1', 'he_limite_horas', 'he_percentagem_2',
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** Empresas geríveis: todas (acesso total ou a todas as empresas) ou as ligadas ao utilizador, em qualquer estado. */
    public function listar(Utilizador $actor, ?string $estado = null): Collection
    {
        return Empresa::query()
            ->when(! $this->veTodas($actor), fn ($q) => $q->whereIn('id', $actor->empresas()->select('empresas.id')))
            ->when($estado, fn ($q) => $q->where('estado', $estado))
            ->orderBy('nome')->get()->map(fn (Empresa $e) => $this->apresentar($e));
    }

    public function obter(int $id, Utilizador $actor): Empresa
    {
        $e = Empresa::query()->find($id);
        if (! $e || ! $this->podeGerir($actor, $e)) {
            throw new ErroNegocio('Empresa não encontrada.', 'NAO_ENCONTRADO', 404);
        }

        return $e;
    }

    /** @return array<string, mixed> */
    public function apresentar(Empresa $e, bool $comLogotipo = false): array
    {
        $dados = ['id' => $e->id] + collect(self::CAMPOS)->mapWithKeys(fn ($c) => [$c => $e->getAttribute($c)])->all() + [
            'estado' => $e->estado,
            'moeda_funcional' => $e->moeda_funcional ?: 'AOA',
            'e_consolidacao' => (bool) $e->e_consolidacao,
            'moeda_consolidacao' => $e->moeda_consolidacao,
            'data_fim_consolidacao' => $e->data_fim_consolidacao?->toDateString(),
            'tem_logotipo' => $e->logotipo !== null && $e->logotipo !== '',
        ];
        foreach (['taxa_inss_patronal', 'taxa_inss_trabalhador', 'he_percentagem_1', 'he_limite_horas', 'he_percentagem_2'] as $n) {
            $dados[$n] = $dados[$n] !== null ? (float) $dados[$n] : null;
        }
        if ($comLogotipo) {
            $dados['logotipo'] = $e->logotipo;
        }

        return $dados;
    }

    /** @param  array<string, mixed>  $d */
    public function criar(array $d, Utilizador $actor): Empresa
    {
        return DB::transaction(function () use ($d, $actor) {
            $this->validar($d, null);
            $e = Empresa::create($this->dados($d) + ['estado' => Empresa::ESTADO_ATIVO,
                'taxa_inss_patronal' => $d['taxa_inss_patronal'] ?? 8, 'taxa_inss_trabalhador' => $d['taxa_inss_trabalhador'] ?? 3,
                'moeda_funcional' => $d['moeda_funcional'] ?? 'AOA']);
            $this->contexto->executarComo($e->id, function () {
                foreach (self::RUBRICAS_OMISSAO as [$tipo, $nome, $inss, $irt]) {
                    InfotipoSalarial::create(['tipo' => $tipo, 'nome' => $nome, 'sujeito_inss' => $inss, 'irt' => $irt, 'base_horaria' => false]);
                }
                foreach (['Colaboradores', 'Sociais'] as $nome) {
                    TipoOrganizacaoRH::create(['nome' => $nome]);
                }
            });
            if (! $this->veTodas($actor)) {
                $actor->empresas()->syncWithoutDetaching([$e->id]);
                $this->empresas->invalidarUtilizador($actor->id);
            }
            $this->auditoria->registar('Sistema/Empresas', 'Criar empresa', "Empresa «{$e->nome}» criada (rubricas e tipos de organização por omissão).",
                'empresas', $e->id, null, $this->apresentar($e), empresaId: $e->id);

            return $e->refresh();
        });
    }

    /** @param  array<string, mixed>  $d */
    public function atualizar(Empresa $e, array $d, Utilizador $actor): Empresa
    {
        return DB::transaction(function () use ($e, $d, $actor) {
            $e = Empresa::query()->lockForUpdate()->findOrFail($e->id);
            if (! $this->podeGerir($actor, $e)) {
                throw new ErroNegocio('Empresa não encontrada.', 'NAO_ENCONTRADO', 404);
            }
            $this->validar($d, $e);
            $antes = $this->apresentar($e);
            $e->update($this->dados($d));
            $this->auditoria->registar('Sistema/Empresas', 'Alterar empresa', "Empresa «{$e->nome}» alterada.", 'empresas', $e->id, $antes, $this->apresentar($e), empresaId: $e->id);

            return $e->refresh();
        });
    }

    public function definirEstado(Empresa $e, string $estado, Utilizador $actor): Empresa
    {
        return DB::transaction(function () use ($e, $estado, $actor) {
            $e = Empresa::query()->lockForUpdate()->findOrFail($e->id);
            if (! $this->podeGerir($actor, $e)) {
                throw new ErroNegocio('Empresa não encontrada.', 'NAO_ENCONTRADO', 404);
            }
            if ($e->estado === $estado) {
                return $e;
            }
            if ($estado === Empresa::ESTADO_INATIVO && Empresa::query()->where('estado', Empresa::ESTADO_ATIVO)->whereKeyNot($e->id)->doesntExist()) {
                throw new ErroNegocio('Não é possível desactivar a única empresa activa.', 'ULTIMA_EMPRESA_ATIVA', 422);
            }
            $anterior = $e->estado;
            $e->update(['estado' => $estado]);
            $this->auditoria->registar('Sistema/Empresas', $estado === Empresa::ESTADO_ATIVO ? 'Activar empresa' : 'Desactivar empresa',
                "Empresa «{$e->nome}»: {$anterior} → {$estado}.", 'empresas', $e->id, ['estado' => $anterior], ['estado' => $estado], empresaId: $e->id);

            return $e;
        });
    }

    /**
     * Regras das horas extra da empresa activa (gravarRegrasHorasExtra: permissão de RH, js/app_v2.js:9168).
     *
     * @param  array{he_percentagem_1: float, he_limite_horas: float, he_percentagem_2: float}  $d
     */
    public function gravarHorasExtra(array $d): Empresa
    {
        return DB::transaction(function () use ($d) {
            $e = Empresa::query()->lockForUpdate()->findOrFail($this->contexto->obrigatorio());
            $antes = ['he_percentagem_1' => $e->he_percentagem_1, 'he_limite_horas' => $e->he_limite_horas, 'he_percentagem_2' => $e->he_percentagem_2];
            $e->update(array_intersect_key($d, array_flip(['he_percentagem_1', 'he_limite_horas', 'he_percentagem_2'])));
            $this->auditoria->registar('RH', 'Regras horas extra', json_encode($d), 'empresas', $e->id, $antes, $d, empresaId: $e->id);

            return $e->refresh();
        });
    }

    public function podeGerir(Utilizador $actor, Empresa $e): bool
    {
        return $this->veTodas($actor) || $actor->empresas()->where('empresas.id', $e->id)->exists();
    }

    private function veTodas(Utilizador $actor): bool
    {
        return $this->permissoes->total($actor) || $actor->acesso_todas_empresas;
    }

    /** @param  array<string, mixed>  $d */
    private function validar(array $d, ?Empresa $e): void
    {
        $nif = isset($d['nif']) ? trim((string) $d['nif']) : $e?->nif;
        if (isset($d['nif']) && Empresa::query()->where('nif', $nif)->when($e, fn ($q) => $q->whereKeyNot($e->id))->exists()) {
            throw new ErroNegocio("Já existe uma empresa com o NIF {$nif}.", 'NIF_DUPLICADO', 422);
        }
        if (array_key_exists('logotipo', $d) && $d['logotipo'] !== null && $d['logotipo'] !== '') {
            self::validarLogotipo((string) $d['logotipo']);
        }
        foreach (['moeda_funcional', 'moeda_consolidacao'] as $campo) {
            if (! empty($d[$campo]) && ! Moeda::query()->where('codigo', $d[$campo])->exists()) {
                throw new ErroNegocio("A moeda {$d[$campo]} não existe.", 'MOEDA_INEXISTENTE', 422);
            }
        }
        if (! $e) {
            return;
        }
        if (! empty($d['moeda_funcional']) && $d['moeda_funcional'] !== ($e->moeda_funcional ?: 'AOA')) {
            $linhas = DB::table('lancamentos_contabeis')->where('empresa_id', $e->id)->count();
            if ($linhas) {
                throw new ErroNegocio("Não é possível alterar a moeda funcional: a empresa já tem {$linhas} linha(s) de lançamento em ".($e->moeda_funcional ?: 'AOA').'.',
                    'MOEDA_FUNCIONAL_COM_MOVIMENTOS', 422);
            }
        }
        if (array_key_exists('e_consolidacao', $d) && (bool) $d['e_consolidacao'] !== (bool) $e->e_consolidacao) {
            if ($d['e_consolidacao']) {
                if (DB::table('lancamentos_contabeis')->where('empresa_id', $e->id)->exists() || DB::table('vendas')->where('empresa_id', $e->id)->exists()) {
                    throw new ErroNegocio('Uma empresa com movimentos não pode passar a holding de consolidação: crie uma empresa própria para a holding.',
                        'HOLDING_COM_MOVIMENTOS', 422);
                }
            } elseif (DB::table('execucoes_consolidacao')->where('empresa_holding_id', $e->id)->exists()
                || DB::table('grupos_consolidacao')->where('empresa_holding_id', $e->id)->exists()) {
                throw new ErroNegocio('Esta holding já tem grupos ou execuções de consolidação: não pode deixar de ser holding.', 'HOLDING_COM_CONSOLIDACOES', 422);
            }
        }
    }

    public static function validarLogotipo(string $logotipo): void
    {
        if (! preg_match('#^data:image/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=\s]+)$#', $logotipo, $m)) {
            throw new ErroNegocio('O logótipo tem de ser uma imagem PNG, JPEG, GIF ou WEBP em base64 (data URI).', 'LOGOTIPO_INVALIDO', 422);
        }
        $binario = base64_decode($m[2], true);
        if ($binario === false || strlen($binario) > self::TAMANHO_MAXIMO_LOGOTIPO) {
            throw new ErroNegocio('O logótipo é inválido ou tem mais de 1 MB.', 'LOGOTIPO_INVALIDO', 422);
        }
    }

    /** @param  array<string, mixed>  $d */
    private function dados(array $d): array
    {
        $dados = array_intersect_key($d, array_flip(array_merge(self::CAMPOS, ['moeda_funcional', 'e_consolidacao', 'moeda_consolidacao'])));
        foreach (['nome', 'nif'] as $c) {
            if (isset($dados[$c])) {
                $dados[$c] = trim((string) $dados[$c]);
            }
        }
        if (array_key_exists('logotipo', $d)) {
            $dados['logotipo'] = $d['logotipo'] !== '' ? $d['logotipo'] : null;
        }

        return $dados;
    }
}
