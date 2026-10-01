import { Alert, Button, Descriptions, Modal, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { NomeArmazem } from '@/modulos/compras/comum/referencias';
import { SeletorProduto } from '@/modulos/compras/comum/Seletores';
import type { MovimentoRecalculado, ProdutoRecalculado, ResultadoRecalculo } from './tipos';

/** Rótulo do botão de aplicação: só se aplica depois de uma simulação com alterações. */
export function podeAplicarRecalculo(r: ResultadoRecalculo | null | undefined): boolean {
  return !!r && !r.aplicado && r.produtos.some((p) => p.movimentos_alterados > 0 || p.custo_medio_alterado);
}

/**
 * Armazém › «Recalcular valorizações de stock» (tarefa armazem_recalcular). Primeiro simula (nada é gravado) e mostra o
 * relatório; só depois se aplica. O servidor não altera valores já contabilizados: aparecem como divergências informativas.
 */
export function RecalculoValorizacoes({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const [produto, setProduto] = useState<number>();
  const [resultado, setResultado] = useState<ResultadoRecalculo | null>(null);
  const accao = useAccao<ResultadoRecalculo>({ invalidar: [['logistica']], aoSucesso: (r) => setResultado(r), tituloErro: 'Não foi possível recalcular as valorizações' });
  const executar = (aplicar: boolean) => accao.mutate({ url: '/logistica/stock/recalcular-valorizacoes', dados: { produto_id: produto, aplicar } });
  const fechar = () => {
    setResultado(null);
    aoFechar();
  };

  return (
    <Modal
      title="Recalcular valorizações de stock"
      open={aberto}
      onCancel={fechar}
      width={1000}
      destroyOnClose
      footer={
        <Space>
          <Button onClick={fechar}>Fechar</Button>
          <Button loading={accao.isPending} onClick={() => executar(false)}>
            Simular
          </Button>
          <Button
            type="primary"
            danger
            disabled={!podeAplicarRecalculo(resultado)}
            loading={accao.isPending}
            onClick={() =>
              Modal.confirm({
                title: 'Aplicar o recálculo?',
                content: 'Os valores dos movimentos não contabilizados e o custo médio dos produtos são actualizados numa única transacção.',
                okText: 'Aplicar',
                cancelText: 'Cancelar',
                onOk: () => executar(true),
              })
            }
          >
            Aplicar
          </Button>
        </Space>
      }
    >
      <Typography.Paragraph type="secondary">
        Refaz o custo médio ponderado e o valor das saídas pela ordem cronológica dos movimentos, a partir do acerto da migração. As entradas mantêm o custo do
        documento; as saídas de documentos contabilizados não são alteradas. Comece por simular.
      </Typography.Paragraph>
      <Space style={{ marginBottom: 12 }} wrap>
        <SeletorProduto allowClear style={{ width: 320 }} value={produto} onChange={(v) => { setProduto(v); setResultado(null); }} />
        <Typography.Text type="secondary">{produto ? 'Só este produto.' : 'Todos os produtos com movimentos.'}</Typography.Text>
      </Space>
      {resultado && (
        <>
          <Alert
            type={resultado.aplicado ? 'success' : 'info'}
            showIcon
            style={{ marginBottom: 12 }}
            message={resultado.aplicado ? 'Recálculo aplicado.' : 'Simulação: nada foi gravado.'}
            description={
              <Descriptions size="small" column={{ xs: 1, md: 3 }}>
                <Descriptions.Item label="Produtos analisados">{resultado.resumo.produtos_analisados}</Descriptions.Item>
                <Descriptions.Item label="Com alterações">{resultado.resumo.produtos_com_alteracoes}</Descriptions.Item>
                <Descriptions.Item label="Movimentos revalorizados">{resultado.resumo.movimentos_alterados}</Descriptions.Item>
                <Descriptions.Item label="Divergências contabilizadas">{resultado.resumo.divergencias_contabilizadas}</Descriptions.Item>
                <Descriptions.Item label="Diferença de valor">{formatarKz(resultado.resumo.diferenca_valor)} Kz</Descriptions.Item>
              </Descriptions>
            }
          />
          {resultado.avisos.map((a) => (
            <Alert key={a} type="warning" showIcon style={{ marginBottom: 8 }} message={a} />
          ))}
          <Table<ProdutoRecalculado>
            rowKey="produto_id"
            size="small"
            pagination={{ pageSize: 10 }}
            scroll={{ x: 'max-content' }}
            dataSource={resultado.produtos}
            locale={{ emptyText: 'Sem diferenças: as valorizações estão coerentes com o histórico.' }}
            expandable={{ expandedRowRender: (p) => <MovimentosProduto linhas={p.movimentos} />, rowExpandable: (p) => p.movimentos.length > 0 }}
            columns={[
              { title: 'Produto', key: 'p', render: (_, p) => (p.codigo ? `${p.codigo} — ${p.nome}` : p.nome) },
              { title: 'Custo médio actual', dataIndex: 'custo_medio_atual', align: 'right', render: (v: string) => formatarKz(v) },
              { title: 'Recalculado', dataIndex: 'custo_medio_recalculado', align: 'right', render: (v: string, p) => <strong style={p.custo_medio_alterado ? { color: '#d4380d' } : undefined}>{formatarKz(v)}</strong> },
              { title: 'Movimentos', dataIndex: 'movimentos_alterados', align: 'right' },
              { title: 'Contabilizados', dataIndex: 'divergencias_contabilizadas', align: 'right', render: (n: number) => (n ? <Tag color="orange">{n}</Tag> : 0) },
              { title: 'Diferença (Kz)', dataIndex: 'diferenca_valor', align: 'right', render: (v: string) => formatarKz(v) },
            ]}
          />
        </>
      )}
    </Modal>
  );
}

function MovimentosProduto({ linhas }: { linhas: MovimentoRecalculado[] }) {
  return (
    <Table<MovimentoRecalculado>
      rowKey={(m) => `${m.movimento_id}-${m.contabilizado ? 'c' : 'a'}`}
      size="small"
      pagination={false}
      dataSource={linhas}
      columns={[
        { title: 'Data', dataIndex: 'data', render: formatarData },
        { title: 'Tipo', key: 't', render: (_, m) => `${m.tipo} · ${m.sentido === 'E' ? 'entrada' : 'saída'}` },
        { title: 'Armazém', dataIndex: 'armazem_id', render: (v: number) => <NomeArmazem id={v} /> },
        { title: 'Documento', key: 'd', render: (_, m) => (m.documento_tipo ? `${m.documento_tipo}${m.documento_id ? ` #${m.documento_id}` : ''}` : '—') },
        { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
        { title: 'Valor actual', dataIndex: 'valor_atual', align: 'right', render: (v: string) => formatarKz(v) },
        { title: 'Valor recalculado', dataIndex: 'valor_recalculado', align: 'right', render: (v: string) => formatarKz(v) },
        { title: '', dataIndex: 'contabilizado', render: (c: boolean) => (c ? <Tag color="orange">Contabilizado — não aplicado</Tag> : null) },
      ]}
    />
  );
}
