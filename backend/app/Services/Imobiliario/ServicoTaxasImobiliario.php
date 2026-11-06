<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\TaxaImobiliario;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Taxas fiscais do módulo imobiliário, por empresa e ano (imo_settings.taxas[ano] do legado). São dados configuráveis,
 * marcados «por validar»: o ERP traz os valores de arranque do legado (fabrica.json) mas NÃO afirma a sua conformidade legal;
 * quem confirma é o contabilista ou o jurista, fora do sistema, e aqui só fica registado quem e quando. Correcções: o ano
 * herda o último ano gravado (o legado só voltava aos valores base), qualquer alteração volta a marcar «por validar» e há
 * selector de ano (o legado só editava o corrente).
 */
final class ServicoTaxasImobiliario
{
    /** Percentagens (0 a 100); as restantes são valores em Kz, dias ou meses (≥ 0). */
    public const PERCENTAGENS = ['ip_taxa', 'ip_base', 'selo_habitacao', 'selo_outro', 'iva_arrendamento', 'iva_alojamento', 'mora_mensal', 'ip_transmissao',
        'selo_transmissao', 'det_escalao1_pct', 'det_escalao3_pct', 'det_terreno_pct', 'det_agravamento_pct'];

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * As taxas de um ano: gravadas, herdadas do último ano anterior gravado ou os valores de arranque.
     *
     * @return array<string, mixed>
     */
    public function doAno(int $ano): array
    {
        $this->conferirAno($ano);
        $linha = TaxaImobiliario::query()->where('ano', $ano)->first();
        $origem = 'GRAVADA';
        $herdadaDe = null;
        if ($linha === null) {
            $linha = TaxaImobiliario::query()->where('ano', '<', $ano)->orderByDesc('ano')->first();
            $origem = $linha ? 'HERDADA' : 'BASE';
            $herdadaDe = $linha?->ano;
        }
        $valores = $linha ? $linha->only(TaxaImobiliario::CAMPOS) : CatalogoImobiliario::taxasBase();
        $gravada = $origem === 'GRAVADA';

        return ['ano' => $ano, 'origem' => $origem, 'herdada_de' => $herdadaDe, 'taxas' => array_map('floatval', $valores),
            'ip_efectivo' => $this->ipEfectivo((float) $valores['ip_taxa'], (float) $valores['ip_base']),
            'estado_validacao' => $gravada ? $linha->estado_validacao : 'POR_VALIDAR', 'validada_por' => $gravada ? $linha->validada_por : null,
            'validada_em' => $gravada ? $linha->validada_em?->toIso8601String() : null, 'validacao_nota' => $gravada ? $linha->validacao_nota : null,
            'aviso' => 'Valores de arranque e editáveis: a validar pelo contabilista ou jurista. O ERP não confirma a sua conformidade legal.'];
    }

    /** @return list<int> anos com taxas gravadas */
    public function anos(): array
    {
        return TaxaImobiliario::query()->orderByDesc('ano')->pluck('ano')->map(fn ($a) => (int) $a)->all();
    }

    /**
     * Grava (cria ou substitui) as taxas do ano. As chaves em falta ficam com o valor que o ano já tem (herdado ou de arranque).
     *
     * @param  array<string, mixed>  $valores
     * @return array<string, mixed>
     */
    public function gravar(int $ano, array $valores): array
    {
        $actuais = $this->doAno($ano)['taxas'];
        $novos = $actuais;
        foreach (TaxaImobiliario::CAMPOS as $c) {
            if (array_key_exists($c, $valores) && $valores[$c] !== null && $valores[$c] !== '') {
                if (! is_numeric($valores[$c])) {
                    throw new ErroNegocio('Todas as taxas têm de ser números.', 'TAXA_INVALIDA');
                }
                $novos[$c] = round((float) $valores[$c], 4);
            }
        }
        foreach ($novos as $c => $v) {
            if ($v < 0) {
                throw new ErroNegocio('As taxas e os valores não podem ser negativos.', 'TAXA_NEGATIVA');
            }
            if (in_array($c, self::PERCENTAGENS, true) && $v > 100) {
                throw new ErroNegocio($c === 'ip_taxa' || $c === 'ip_base' ? 'As percentagens do Imposto Predial não podem passar de 100%.' : 'As percentagens não podem passar de 100%.', 'TAXA_PERCENTAGEM');
            }
        }
        DB::transaction(function () use ($ano, $novos) {
            $linha = TaxaImobiliario::query()->where('ano', $ano)->lockForUpdate()->first();
            $dados = $novos + ['estado_validacao' => 'POR_VALIDAR', 'validada_por' => null, 'validada_em' => null, 'validacao_nota' => null, 'atualizado_por' => $this->utilizador()];
            if ($linha) {
                $linha->update($dados);
            } else {
                TaxaImobiliario::query()->create($dados + ['empresa_id' => $this->contexto->id(), 'ano' => $ano, 'criado_por' => $this->utilizador()]);
            }
        });

        return $this->doAno($ano);
    }

    /**
     * Regista que as taxas gravadas do ano foram confirmadas por um contabilista ou jurista (a confirmação é feita fora do ERP).
     *
     * @return array<string, mixed>
     */
    public function validar(int $ano, string $nota): array
    {
        $this->conferirAno($ano);
        $nota = trim($nota);
        if (mb_strlen($nota) < 3) {
            throw new ErroNegocio('Indique quem confirmou as taxas (nome e função) para ficar registado.', 'TAXA_VALIDACAO_NOTA');
        }
        $linha = TaxaImobiliario::query()->where('ano', $ano)->first();
        if ($linha === null) {
            throw new ErroNegocio('Grave primeiro as taxas deste ano.', 'TAXA_SEM_REGISTO');
        }
        $linha->update(['estado_validacao' => 'VALIDADA', 'validada_por' => $this->utilizador(), 'validada_em' => now(), 'validacao_nota' => mb_substr($nota, 0, 300),
            'atualizado_por' => $this->utilizador()]);

        return $this->doAno($ano);
    }

    /** Taxa efectiva do Imposto Predial sobre a renda: taxa × base ÷ 100 (25 × 60 ÷ 100 = 15). Em bcmath, sem float. */
    public function ipEfectivo(float $taxa, float $base): float
    {
        return (float) bcdiv(bcmul(number_format($taxa, 4, '.', ''), number_format($base, 4, '.', ''), 8), '100', 4);
    }

    private function conferirAno(int $ano): void
    {
        if ($ano < 2000 || $ano > 2100) {
            throw new ErroNegocio('Indique um ano entre 2000 e 2100.', 'TAXA_ANO');
        }
    }

    private function utilizador(): string
    {
        return mb_substr((string) (Auth::user()?->nome ?? 'sistema'), 0, 100);
    }
}
