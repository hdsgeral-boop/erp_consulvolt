<?php

namespace App\Services\Procedimentos;

use App\Services\Gestao\Fluxos\CatalogoFluxos;

/**
 * Narrativas de fábrica do Manual de Procedimentos (19 processos, 109 etapas, 84 cruzamentos), lidas de
 * backend/resources/procedimentos/narrativas.json, gerado por ferramentas/paridade/extrair_narrativas_procedimentos.mjs a
 * partir do legado. É uma fonte própria do manual: o ficheiro do Fluxo de Processos (14 narrativas) não é tocado.
 * Acompanham as actualizações do ERP: um processo que a empresa não editou vem sempre daqui.
 */
final class CatalogoFabricaProcedimentos
{
    /** Processos que vivem noutros documentos: cruza-se com eles mas não são procedimentos deste manual. */
    public const EXTERNOS = [
        'estrutura_manual' => 'Estrutura Orgânica — Manual da Organização',
        'estrategia_plano' => 'Planeamento Estratégico — Documento do Plano',
    ];

    /** Que processos cobrem cada módulo do ERP (um módulo pode ter mais do que um). */
    public const POR_MODULO = [
        'rh' => ['nome' => 'Salários e RH', 'processos' => ['rh', 'ferias', 'avaliacao', 'entregaveis']],
        'vendas' => ['nome' => 'Vendas e POS', 'processos' => ['vendas', 'pos', 'lavandaria']],
        'compras' => ['nome' => 'Compras', 'processos' => ['compras']],
        'stock' => ['nome' => 'Stock e Armazém', 'processos' => ['stock']],
        'tesouraria' => ['nome' => 'Tesouraria', 'processos' => ['bancos', 'caixa']],
        'contabilidade' => ['nome' => 'Contabilidade', 'processos' => ['acrescimos', 'fecho']],
        'activos' => ['nome' => 'Activos', 'processos' => ['imobilizado', 'manutencao']],
        'projectos' => ['nome' => 'Projectos e Obras', 'processos' => ['projetos']],
        'imobiliario' => ['nome' => 'Gestão Imobiliária', 'processos' => ['imobiliario']],
        'orcamento' => ['nome' => 'Gestão Orçamental', 'processos' => ['orcamento']],
        'crm' => ['nome' => 'CRM', 'processos' => ['crm']],
        'estrutura' => ['nome' => 'Estrutura Orgânica', 'processos' => ['entregaveis']],
        'estrategia' => ['nome' => 'Planeamento Estratégico', 'processos' => []],
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, array{chave: string, titulo: string, objetivo: string, fluxo_id: ?string, modulo: ?string, intervenientes: list<string>, etapas: list<array<string, string>>, cruza: list<array<string, string>>}>
     */
    public function todas(): array
    {
        if (self::$cache === null) {
            $bruto = json_decode((string) file_get_contents(resource_path('procedimentos/narrativas.json')), true) ?: [];
            $out = [];
            foreach ($bruto as $chave => $n) {
                $out[(string) $chave] = [
                    'chave' => (string) $chave,
                    'titulo' => (string) $n['titulo'],
                    'objetivo' => (string) $n['objectivo'],
                    'fluxo_id' => $n['fluxo_id'] ?? null,
                    'modulo' => $n['modulo'] ?? null,
                    'intervenientes' => array_values($n['intervenientes'] ?? []),
                    'etapas' => array_values($n['etapas'] ?? []),
                    'cruza' => array_values($n['cruza'] ?? []),
                ];
            }
            self::$cache = $out;
        }

        return self::$cache;
    }

    public function existe(string $chave): bool
    {
        return isset($this->todas()[$chave]);
    }

    /** Chaves dos painéis do Fluxo de Processos do ERP (os que têm acompanhamento calculado dos dados reais). */
    public function paineis(): array
    {
        return array_keys(CatalogoFluxos::FLUXOS);
    }

    /** Hash do texto de fábrica de um processo (detecta que o ERP o actualizou depois de a empresa o editar). */
    public function hash(string $chave): ?string
    {
        $n = $this->todas()[$chave] ?? null;

        return $n === null ? null : hash('sha256', json_encode($n, JSON_UNESCAPED_UNICODE));
    }
}
