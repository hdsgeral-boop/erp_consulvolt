<?php

namespace App\Models\Base;

use App\Models\AusenciaFaltaColaborador;
use App\Models\AutoavaliacaoColaborador;
use App\Models\AvaliacaoDesempenhoRH;
use App\Models\BonificacaoAvaliacaoRH;
use App\Models\CargoFuncao;
use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ConfirmacaoAvaliacaoRH;
use App\Models\ContratoTrabalho;
use App\Models\CoordenadaBancariaColaborador;
use App\Models\CriterioAvaliacaoRH;
use App\Models\DependenteColaborador;
use App\Models\EfectividadeAssiduidade;
use App\Models\FeedbackAvaliacao360;
use App\Models\FolhaHorasProjeto;
use App\Models\HabilitacaoColaborador;
use App\Models\ItemCartaPagamento;
use App\Models\LinhaFolhaSalarial;
use App\Models\LinhaRevisaoProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\ModeloBase;
use App\Models\ParticipacaoAscendenteRH;
use App\Models\ParticipanteAvaliacao360;
use App\Models\PedidoCompra;
use App\Models\PedidoLavandaria;
use App\Models\PedidoPortalColaborador;
use App\Models\PlanoFeriasColaborador;
use App\Models\PostoTrabalho;
use App\Models\RegistoProdutividadeRH;
use App\Models\RespostaAscendenteRH;
use App\Models\RespostaAvaliacao360;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeNegocio;
use App\Models\UnidadeOrganica;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela colaboradores (módulo RH). Legado: employees · 133 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\Colaborador.
 */
abstract class ColaboradorBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'colaboradores';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'nome_completo', 'nif', 'numero_inss', 'cargo_funcao_id', 'tipo_organizacao_id', 'estado', 'estado_original', 'dias_uteis_mes', 'reformado', 'unidade_negocio_id', 'centro_custo_id', 'avencado', 'sexo', 'data_nascimento', 'estado_civil', 'estado_civil_original', 'nacionalidade', 'naturalidade', 'provincia_naturalidade', 'documento_identificacao', 'documento_validade', 'data_admissao', 'endereco', 'bairro', 'municipio', 'provincia', 'telefone', 'telefone_alternativo', 'email', 'emergencia_nome', 'emergencia_telefone', 'emergencia_parentesco', 'habilitacao_maxima', 'unidade_organica_id', 'posto_trabalho_id', 'colaborador_gestor_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'cargo_funcao_id' => 'integer',
            'tipo_organizacao_id' => 'integer',
            'dias_uteis_mes' => 'integer',
            'reformado' => 'boolean',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'avencado' => 'boolean',
            'data_nascimento' => 'date',
            'documento_validade' => 'date',
            'data_admissao' => 'date',
            'unidade_organica_id' => 'integer',
            'posto_trabalho_id' => 'integer',
            'colaborador_gestor_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function cargoFuncao(): BelongsTo
    {
        return $this->belongsTo(CargoFuncao::class, 'cargo_funcao_id');
    }

    public function tipoOrganizacao(): BelongsTo
    {
        return $this->belongsTo(TipoOrganizacaoRH::class, 'tipo_organizacao_id');
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function unidadeOrganica(): BelongsTo
    {
        return $this->belongsTo(UnidadeOrganica::class, 'unidade_organica_id');
    }

    public function postoTrabalho(): BelongsTo
    {
        return $this->belongsTo(PostoTrabalho::class, 'posto_trabalho_id');
    }

    public function colaboradorGestor(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_gestor_id');
    }

    public function unidadesNegocio(): HasMany
    {
        return $this->hasMany(UnidadeNegocio::class, 'colaborador_gestor_id');
    }

    public function pedidosCompra(): HasMany
    {
        return $this->hasMany(PedidoCompra::class, 'colaborador_requerente_id');
    }

    public function colaboradoresPorColaboradorGestor(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'colaborador_gestor_id');
    }

    public function contratosTrabalho(): HasMany
    {
        return $this->hasMany(ContratoTrabalho::class, 'colaborador_id');
    }

    public function linhasFolhaSalarial(): HasMany
    {
        return $this->hasMany(LinhaFolhaSalarial::class, 'colaborador_id');
    }

    public function coordenadasBancariasColaboradores(): HasMany
    {
        return $this->hasMany(CoordenadaBancariaColaborador::class, 'colaborador_id');
    }

    public function itensCartaPagamento(): HasMany
    {
        return $this->hasMany(ItemCartaPagamento::class, 'colaborador_id');
    }

    public function dependentesColaboradores(): HasMany
    {
        return $this->hasMany(DependenteColaborador::class, 'colaborador_id');
    }

    public function habilitacoesColaboradores(): HasMany
    {
        return $this->hasMany(HabilitacaoColaborador::class, 'colaborador_id');
    }

    public function planoFeriasColaboradores(): HasMany
    {
        return $this->hasMany(PlanoFeriasColaborador::class, 'colaborador_id');
    }

    public function ausenciasFaltasColaboradores(): HasMany
    {
        return $this->hasMany(AusenciaFaltaColaborador::class, 'colaborador_id');
    }

    public function efectividadeAssiduidade(): HasMany
    {
        return $this->hasMany(EfectividadeAssiduidade::class, 'colaborador_id');
    }

    public function registosProdutividadeRh(): HasMany
    {
        return $this->hasMany(RegistoProdutividadeRH::class, 'colaborador_id');
    }

    public function avaliacoesDesempenhoRh(): HasMany
    {
        return $this->hasMany(AvaliacaoDesempenhoRH::class, 'colaborador_id');
    }

    public function criteriosAvaliacaoRh(): HasMany
    {
        return $this->hasMany(CriterioAvaliacaoRH::class, 'colaborador_id');
    }

    public function participantesAvaliacao360PorColaboradorAvaliador(): HasMany
    {
        return $this->hasMany(ParticipanteAvaliacao360::class, 'colaborador_avaliador_id');
    }

    public function participantesAvaliacao360PorColaboradorAvaliado(): HasMany
    {
        return $this->hasMany(ParticipanteAvaliacao360::class, 'colaborador_avaliado_id');
    }

    public function respostasAvaliacao360(): HasMany
    {
        return $this->hasMany(RespostaAvaliacao360::class, 'colaborador_avaliado_id');
    }

    public function feedbacksAvaliacao360PorColaborador(): HasMany
    {
        return $this->hasMany(FeedbackAvaliacao360::class, 'colaborador_id');
    }

    public function feedbacksAvaliacao360PorColaboradorChefia(): HasMany
    {
        return $this->hasMany(FeedbackAvaliacao360::class, 'colaborador_chefia_id');
    }

    public function bonificacoesAvaliacaoRh(): HasMany
    {
        return $this->hasMany(BonificacaoAvaliacaoRH::class, 'colaborador_id');
    }

    public function confirmacoesAvaliacaoRh(): HasMany
    {
        return $this->hasMany(ConfirmacaoAvaliacaoRH::class, 'colaborador_id');
    }

    public function pedidosPortalColaborador(): HasMany
    {
        return $this->hasMany(PedidoPortalColaborador::class, 'colaborador_id');
    }

    public function autoavaliacoesColaborador(): HasMany
    {
        return $this->hasMany(AutoavaliacaoColaborador::class, 'colaborador_id');
    }

    public function participacoesAscendentesRhPorColaborador(): HasMany
    {
        return $this->hasMany(ParticipacaoAscendenteRH::class, 'colaborador_id');
    }

    public function participacoesAscendentesRhPorColaboradorAlvo(): HasMany
    {
        return $this->hasMany(ParticipacaoAscendenteRH::class, 'colaborador_alvo_id');
    }

    public function respostasAscendentesRh(): HasMany
    {
        return $this->hasMany(RespostaAscendenteRH::class, 'colaborador_alvo_id');
    }

    public function unidadesOrganicas(): HasMany
    {
        return $this->hasMany(UnidadeOrganica::class, 'colaborador_responsavel_id');
    }

    public function pedidosLavandaria(): HasMany
    {
        return $this->hasMany(PedidoLavandaria::class, 'colaborador_atribuido_id');
    }

    public function membrosEquipaProjeto(): HasMany
    {
        return $this->hasMany(MembroEquipaProjeto::class, 'colaborador_id');
    }

    public function folhasHorasProjeto(): HasMany
    {
        return $this->hasMany(FolhaHorasProjeto::class, 'colaborador_id');
    }

    public function linhasRevisaoProjeto(): HasMany
    {
        return $this->hasMany(LinhaRevisaoProjeto::class, 'colaborador_id');
    }
}
