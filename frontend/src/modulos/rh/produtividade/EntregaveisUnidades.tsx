import { Alert, Button, Card, Popconfirm, Select, Space, Table, Tag, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { scrollTabela } from '@/componentes/responsivo';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { analisarEntregaveis, anularConversao, converterEntregaveis } from '@/modulos/estrutura/tarefas/api';
import { useMutacao } from '@/modulos/estrutura/tarefas/comum';
import { fq, num, resumirAnalise } from '@/modulos/estrutura/tarefas/regras';
import type { ErroAnalise, GrupoExcluido, GrupoValido } from '@/modulos/estrutura/tarefas/tipos';
import type { Estrutura } from '@/modulos/estrutura/comum/arvore';
import { aplanar, construirArvore } from '@/modulos/estrutura/comum/arvore';
import type { PeriodoProdutividade } from '../api';
import { useAvisarErro } from '../comum/consultas';
import { mesPorExtenso } from '../comum/regras';

const CORES_MOTIVO: Record<string, string> = { JA_CONVERTIDA: 'blue', NAO_VALIDADA: 'orange', SEM_ITEM: 'default', FORA_DO_PRAZO: 'red' };

/**
 * RH › Produtividade › «Entregáveis das unidades» (estrutura_tarefas_ui.js aba, 842-870; especificação §3.10): escolher o
 * período aberto → analisar → tabela com os grupos (entregável × colaborador) válidos e os excluídos com o motivo →
 * Converter (idempotente: soma ao registo existente) / Anular conversão.
 */
export function EntregaveisUnidades({ periodos, periodoInicial }: { periodos: PeriodoProdutividade[]; periodoInicial?: number }) {
  const { pode } = useSessao();
  const abertos = periodos.filter((p) => p.estado !== 'FECHADO');
  const [periodoId, setPeriodoId] = useState<number | undefined>(() => (abertos.some((p) => p.id === periodoInicial) ? periodoInicial : abertos[0]?.id));
  const [unidades, setUnidades] = useState<number[]>([]);
  const estrutura = useQuery({ queryKey: ['rh', 'estrutura'], queryFn: () => obter<Estrutura>('/rh/estrutura'), retry: false, staleTime: 5 * 60_000 });
  const analise = useQuery({ queryKey: ['rh', 'produtividade', 'entregaveis', periodoId, unidades], queryFn: () => analisarEntregaveis(periodoId!, unidades), enabled: periodoId !== undefined, retry: false });
  useAvisarErro(analise.error, 'Não foi possível analisar os entregáveis');
  const converter = useMutacao((p: { id: number; unidades: number[] }) => converterEntregaveis(p.id, p.unidades), { invalidar: [['rh', 'produtividade'], ['rh', 'estrutura']] });
  const anular = useMutacao((id: number) => anularConversao(id), { invalidar: [['rh', 'produtividade'], ['rh', 'estrutura']] });
  const periodo = periodos.find((p) => p.id === periodoId);
  const a = analise.data;
  const r = resumirAnalise(a);
  const validas = a?.validas ?? [];
  const excluidas = a?.excluidas ?? [];
  const erros = a?.erros ?? [];
  const registar = pode('rh_prod_registar');
  const opcoesUnidades = estrutura.data ? aplanar(construirArvore(estrutura.data.unidades)).map((n) => ({ value: n.unidade.id, label: `${'— '.repeat(n.nivel)}${n.unidade.nome}` })) : [];

  const colValidas: ColumnsType<GrupoValido> = [
    { title: 'Entregável', key: 'e', render: (_, g) => <><b>{g.entregavel}</b><div style={{ fontSize: '0.74rem', color: '#64748b' }}>item {g.item_codigo}</div></> },
    { title: 'Colaborador', key: 'c', render: (_, g) => <>{g.colaborador_nome}{!g.ja_elegivel && <div style={{ fontSize: '0.72rem', color: '#0369a1' }}><IconeFa nome="user-plus" /> passa a elegível</div>}{g.sem_evidencia ? <div style={{ fontSize: '0.72rem', color: '#b91c1c' }}><IconeFa nome="paperclip" /> {g.sem_evidencia} sem evidência</div> : null}</> },
    { title: 'A converter', key: 'q', align: 'right', render: (_, g) => <span style={{ whiteSpace: 'nowrap' }}>{fq(g.quantidade)}<div style={{ fontSize: '0.72rem', color: '#64748b' }}>{g.registos} entrega(s)</div></span> },
    { title: 'Fica no período', key: 'p', align: 'right', render: (_, g) => <>{fq(g.quantidade_total)}{g.registo_existente_id ? <div style={{ fontSize: '0.72rem', color: '#b45309' }}>já tinha {fq(num(g.quantidade_total) - num(g.quantidade))}</div> : null}</> },
    { title: 'Acréscimo', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) },
    { title: 'Subsídio do período', dataIndex: 'valor_total', align: 'right', render: (v) => <b>{formatarKz(v)}</b> },
  ];
  const colExcl: ColumnsType<GrupoExcluido> = [
    { title: 'Entregável', dataIndex: 'entregavel', render: (v: string) => <b>{v}</b> },
    { title: 'Colaborador', dataIndex: 'colaborador_nome' },
    { title: 'Quantidade', key: 'q', align: 'right', render: (_, g) => <span style={{ whiteSpace: 'nowrap' }}>{fq(g.quantidade)} <small style={{ color: '#64748b' }}>({g.registos})</small></span> },
    { title: 'Motivo', key: 'm', render: (_, g) => <><Tag color={CORES_MOTIVO[g.codigo] ?? 'default'}>{g.codigo}</Tag> {g.motivo}</> },
  ];
  const colErros: ColumnsType<ErroAnalise> = [
    { title: 'Entregável', dataIndex: 'entregavel', render: (v: string) => <b>{v}</b> },
    { title: 'Colaborador', dataIndex: 'colaborador_nome' },
    { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (v) => fq(v) },
    { title: 'Porque não entra', dataIndex: 'mensagem', render: (v: string) => <Typography.Text type="danger">{v}</Typography.Text> },
  ];

  return (
    <Card size="small" title={<Space><IconeFa nome="exchange-alt" />Entregáveis das unidades → produtividade</Space>}>
      <Typography.Paragraph type="secondary">
        As entregas <b>validadas</b> dos entregáveis das unidades orgânicas, dentro da janela de medição do período, passam a registos de produtividade (origem ESTRUTURA). Quem executou fica elegível para o item ao converter — a elegibilidade é registada em RH › Produtividade › Elegibilidade, não no contrato. Repetir a conversão não duplica: soma ao registo existente.
      </Typography.Paragraph>
      <Space wrap style={{ marginBottom: 12 }}>
        <Select<number> style={{ minWidth: 320 }} value={periodoId} onChange={setPeriodoId} aria-label="Período de produtividade" placeholder="— sem períodos abertos —"
          options={abertos.map((p) => ({ value: p.id, label: `${mesPorExtenso(p.mes)} · medição ${formatarData(p.data_inicio)} a ${formatarData(p.data_fim)}` }))} />
        <Select mode="multiple" allowClear style={{ minWidth: 280 }} placeholder="Todas as unidades" aria-label="Unidades" value={unidades} onChange={setUnidades} loading={estrutura.isLoading} optionFilterProp="label" options={opcoesUnidades} />
        <Button icon={<IconeFa nome="search" />} loading={analise.isFetching} disabled={!periodoId} onClick={() => analise.refetch()}>Analisar</Button>
      </Space>
      {!abertos.length && <Alert type="info" showIcon title="Não há períodos de produtividade abertos. Crie um com «Novo período»." />}
      {a && periodo && (
        <>
          <GrelhaKpi larguraMinima={150} style={{ marginBottom: 12 }}>
            <CartaoKpi barra="nenhuma" rotulo="Grupos a converter" valor={r.validas} />
            <CartaoKpi barra="nenhuma" rotulo="Excluídos" valor={r.excluidas + r.erros} corValor={r.excluidas + r.erros ? '#b45309' : undefined} />
            <CartaoKpi barra="nenhuma" rotulo="Passam a elegíveis" valor={r.elegiveis} />
            <CartaoKpi barra="nenhuma" rotulo="Já convertidas" valor={a.convertidas ?? 0} secundaria="entregas do período" />
            <CartaoKpi barra="nenhuma" rotulo="Acréscimo desta conversão" valor={formatarKz(r.total)} unidade="Kz" />
          </GrelhaKpi>
          <Table<GrupoValido> rowKey="origem_chave" size="small" dataSource={validas} columns={colValidas} pagination={false} scroll={scrollTabela()}
            locale={{ emptyText: 'Nada por converter neste período.' }}
            summary={() => validas.length ? (
              <Table.Summary.Row><Table.Summary.Cell index={0} colSpan={4}><b>Total do acréscimo</b></Table.Summary.Cell><Table.Summary.Cell index={4} align="right"><b>{formatarKz(a.total)}</b></Table.Summary.Cell><Table.Summary.Cell index={5} /></Table.Summary.Row>
            ) : null} />
          <Typography.Paragraph type="secondary" style={{ fontSize: '0.78rem', marginTop: 6 }}>O subsídio é calculado sobre a quantidade <b>acumulada do período</b>, não só sobre o que agora se converte — é por isso que as duas colunas podem não coincidir.</Typography.Paragraph>
          {(a.por_elegibilizar ?? []).length > 0 && (
            <Alert type="info" showIcon style={{ marginBottom: 12 }} title={`${a.por_elegibilizar.length} colaborador(es) passam a ser elegíveis para o item de produtividade por terem executado o entregável`}
              description={<>A elegibilidade fica em RH › Produtividade › Elegibilidade — não toca no contrato: <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>{a.por_elegibilizar.slice(0, 6).map((x) => <li key={`${x.colaborador_id}-${x.item_produtividade_id}`}>{x.colaborador_nome} → {x.item_codigo} a {formatarKz(x.preco)}</li>)}{a.por_elegibilizar.length > 6 && <li>…</li>}</ul></>} />
          )}
          {excluidas.length > 0 && (
            <>
              <Typography.Title level={5}>Não entram ({excluidas.length}){r.porMotivo.map((m) => <Tag key={m.codigo} color={CORES_MOTIVO[m.codigo] ?? 'default'} style={{ marginLeft: 8 }}>{m.n} {m.codigo}</Tag>)}</Typography.Title>
              <Table<GrupoExcluido> rowKey={(g) => `${g.entregavel_id}-${g.colaborador_id}-${g.codigo}`} size="small" dataSource={excluidas} columns={colExcl} pagination={excluidas.length > 20 ? { pageSize: 20 } : false} scroll={scrollTabela()} />
              {r.porMotivo.some((m) => m.codigo === 'NAO_VALIDADA') && <Alert type="warning" showIcon style={{ marginTop: 8 }} title="Os grupos à espera do visto do responsável não entram: valide as entregas na estrutura orgânica (botão «Entregas» de cada entregável) ou no portal." />}
            </>
          )}
          {erros.length > 0 && (
            <>
              <Typography.Title level={5}>Com erro ({erros.length})</Typography.Title>
              <Table<ErroAnalise> rowKey={(g) => `${g.entregavel_id}-${g.colaborador_id}`} size="small" dataSource={erros} columns={colErros} pagination={false} scroll={scrollTabela()} />
            </>
          )}
          {registar && (
            <Space wrap style={{ marginTop: 12, justifyContent: 'flex-end', width: '100%' }}>
              <Popconfirm title="Anular a conversão deste período?" description="Apaga os registos de produtividade de origem ESTRUTURA do período e liberta as entregas." okText="Anular" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => anular.mutateAsync(periodo.id)}>
                <Button danger icon={<IconeFa nome="undo" />} loading={anular.isPending} disabled={!a.convertidas}>Anular conversão</Button>
              </Popconfirm>
              <Button type="primary" icon={<IconeFa nome="exchange-alt" />} disabled={!validas.length} loading={converter.isPending} onClick={() => converter.mutate({ id: periodo.id, unidades })}>Converter em produtividade</Button>
            </Space>
          )}
        </>
      )}
    </Card>
  );
}
