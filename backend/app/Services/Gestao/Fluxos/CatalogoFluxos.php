<?php

namespace App\Services\Gestao\Fluxos;

use App\Services\Gestao\Fluxos\Avaliadores\FluxoAcrescimos;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoAvaliacao;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoBancos;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoCaixa;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoCompras;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoCRM;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoFerias;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoImobilizado;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoLavandaria;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoOrcamento;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoPOS;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoProjetos;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoSalarios;
use App\Services\Gestao\Fluxos\Avaliadores\FluxoVendas;

/**
 * Catálogo dos fluxos do ecrã «Fluxo de Processos» (renderFluxoProcessos, js/fluxo_processos.js:554-621), pela ordem dos
 * separadores do legado: nome, subtítulo, consultas do módulo de origem que dão acesso ao separador (podeRH, podeVendas…),
 * avaliador e etapas. A narrativa de cada fluxo (objectivo, intervenientes e, por etapa, quem, descrição, controlos e
 * resultado) vem de narrativas.json, extraído do próprio legado (NARRATIVAS, js/fluxo_narrativa.js:17-200).
 */
final class CatalogoFluxos
{
    public const FLUXOS = [
        'rh' => ['nome' => 'Salários e RH', 'sub' => 'Onde está cada período de salários, do lançamento das rubricas à liquidação, e o que falta para avançar.',
            'vistas' => ['calcular', 'processamento', 'colaboradores'], 'avaliador' => FluxoSalarios::class,
            'etapas' => ['base' => 'Dados base', 'calc' => 'Lançar rubricas', 'fecho' => 'Fechar cálculo', 'valid' => 'Processar e validar', 'integ' => 'Integrar na contabilidade', 'pag' => 'Liquidar salários e obrigações']],
        'ferias' => ['nome' => 'Plano de Férias', 'sub' => 'O plano de férias de cada colaborador no ano: direito, marcação, aprovação da chefia e do RH, gozo e saldo.',
            'vistas' => ['rh_ferias', 'rh_portal_gestao'], 'avaliador' => FluxoFerias::class,
            'etapas' => ['direito' => 'Direito anual', 'marcacao' => 'Marcação das férias', 'chefia' => 'Aprovação da chefia', 'rh' => 'Aprovação do RH', 'gozo' => 'Gozo das férias', 'saldo' => 'Saldo do ano']],
        'avaliacao' => ['nome' => 'Avaliação de Desempenho', 'sub' => 'A avaliação de cada colaborador: critérios e objectivos, autoavaliação, avaliação, classificação, comentário e plano de desenvolvimento.',
            'vistas' => ['rh_avaliacao'], 'avaliador' => FluxoAvaliacao::class,
            'etapas' => ['itens' => 'Critérios e objectivos', 'r360' => 'Autoavaliação e 360º', 'aval' => 'Avaliação da chefia', 'conh' => 'Conhecimento e comentário', 'cont' => 'Contestação e decisão', 'plano' => 'Plano e bonificação']],
        'vendas' => ['nome' => 'Vendas', 'sub' => 'Cada processo de venda, do orçamento ao recebimento do cliente (Tesouraria ou compensação), e o que falta para avançar.',
            'vistas' => ['vendas_faturacao'], 'avaliador' => FluxoVendas::class,
            'etapas' => ['orc' => 'Orçamento / Proforma', 'enc' => 'Encomenda do cliente', 'ent' => 'Entrega (guia)', 'fact' => 'Factura', 'contab' => 'Contabilização', 'rec' => 'Recebimento ou compensação']],
        'pos' => ['nome' => 'POS', 'sub' => 'Cada sessão de caixa, da abertura à compensação das contas transitórias dos meios de pagamento na contabilidade.',
            'vistas' => ['pos'], 'avaliador' => FluxoPOS::class,
            'etapas' => ['abert' => 'Abertura da caixa', 'fecho' => 'Vendas e fecho (Z)', 'desvio' => 'Desvio de caixa', 'integ' => 'Integração contabilística', 'prest' => 'Prestação de contas', 'comp' => 'Compensação das transitórias']],
        'lavandaria' => ['nome' => 'Lavandaria', 'sub' => 'Cada ordem de serviço, da recepção das peças ao recebimento do cliente, e o que falta para avançar.',
            'vistas' => ['pos'], 'avaliador' => FluxoLavandaria::class,
            'etapas' => ['recep' => 'Recepção', 'orc' => 'Orçamento (alfaiataria)', 'exec' => 'Execução', 'ent' => 'Entrega ao cliente', 'fact' => 'Facturação e contabilização', 'rec' => 'Recebimento ou compensação']],
        'projetos' => ['nome' => 'Projectos e Obras', 'sub' => 'Cada projecto ou obra, da abertura ao planeamento, orçamento, execução, autos e facturação (sem IVA), recebimento e encerramento.',
            'vistas' => ['projectos_carteira', 'projectos_extracto'], 'avaliador' => FluxoProjetos::class,
            'etapas' => ['abertura' => 'Abertura', 'planeamento' => 'Planeamento (WBS)', 'equipa_orcamento' => 'Equipa e orçamento', 'execucao' => 'Execução', 'faturacao' => 'Autos e facturação',
                'recebimento' => 'Recebimento', 'encerramento' => 'Encerramento']],
        'compras' => ['nome' => 'Compras', 'sub' => 'Cada processo de compra, do pedido interno ao pagamento do fornecedor (Tesouraria ou compensação), e o que falta para avançar.',
            'vistas' => ['compras'], 'avaliador' => FluxoCompras::class,
            'etapas' => ['ped' => 'Pedido interno', 'prosp' => 'Prospecção e adjudicação', 'enc' => 'Encomenda', 'rec' => 'Recepção', 'fact' => 'Factura e contabilização', 'pag' => 'Pagamento ao fornecedor']],
        'imobilizado' => ['nome' => 'Imobilizado', 'sub' => 'Cada compra de imobilizado, da factura à integração das amortizações, e o que falta para avançar.',
            'vistas' => ['activos'], 'avaliador' => FluxoImobilizado::class,
            'etapas' => ['fact' => 'Factura de compra', 'contab' => 'Contabilização da compra', 'invent' => 'Inventariação do activo', 'cat' => 'Categoria e vida útil', 'calc' => 'Cálculo das amortizações', 'integ' => 'Integração das amortizações']],
        'bancos' => ['nome' => 'Bancos (43)', 'sub' => 'Cada mês de cada conta de depósitos à ordem (43): lançamento na Tesouraria, integração na contabilidade e reconciliação mensal com o extracto.',
            'vistas' => ['teso_gestao_conciliacao', 'teso_contab_integracao'], 'avaliador' => FluxoBancos::class,
            'etapas' => ['lanc' => 'Lançamento na Tesouraria', 'integ' => 'Integração na contabilidade', 'ext' => 'Extracto bancário', 'conc' => 'Reconciliação mensal']],
        'caixa' => ['nome' => 'Folha de Caixa', 'sub' => 'Cada sessão da Folha de Caixa, da abertura à integração na contabilidade.',
            'vistas' => ['teso_folha_caixa'], 'avaliador' => FluxoCaixa::class,
            'etapas' => ['abert' => 'Abertura', 'mov' => 'Movimentos', 'fecho' => 'Fecho e contagem', 'dif' => 'Diferença de caixa', 'integ' => 'Integração na contabilidade']],
        'orcamento' => ['nome' => 'Orçamento', 'sub' => 'Cada orçamento, da preparação e contributos dos responsáveis à aprovação, execução e decisão dos excessos.',
            'vistas' => ['orc_orcamentos', 'orc_controlo'], 'avaliador' => FluxoOrcamento::class,
            'etapas' => ['prep' => 'Preparação', 'contrib' => 'Contributos (bottom-up)', 'subm' => 'Submissão', 'aprov' => 'Aprovação', 'exec' => 'Execução e controlo', 'exc' => 'Excessos e desvios']],
        'crm' => ['nome' => 'CRM', 'sub' => 'Cada oportunidade comercial, do primeiro contacto à proposta, fecho, documento de venda e recebimento.',
            'vistas' => ['crm_pipeline'], 'avaliador' => FluxoCRM::class,
            'etapas' => ['lead' => 'Oportunidade', 'qual' => 'Qualificação e actividades', 'prop' => 'Proposta', 'fecho' => 'Negociação e fecho', 'doc' => 'Encomenda ou factura', 'rec' => 'Recebimento']],
        'acrescimos' => ['nome' => 'Acréscimos e Diferimentos', 'sub' => 'Cada acréscimo ou diferimento, do registo aos reconhecimentos mensais, regularização e saldo da conta 37.',
            'vistas' => ['ad_registos', 'ad_propostas'], 'avaliador' => FluxoAcrescimos::class,
            'etapas' => ['reg' => 'Registo', 'ini' => 'Lançamento inicial', 'recon' => 'Reconhecimentos mensais', 'doc' => 'Documento real / fim do período', 'regul' => 'Regularização ou término', 'saldo' => 'Conta 37 saldada']],
    ];

    /**
     * Ícones Font Awesome do legado (nomes da versão 5 usados em js/fluxo_*.js): «fluxo» é o do separador
     * (renderFluxoProcessos, fluxo_processos.js:605-620) e «etapas» o de cada nó do diagrama (const ETAPAS de cada fluxo).
     * O frontend desenha-os em SVG (componentes/fluxos/iconesFa.ts), com o mesmo desenho.
     */
    public const ICONES = [
        'rh' => ['fluxo' => 'users', 'etapas' => ['base' => 'users', 'calc' => 'calculator', 'fecho' => 'lock', 'valid' => 'check-double', 'integ' => 'book', 'pag' => 'hand-holding-usd']],
        'ferias' => ['fluxo' => 'umbrella-beach', 'etapas' => ['direito' => 'calendar-alt', 'marcacao' => 'calendar-plus', 'chefia' => 'user-tie', 'rh' => 'check-double', 'gozo' => 'umbrella-beach', 'saldo' => 'balance-scale']],
        'avaliacao' => ['fluxo' => 'star-half-alt', 'etapas' => ['itens' => 'list-check', 'r360' => 'sync-alt', 'aval' => 'star-half-alt', 'conh' => 'eye', 'cont' => 'balance-scale', 'plano' => 'seedling']],
        'vendas' => ['fluxo' => 'file-invoice-dollar', 'etapas' => ['orc' => 'file-alt', 'enc' => 'shopping-basket', 'ent' => 'truck', 'fact' => 'file-invoice-dollar', 'contab' => 'book', 'rec' => 'hand-holding-usd']],
        'pos' => ['fluxo' => 'cash-register', 'etapas' => ['abert' => 'door-open', 'fecho' => 'cash-register', 'desvio' => 'balance-scale-left', 'integ' => 'book', 'prest' => 'hand-holding-usd', 'comp' => 'check-double']],
        'lavandaria' => ['fluxo' => 'tshirt', 'etapas' => ['recep' => 'inbox', 'orc' => 'cut', 'exec' => 'soap', 'ent' => 'box-open', 'fact' => 'file-invoice-dollar', 'rec' => 'hand-holding-usd']],
        'projetos' => ['fluxo' => 'hard-hat', 'etapas' => ['abertura' => 'folder-plus', 'planeamento' => 'sitemap', 'equipa_orcamento' => 'calculator', 'execucao' => 'hard-hat', 'faturacao' => 'file-invoice-dollar',
            'recebimento' => 'hand-holding-usd', 'encerramento' => 'lock']],
        'compras' => ['fluxo' => 'truck-loading', 'etapas' => ['ped' => 'clipboard-list', 'prosp' => 'search-dollar', 'enc' => 'shopping-cart', 'rec' => 'dolly', 'fact' => 'file-invoice-dollar', 'pag' => 'hand-holding-usd']],
        'imobilizado' => ['fluxo' => 'couch', 'etapas' => ['fact' => 'file-invoice', 'contab' => 'book', 'invent' => 'barcode', 'cat' => 'tags', 'calc' => 'percent', 'integ' => 'check-double']],
        'bancos' => ['fluxo' => 'university', 'etapas' => ['lanc' => 'edit', 'integ' => 'book', 'ext' => 'file-upload', 'conc' => 'balance-scale']],
        'caixa' => ['fluxo' => 'cash-register', 'etapas' => ['abert' => 'door-open', 'mov' => 'receipt', 'fecho' => 'lock', 'dif' => 'balance-scale', 'integ' => 'book']],
        'orcamento' => ['fluxo' => 'file-invoice-dollar', 'etapas' => ['prep' => 'pencil-ruler', 'contrib' => 'users', 'subm' => 'paper-plane', 'aprov' => 'check-double', 'exec' => 'balance-scale', 'exc' => 'bell']],
        'crm' => ['fluxo' => 'handshake', 'etapas' => ['lead' => 'lightbulb', 'qual' => 'tasks', 'prop' => 'file-alt', 'fecho' => 'handshake', 'doc' => 'file-invoice-dollar', 'rec' => 'hand-holding-usd']],
        'acrescimos' => ['fluxo' => 'exchange-alt', 'etapas' => ['reg' => 'file-signature', 'ini' => 'play', 'recon' => 'calendar-check', 'doc' => 'file-invoice', 'regul' => 'exchange-alt', 'saldo' => 'balance-scale']],
    ];

    /** Narrativa do fluxo (textos-base para o ecrã, a impressão do workflow e a apresentação). */
    public static function narrativa(string $id): ?array
    {
        static $todas = null;
        $todas ??= json_decode((string) file_get_contents(__DIR__.'/narrativas.json'), true) ?: [];

        return $todas[$id] ?? null;
    }
}
