import { Alert, Button, DatePicker, Modal, Space, Spin, Table, Typography, message } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { larguraModal } from '@/componentes/responsivo';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { estadoCRM, sincronizarCRM } from './api';
import { useMutacao } from './comum';
import { fq, textoSincronizacao, VALIDACAO } from './regras';
import type { ActividadeCRM, Entregavel, OrfaCRM } from './tipos';

/**
 * Ponte CRM → entregável (entregaveis_crm.js; estrutura_tarefas_ui.js abrirImportacaoCRM, 688-781): previsto vs feito no
 * intervalo, por realizar, por importar, entregas a retirar (órfãs), datas a corrigir, sem colaborador ligado; «Actualizar»
 * põe o entregável a par do CRM (cria, corrige datas e retira as órfãs só se pendentes e não convertidas — as validadas
 * ou pagas ficam presas e são reportadas).
 */
export function SincronizarCRM({ entregavel, aoFechar }: { entregavel: Entregavel | null; aoFechar: () => void }) {
  const [intervalo, setIntervalo] = useState<[Dayjs, Dayjs] | null>(null);
  const filtro = intervalo ? { de: dataApi(intervalo[0]), ate: dataApi(intervalo[1]) } : undefined;
  const q = useQuery({ queryKey: ['rh', 'estrutura', 'crm', entregavel?.id, filtro], queryFn: () => estadoCRM(entregavel!.id, filtro), enabled: !!entregavel, retry: false });
  const sync = useMutacao((id: number) => sincronizarCRM(id), {
    silenciosa: true,
    aoSucesso: (r) => {
      message.success(textoSincronizacao(r));
      if (r.sem_colaborador) message.warning(`${r.sem_colaborador} actividade(s) ficaram de fora: o utilizador que as concluiu não está ligado a um colaborador.`);
      if (r.presas?.length) message.warning(`${r.presas.length} entrega(s) não foram retiradas porque ${r.presas[0].motivo}.`);
      void q.refetch();
    },
  });
  if (!entregavel) return null;
  const s = q.data;
  const listaActividades = (titulo: string, itens: ActividadeCRM[] | undefined, cor?: string) => itens?.length ? (
    <div style={{ marginTop: 12 }}>
      <Typography.Text strong style={{ color: cor }}>{titulo} ({itens.length})</Typography.Text>
      <Table<ActividadeCRM> size="small" rowKey="actividade_id" pagination={itens.length > 20 ? { pageSize: 20 } : false} dataSource={itens} style={{ marginTop: 4 }}
        columns={[
          { title: 'Data', dataIndex: 'data', render: (v: string | null, a) => <span style={{ whiteSpace: 'nowrap', color: a.atrasada ? '#b91c1c' : undefined }}>{formatarData(v)}{a.atrasada ? ' (em atraso)' : ''}</span> },
          { title: 'Actividade', dataIndex: 'titulo' },
          { title: 'Quem', key: 'q', render: (_, a) => a.colaborador_nome ?? a.utilizador ?? '—' },
          { title: 'Oportunidade', key: 'o', render: (_, a) => (a.oportunidade ? `${a.oportunidade.titulo} (${a.oportunidade.estado})` : '') },
        ]} />
    </div>
  ) : null;
  const listaOrfas = (titulo: string, itens: OrfaCRM[] | undefined, cor: string) => itens?.length ? (
    <div style={{ marginTop: 12 }}>
      <Typography.Text strong style={{ color: cor }}>{titulo} ({itens.length})</Typography.Text>
      <Table<OrfaCRM> size="small" rowKey="execucao_id" pagination={false} dataSource={itens} style={{ marginTop: 4 }}
        columns={[
          { title: 'Data', dataIndex: 'data', render: (v: string) => formatarData(v) },
          { title: 'Quantidade', dataIndex: 'quantidade', align: 'right', render: (v) => fq(v) },
          { title: 'Validação', dataIndex: 'validacao', render: (v: OrfaCRM['validacao']) => VALIDACAO[v]?.rotulo ?? v },
          { title: 'Nota', dataIndex: 'motivo' },
        ]} />
    </div>
  ) : null;
  const pct = s && s.previstas ? Math.round((s.feitas / s.previstas) * 100) : 0;
  return (
    <Modal open title={`CRM — ${entregavel.nome}`} onCancel={aoFechar} width={larguraModal(860)} destroyOnHidden
      footer={<Space wrap>
        <Button onClick={aoFechar}>Fechar</Button>
        <Button icon={<IconeFa nome="search" />} loading={q.isFetching} onClick={() => q.refetch()}>Analisar</Button>
        <Button type="primary" icon={<IconeFa nome="sync" />} loading={sync.isPending} disabled={entregavel.origem_dados !== 'CRM'}
          title="Põe o entregável a par do CRM: traz o que foi concluído, corrige as datas que mudaram e retira o que foi reaberto ou apagado" onClick={() => sync.mutate(entregavel.id)}>Actualizar</Button>
      </Space>}>
      <Typography.Paragraph type="secondary">
        Traz as <b>actividades concluídas</b> do CRM que cumprem o filtro deste entregável. Cada actividade vira uma entrega de 1 unidade em nome de quem a concluiu, e <b>fica por validar</b>. Repetir não duplica nada.
      </Typography.Paragraph>
      <Space wrap style={{ marginBottom: 12 }}>
        <DatePicker.RangePicker format="DD/MM/YYYY" value={intervalo} onChange={(v) => setIntervalo(v && v[0] && v[1] ? [v[0], v[1]] : null)} placeholder={['Desde', 'Até']} aria-label="Intervalo" />
        {!intervalo && <Typography.Text type="secondary">Sem intervalo: todas as actividades.</Typography.Text>}
        {intervalo && <Button size="small" onClick={() => setIntervalo([dayjs().startOf('month'), dayjs().endOf('month')])}>Este mês</Button>}
      </Space>
      {entregavel.origem_dados !== 'CRM' && <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Este entregável não está marcado como alimentado pelo CRM. Na edição do entregável, escolha «Actividades concluídas do CRM» em «De onde vêm as entregas»." />}
      {q.isLoading && <Spin />}
      {q.isError && <Alert type="error" showIcon title="Não foi possível ler o CRM." />}
      {s && (
        <>
          <GrelhaKpi larguraMinima={140}>
            <CartaoKpi barra="nenhuma" rotulo="Previstas" valor={s.previstas} />
            <CartaoKpi barra="nenhuma" rotulo="Feitas" valor={s.feitas} secundaria={`${pct}% do previsto · ${s.importadas} já importada(s)`} />
            <CartaoKpi barra="nenhuma" rotulo="Por importar" valor={s.por_criar?.length ?? 0} corValor={s.por_criar?.length ? '#0369a1' : undefined} />
            <CartaoKpi barra="nenhuma" rotulo="Por realizar" valor={s.por_fazer?.length ?? 0} corValor={s.por_fazer?.length ? '#b45309' : undefined} />
            <CartaoKpi barra="nenhuma" rotulo="A retirar (órfãs)" valor={s.orfas_removiveis?.length ?? 0} corValor={s.orfas_removiveis?.length ? '#b91c1c' : undefined} />
            <CartaoKpi barra="nenhuma" rotulo="Datas a corrigir" valor={s.por_actualizar?.length ?? 0} corValor={s.por_actualizar?.length ? '#b45309' : undefined} />
          </GrelhaKpi>
          {listaActividades('Por importar (concluídas sem entrega)', s.por_criar, '#0369a1')}
          {listaActividades('Por realizar', s.por_fazer, '#b45309')}
          {listaOrfas('Entregas a retirar (actividade reaberta, apagada ou fora do filtro)', s.orfas_removiveis, '#b91c1c')}
          {listaOrfas('Entregas presas (validadas ou convertidas: ficam e são só reportadas)', s.orfas_presas, '#64748b')}
          {s.por_actualizar?.length ? (
            <div style={{ marginTop: 12 }}>
              <Typography.Text strong style={{ color: '#b45309' }}>Datas a corrigir ({s.por_actualizar.length})</Typography.Text>
              <Table size="small" rowKey="execucao_id" pagination={false} dataSource={s.por_actualizar} style={{ marginTop: 4 }}
                columns={[{ title: 'Entrega', dataIndex: 'execucao_id', render: (v: number) => `#${v}` }, { title: 'Data actual', dataIndex: 'data_actual', render: (v: string) => formatarData(v) }, { title: 'Data no CRM', dataIndex: 'data_nova', render: (v: string) => formatarData(v) }]} />
            </div>
          ) : null}
          {s.sem_colaborador?.length ? (
            <Alert type="warning" showIcon style={{ marginTop: 12 }} title={`${s.sem_colaborador.length} actividade(s) ficam de fora porque o utilizador que as concluiu não está ligado a nenhum colaborador`}
              description={`${[...new Set(s.sem_colaborador.map((a) => a.utilizador || '—'))].slice(0, 6).join(', ')}. Ligue-os em Configurações › Utilizadores — sem colaborador não há a quem atribuir a entrega.`} />
          ) : null}
        </>
      )}
    </Modal>
  );
}
