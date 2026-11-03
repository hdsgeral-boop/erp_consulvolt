import { Alert, Button, Popconfirm, Space, Table, Tag, Tooltip } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { Unidade } from '../comum/arvore';
import { CHAVE_TAREFAS, eliminarEntregavel, eliminarTarefa, tarefasDaUnidade } from './api';
import { useMutacao } from './comum';
import { EditorEntregavel, ModalCopiar, ModalCriarItem } from './EditorEntregavel';
import { EditorTarefa } from './EditorTarefa';
import { Execucoes } from './Execucoes';
import { Impedimentos } from './Impedimentos';
import { agruparAtrasosPorUnidade, ESTADO_PERIODO, fq, nomeMetrica, num, pctMeta, PERIODICIDADES, prazoTexto, totalMultas } from './regras';
import { SincronizarCRM } from './SincronizarCRM';
import { EtiquetaMulta } from '../faseK/EtiquetaMulta';
import type { Atraso, Entregavel, TarefaUnidade as Tarefa } from './tipos';

/** Faixa dos entregáveis em atraso (portal_ui.js:1504-1528 e KPI «Entregáveis em atraso» do painel): prazo passado sem estar cumprido. */
export function FaixaAtrasos({ atrasos, titulo = 'Entregáveis em atraso' }: { atrasos: Atraso[]; titulo?: string }) {
  if (!atrasos.length) return null;
  const multa = totalMultas(atrasos);
  return (
    <Alert type="error" showIcon style={{ marginBottom: 12 }} title={<><b>{titulo} ({atrasos.length})</b> — prazo ultrapassado sem estar cumprido.{multa ? <> Exposição a multas: <b>{formatarKz(multa, true)}</b>.</> : null}</>}
      description={
        <ul style={{ margin: 0, paddingLeft: 18 }}>
          {agruparAtrasosPorUnidade(atrasos).flatMap((g) => g.itens.map((a) => (
            <li key={`${a.entregavel.id}-${a.periodo_de}`}>
              <b>{a.entregavel.nome}</b> ({a.unidade?.nome ?? g.unidade}) — limite {formatarData(a.limite)}, há {a.dias_atraso} dia(s);
              {a.falta != null ? ` falta ${fq(a.falta)} ${a.entregavel.unidade}` : ' por fazer'}{a.meta != null ? ` (feito ${fq(a.realizado)} de ${fq(a.meta)})` : ''}
              {a.multa_valor ? <> · multa <b>{formatarKz(a.multa_valor, true)}</b></> : null}{a.multa_descricao ? ` (${a.multa_descricao})` : ''}{a.multa ? <> <EtiquetaMulta multa={a.multa} /></> : null}
              {a.dono ? ` · ${a.dono.nome}` : ''}
              {a.impedimento ? <> · <span style={{ color: '#b45309' }}>impedimento por decidir: {a.impedimento.motivo}</span></> : null}
            </li>
          )))}
        </ul>
      } />
  );
}

/**
 * Secção «Tarefas e entregáveis» do painel da unidade (estrutura_tarefas_ui.js seccao/ligar, 35-170): uma linha por tarefa
 * com os seus entregáveis (medida, meta, realizado do período, estado, prazo, dono, item), e as acções — tarefa, entregável,
 * entregas, impedimentos, CRM, copiar, criar item. A ponte para a produtividade faz-se em RH › Produtividade › Entregáveis das unidades.
 */
export function TarefasUnidade({ unidade }: { unidade: Unidade }) {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const editar = pode('est_editar');
  const q = useQuery({ queryKey: [...CHAVE_TAREFAS, unidade.id], queryFn: () => tarefasDaUnidade(unidade.id), retry: false });
  const tarefas: Tarefa[] = Array.isArray(q.data?.tarefas) ? q.data.tarefas : [];
  const atrasos: Atraso[] = Array.isArray(q.data?.atrasos) ? q.data.atrasos : [];
  const [abertas, setAbertas] = useState<number[] | null>(null);
  const [tarefa, setTarefa] = useState<Tarefa | 'nova' | null>(null);
  const [entregavel, setEntregavel] = useState<{ tarefa: Tarefa; entregavel: Entregavel | null } | null>(null);
  const [copiar, setCopiar] = useState<Entregavel | null>(null);
  const [criarItem, setCriarItem] = useState<Entregavel | null>(null);
  const [entregas, setEntregas] = useState<Entregavel | null>(null);
  const [impedimentos, setImpedimentos] = useState<Entregavel | null>(null);
  const [crm, setCrm] = useState<Entregavel | null>(null);
  const apagarTarefa = useMutacao((id: number) => eliminarTarefa(id));
  const apagarEntregavel = useMutacao((id: number) => eliminarEntregavel(id));
  const expandidas = abertas ?? tarefas.filter((t) => t.entregaveis.length).map((t) => t.id);
  const remuneraveis = (t: Tarefa) => t.entregaveis.filter((e) => e.item_produtividade_id).length;

  const colEnt = (t: Tarefa): ColumnsType<Entregavel> => [
    {
      title: 'Entregável', key: 'nome',
      render: (_, e) => (
        <div style={{ paddingLeft: 12 }}>
          <IconeFa nome="cube" style={{ color: '#0f766e', marginRight: 5 }} />
          <b>{e.nome}</b>{!e.ativo && <Tag style={{ marginLeft: 6 }}>inactivo</Tag>}
          {e.dono && <small style={{ marginLeft: 8, color: '#0369a1' }} title="Atribuído: aparece no Portal do Colaborador desta pessoa"><IconeFa nome="user-tag" /> {e.dono.nome}</small>}
          {e.origem_dados === 'CRM' && <small style={{ marginLeft: 8, color: '#4338ca' }} title="As entregas vêm das actividades concluídas do CRM"><IconeFa nome="handshake" /> CRM</small>}
          {e.descricao && <div style={{ fontSize: '0.74rem', color: '#64748b' }}>{e.descricao.slice(0, 120)}</div>}
        </div>
      ),
    },
    { title: 'Medida', key: 'medida', render: (_, e) => <span style={{ fontSize: '0.8rem' }}>{nomeMetrica(e.metrica)}<div style={{ color: '#64748b' }}>em {e.unidade}</div></span> },
    {
      title: 'Meta e prazo', key: 'meta', align: 'right',
      render: (_, e) => {
        const texto = prazoTexto(e, t.periodicidade);
        const r = e.resumo;
        return (
          <>
            {e.meta != null ? fq(e.meta) : '—'}
            {texto && (
              <div style={{ fontSize: '0.72rem', color: r?.estado === 'EM_ATRASO' ? '#b91c1c' : '#64748b', whiteSpace: 'nowrap' }} title={`Prazo de execução: ${texto}${e.paga_fora_prazo ? '' : ' — fora do prazo não é pago'}`}>
                <IconeFa nome="calendar-day" /> {r?.limite ? formatarData(r.limite) : texto}{r?.estado === 'EM_ATRASO' ? <b> em atraso</b> : null}
              </div>
            )}
            {e.alerta && <div style={{ fontSize: '0.72rem', color: '#b45309' }} title={`${e.multa_descricao || 'Avisa quando o prazo passar sem estar cumprido'}${e.multa_valor ? ` · multa ${formatarKz(e.multa_valor, true)}` : ''}`}><IconeFa nome="bell" /> alerta</div>}
          </>
        );
      },
    },
    {
      title: 'Realizado', key: 'realizado', align: 'right',
      render: (_, e) => {
        const r = e.resumo;
        const pct = r ? pctMeta(r.realizado, r.meta ?? e.meta) : null;
        return (
          <>
            <b>{fq(r?.realizado ?? 0)}</b>
            {pct != null && <div style={{ fontSize: '0.72rem', color: pct >= 100 ? '#059669' : '#b45309' }}>{pct}% da meta</div>}
            {r && <div><Tag color={ESTADO_PERIODO[r.estado]?.cor} style={{ fontSize: '0.7rem' }}>{ESTADO_PERIODO[r.estado]?.rotulo ?? r.estado}</Tag></div>}
            {r?.pendentes ? <div style={{ fontSize: '0.72rem', color: '#b45309' }} title="Entregas à espera do visto do responsável da unidade"><IconeFa nome="user-check" /> {r.pendentes} por validar</div> : null}
            {r?.devolvidas ? <div style={{ fontSize: '0.72rem', color: '#9a3412' }}>{r.devolvidas} devolvida(s)</div> : null}
            {r?.recusadas ? <div style={{ fontSize: '0.72rem', color: '#b91c1c' }}>{r.recusadas} recusada(s)</div> : null}
            {e.impedimento_aberto && <div style={{ fontSize: '0.72rem', color: '#b45309' }}><IconeFa nome="exclamation-triangle" /> impedimento por decidir</div>}
          </>
        );
      },
    },
    {
      title: 'Produtividade', key: 'prod',
      render: (_, e) => (
        <div style={{ fontSize: '0.78rem' }}>
          {e.item_produtividade_id
            ? <Tag color="blue" title={e.item ? `${e.item.codigo} · ${e.item.descricao}` : 'Ligado a um item de produtividade'}>remunerável{e.item ? ` · ${e.item.codigo}` : ''}</Tag>
            : <Tag color="gold" title="Sem item de produtividade: as entregas ficam registadas mas não são remuneradas">não remunerado</Tag>}
          {e.avaliacao && <div style={{ marginTop: 2 }}><Tag color="purple" title={`Entra como objectivo na avaliação de desempenho de quem o executa (peso ${num(e.peso_avaliacao) || 1})`}><IconeFa nome="star-half-alt" /> avaliação</Tag></div>}
        </div>
      ),
    },
    {
      title: '', key: 'a', align: 'right',
      render: (_, e) => (
        <Space size={2} wrap>
          <Button size="small" type="text" title="Entregas: ver, registar e validar" aria-label={`Entregas de ${e.nome}`} icon={<IconeFa nome="list" />} onClick={() => setEntregas(e)} />
          <Button size="small" type="text" title="Impedimentos (não há condições de executar)" aria-label={`Impedimentos de ${e.nome}`} icon={<IconeFa nome="exclamation-triangle" />} onClick={() => setImpedimentos(e)} />
          {e.origem_dados === 'CRM' && editar && <Button size="small" type="text" title="CRM: estado e sincronização das actividades concluídas" aria-label={`CRM de ${e.nome}`} icon={<IconeFa nome="handshake" />} onClick={() => setCrm(e)} />}
          {editar && (
            <>
              <Button size="small" type="text" title="Editar entregável" aria-label={`Editar entregável ${e.nome}`} icon={<IconeFa nome="edit" />} onClick={() => setEntregavel({ tarefa: t, entregavel: e })} />
              <Button size="small" type="text" title="Copiar este entregável para esta ou outra tarefa" aria-label={`Copiar entregável ${e.nome}`} icon={<IconeFa nome="copy" />} onClick={() => setCopiar(e)} />
              {!e.item_produtividade_id && pode('rh_prod_config') && <Button size="small" type="text" title="Criar o item de produtividade a partir deste entregável" aria-label={`Criar item de ${e.nome}`} icon={<IconeFa nome="coins" />} onClick={() => setCriarItem(e)} />}
              <Popconfirm title="Eliminar este entregável?" description="Só é possível sem entregas registadas; senão desactive-o." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => apagarEntregavel.mutateAsync(e.id)}>
                <Button size="small" type="text" danger aria-label={`Eliminar entregável ${e.nome}`} icon={<IconeFa nome="trash" />} />
              </Popconfirm>
            </>
          )}
        </Space>
      ),
    },
  ];

  const colunas: ColumnsType<Tarefa> = [
    {
      title: 'Tarefa', key: 'nome',
      render: (_, t) => (
        <>
          <IconeFa nome="tasks" style={{ color: '#b45309', marginRight: 5 }} />
          <b>{t.codigo ? `${t.codigo} · ` : ''}{t.nome}</b>{!t.ativo && <Tag style={{ marginLeft: 6 }}>inactiva</Tag>}
          {t.url_instrucoes && <a href={t.url_instrucoes} target="_blank" rel="noopener noreferrer" title="Abrir as instruções da tarefa" style={{ marginLeft: 6 }}><IconeFa nome="book" /></a>}
          {t.descricao && <div style={{ fontSize: '0.74rem', color: '#64748b' }}>{t.descricao.slice(0, 160)}</div>}
        </>
      ),
    },
    { title: 'Periodicidade', dataIndex: 'periodicidade', render: (p: Tarefa['periodicidade']) => PERIODICIDADES[p] ?? p },
    { title: 'Entregáveis', key: 'n', render: (_, t) => <span style={{ fontSize: '0.8rem', color: '#64748b' }}>{t.entregaveis.length} entregável(eis){remuneraveis(t) ? ` · ${remuneraveis(t)} remunerável(eis)` : ''}</span> },
    {
      title: '', key: 'a', align: 'right',
      render: (_, t) => editar && (
        <Space size={2}>
          <Button size="small" icon={<IconeFa nome="plus" />} title="Novo entregável" onClick={() => setEntregavel({ tarefa: t, entregavel: null })}>Entregável</Button>
          <Button size="small" type="text" aria-label={`Editar tarefa ${t.nome}`} icon={<IconeFa nome="edit" />} onClick={() => setTarefa(t)} />
          <Popconfirm title="Eliminar esta tarefa e os seus entregáveis?" description="Só é possível sem entregas registadas; senão desactive-a." okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }} onConfirm={() => apagarTarefa.mutateAsync(t.id)}>
            <Button size="small" type="text" danger aria-label={`Eliminar tarefa ${t.nome}`} icon={<IconeFa nome="trash" />} />
          </Popconfirm>
        </Space>
      ),
    },
  ];

  return (
    <>
      <h4 className="est-h4">
        <span>Tarefas e entregáveis ({tarefas.length})</span>
        <Space size={4} wrap>
          {tarefas.some((t) => t.entregaveis.length) && (
            <>
              <Button size="small" title="Expandir todos" aria-label="Expandir todos" icon={<IconeFa nome="expand-arrows-alt" />} onClick={() => setAbertas(tarefas.map((t) => t.id))} />
              <Button size="small" title="Recolher todos" aria-label="Recolher todos" icon={<IconeFa nome="compress-arrows-alt" />} onClick={() => setAbertas([])} />
            </>
          )}
          {editar && pode('rh_prod_registar') && <Button size="small" icon={<IconeFa nome="exchange-alt" />} title="Levar os entregáveis executados para um período de produtividade" onClick={() => navegar('/m/rh/rh_produtividade?sep=entregaveis')}>Converter em produtividade</Button>}
          {editar && <Button size="small" icon={<IconeFa nome="plus" />} onClick={() => setTarefa('nova')}>Tarefa</Button>}
        </Space>
      </h4>
      {!unidade.colaborador_responsavel_id && tarefas.length > 0 && (
        <div className="est-aviso" style={{ borderLeftColor: '#b45309' }}>
          <IconeFa nome="user-slash" /> <b>Esta unidade não tem responsável definido</b> — ninguém pode validar as entregas destes entregáveis, por isso não chegam à produtividade. Defina o responsável em «Editar» na unidade.
        </div>
      )}
      <FaixaAtrasos atrasos={atrasos} />
      {q.isError && <Alert type="warning" showIcon style={{ marginBottom: 8 }} title="Não foi possível carregar as tarefas desta unidade." />}
      {tarefas.length ? (
        <Table<Tarefa> rowKey="id" size="small" loading={q.isFetching} dataSource={tarefas} columns={colunas} pagination={false} scroll={scrollTabela()}
          expandable={{
            expandedRowKeys: expandidas,
            onExpandedRowsChange: (chaves) => setAbertas(chaves.map(Number)),
            rowExpandable: (t) => t.entregaveis.length > 0,
            expandedRowRender: (t) => <Table<Entregavel> rowKey="id" size="small" dataSource={t.entregaveis} columns={colEnt(t)} pagination={false} showHeader scroll={scrollTabela()} />,
          }} />
      ) : !q.isLoading && (
        <p style={{ color: '#64748b', fontSize: '0.84rem' }}>
          Sem tarefas nesta unidade. Uma tarefa é o que a unidade faz; cada tarefa tem entregáveis medidos em quantidade ou noutra unidade de medida, e esses entregáveis podem ser remunerados pelo subsídio de produtividade.
        </p>
      )}
      {tarefas.some((t) => !t.entregaveis.length) && (
        <Tooltip title="Um entregável é o que se mede: «relatório mensal», «m³ de betão», «viaturas revistas».">
          <p style={{ color: '#64748b', fontSize: '0.78rem', margin: '4px 0 0' }}>{tarefas.filter((t) => !t.entregaveis.length).length} tarefa(s) sem entregáveis.</p>
        </Tooltip>
      )}

      <EditorTarefa tarefa={tarefa} unidade={unidade} aoFechar={() => setTarefa(null)} />
      <EditorEntregavel alvo={entregavel} aoFechar={() => setEntregavel(null)} />
      <ModalCopiar entregavel={copiar} tarefas={tarefas} aoFechar={() => setCopiar(null)} />
      <ModalCriarItem entregavel={criarItem} aoFechar={() => setCriarItem(null)} />
      <Execucoes entregavel={entregas} aoFechar={() => setEntregas(null)} />
      <Impedimentos entregavel={impedimentos} aoFechar={() => setImpedimentos(null)} />
      <SincronizarCRM entregavel={crm} aoFechar={() => setCrm(null)} />
    </>
  );
}
