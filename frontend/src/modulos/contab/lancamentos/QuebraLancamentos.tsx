import { Button, Checkbox, Flex, Table, Tooltip, Typography, theme } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { BotoesExportar } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import type { AccaoLinha } from '@/componentes/vistas';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';
import type { LinhaLista } from './ferramentas';
import { ROTULO_DIMENSAO, documentoQuebra, filtrosDoGrupo, rotuloGrupo, type GrupoQuebra, type PorQuebra, type Quebra } from './quebra';

const URL = '/contabilidade/lancamentos/quebra';
const COR_D = '#2563eb';
const COR_C = '#dc2626';

interface Props {
  por: PorQuebra;
  /** Filtros aplicados na lista (os mesmos da vista «Linhas»). */
  filtros: Record<string, unknown>;
  /** Colunas e acções da lista, reutilizadas nas linhas de cada grupo. */
  colunas: ColunaApi<LinhaLista>[];
  accoes: (r: LinhaLista) => (AccaoLinha | false)[];
  aoAbrir: (id: number) => void;
  impressao: { titulo: string; periodo?: string; filtros?: (string | null | undefined | false)[] };
}

const Valor = ({ v, cor, forte }: { v: string; cor?: string; forte?: boolean }) => (
  <span style={{ whiteSpace: 'nowrap', color: cor, fontWeight: forte ? 600 : undefined }}>{formatarKz(v)}</span>
);

/**
 * Lista de lançamentos agrupada (quebra) por terceiro, unidade de negócio ou centro de custo: subtotais por grupo calculados
 * no servidor (paginação pelos grupos, total geral de todas as linhas filtradas), cada grupo abre as suas linhas (a mesma
 * tabela e as mesmas acções da lista), «Expandir tudo / Recolher tudo» e Imprimir/PDF/Excel com ou sem o detalhe.
 */
export function QuebraLancamentos({ por, filtros, colunas, accoes, aoAbrir, impressao }: Props) {
  const { token } = theme.useToken();
  const [pagina, setPagina] = useState(1);
  const [porPagina, setPorPagina] = useState(25);
  const [abertos, setAbertos] = useState<string[]>([]);
  const [comDetalhe, setComDetalhe] = useState(true);
  const chaveFiltros = JSON.stringify(filtros);
  useEffect(() => {
    setPagina(1);
    setAbertos([]);
  }, [chaveFiltros, por]);

  const consulta = useQuery({
    queryKey: ['contab', 'lancamentos', 'quebra', por, filtros, pagina, porPagina],
    queryFn: () => obter<Quebra>(URL, { ...filtros, por, pagina, por_pagina: porPagina }),
    placeholderData: keepPreviousData,
  });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Não foi possível calcular a quebra');
  }, [consulta.error]);
  const d = consulta.data;
  const chave = (g: GrupoQuebra) => (g.chave === null ? 'sem' : String(g.chave));
  const rotulo = ROTULO_DIMENSAO[por];

  const obterPedido = async () => {
    try {
      let grupos: GrupoQuebra[];
      let total = d?.total;
      if (comDetalhe) {
        const r = await obter<Quebra>(URL, { ...filtros, por, com_linhas: 1 });
        grupos = r.grupos;
        total = r.total;
      } else {
        grupos = [];
        for (let p = 1, ultima = 1; p <= ultima; p++) {
          const r = await obter<Quebra>(URL, { ...filtros, por, pagina: p, por_pagina: 200 });
          grupos.push(...r.grupos);
          ultima = r.paginacao.ultima_pagina;
          total = r.total;
        }
      }
      if (!total) return null;
      return {
        titulo: `${impressao.titulo} — quebra por ${rotulo.toLowerCase()}`,
        periodo: impressao.periodo,
        filtros: [...(impressao.filtros ?? []), comDetalhe ? 'Com o detalhe das linhas' : 'Só subtotais'],
        orientacao: comDetalhe ? ('paisagem' as const) : ('retrato' as const),
        conteudo: documentoQuebra(por, grupos, total, comDetalhe),
      };
    } catch (e) {
      notificarErro(e, 'Não foi possível preparar a impressão da quebra');
      return null;
    }
  };

  const colunasGrupo: ColumnsType<GrupoQuebra> = [
    {
      title: rotulo,
      key: 'grupo',
      render: (_, g) => (
        <Typography.Text strong={g.chave !== null} type={g.chave === null ? 'secondary' : undefined}>
          <IconeFa nome={por === 'terceiro' ? 'user-tag' : por === 'unidade' ? 'building' : 'sitemap'} style={{ marginRight: 6, opacity: 0.7 }} />
          {rotuloGrupo(g)}
        </Typography.Text>
      ),
    },
    { title: 'Linhas', dataIndex: 'linhas', align: 'right', width: 90 },
    { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v: string) => <Valor v={v} cor={COR_D} /> },
    { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v: string) => <Valor v={v} cor={COR_C} /> },
    { title: 'Saldo (D − C)', dataIndex: 'saldo', align: 'right', render: (v: string) => <Valor v={v} forte /> },
  ];

  return (
    <>
      <Flex wrap gap={8} align="center" justify="space-between" style={{ marginBottom: 8 }}>
        <Flex wrap gap={8} align="center">
          <Button size="small" icon={<IconeFa nome="expand-alt" />} disabled={!d?.grupos.length} onClick={() => setAbertos((d?.grupos ?? []).map(chave))}>
            Expandir tudo
          </Button>
          <Button size="small" icon={<IconeFa nome="compress-alt" />} disabled={!abertos.length} onClick={() => setAbertos([])}>
            Recolher tudo
          </Button>
          {d && (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
              {d.total.grupos.toLocaleString('pt-PT')} grupo(s) · {d.total.linhas.toLocaleString('pt-PT')} linha(s)
            </Typography.Text>
          )}
        </Flex>
        <Flex wrap gap={8} align="center">
          <Tooltip title="Na impressão, PDF e Excel: as linhas de cada grupo com o subtotal (até 5 000 linhas), ou só os subtotais">
            <Checkbox checked={comDetalhe} onChange={(e) => setComDetalhe(e.target.checked)}>
              Com o detalhe das linhas
            </Checkbox>
          </Tooltip>
          <BotoesExportar tamanho="small" desactivado={!d?.grupos.length} obterPedido={obterPedido} chave={`contab/lancamentos/quebra/${comDetalhe ? 'detalhe' : 'subtotais'}`} />
        </Flex>
      </Flex>
      <Table<GrupoQuebra>
        rowKey={chave}
        size="small"
        loading={consulta.isFetching}
        columns={colunasGrupo}
        dataSource={d?.grupos ?? []}
        scroll={scrollTabela()}
        locale={{ emptyText: 'Nenhum lançamento com os filtros aplicados.' }}
        pagination={{
          current: pagina,
          pageSize: porPagina,
          total: d?.paginacao.total ?? 0,
          showSizeChanger: true,
          pageSizeOptions: [10, 25, 50, 100, 200],
          showTotal: (n) => `${n.toLocaleString('pt-PT')} grupo(s)`,
          onChange: (p, t) => {
            setPagina(t !== porPagina ? 1 : p);
            setPorPagina(t);
          },
        }}
        expandable={{
          expandedRowKeys: abertos,
          onExpandedRowsChange: (k) => setAbertos(k.map(String)),
          expandedRowRender: (g) => (
            <div style={{ padding: '4px 0', background: token.colorBgContainer }}>
              <TabelaApi<LinhaLista>
                url="/contabilidade/lancamentos"
                chaveConsulta={['contab', 'lancamentos', 'quebra', por, chave(g)]}
                idVista={`contabilidade/lancamentos/quebra/${por}`}
                modos={false}
                porPagina={20}
                filtros={filtrosDoGrupo(filtros, por, g.chave)}
                columns={colunas}
                size="small"
                accoes={accoes}
                tituloAccoes="Ações"
                onRow={(r) => ({ onDoubleClick: () => aoAbrir(r.id) })}
              />
            </div>
          ),
        }}
        summary={() =>
          d ? (
            <Table.Summary fixed>
              <Table.Summary.Row style={{ background: token.colorFillAlter }}>
                <Table.Summary.Cell index={0} colSpan={2}>
                  <strong>TOTAL GERAL ({d.total.grupos.toLocaleString('pt-PT')} grupo(s))</strong>
                </Table.Summary.Cell>
                <Table.Summary.Cell index={2} align="right">
                  <strong>{d.total.linhas.toLocaleString('pt-PT')}</strong>
                </Table.Summary.Cell>
                <Table.Summary.Cell index={3} align="right">
                  <Valor v={d.total.debito} cor={COR_D} forte />
                </Table.Summary.Cell>
                <Table.Summary.Cell index={4} align="right">
                  <Valor v={d.total.credito} cor={COR_C} forte />
                </Table.Summary.Cell>
                <Table.Summary.Cell index={5} align="right">
                  <Valor v={d.total.saldo} forte />
                </Table.Summary.Cell>
              </Table.Summary.Row>
            </Table.Summary>
          ) : null
        }
      />
    </>
  );
}
