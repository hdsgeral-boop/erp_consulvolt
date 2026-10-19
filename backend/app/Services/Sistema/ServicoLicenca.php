<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\SubscricaoEmpresa;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Licença por empresa e cobrança por plano e tempo (ADR-074).
 *
 * Fluxo: a empresa pede uma renovação (plano + meses) → subscrição PENDENTE com referência de pagamento e valor →
 * um super-administrador confirma o pagamento → a licença é prolongada (começa no dia a seguir ao fim actual, ou hoje se
 * já expirou; sem tolerância nem dias perdidos) e a empresa passa a PLANO. Não há gateway de pagamentos: a confirmação
 * é manual até se decidir o gateway.
 */
final class ServicoLicenca
{
    /** Dias antes do fim em que o aviso aparece. */
    public const DIAS_AVISO = 7;

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** @return array<string, mixed> */
    public function estado(): array
    {
        $empresa = Empresa::query()->findOrFail($this->contexto->obrigatorio());
        $ativas = (bool) config('erp.licencas.ativo');
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
            'dias_teste' => (int) config('erp.licencas.dias_teste', 7),
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

    public function pedirRenovacao(int $planoId, int $meses, ?Utilizador $utilizador): array
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

        $s = SubscricaoEmpresa::query()->create([
            'empresa_id' => $empresa->id, 'plano_id' => $plano->id, 'meses' => $meses,
            'valor' => round((float) $plano->preco_mensal * $meses, 2), 'moeda' => $plano->moeda,
            'estado' => SubscricaoEmpresa::PENDENTE, 'referencia' => $this->novaReferencia($empresa->id),
            'criado_por' => $utilizador?->getKey(),
        ]);

        return $this->subscricao($s->load('plano:id,nome,codigo'));
    }

    /** Confirma o pagamento e prolonga a licença. Só super-administradores (a autorização é do controlador). */
    public function confirmarPagamento(int $id, ?string $metodo, ?string $notas, Utilizador $utilizador): array
    {
        return DB::transaction(function () use ($id, $metodo, $notas, $utilizador) {
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

            return $this->subscricao($s->load('plano:id,nome,codigo'));
        });
    }

    public function cancelar(int $id): array
    {
        $s = SubscricaoEmpresa::query()->findOrFail($id);
        if ($s->estado !== SubscricaoEmpresa::PENDENTE) {
            throw new ErroNegocio('Só se pode cancelar um pedido pendente.', 'SUBSCRICAO_NAO_PENDENTE', 422);
        }
        $s->forceFill(['estado' => SubscricaoEmpresa::CANCELADO])->save();

        return $this->subscricao($s->load('plano:id,nome,codigo'));
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
        ];
    }

    /** @return array<string, mixed> */
    private function subscricao(SubscricaoEmpresa $s): array
    {
        return [
            'id' => $s->id, 'plano' => $s->plano ? ['id' => $s->plano->id, 'codigo' => $s->plano->codigo, 'nome' => $s->plano->nome] : null,
            'meses' => $s->meses, 'valor' => (float) $s->valor, 'moeda' => $s->moeda, 'estado' => $s->estado, 'referencia' => $s->referencia,
            'periodo_inicio' => $s->periodo_inicio?->toDateString(), 'periodo_fim' => $s->periodo_fim?->toDateString(),
            'pago_em' => $s->pago_em instanceof Carbon ? $s->pago_em->toIso8601String() : null, 'metodo' => $s->metodo, 'notas' => $s->notas,
            'criado_em' => $s->criado_em instanceof Carbon ? $s->criado_em->toIso8601String() : null,
        ];
    }
}
