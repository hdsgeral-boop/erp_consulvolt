import { Alert, Button, Card, Descriptions, Empty, Flex, Select, Space, Spin, Table, Tag, Typography, message } from 'antd';
import { SendOutlined, SearchOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { formatarQtd } from './regrasMetas';
import type { AnaliseProdutividade, LinhaRecolha, RecolhaProdutividade } from './tipos';

export const TIPOS_PONTE: Record<string, string> = { HORAS: 'Horas das folhas de horas', QUANTIDADE: 'Quantidades medidas nas metas', TAREFAS: 'Tarefas concluídas' };

/**
 * Ponte Execução de projectos → RH › Produtividade (Versão Actual, js/modules/rh/produtividade_projectos.js:251-386):
 * recolhe horas, quantidades medidas e tarefas concluídas no intervalo, sugere o item de produtividade de cada grupo
 * (mesma métrica e unidade equivalente), analisa contra o período e os contratos (sem gravar) e envia. Reenviar
 * actualiza os registos em vez de os duplicar. Serve o separador Execução do projecto e o separador do RH.
 */
export function PonteProdutividade({ de, ate, projetos }: { de: string; ate: string; projetos?: number[] }) {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [periodo, setPeriodo] = useState<number>();
  const [mapa, setMapa] = useState<Record<string, number | null>>({});
  const [analise, setAnalise] = useState<AnaliseProdutividade | null>(null);
  const [aAnalisar, setAAnalisar] = useState(false);
  const [aEnviar, setAEnviar] = useState(false);
  const chaveProj = (projetos ?? []).join(',');
  const q = useQuery({
    queryKey: ['rh', 'produtividade-projetos', de, ate, chaveProj],
    queryFn: () => obter<RecolhaProdutividade>('/rh/produtividade/projetos/recolha', { de, ate, ...(projetos?.length ? { projetos } : {}) }),
    enabled: !!de && !!ate,
    retry: false,
  });
  const r = q.data;
  useEffect(() => {
    setAnalise(null);
    if (!r) return;
    setMapa(Object.fromEntries(r.grupos.map((g) => [g.chave, g.item_sugerido_id])));
    setPeriodo((p) => p ?? r.periodos.find((x) => x.data_inicio <= ate && x.data_fim >= de)?.id ?? r.periodos[0]?.id);
  }, [r, de, ate]);
  const corpo = useMemo(() => ({ de, ate, projetos: projetos?.length ? projetos : undefined, periodo_id: periodo, mapa }), [de, ate, projetos, periodo, mapa]);

  const analisar = async () => {
    setAAnalisar(true);
    try {
      setAnalise((await enviar<AnaliseProdutividade>('post', '/rh/produtividade/projetos/analisar', corpo)).dados);
    } catch (e) {
      notificarErro(e, 'A análise falhou');
    } finally {
      setAAnalisar(false);
    }
  };
  const enviarRegistos = async () => {
    setAEnviar(true);
    try {
      const res = await enviar<{ criados: number; actualizados: number; total: number }>('post', '/rh/produtividade/projetos/enviar', corpo);
      void cliente.invalidateQueries({ queryKey: ['rh'] });
      setAnalise(null);
      void q.refetch();
      void message.success(res.mensagem);
    } catch (e) {
      notificarErro(e, 'Não foi possível enviar para a produtividade');
    } finally {
      setAEnviar(false);
    }
  };

  if (q.isLoading) return <Spin style={{ display: 'block', margin: 24 }} />;
  if (q.error) return <Alert type="error" showIcon message={(q.error as Error).message} />;
  if (!r) return null;
  const quem = (l: LinhaRecolha) => l.nome ?? l.motivo ?? '—';

  return (
    <Space direction="vertical" size={12} style={{ width: '100%' }}>
      {!r.linhas.length && !r.orfas.length ? <Empty description="Sem horas, medições nem tarefas concluídas no intervalo." /> : (
        <>
          <Typography.Text type="secondary">Escolha o item de produtividade de cada grupo (sugerido: mesma métrica e unidade equivalente) e o período. Só entram colaboradores com o item no contrato activo.</Typography.Text>
          <Table size="small" rowKey="chave" pagination={false} dataSource={r.grupos} scroll={scrollTabela()}
            columns={[
              { title: 'Origem', dataIndex: 'tipo', render: (v) => TIPOS_PONTE[v] ?? v },
              { title: 'Unidade', dataIndex: 'unidade' },
              { title: 'Colaboradores', dataIndex: 'colaboradores', align: 'right' },
              { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (v) => formatarQtd(v, 4) },
              { title: 'Item de produtividade', key: 'i', width: 300, render: (_, g) => (
                <Select allowClear size="small" style={{ width: '100%' }} placeholder="— não enviar —" value={mapa[g.chave] ?? undefined}
                  onChange={(v) => { setMapa((m) => ({ ...m, [g.chave]: v ?? null })); setAnalise(null); }}
                  options={g.candidatos.map((c) => ({ value: c.id, label: `${c.codigo} — ${c.descricao ?? ''}${c.unidade ? ` (${c.unidade})` : ''}` }))}
                  notFoundContent="Nenhum item activo com esta métrica" />
              ) },
            ]} />
          {r.orfas.length > 0 && (
            <Alert type="warning" showIcon message="Execução sem colaborador interno (não entra no subsídio)"
              description={<ul style={{ margin: 0, paddingLeft: 18 }}>{r.orfas.map((o) => <li key={`${o.tipo}|${o.unidade}|${o.motivo}`}>{TIPOS_PONTE[o.tipo]}: {formatarQtd(o.quantidade, 4)} {o.unidade} — {o.motivo}</li>)}</ul>} />
          )}
          <Flex gap={8} wrap align="center">
            <Select style={{ width: 260 }} placeholder="Período de produtividade (aberto)" value={periodo} onChange={(v) => { setPeriodo(v); setAnalise(null); }}
              options={r.periodos.map((p) => ({ value: p.id, label: `${p.mes} (${formatarData(p.data_inicio)} a ${formatarData(p.data_fim)})` }))} notFoundContent="Não há períodos abertos" />
            <Button icon={<SearchOutlined />} onClick={analisar} loading={aAnalisar} disabled={!periodo}>Analisar</Button>
            {pode('rh_prod_registar') && <Button type="primary" icon={<SendOutlined />} onClick={enviarRegistos} loading={aEnviar} disabled={!analise?.validas.length}>Enviar para a produtividade</Button>}
          </Flex>
        </>
      )}
      {analise && (
        <Card size="small" title={`Análise — ${analise.periodo.mes} (nada foi gravado)`}>
          <Descriptions size="small" column={{ xs: 1, md: 4 }} items={[
            { key: 'v', label: 'A enviar', children: analise.validas.length },
            { key: 't', label: 'Valor estimado', children: formatarKz(analise.total, true) },
            { key: 'e', label: 'Com erro', children: analise.erros.length },
            { key: 's', label: 'Sem item', children: analise.sem_item.length },
          ]} />
          <Table size="small" rowKey="chave" pagination={false} dataSource={analise.validas} scroll={scrollTabela()} style={{ marginTop: 8 }}
            columns={[
              { title: 'Colaborador', key: 'c', render: (_, v) => quem(v.linha) },
              { title: 'Item', key: 'i', render: (_, v) => v.item.codigo },
              { title: 'Quantidade', key: 'q', align: 'right', render: (_, v) => `${formatarQtd(v.linha.quantidade, 4)} ${v.linha.unidade}` },
              { title: 'Data', dataIndex: 'data', render: formatarData },
              { title: 'Preço', dataIndex: 'preco_unitario', align: 'right', render: (x) => formatarKz(x) },
              { title: 'Valor', dataIndex: 'valor', align: 'right', render: (x) => formatarKz(x) },
              { title: '', key: 'e', render: (_, v) => (v.registo_existente_id ? <Tag color="blue">actualiza</Tag> : <Tag color="green">novo</Tag>) },
            ]} />
          {analise.erros.length > 0 && (
            <Alert style={{ marginTop: 8 }} type="error" showIcon message="Linhas que não podem ser enviadas"
              description={<ul style={{ margin: 0, paddingLeft: 18 }}>{analise.erros.map((e, i) => <li key={i}>{quem(e.linha)} · {e.item.codigo}: {e.erro}</li>)}</ul>} />
          )}
        </Card>
      )}
    </Space>
  );
}
