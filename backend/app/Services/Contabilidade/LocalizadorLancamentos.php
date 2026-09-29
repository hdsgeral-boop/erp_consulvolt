<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\DiarioContabil;
use App\Models\LancamentoContabil;

/**
 * Localiza o lançamento activo de um documento de um módulo, para estorno (ADR-029/031).
 * Documentos novos guardam o n.º do lançamento; os do legado só se ligavam pelo n.º do documento, que o legado
 * reutilizava entre documentos (FT/FR "FA", números externos de facturas e guias de fornecedor, pagamentos da
 * Tesouraria com o mesmo n.º). Nesses casos só serve um lançamento que movimente a conta indicada no sentido
 * indicado (cliente a débito, fornecedor a crédito…); se ainda houver ambiguidade, recusa — nunca adivinha.
 */
final class LocalizadorLancamentos
{
    public function localizar(?string $numeroLan, string $numeroDocumento, ?string $conta = null, string $dc = 'D', ?string $diarioCodigo = null): LancamentoContabil
    {
        $q = LancamentoContabil::query()->whereNull('estorno_de_id')->whereNull('estornado_por_id');
        if ($numeroLan) {
            return (clone $q)->where('numero_lan', $numeroLan)->orderBy('id')->first()
                ?? throw new ErroNegocio("Lançamento {$numeroLan} não encontrado ou já estornado.", 'LANCAMENTO_NAO_ENCONTRADO', 422);
        }
        $base = (clone $q)->where('numero_documento', $numeroDocumento)
            ->when($diarioCodigo, fn ($x) => $x->whereIn('diario_id', DiarioContabil::query()->where('codigo', $diarioCodigo)->select('id')));
        $chaves = (clone $base)->selectRaw(LancamentoContabil::chaveSql().' as chave, diario_id')->distinct()->get();
        if ($conta && $chaves->count() > 1) {
            $chaves = $chaves->filter(fn ($c) => (clone $base)->where('diario_id', $c->diario_id)->whereRaw(LancamentoContabil::chaveSql().' = ?', [$c->chave])
                ->where('codigo_conta', $conta)->where('tipo_dc', $dc)->exists())->values();
        }
        if ($chaves->count() !== 1) {
            throw new ErroNegocio($chaves->isEmpty()
                ? "Não foi encontrado o lançamento do documento {$numeroDocumento}. Verifique na Contabilidade."
                : "O documento {$numeroDocumento} corresponde a vários lançamentos: estorne-o na Contabilidade.",
                'LANCAMENTO_NAO_ENCONTRADO', 422, ['lancamentos' => $chaves->pluck('chave')->all()]);
        }

        return (clone $base)->where('diario_id', $chaves[0]->diario_id)->whereRaw(LancamentoContabil::chaveSql().' = ?', [$chaves[0]->chave])->orderBy('id')->firstOrFail();
    }

    public function diario(string $codigo, string $nome): DiarioContabil
    {
        return DiarioContabil::query()->firstOrCreate(['codigo' => $codigo], ['nome' => $nome, 'descricao' => "Diário de {$nome} (criado automaticamente)"]);
    }
}
