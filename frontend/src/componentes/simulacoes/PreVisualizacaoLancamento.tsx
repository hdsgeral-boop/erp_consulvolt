import { Alert, Button, Descriptions, Modal, Space, Table, Tag, Typography } from 'antd';
import { EyeOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { BotoesExportar, CSS_SIMULACAO_COMUM, marcaSimulacao, tabelaLancamentoHtml, type PedidoImpressao } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';

/** Resposta de GET …/contabilizacao/pre-visualizacao (PreVisualizacaoContabilizacaoController). */
export interface PreVisualizacaoLancamento {
  diario: string | null;
  data_documento: string | null;
  numero_documento: string | null;
  referencia: string | null;
  descricao: string | null;
  linhas: {
    codigo_conta: string;
    nome_conta: string | null;
    tipo_dc: 'D' | 'C';
    valor: string;
    terceiro: string | null;
    unidade_negocio: string | null;
    centro_custo: string | null;
    projeto: string | null;
    codigo_moeda: string | null;
    valor_moeda: string | null;
  }[];
  total_debito: string;
  total_credito: string;
  equilibrado: boolean;
}

type Linha = PreVisualizacaoLancamento['linhas'][number];

/** Pedido de impressão da pré-visualização (marcada «Simulação»). */
export function pedidoPreVisualizacaoLancamento(p: PreVisualizacaoLancamento, titulo: string): PedidoImpressao {
  return {
    titulo,
    subtitulo: p.descricao,
    filtros: [p.diario ? `Diário ${p.diario}` : null, p.data_documento ? `Data ${formatarData(p.data_documento)}` : null, p.referencia ? `Referência ${p.referencia}` : null],
    conteudo:
      marcaSimulacao('pré-visualização do lançamento calculada no servidor com as regras da contabilização; nada foi gravado.') +
      tabelaLancamentoHtml(
        p.linhas.map((l) => ({
          conta: l.codigo_conta,
          descricao: [l.nome_conta, l.terceiro, l.projeto ? `Projecto ${l.projeto}` : null].filter(Boolean).join(' · ') || null,
          unidade: l.unidade_negocio,
          centro: l.centro_custo,
          tipo_dc: l.tipo_dc,
          valor: l.valor,
        })),
      ),
    cssExtra: CSS_SIMULACAO_COMUM,
    orientacao: 'retrato',
  };
}

/**
 * «Pré-visualizar lançamento» (legado `showPostingPreview` / «Simulação contabilística»): mostra, antes de contabilizar,
 * as linhas que o servidor vai gravar — contas, terceiro, UN/CC/projecto, débito e crédito, totais e equilíbrio — e
 * permite imprimir. Nada é gravado.
 */
export function BotaoPreVisualizarLancamento({ url, titulo, tamanho, aoContabilizar, aContabilizar }: {
  /** Endpoint GET da pré-visualização (ex.: `/vendas/documentos/12/contabilizacao/pre-visualizacao`). */
  url: string;
  titulo: string;
  tamanho?: 'small' | 'middle' | 'large';
  /** Se indicado, o modal mostra «Contabilizar» (acção do ecrã). */
  aoContabilizar?: () => void;
  aContabilizar?: boolean;
}) {
  const [aberto, setAberto] = useState(false);
  const q = useQuery({ queryKey: ['simulacoes', 'lancamento', url], queryFn: () => obter<PreVisualizacaoLancamento>(url), enabled: aberto, retry: false, staleTime: 0, gcTime: 0 });
  const p = q.data;
  const moeda = p?.linhas.some((l) => l.codigo_moeda && l.codigo_moeda !== 'AOA');
  return (
    <>
      <Button size={tamanho} icon={<EyeOutlined />} onClick={() => setAberto(true)}>Pré-visualizar lançamento</Button>
      <Modal open={aberto} onCancel={() => setAberto(false)} width={larguraModal(980)} destroyOnHidden title={`Pré-visualização do lançamento — ${titulo}`}
        footer={
          <Space wrap>
            <BotoesExportar chave="pre-visualizacao lancamento" excel={false} desactivado={!p} textoImprimir="Imprimir simulação"
              obterPedido={() => p && pedidoPreVisualizacaoLancamento(p, `Simulação do lançamento — ${titulo}`)} />
            <Button onClick={() => setAberto(false)}>Fechar</Button>
            {aoContabilizar && <Button type="primary" disabled={!p || !p.equilibrado} loading={aContabilizar} onClick={() => { aoContabilizar(); setAberto(false); }}>Contabilizar</Button>}
          </Space>
        }>
        {q.isLoading && <Typography.Text type="secondary">A calcular no servidor…</Typography.Text>}
        {q.error && <Alert type="warning" showIcon message="Não é possível contabilizar este documento" description={(q.error as Error).message} />}
        {p && (
          <>
            <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Simulação — nada foi gravado" description="Estas são as linhas que a contabilização vai gravar (mesmas contas e valores)." />
            <Descriptions size="small" column={{ xs: 1, sm: 2, lg: 4 }} style={{ marginBottom: 12 }}>
              <Descriptions.Item label="Diário">{p.diario ?? '—'}</Descriptions.Item>
              <Descriptions.Item label="Data">{formatarData(p.data_documento)}</Descriptions.Item>
              <Descriptions.Item label="Documento">{p.numero_documento ?? '—'}</Descriptions.Item>
              <Descriptions.Item label="Equilíbrio">{p.equilibrado ? <Tag color="green">Equilibrado</Tag> : <Tag color="red">Desequilibrado</Tag>}</Descriptions.Item>
              {p.descricao && <Descriptions.Item label="Descrição" span={4}>{p.descricao}</Descriptions.Item>}
            </Descriptions>
            <Table<Linha> size="small" rowKey={(_, i) => String(i)} pagination={false} dataSource={p.linhas} scroll={scrollTabela()}
              columns={[
                { title: 'Conta', key: 'c', render: (_, l) => <span><strong>{l.codigo_conta}</strong>{l.nome_conta ? ` — ${l.nome_conta}` : ''}</span> },
                { title: 'Terceiro', dataIndex: 'terceiro', responsive: ['md'], render: (v: string | null) => v ?? '—' },
                { title: 'UN / CC / Projecto', key: 'a', responsive: ['lg'], render: (_, l) => [l.unidade_negocio, l.centro_custo, l.projeto].filter(Boolean).join(' · ') || '—' },
                ...(moeda ? [{ title: 'Em moeda', key: 'm', align: 'right' as const, render: (_: unknown, l: Linha) => (l.codigo_moeda && l.valor_moeda ? `${formatarKz(l.valor_moeda)} ${l.codigo_moeda}` : '') }] : []),
                { title: 'Débito (Kz)', key: 'd', align: 'right', render: (_, l) => (l.tipo_dc === 'D' ? formatarKz(l.valor) : '') },
                { title: 'Crédito (Kz)', key: 'cr', align: 'right', render: (_, l) => (l.tipo_dc === 'C' ? formatarKz(l.valor) : '') },
              ]}
              summary={() => (
                <Table.Summary.Row style={{ fontWeight: 600 }}>
                  <Table.Summary.Cell index={0} colSpan={moeda ? 4 : 3}>Totais</Table.Summary.Cell>
                  <Table.Summary.Cell index={1} align="right">{formatarKz(p.total_debito)}</Table.Summary.Cell>
                  <Table.Summary.Cell index={2} align="right">{formatarKz(p.total_credito)}</Table.Summary.Cell>
                </Table.Summary.Row>
              )} />
          </>
        )}
      </Modal>
    </>
  );
}
