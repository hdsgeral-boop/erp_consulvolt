import { Alert, Button, Card, InputNumber, Segmented, Space, Table, Tag, Tooltip } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState, type Key } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { CartaoKpi, GrelhaKpi, CORES_KPI } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { ESTADOS_NIVEL, corpoPedidoReposicao, sugestoes, type EstadoNivel, type LinhaNivel, type RespostaNiveis } from './niveis';

/** Avaliação dos níveis de stock (servidor, em cache Redis). */
export function useNiveisStock(activo = true) {
  return useQuery({ queryKey: ['logistica', 'niveis-stock'], queryFn: () => obter<RespostaNiveis>('/logistica/niveis-stock'), staleTime: 60_000, retry: false, enabled: activo });
}

const qtd = (x: string | null | undefined) => (x === null || x === undefined ? '—' : formatarNumero(x));

/**
 * Armazém › Níveis de stock — «Alertas e sugestões de reposição» (ronda 3): cartões com os críticos, as reposições e os
 * excessos, a lista filtrável e, nas sugestões, a quantidade a encomendar (editável) e «Gerar pedido de compra» pelo
 * fluxo das Compras (numeração PC e deliberação).
 */
export function AlertasNiveis() {
  const { pode } = useSessao();
  const podePedido = pode('compras_ped_criar');
  const consulta = useNiveisStock();
  const pequeno = useEcraPequeno();
  const [filtro, setFiltro] = useState<EstadoNivel | 'SUGESTOES'>('SUGESTOES');
  const [escolhidos, setEscolhidos] = useState<Key[]>([]);
  const [quantidades, setQuantidades] = useState<Record<number, number | null>>({});
  const gerar = useAccao<{ numero_pedido: string }>({
    invalidar: [['logistica', 'niveis-stock'], ['compras']],
    aoSucesso: () => { setEscolhidos([]); setQuantidades({}); },
    tituloErro: 'Não foi possível gerar o pedido de compra',
  });

  const linhas = useMemo(() => {
    const todas = consulta.data?.linhas ?? [];
    return filtro === 'SUGESTOES' ? sugestoes(todas) : todas.filter((l) => l.estado === filtro);
  }, [consulta.data, filtro]);
  const r = consulta.data?.resumo;
  const nSugestoes = sugestoes(consulta.data?.linhas ?? []).length;
  const escolhidas = linhas.filter((l) => escolhidos.includes(l.produto_id));

  if (consulta.error) return <Alert type="error" showIcon style={{ marginBottom: 16 }} message="Não foi possível avaliar os níveis de stock." />;
  if (!consulta.isLoading && !consulta.data?.linhas.length) return null;   // nenhum produto precisa de atenção

  return (
    <Card title={<Space><IconeFa nome="bell" /> Alertas de níveis de stock e sugestões de reposição</Space>} style={{ marginBottom: 16 }} loading={consulta.isLoading}>
      <GrelhaKpi larguraMinima={180} style={{ marginBottom: 16 }}>
        <CartaoKpi rotulo="Stock crítico" valor={r?.criticos ?? 0} icone="exclamation-triangle" cor={CORES_KPI.vermelho} ajuda="Abaixo do mínimo ou do stock de segurança" aoClicar={() => setFiltro('CRITICO')} activo={filtro === 'CRITICO'} />
        <CartaoKpi rotulo="Ponto de encomenda" valor={r?.reposicao ?? 0} icone="truck-loading" cor={CORES_KPI.laranja} ajuda="Stock + encomendas em curso no ponto de encomenda ou abaixo" aoClicar={() => setFiltro('REPOSICAO')} activo={filtro === 'REPOSICAO'} />
        <CartaoKpi rotulo="Excesso" valor={r?.excesso ?? 0} icone="boxes" cor={CORES_KPI.roxo} ajuda="Acima do stock máximo" aoClicar={() => setFiltro('EXCESSO')} activo={filtro === 'EXCESSO'} />
        <CartaoKpi rotulo="Reposição sugerida" valor={formatarKz(r?.valor_sugerido ?? 0)} unidade="Kz" secundaria={`${nSugestoes} artigo(s), ao custo médio`} icone="shopping-cart" cor={CORES_KPI.azul} aoClicar={() => setFiltro('SUGESTOES')} activo={filtro === 'SUGESTOES'} />
      </GrelhaKpi>
      <Space wrap style={{ marginBottom: 12, justifyContent: 'space-between', width: '100%' }}>
        <Segmented<EstadoNivel | 'SUGESTOES'>
          value={filtro}
          onChange={(v) => { setFiltro(v); setEscolhidos([]); }}
          options={[{ value: 'SUGESTOES', label: `Sugestões de reposição (${nSugestoes})` }, { value: 'CRITICO', label: 'Críticos' }, { value: 'REPOSICAO', label: 'Reposição' }, { value: 'EXCESSO', label: 'Excesso' }]}
        />
        {filtro !== 'EXCESSO' && podePedido && (
          <Button
            type="primary"
            icon={<IconeFa nome="file-invoice" />}
            disabled={!escolhidas.length}
            loading={gerar.isPending}
            onClick={() => gerar.mutate({ url: '/logistica/niveis-stock/pedido-compra', dados: corpoPedidoReposicao(escolhidas, quantidades) })}
          >
            Gerar pedido de compra ({escolhidas.length})
          </Button>
        )}
      </Space>
      <Table<LinhaNivel>
        rowKey="produto_id"
        size={pequeno ? 'small' : 'middle'}
        scroll={scrollTabela()}
        dataSource={linhas}
        pagination={linhas.length > 20 ? { pageSize: 20 } : false}
        rowSelection={filtro !== 'EXCESSO' && podePedido ? { selectedRowKeys: escolhidos, onChange: setEscolhidos, getCheckboxProps: (l) => ({ disabled: Number(l.quantidade_sugerida) <= 0 && quantidades[l.produto_id] == null, 'aria-label': `Escolher ${l.nome}` }) } : undefined}
        locale={{ emptyText: 'Nada a assinalar neste filtro.' }}
        columns={[
          { title: 'Estado', dataIndex: 'estado', render: (e: EstadoNivel, l) => <Tooltip title={l.motivo}><Tag color={ESTADOS_NIVEL[e].cor}>{ESTADOS_NIVEL[e].rotulo}</Tag></Tooltip> },
          { title: 'Código', dataIndex: 'codigo', render: (x: string | null) => x ?? '—' },
          { title: 'Produto', dataIndex: 'nome', render: (x: string, l) => <Space direction="vertical" size={0}><strong>{x}</strong>{l.motivo && <small style={{ opacity: 0.75 }}>{l.motivo}</small>}</Space> },
          { title: 'Stock', dataIndex: 'stock', align: 'right', render: qtd },
          { title: 'Em encomenda', dataIndex: 'em_encomenda', align: 'right', render: (x: string) => (Number(x) ? qtd(x) : '—') },
          { title: 'Mín. / Seg.', key: 'ms', align: 'right', render: (_, l) => `${qtd(l.stock_minimo)} / ${qtd(l.stock_seguranca)}` },
          { title: 'Ponto enc.', dataIndex: 'ponto_encomenda', align: 'right', render: qtd },
          { title: 'Máximo', dataIndex: 'stock_maximo', align: 'right', render: qtd },
          ...(filtro !== 'EXCESSO'
            ? [{
                title: 'A encomendar', key: 'q', align: 'right' as const, width: 140,
                render: (_: unknown, l: LinhaNivel) => (
                  <InputNumber
                    size="small"
                    min={0}
                    style={{ width: 110 }}
                    aria-label={`Quantidade a encomendar de ${l.nome}`}
                    value={quantidades[l.produto_id] ?? Number(l.quantidade_sugerida)}
                    onChange={(v) => setQuantidades((q) => ({ ...q, [l.produto_id]: v }))}
                    disabled={!podePedido}
                  />
                ),
              }, { title: 'Valor (CMP)', dataIndex: 'valor_sugerido', align: 'right' as const, render: (x: string) => `${formatarKz(x)} Kz` }]
            : []),
        ]}
      />
    </Card>
  );
}
