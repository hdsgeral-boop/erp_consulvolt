<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\SubscricaoEmpresa;
use App\Models\Utilizador;
use App\Services\Pagamentos\ServicoCupoes;
use App\Services\Plataforma\ServicoFaturacaoSaas;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DefinicoesPlataforma;
use App\Support\Tenancy\ModulosPlano;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Licença por empresa e cobrança por plano e tempo (ADR-074).
 *
 * Fluxo: a empresa pede uma renovação (plano + meses) → subscrição PENDENTE com referência de pagamento e valor →
 * o pagamento é confirmado (manualmente por um super-administrador, ou automaticamente pelo gateway angolano —
 * Referência/Multicaixa Express, ver GatewayPagamentoController/ProcessadorPagamentos) → a licença é prolongada
 * (começa no dia a seguir ao fim actual, ou hoje se já expirou; sem tolerância nem dias perdidos) e a empresa passa a PLANO.
 */
final class ServicoLicenca
{
    /** Dias antes do fim em que o aviso aparece. */
    public const DIAS_AVISO = 7;

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly CalculadoraPrecoPlano $precos,
        private readonly ContextoTenant $tenant,
        private readonly ServicoCupoes $cupoes,
        private readonly ServicoFaturacaoSaas $faturacaoSaas,
    ) {}

    /** @return array<string, mixed> */
    public function estado(): array
    {
        $empresa = Empresa::query()->findOrFail($this->contexto->obrigatorio());
        $ativas = DefinicoesPlataforma::licencasAtivas();
        $fim = $empresa->licenca_fim;
        $expirada = $empresa->licencaExpirada();
        $dias = $fim === null ? null : (int) now()->startOfDay()->diffInDays($fim->copy()->startOfDay(), false);

        $aviso = null;
        if ($ativas && $fim !== null) {
            $aviso = $expirada ? 'EXPIRADA' : ($dias <= self::DIAS_AVISO ? 'A_EXPIRAR' : null);
        }

        $ultima = SubscricaoEmpresa::query()->where('estado', SubscricaoEmpresa::PAGO)->with('plano:id,nome,codigo')->orderByDesc('pago_em')->first();

        return [
            'licencas_ativas' => $ativas,
            'origem' => $empresa->licenca_origem,
            'inicio' => $empresa->licenca_inicio?->toDateString(),
            'fim' => $fim?->toDateString(),
            'dias_restantes' => $dias,
            'expirada' => $expirada,
            'somente_leitura' => $ativas && $expirada,
            'aviso' => $aviso,
            'plano' => $ultima?->plano ? ['id' => $ultima->plano->id, 'codigo' => $ultima->plano->codigo, 'nome' => $ultima->plano->nome] : null,
            'pendentes' => SubscricaoEmpresa::query()->where('estado', SubscricaoEmpresa::PENDENTE)->count(),
            'dias_teste' => DefinicoesPlataforma::diasTestePadrao(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function planos(bool $incluirInativos = false): array
    {
        return PlanoLicenca::query()->when(! $incluirInativos, fn ($q) => $q->where('ativo', true))
            ->orderBy('ordem')->orderBy('preco_mensal')->get()->map(fn (PlanoLicenca $p) => $this->plano($p))->all();
    }

    /** @param  array<string, mixed>  $dados */
    public function gravarPlano(?PlanoLicenca $plano, array $dados): array
    {
        $plano ??= new PlanoLicenca;
        $plano->fill($dados)->save();

        return $this->plano($plano->refresh());
    }

    /** @return list<array<string, mixed>> */
    public function subscricoes(): array
    {
        return SubscricaoEmpresa::query()->with('plano:id,nome,codigo')->orderByDesc('id')->limit(200)->get()
            ->map(fn (SubscricaoEmpresa $s) => $this->subscricao($s))->all();
    }

    public function pedirRenovacao(int $planoId, int $meses, ?Utilizador $utilizador, ?string $cupomCodigo = null): array
    {
        $empresa = Empresa::query()->findOrFail($this->contexto->obrigatorio());
        if ($empresa->licenca_origem === Empresa::LICENCA_SEM_LIMITE && $empresa->licenca_fim === null) {
            throw new ErroNegocio('Esta empresa tem licença sem limite: não precisa de renovação.', 'LICENCA_SEM_LIMITE', 422);
        }
        $plano = PlanoLicenca::query()->where('ativo', true)->find($planoId);
        if ($plano === null) {
            throw new ErroNegocio('O plano indicado não existe ou está inactivo.', 'PLANO_INEXISTENTE', 422);
        }
        if ($meses < 1 || $meses > 36) {
            throw new ErroNegocio('O período tem de ser de 1 a 36 meses.', 'PERIODO_INVALIDO', 422);
        }

        $calculo = $this->precos->calcularPorMeses((float) $plano->preco_mensal, $meses);
        $tenantId = $this->tenant->obrigatorio();

        $s = DB::transaction(function () use ($empresa, $plano, $meses, $calculo, $utilizador, $cupomCodigo, $tenantId) {
            $s = SubscricaoEmpresa::query()->create([
                'empresa_id' => $empresa->id, 'plano_id' => $plano->id, 'meses' => $meses,
                'ciclo_codigo' => $calculo['ciclo_codigo'],
                'valor_bruto' => $calculo['valor_bruto'],
                'desconto_ciclo_percentagem' => $calculo['desconto_percentagem'],
                'valor_desconto_ciclo' => $calculo['valor_desconto_ciclo'],
                'valor_apos_ciclo' => $calculo['valor_apos_ciclo'],
                'valor' => $calculo['valor_apos_ciclo'], 'moeda' => $plano->moeda,
                'estado' => SubscricaoEmpresa::PENDENTE, 'referencia' => $this->novaReferencia($empresa->id),
                'criado_por' => $utilizador?->getKey(),
            ]);

            $cupom = $this->cupoes->aplicarNaTransacao(
                $cupomCodigo, $plano, $calculo['ciclo_codigo'], (float) $calculo['valor_apos_ciclo'],
                $tenantId, $empresa->id, $s->id,
            );
            if ($cupom['cupom_id'] !== null) {
                $valorFinal = round((float) $calculo['valor_apos_ciclo'] - $cupom['valor_desconto_cupom'], 2);
                $s->forceFill([
                    'cupom_id' => $cupom['cupom_id'], 'cupom_codigo' => $cupom['cupom_codigo'],
                    'desconto_cupom_tipo' => $cupom['desconto_cupom_tipo'], 'desconto_cupom_valor' => $cupom['desconto_cupom_valor'],
                    'valor_desconto_cupom' => $cupom['valor_desconto_cupom'], 'valor' => $valorFinal,
                ])->save();
            }

            return $s;
        });

        return $this->subscricao($s->load('plano:id,nome,codigo'));
    }

    /** Pré-visualização de cupão para o checkout — sem efeitos secundários (botão "Aplicar"). */
    public function validarCupom(string $codigo, int $planoId, string $cicloCodigo): array
    {
        $plano = PlanoLicenca::query()->where('ativo', true)->findOrFail($planoId);
        $calculo = $this->precos->calcular((float) $plano->preco_mensal, $cicloCodigo);

        return $this->cupoes->preVisualizar($codigo, $planoId, $cicloCodigo, (float) $calculo['valor_apos_ciclo'], $this->tenant->obrigatorio());
    }

    /** Confirma o pagamento e prolonga a licença. Só super-administradores (a autorização é do controlador). */
    public function confirmarPagamento(int $id, ?string $metodo, ?string $notas, Utilizador $utilizador): array
    {
        $s = DB::transaction(function () use ($id, $metodo, $notas, $utilizador) {
            $s = SubscricaoEmpresa::query()->lockForUpdate()->findOrFail($id);
            if ($s->estado !== SubscricaoEmpresa::PENDENTE) {
                throw new ErroNegocio('Só se pode confirmar o pagamento de um pedido pendente.', 'SUBSCRICAO_NAO_PENDENTE', 422);
            }

            $empresa = Empresa::query()->lockForUpdate()->findOrFail($s->empresa_id);
            $hoje = now()->startOfDay();
            $inicio = ($empresa->licenca_fim !== null && $empresa->licenca_fim->gte($hoje)) ? $empresa->licenca_fim->copy()->addDay()->startOfDay() : $hoje->copy();
            $fim = $inicio->copy()->addMonthsNoOverflow($s->meses)->subDay();

            $s->forceFill([
                'estado' => SubscricaoEmpresa::PAGO, 'periodo_inicio' => $inicio->toDateString(), 'periodo_fim' => $fim->toDateString(),
                'pago_em' => now(), 'pago_por' => $utilizador->getKey(), 'metodo' => $metodo, 'notas' => $notas,
            ])->save();

            $empresa->forceFill([
                'licenca_origem' => Empresa::LICENCA_PLANO,
                'licenca_inicio' => $empresa->licenca_origem === Empresa::LICENCA_PLANO && $empresa->licenca_inicio ? $empresa->licenca_inicio->toDateString() : $inicio->toDateString(),
                'licenca_fim' => $fim->toDateString(),
            ])->save();

            return $s;
        });

        // Fora da transacção do pagamento: uma falha na emissão da factura (AGT em baixo, etc.) nunca desfaz um
        // pagamento já confirmado (ServicoFaturacaoSaas nunca lança para fora — fica em log para reprocessar).
        $this->faturacaoSaas->emitirParaSubscricaoPaga($s);

        return $this->subscricao($s->load('plano:id,nome,codigo'));
    }

    public function cancelar(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $s = SubscricaoEmpresa::query()->lockForUpdate()->findOrFail($id);
            if ($s->estado !== SubscricaoEmpresa::PENDENTE) {
                throw new ErroNegocio('Só se pode cancelar um pedido pendente.', 'SUBSCRICAO_NAO_PENDENTE', 422);
            }
            $this->cupoes->libertar($s);
            $s->forceFill(['estado' => SubscricaoEmpresa::CANCELADO])->save();

            return $this->subscricao($s->load('plano:id,nome,codigo'));
        });
    }

    private function novaReferencia(int $empresaId): string
    {
        return 'LIC-'.now()->format('ymd').'-'.$empresaId.'-'.Str::upper(Str::random(5));
    }

    /** @return array<string, mixed> */
    private function plano(PlanoLicenca $p): array
    {
        return [
            'id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'descricao' => $p->descricao,
            'preco_mensal' => (float) $p->preco_mensal, 'moeda' => $p->moeda, 'max_utilizadores' => $p->max_utilizadores, 'max_empresas' => $p->max_empresas,
            'ativo' => $p->ativo, 'ordem' => $p->ordem,
            // Módulos da versão publicada em vigor (ModulosPlano, Fase 1 — os mesmos que bloqueiam o menu e a API):
            // a landing page mostra sempre a verdade do que cada plano inclui, nunca uma lista à parte a divergir.
            'modulos' => ModulosPlano::doTenant($p->codigo) ?? [],
            // Matriz pré-calculada dos ciclos (Mensal/Trimestral/Semestral/Anual): o checkout troca de preço
            // instantaneamente sem pedido ao servidor — o valor autoritário só é fixado em pedirRenovacao().
            'ciclos' => $this->precos->matriz((float) $p->preco_mensal),
        ];
    }

    /** @return array<string, mixed> */
    private function subscricao(SubscricaoEmpresa $s): array
    {
        return [
            'id' => $s->id, 'plano' => $s->plano ? ['id' => $s->plano->id, 'codigo' => $s->plano->codigo, 'nome' => $s->plano->nome] : null,
            'meses' => $s->meses, 'valor' => (float) $s->valor, 'moeda' => $s->moeda, 'estado' => $s->estado, 'referencia' => $s->referencia,
            'ciclo_codigo' => $s->ciclo_codigo, 'valor_bruto' => (float) $s->valor_bruto,
            'desconto_ciclo_percentagem' => (float) $s->desconto_ciclo_percentagem, 'valor_desconto_ciclo' => (float) $s->valor_desconto_ciclo,
            'valor_apos_ciclo' => (float) $s->valor_apos_ciclo,
            'cupom_codigo' => $s->cupom_codigo, 'desconto_cupom_tipo' => $s->desconto_cupom_tipo,
            'desconto_cupom_valor' => $s->desconto_cupom_valor !== null ? (float) $s->desconto_cupom_valor : null,
            'valor_desconto_cupom' => (float) $s->valor_desconto_cupom, 'venda_id' => $s->venda_id,
            'periodo_inicio' => $s->periodo_inicio?->toDateString(), 'periodo_fim' => $s->periodo_fim?->toDateString(),
            'pago_em' => $s->pago_em instanceof Carbon ? $s->pago_em->toIso8601String() : null, 'metodo' => $s->metodo, 'notas' => $s->notas,
            'criado_em' => $s->criado_em instanceof Carbon ? $s->criado_em->toIso8601String() : null,
        ];
    }
}
