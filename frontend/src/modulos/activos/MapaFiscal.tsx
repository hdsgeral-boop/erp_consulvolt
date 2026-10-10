import { Card, Flex, Input, Select, Table, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { BotaoImprimir, type PedidoImpressao } from '@/componentes/impressao';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { NavActivos } from './comum/NavActivos';
import type { LinhaFiscal, MapaFiscal as DadosMapaFiscal } from './comum/tipos';
import { anosFiscais, anosUtilidade, filtrarFiscal, htmlMapaFiscal, percentagem, taxaCorrigida, totaisFiscal } from './fiscal/regras';

const centro = { align: 'center' as const };
const valor = (k: keyof LinhaFiscal, forte = false) => ({ dataIndex: k, align: 'right' as const, render: (v: string) => <ValorKz valor={v} forte={forte} /> });
const mesOuAno = (k: keyof LinhaFiscal) => ({ dataIndex: k, ...centro, width: 52, render: (v: number | null) => v || '—' });

/** Colunas do modelo oficial, com o cabeçalho agrupado em 3 linhas do legado (ui_assets.js:3655-3681). */
const COLUNAS: ColumnsType<LinhaFiscal> = [
  { title: 'Conta do Plano Geral de Contabilidade', dataIndex: 'conta', ...centro, width: 110, fixed: 'left', render: (v) => v ?? '—' },
  { title: 'Descrição do Activo Imobilizado', dataIndex: 'descricao', width: 220, render: (v) => <strong>{v}</strong> },
  {
    title: 'Datas', ...centro, children: [
      { title: 'Aquisição / Construção', ...centro, children: [{ title: 'Mês', key: 'ma', ...mesOuAno('mes_aquisicao') }, { title: 'Ano', key: 'aa', ...mesOuAno('ano_aquisicao') }] },
      { title: 'Início de Utilização', ...centro, children: [{ title: 'Mês', key: 'mi', ...mesOuAno('mes_inicio_utilizacao') }, { title: 'Ano', key: 'ai', ...mesOuAno('ano_inicio_utilizacao') }] },
    ],
  },
  { title: 'Importado (Sim/Não)', key: 'importado', ...centro, render: () => 'Não' },
  { title: 'Valor de Aquisição / Construção', key: 'valor_aquisicao', ...valor('valor_aquisicao') },
  { title: 'Anos de Utilidade Esperada', dataIndex: 'anos_vida', ...centro, render: anosUtilidade },
  {
    title: 'Reavaliação', ...centro, children: [
      { title: 'Ano da Reaval.', key: 'ra', ...centro, render: () => '—' },
      { title: 'Valor Acréscimo', key: 'rv', ...centro, render: () => '—' },
      { title: 'Valor Total', key: 'rt', ...centro, render: () => '—' },
    ],
  },
  {
    title: 'Reintegrações e Amortizações', ...centro, children: [
      { title: 'Exercícios Anteriores', key: 'anteriores', ...valor('anteriores') },
      {
        title: 'Do Exercício', ...centro, children: [
          { title: 'Taxa %', dataIndex: 'taxa_categoria', ...centro, render: percentagem },
          { title: 'Taxa Corrig.', key: 'tc', ...centro, render: (_, l) => taxaCorrigida(l) },
          { title: 'Valor', key: 'exercicio', ...valor('exercicio') },
        ],
      },
      { title: 'Amortizações Acumuladas', key: 'acumuladas', ...valor('acumuladas') },
      { title: 'Valor Contabilístico (Residual)', key: 'liquido', ...valor('liquido', true) },
    ],
  },
];

/**
 * Activos › Mapa Fiscal (separador do agregador, rota /m/activos/activos_mapa/fiscal): Mapa Fiscal de Reintegrações e
 * Amortizações do ano, modelo oficial (legado renderFiscalAssetMap). Dados: GET /ativos/mapas/fiscal (só quotas integradas;
 * sem os activos adquiridos depois do ano nem os abatidos em anos anteriores). Reavaliações: o sistema não as regista
 * (como o legado, «—»); «Importado»: «Não» (o legado não tinha o dado).
 */
export default function MapaFiscal() {
  const [ano, setAno] = useState(dayjs().year());
  const [texto, setTexto] = useState('');
  const q = useQuery({ queryKey: ['activos', 'mapa-fiscal', ano], queryFn: () => obter<DadosMapaFiscal>('/ativos/mapas/fiscal', { ano }) });
  const linhas = useMemo(() => filtrarFiscal(q.data?.linhas ?? [], texto), [q.data, texto]);
  const filtrado = linhas.length !== (q.data?.linhas.length ?? 0);
  const totais = filtrado || !q.data ? totaisFiscal(linhas) : q.data.totais;
  const titulo = `Mapa Fiscal de Reintegrações e Amortizações - Ano ${ano}`;

  const pedido = (): PedidoImpressao => ({
    titulo: 'Mapa Fiscal de Reintegrações e Amortizações',
    periodo: String(ano),
    filtros: texto ? [`Pesquisa: ${texto}`] : undefined,
    orientacao: 'paisagem',
    conteudo: htmlMapaFiscal(linhas, totais, filtrado ? 'TOTAIS FILTRADOS' : 'TOTAIS'),
  });

  return (
    <>
      <NavActivos actual="activos_mapa_fiscal" />
      <Card
        title={titulo}
        extra={(
          <Flex gap={8} wrap align="center" className="erp-oculto-impressao">
            <Typography.Text strong style={{ fontSize: 13 }}>Ano:</Typography.Text>
            <Select aria-label="Ano" value={ano} onChange={setAno} style={{ width: 100 }} size="small"
              options={anosFiscais(dayjs().year(), ano).map((a) => ({ value: a, label: String(a) }))} />
            <BotaoImprimir tamanho="small" tipo="primary" texto="Extrair" desactivado={!q.data} obterPedido={pedido} />
            <BotaoImprimir tamanho="small" modo="excel" texto="Exportar" desactivado={!q.data} obterPedido={pedido} />
          </Flex>
        )}
      >
        <Flex justify="space-between" wrap gap={8} style={{ marginBottom: 8 }}>
          <Typography.Text type="secondary">{linhas.length} registos encontrados</Typography.Text>
          <Input.Search placeholder="Conta, código ou descrição" allowClear onSearch={setTexto} style={{ width: 260 }} size="small" />
        </Flex>
        <Table<LinhaFiscal>
          rowKey="ativo_imobilizado_id"
          size="small"
          bordered
          loading={q.isFetching}
          dataSource={linhas}
          columns={COLUNAS}
          style={{ fontSize: 12 }}
          scroll={{ x: 'max-content', y: 560 }}
          pagination={false}
          locale={{ emptyText: 'Sem activos para o ano.' }}
          summary={() => linhas.length > 0 && (
            <Table.Summary fixed>
              <Table.Summary.Row>
                <Table.Summary.Cell index={0} colSpan={7} align="right"><Typography.Text strong>{filtrado ? 'TOTAIS FILTRADOS' : 'TOTAIS'}:</Typography.Text></Table.Summary.Cell>
                <Table.Summary.Cell index={7} align="right"><ValorKz valor={totais.valor_aquisicao} forte /></Table.Summary.Cell>
                <Table.Summary.Cell index={8} colSpan={4} />
                <Table.Summary.Cell index={12} align="right"><ValorKz valor={totais.anteriores} forte /></Table.Summary.Cell>
                <Table.Summary.Cell index={13} colSpan={2} />
                <Table.Summary.Cell index={15} align="right"><ValorKz valor={totais.exercicio} forte /></Table.Summary.Cell>
                <Table.Summary.Cell index={16} align="right"><ValorKz valor={totais.acumuladas} forte /></Table.Summary.Cell>
                <Table.Summary.Cell index={17} align="right"><ValorKz valor={totais.liquido} forte /></Table.Summary.Cell>
              </Table.Summary.Row>
            </Table.Summary>
          )}
        />
      </Card>
    </>
  );
}
