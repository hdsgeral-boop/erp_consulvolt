import { Button, Descriptions, Drawer, Empty, Flex, Skeleton, Table, Tabs, Typography } from 'antd';
import { PrinterOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { obter } from '@/api/cliente';
import { BotoesExportar } from '@/componentes/impressao';
import { larguraGaveta, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { EstadoPOS, rotuloEstadoPOS } from './estados';
import { pedidoSessao } from './documentos';
import { htmlRelatorioSessao, lerPreferencias, reimprimir, useCabecalhoTalao } from './impressao';
import { DECISOES } from './regras';
import type { FechoTPA, SessaoPOS, TotalMeio, VendaSessao } from './tipos';

export function useSessaoPOS(id: number | null | undefined) {
  return useQuery({ queryKey: ['pos', 'sessao', id], queryFn: () => obter<SessaoPOS>(`/pos/sessoes/${id}`), enabled: !!id });
}

/** Valor com cor: verde se positivo, vermelho se negativo. */
export function ValorDesvio({ valor }: { valor: string | number | null | undefined }) {
  const n = Number(valor ?? 0);
  if (valor === null || valor === undefined) return <>—</>;
  return <Typography.Text type={n < 0 ? 'danger' : n > 0 ? 'success' : undefined}>{formatarKz(valor)}</Typography.Text>;
}

/** Painel lateral com o fecho de uma sessão POS: totais, numerário, TPA, desvio, deliberação e vendas. */
export function DetalheSessao({ id, aoFechar, accoes }: { id: number | null; aoFechar: () => void; accoes?: (s: SessaoPOS) => ReactNode }) {
  const { empresa } = useSessao();
  const cabecalho = useCabecalhoTalao();
  const consulta = useSessaoPOS(id);
  const s = consulta.data;

  return (
    <Drawer
      open={!!id}
      onClose={aoFechar}
      width={larguraGaveta(820)}
      title={s ? `Sessão ${s.codigo_sessao}${s.numero_z ? ` · ${s.numero_z}` : ''}` : 'Sessão'}
      extra={
        s && (
          <Flex gap={8} wrap justify="flex-end">
            <BotoesExportar tamanho="small" obterPedido={() => pedidoSessao(s)} />
            {s.estado === 'FECHADA' && (
              <Button icon={<PrinterOutlined />} onClick={() => reimprimir(htmlRelatorioSessao(s, cabecalho(), lerPreferencias(empresa?.id)), lerPreferencias(empresa?.id))}>
                Imprimir Z
              </Button>
            )}
            {accoes?.(s)}
          </Flex>
        )
      }
      destroyOnHidden
    >
      {!s ? (
        <Skeleton active />
      ) : (
        <>
          <Descriptions size="small" column={{ xs: 1, sm: 2 }} bordered>
            <Descriptions.Item label="Terminal">{`${s.codigo_terminal} — ${s.nome_terminal}`}</Descriptions.Item>
            <Descriptions.Item label="Operador">{s.nome_operador ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Abertura">{formatarDataHora(s.aberto_em)}</Descriptions.Item>
            <Descriptions.Item label="Fecho">{s.fechado_em ? `${formatarDataHora(s.fechado_em)} (${s.fechado_por ?? '—'})` : '—'}</Descriptions.Item>
            <Descriptions.Item label="Estado">
              <EstadoPOS estado={s.estado} />
            </Descriptions.Item>
            <Descriptions.Item label="Integração">
              <EstadoPOS estado={s.estado_contabilizacao} /> {s.lans_contabilizacao?.join(', ')}
            </Descriptions.Item>
            <Descriptions.Item label="Desvio">
              <EstadoPOS estado={s.estado_desvio} />
            </Descriptions.Item>
            <Descriptions.Item label="Prestação de contas">
              <EstadoPOS estado={s.estado_liquidacao} />
            </Descriptions.Item>
            <Descriptions.Item label="N.º de vendas">{s.numero_vendas ?? 0}</Descriptions.Item>
            <Descriptions.Item label="Total de vendas">{formatarKz(s.total_vendas)}</Descriptions.Item>
            <Descriptions.Item label="Fundo de maneio">{formatarKz(s.fundo_maneio_abertura)}</Descriptions.Item>
            <Descriptions.Item label="Numerário esperado">{formatarKz(s.numerario_esperado)}</Descriptions.Item>
            <Descriptions.Item label="Numerário contado">{formatarKz(s.numerario_contado)}</Descriptions.Item>
            <Descriptions.Item label="Desvio (contado − esperado)">
              <ValorDesvio valor={s.desvio} />
            </Descriptions.Item>
            {s.justificacao && (
              <Descriptions.Item label="Justificação" span={2}>
                {s.justificacao}
              </Descriptions.Item>
            )}
            {s.deliberacao && (
              <Descriptions.Item label="Deliberação" span={2}>
                <b>{DECISOES[s.deliberacao.decisao]?.rotulo ?? rotuloEstadoPOS(s.deliberacao.decisao)}</b>
                {s.deliberacao.automatica ? ' (automática, dentro da tolerância)' : ''} · {formatarKz(s.deliberacao.valor)} Kz
                {s.deliberacao.nota ? ` · ${s.deliberacao.nota}` : ''}
                {s.deliberacao.por ? ` · ${s.deliberacao.por}` : ''}
                {s.deliberacao.numero_lan ? ` · lançamento ${s.deliberacao.numero_lan}` : ''}
              </Descriptions.Item>
            )}
          </Descriptions>

          <Tabs
            style={{ marginTop: 16 }}
            items={[
              { key: 'meios', label: 'Por meio de pagamento', children: <TabelaMeios linhas={s.totais_por_metodo ?? []} /> },
              { key: 'tpa', label: 'Talões TPA', children: <TabelaFechosTPA linhas={s.fechos_tpa ?? []} /> },
              { key: 'contagem', label: 'Contagem', children: <TabelaContagem contagens={s.contagens_numerario} /> },
              { key: 'vendas', label: `Vendas (${s.vendas?.length ?? 0})`, children: <TabelaVendas linhas={s.vendas ?? []} /> },
            ]}
          />
        </>
      )}
    </Drawer>
  );
}

export function TabelaMeios({ linhas }: { linhas: TotalMeio[] }) {
  return (
    <Table<TotalMeio>
      size="small"
      scroll={scrollTabela()}
      pagination={false}
      rowKey={(m) => `${m.meio_id ?? m.tipo}`}
      dataSource={linhas}
      locale={{ emptyText: <Empty description="Sem movimentos" image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
      columns={[
        { title: 'Meio', dataIndex: 'nome' },
        { title: 'Natureza', dataIndex: 'tipo', render: (t: string) => <EstadoPOS estado={t} /> },
        { title: 'Transitória', dataIndex: 'conta_transitoria', render: (c) => c ?? '—', responsive: ['md'] },
        { title: 'Operações', dataIndex: 'quantidade', align: 'right' },
        { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) },
      ]}
    />
  );
}

export function TabelaFechosTPA({ linhas }: { linhas: FechoTPA[] }) {
  return (
    <Table<FechoTPA>
      size="small"
      scroll={scrollTabela()}
      pagination={false}
      rowKey="meio_id"
      dataSource={linhas}
      locale={{ emptyText: <Empty description="Sem TPA com movimento" image={Empty.PRESENTED_IMAGE_SIMPLE} /> }}
      columns={[
        { title: 'TPA', render: (_, f) => `${f.nome}${f.codigo_tpa ? ` (${f.codigo_tpa})` : ''}` },
        { title: 'Sistema', dataIndex: 'valor_sistema', align: 'right', render: (v) => formatarKz(v) },
        { title: 'Op. sistema', dataIndex: 'operacoes_sistema', align: 'right' },
        { title: 'Talão', dataIndex: 'valor_talao', align: 'right', render: (v) => formatarKz(v) },
        { title: 'Op. talão', dataIndex: 'operacoes_talao', align: 'right' },
        { title: 'Lote', dataIndex: 'referencia_lote', render: (v) => v ?? '—', responsive: ['md'] },
        { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (v) => <ValorDesvio valor={v} /> },
      ]}
    />
  );
}

function TabelaContagem({ contagens }: { contagens: Record<string, number> | null }) {
  const linhas = Object.entries(contagens ?? {})
    .map(([d, q]) => ({ d: Number(d), q }))
    .sort((a, b) => b.d - a.d);
  if (!linhas.length) return <Empty description="Contagem pelo total (sem detalhe por notas e moedas)" image={Empty.PRESENTED_IMAGE_SIMPLE} />;
  return (
    <Table
      size="small"
      scroll={scrollTabela()}
      pagination={false}
      rowKey="d"
      dataSource={linhas}
      columns={[
        { title: 'Nota/moeda', dataIndex: 'd', render: (d: number) => `${formatarKz(d)} Kz` },
        { title: 'Quantidade', dataIndex: 'q', align: 'right' },
        { title: 'Subtotal', align: 'right', render: (_, l) => formatarKz(l.d * l.q) },
      ]}
    />
  );
}

function TabelaVendas({ linhas }: { linhas: VendaSessao[] }) {
  return (
    <Table<VendaSessao>
      size="small"
      scroll={scrollTabela()}
      rowKey="id"
      dataSource={linhas}
      pagination={{ defaultPageSize: 10 }}
      columns={[
        { title: 'Documento', dataIndex: 'numero_documento' },
        { title: 'Data', dataIndex: 'data_emissao', render: (d) => formatarDataHora(d) },
        { title: 'Operador', dataIndex: 'pos_operador', render: (v) => v ?? '—', responsive: ['md'] },
        { title: 'Pagamentos', render: (_, v) => (v.pos_pagamentos ?? []).map((p) => `${p.nome ?? p.tipo}: ${formatarKz(p.valor)}`).join(' · ') || '—' },
        { title: 'Troco', dataIndex: 'pos_troco', align: 'right', render: (v) => formatarKz(v) },
        { title: 'Total', dataIndex: 'total_bruto', align: 'right', render: (v) => formatarKz(v) },
        { title: 'Estado', dataIndex: 'estado', render: (e) => <EstadoPOS estado={e} /> },
      ]}
    />
  );
}
