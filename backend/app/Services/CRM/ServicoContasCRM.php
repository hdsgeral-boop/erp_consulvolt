<?php

namespace App\Services\CRM;

use App\Exceptions\ErroNegocio;
use App\Models\AtividadeComercialCRM;
use App\Models\ContaCRM;
use App\Models\ContactoCRM;
use App\Models\OportunidadeVendaCRM;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Terceiros\ServicoTerceiros;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Contas (prospects e clientes), contactos, ficha 360º e passagem de prospect a cliente (crm_dados.js:119-173, 275-309).
 * Paridade:
 *   - conta: nome obrigatório, NIF único entre as contas, email válido; nasce PROSPECT, ou CLIENTE se ligada a um terceiro;
 *   - conta de um cliente existente sem duplicar dados (uma por terceiro);
 *   - contacto: nome, email válido; só um contacto principal por conta;
 *   - prospect → cliente: a conta contabilística é escolhida pelo utilizador (nunca por omissão); se o NIF já existir nos
 *     terceiros, o terceiro existente ganha o papel de cliente (e fica com as contas/email/morada que lhe faltem);
 *   - ficha 360º: contactos, oportunidades, actividades, histórico financeiro e resumo (abertas, valor, ganhas, perdidas,
 *     taxa de ganho); facturas em atraso só avisam.
 * Correcções: o terceiro é criado pelo ServicoTerceiros (conta de movimento, NIF único, papéis combinados); a conta do
 * cliente tem de ser uma conta 31 de movimento (o legado só a restringia no ecrã); o saldo em aberto de cada factura é o
 * `valor_pendente` mantido por Vendas/Recibos/Tesouraria (o legado recalculava-o somando recibos e movimentos de
 * tesouraria pelo N.º do documento, que se repete); documentos anulados ficam de fora dos totais.
 */
final class ServicoContasCRM
{
    public function __construct(
        private readonly ServicoConfiguracaoCRM $config,
        private readonly ServicoTerceiros $terceiros,
    ) {}

    public function guardar(array $d, ?ContaCRM $conta = null): ContaCRM
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Indique o nome da empresa / pessoa.', 'NOME_OBRIGATORIO', 422);
        }
        $nif = trim((string) ($d['nif'] ?? ''));
        if ($nif !== '' && ($dup = ContaCRM::query()->where('nif', $nif)->when($conta, fn ($q) => $q->whereKeyNot($conta->id))->first())) {
            throw new ErroNegocio("Já existe a conta «{$dup->nome}» com o NIF {$nif}.", 'NIF_DUPLICADO', 422, ['conta_crm_id' => $dup->id]);
        }
        $email = trim((string) ($d['email'] ?? ''));
        if (! RegrasCRM::emailValido($email)) {
            throw new ErroNegocio('O email não é válido.', 'EMAIL_INVALIDO', 422);
        }
        $reg = ['nome' => $nome, 'nif' => $nif ?: null, 'setor' => trim((string) ($d['setor'] ?? '')) ?: null, 'email' => $email ?: null,
            'telefone' => trim((string) ($d['telefone'] ?? '')) ?: null, 'morada' => trim((string) ($d['morada'] ?? '')) ?: null,
            'website' => trim((string) ($d['website'] ?? '')) ?: null, 'responsavel' => ($d['responsavel'] ?? null) ?: ($conta?->responsavel ?? Auth::user()?->nome_utilizador),
            'notas' => trim((string) ($d['notas'] ?? '')) ?: null] + RegrasCRM::origem($d['origem'] ?? null);
        if ($conta) {
            $conta->update($reg);

            return $conta;
        }
        $terceiro = empty($d['terceiro_id']) ? null : Terceiro::query()->findOrFail($d['terceiro_id']);
        if ($terceiro && ContaCRM::query()->where('terceiro_id', $terceiro->id)->exists()) {
            throw new ErroNegocio('Este cliente já tem conta no CRM.', 'CLIENTE_COM_CONTA', 422);
        }

        return ContaCRM::create($reg + ['tipo' => $terceiro ? 'CLIENTE' : 'PROSPECT', 'terceiro_id' => $terceiro?->id, 'criado_por' => Auth::user()?->nome_utilizador]);
    }

    /** Conta do CRM de um cliente existente (cria-a com os dados do terceiro se ainda não houver). */
    public function contaDoCliente(int $terceiroId): ContaCRM
    {
        return DB::transaction(function () use ($terceiroId) {
            $t = Terceiro::query()->lockForUpdate()->findOrFail($terceiroId);
            if ($existente = ContaCRM::query()->where('terceiro_id', $t->id)->first()) {
                return $existente;
            }

            return ContaCRM::create(['tipo' => 'CLIENTE', 'terceiro_id' => $t->id, 'nome' => $t->nome, 'nif' => $t->nif ?: null, 'email' => $t->email ?: null,
                'telefone' => $t->telefone ?: null, 'morada' => $t->endereco ?: null, 'responsavel' => Auth::user()?->nome_utilizador,
                'criado_por' => Auth::user()?->nome_utilizador] + RegrasCRM::origem('Cliente existente'));
        });
    }

    public function guardarContacto(array $d, ContaCRM $conta, ?ContactoCRM $c = null): ContactoCRM
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Indique o nome do contacto.', 'NOME_OBRIGATORIO', 422);
        }
        $email = trim((string) ($d['email'] ?? ''));
        if (! RegrasCRM::emailValido($email)) {
            throw new ErroNegocio('O email do contacto não é válido.', 'EMAIL_INVALIDO', 422);
        }
        $reg = ['conta_crm_id' => $conta->id, 'nome' => $nome, 'cargo' => trim((string) ($d['cargo'] ?? '')) ?: null, 'email' => $email ?: null,
            'telefone' => trim((string) ($d['telefone'] ?? '')) ?: null, 'principal' => ! empty($d['principal'])];

        return DB::transaction(function () use ($reg, $conta, $c) {
            if ($reg['principal']) {
                ContactoCRM::query()->where('conta_crm_id', $conta->id)->when($c, fn ($q) => $q->whereKeyNot($c->id))->update(['principal' => false]);
            }
            $c ? $c->update($reg) : $c = ContactoCRM::create($reg);

            return $c;
        });
    }

    /** Prospect → cliente (terceiro). Devolve a conta actualizada. */
    public function converterEmCliente(ContaCRM $conta, string $codigoConta): ContaCRM
    {
        return DB::transaction(function () use ($conta, $codigoConta) {
            $conta = ContaCRM::query()->lockForUpdate()->findOrFail($conta->id);
            if ($conta->terceiro_id) {
                return $conta;
            }
            $codigoConta = trim($codigoConta);
            if ($codigoConta === '' || ! str_starts_with($codigoConta, '31')) {
                throw new ErroNegocio('Escolha a conta contabilística do cliente (conta 31 de movimento).', 'CONTA_CLIENTE_INVALIDA', 422);
            }
            $existente = $conta->nif ? Terceiro::query()->where('nif', $conta->nif)->first() : null;
            $t = $existente
                ? $this->terceiros->guardar(Terceiro::CLIENTE, ['codigo_conta' => $existente->codigo_conta ?: $codigoConta, 'email' => $existente->email ?: $conta->email,
                    'endereco' => $existente->endereco ?: $conta->morada], $existente)
                : $this->terceiros->guardar(Terceiro::CLIENTE, ['nome' => $conta->nome, 'nif' => $conta->nif, 'endereco' => $conta->morada, 'email' => $conta->email,
                    'telefone' => $conta->telefone, 'codigo_conta' => $codigoConta, 'codigo_moeda' => 'AOA']);
            $conta->update(['tipo' => 'CLIENTE', 'terceiro_id' => $t->id]);

            return $conta;
        });
    }

    /** Facturas do cliente com saldo, vencimento e dias de atraso; facturado a 12 meses; últimos documentos. */
    public function financeiro(?int $terceiroId): array
    {
        $vazio = ['facturas' => [], 'em_aberto' => '0.00', 'em_atraso' => '0.00', 'max_dias_atraso' => 0, 'n_atrasadas' => 0, 'facturado_12m' => '0.00', 'ultima_venda' => null, 'documentos' => []];
        if (! $terceiroId) {
            return $vazio;
        }
        $prazo = (int) $this->config->obter()['prazo_pagamento_dias'];
        $hoje = RegrasCRM::hoje();
        $vendas = Venda::query()->where('cliente_id', $terceiroId)->orderByDesc('data_emissao')->orderByDesc('id')
            ->get(['id', 'tipo_documento', 'numero_documento', 'data_emissao', 'total_bruto', 'valor_pago', 'valor_pendente', 'data_vencimento', 'valido_ate', 'estado', 'oportunidade_crm_id']);
        $validas = $vendas->where('estado', '!==', 'ANULADO');
        $facturas = $validas->where('tipo_documento', 'FT')->map(function ($v) use ($prazo, $hoje) {
            $saldo = bccomp((string) $v->valor_pendente, '0', 2) > 0 ? (string) $v->valor_pendente : '0.00';
            $venc = $v->data_vencimento?->toDateString() ?? $v->valido_ate?->toDateString() ?? RegrasCRM::somarDias($v->data_emissao->toDateString(), $prazo);
            $dias = bccomp($saldo, '0.01', 2) >= 0 ? max(0, RegrasCRM::diasEntre($venc, $hoje)) : 0;

            return ['id' => $v->id, 'doc' => $v->numero_documento, 'data' => $v->data_emissao->toDateString(), 'total' => (string) $v->total_bruto,
                'pago' => bcsub((string) $v->total_bruto, $saldo, 2), 'saldo' => $saldo, 'vencimento' => $venc, 'dias_atraso' => $dias];
        })->values();
        $abertas = $facturas->filter(fn ($f) => bccomp($f['saldo'], '0.01', 2) >= 0);
        $atrasadas = $abertas->filter(fn ($f) => $f['dias_atraso'] > 0);
        $soma = fn ($c, $k) => $c->reduce(fn ($s, $x) => bcadd($s, (string) (is_array($x) ? $x[$k] : $x->{$k}), 2), '0.00');
        $faturas = $validas->whereIn('tipo_documento', ['FT', 'FR']);
        $ha12 = RegrasCRM::somarDias($hoje, -365);

        return ['facturas' => $facturas->all(), 'em_aberto' => $soma($abertas, 'saldo'), 'em_atraso' => $soma($atrasadas, 'saldo'),
            'max_dias_atraso' => (int) $atrasadas->max('dias_atraso'), 'n_atrasadas' => $atrasadas->count(),
            'facturado_12m' => $soma($faturas->filter(fn ($v) => $v->data_emissao->toDateString() >= $ha12), 'total_bruto'),
            'ultima_venda' => $faturas->max(fn ($v) => $v->data_emissao->toDateString()),
            'documentos' => $vendas->take(30)->map(fn ($v) => ['id' => $v->id, 'tipo' => $v->tipo_documento, 'doc' => $v->numero_documento, 'data' => $v->data_emissao?->toDateString(),
                'total' => (string) $v->total_bruto, 'estado' => $v->estado, 'oportunidade_crm_id' => $v->oportunidade_crm_id])->values()->all()];
    }

    public function ficha360(ContaCRM $conta): array
    {
        $opps = OportunidadeVendaCRM::query()->where('conta_crm_id', $conta->id)->orderByDesc('criado_em')->get();
        $ganhas = $opps->where('estado', 'GANHA')->count();
        $perdidas = $opps->where('estado', 'PERDIDA')->count();
        $abertas = $opps->where('estado', 'ABERTA');

        return [
            'conta' => $conta, 'contactos' => ContactoCRM::query()->where('conta_crm_id', $conta->id)->orderByDesc('principal')->orderBy('nome')->get(),
            'oportunidades' => $opps, 'atividades' => AtividadeComercialCRM::query()->where('conta_crm_id', $conta->id)->orderByDesc('data_prevista')->orderByDesc('id')->get(),
            'financeiro' => $this->financeiro($conta->terceiro_id),
            'resumo' => ['abertas' => $abertas->count(), 'valor_aberto' => $abertas->reduce(fn ($s, $o) => bcadd($s, (string) $o->valor, 2), '0.00'),
                'ganhas' => $ganhas, 'perdidas' => $perdidas, 'taxa_ganho' => $ganhas + $perdidas ? round($ganhas / ($ganhas + $perdidas) * 100, 2) : null],
        ];
    }
}
