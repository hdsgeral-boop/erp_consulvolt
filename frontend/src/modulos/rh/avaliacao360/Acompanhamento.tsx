import { Alert, Card, Space, Tag, Typography } from 'antd';
import { useNavigate } from 'react-router-dom';
import { IconeFa } from '@/componentes/fluxos';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { CartaoKpi, GrelhaKpi, TabelaComModos } from '@/componentes/vistas';
import { formatarData, formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';
import { FaseTag } from '../comum/componentes';

/** Resposta de GET /rh/paridade/avaliacao/ciclos/{id}/acompanhamento (ServicoAcompanhamento360RH). */
export interface Acompanhamento360 {
  participacao: LinhaParticipacao[];
  reunioes: {
    periodicidade: string;
    registadas: { id: number; colaborador_id: number; nome: string | null; periodo_referencia: string; data: string | null; resumo: string | null; confirmado_em: string | null; comentario: string | null }[];
    em_falta: { colaborador_id: number; nome: string; chefia: string | null; periodo_referencia: string; terminou: string }[];
  };
  comunicado: { titulo: string | null; texto: string | null; confirmacoes: { colaborador_id: number; nome: string | null; em: string | null }[]; por_confirmar: { colaborador_id: number; nome: string }[] };
  indicadores: { colaboradores: number; autoavaliacoes_submetidas: number; respostas_360: number; chefia_concluida: number; contestacoes_por_decidir: number; finais: number };
  contadores: { contestacoes: number; reunioes: number; comunicado: number };
}

export interface LinhaParticipacao {
  colaborador_id: number;
  nome: string;
  chefia_id: number | null;
  chefia: string | null;
  auto: 'SUBMETIDA' | 'RASCUNHO' | null;
  pares_recebidas: number;
  pares_total: number;
  subordinados_recebidas: number;
  subordinados_total: number;
  avaliacao_id: number | null;
  estado: string | null;
  fase: string;
  nota_chefia: number | null;
  nota_final: number | null;
}

/** Cartões do topo do antigo (Colaboradores, Autoavaliações submetidas, Avaliações 360º recebidas, …). */
export function IndicadoresCiclo({ dados }: { dados: Acompanhamento360 }) {
  const i = dados.indicadores;
  return (
    <GrelhaKpi larguraMinima={160}>
      <CartaoKpi rotulo="Colaboradores" valor={i.colaboradores} icone="users" cor="azul" />
      <CartaoKpi rotulo="Autoavaliações submetidas" valor={i.autoavaliacoes_submetidas} icone="user-check" cor="verdeAgua" />
      <CartaoKpi rotulo="Avaliações 360º recebidas" valor={i.respostas_360} icone="sync-alt" cor="roxo" />
      <CartaoKpi rotulo="Chefia concluída" valor={i.chefia_concluida} icone="user-tie" cor="primario" />
      <CartaoKpi rotulo="Contestações por decidir" valor={i.contestacoes_por_decidir} icone="balance-scale" cor={i.contestacoes_por_decidir ? 'vermelho' : 'cinzento'} corValor={i.contestacoes_por_decidir ? '#b91c1c' : undefined} />
      <CartaoKpi rotulo="Finais" valor={i.finais} icone="check-double" cor="verde" />
    </GrelhaKpi>
  );
}

/** «Participação»: Colaborador, Chefia, Auto, Pares, Subord., Fase, Nota chefia, Nota 360º final (só contagens: anonimato). */
export function Participacao({ dados, carregando }: { dados?: Acompanhamento360; carregando: boolean }) {
  const navegar = useNavigate();
  const colunas: ColunaApi<LinhaParticipacao>[] = [
    { title: 'Colaborador', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
    { title: 'Chefia', dataIndex: 'chefia', render: (v: string | null) => v ?? '—' },
    { title: 'Auto', dataIndex: 'auto', align: 'center', valorImpressao: (l) => (l.auto === 'SUBMETIDA' ? 'Submetida' : l.auto === 'RASCUNHO' ? 'Rascunho' : '—'),
      render: (v: string | null) => (v === 'SUBMETIDA' ? <span style={{ color: '#166534' }}><IconeFa nome="check" /></span> : v === 'RASCUNHO' ? 'rascunho' : '—') },
    { title: 'Pares', key: 'pares', align: 'right', render: (_, l) => `${l.pares_recebidas}/${l.pares_total}` },
    { title: 'Subord.', key: 'subordinados', align: 'right', render: (_, l) => `${l.subordinados_recebidas}/${l.subordinados_total}` },
    { title: 'Fase', dataIndex: 'fase', render: (f: string) => <FaseTag fase={f} /> },
    { title: 'Nota chefia', dataIndex: 'nota_chefia', align: 'right', render: (v: number | null) => (v !== null ? formatarNumero(v) : '—') },
    { title: 'Nota 360º final', dataIndex: 'nota_final', align: 'right', render: (v: number | null) => (v !== null ? <strong>{formatarNumero(v)}</strong> : '—') },
  ];
  return (
    <Card>
      <TabelaComModos<LinhaParticipacao> idVista="participacao-360" rowKey="colaborador_id" size="small" loading={carregando} columns={colunas} dataSource={dados?.participacao ?? []} modos={false}
        impressao={{ titulo: 'Participação no ciclo 360º', orientacao: 'paisagem' }}
        vazio={{ icone: 'users', titulo: 'Sem participantes', texto: 'A composição é fotografada ao abrir o ciclo.' }}
        accoes={(l) => [{ icone: 'star', titulo: l.avaliacao_id ? 'Abrir a avaliação' : 'Avaliar', texto: l.avaliacao_id ? 'Abrir' : 'Avaliar', onClick: () => navegar('/m/rh/rh_avaliacao') }]} />
    </Card>
  );
}

/** «Reuniões de acompanhamento»: em falta (períodos terminados sem registo) e registadas, com a confirmação do colaborador. */
export function Reunioes({ dados, carregando }: { dados?: Acompanhamento360; carregando: boolean }) {
  type Registada = Acompanhamento360['reunioes']['registadas'][number];
  type EmFalta = Acompanhamento360['reunioes']['em_falta'][number];
  const colFalta: ColunaApi<EmFalta>[] = [
    { title: 'Colaborador', dataIndex: 'nome' },
    { title: 'Chefia', dataIndex: 'chefia', render: (v: string | null) => v ?? '—' },
    { title: 'Período', dataIndex: 'periodo_referencia' },
    { title: 'Terminou', dataIndex: 'terminou', render: formatarData },
  ];
  const colReg: ColunaApi<Registada>[] = [
    { title: 'Colaborador', dataIndex: 'nome', render: (v: string | null) => v ?? '—' },
    { title: 'Período', dataIndex: 'periodo_referencia' },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Acordos', dataIndex: 'resumo', render: (v: string | null) => <span style={{ whiteSpace: 'pre-line' }}>{v ?? ''}</span> },
    { title: 'Confirmação', key: 'confirmacao', valorImpressao: (r) => (r.confirmado_em ? `Confirmada ${formatarData(r.confirmado_em)}` : 'Por confirmar'),
      render: (_, r) => (r.confirmado_em
        ? <span><IconeFa nome="check" /> {formatarData(r.confirmado_em)}{r.comentario && <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>{r.comentario}</Typography.Text>}</span>
        : <span style={{ color: '#b45309' }}>por confirmar</span>) },
  ];
  const r = dados?.reunioes;
  return (
    <Space direction="vertical" style={{ width: '100%' }} size={12}>
      {r?.periodicidade === 'NENHUM' && <Alert type="info" showIcon message="Este ciclo não tem reuniões de acompanhamento (veja «Feedback periódico» na Configuração da avaliação)." />}
      {(r?.em_falta.length ?? 0) > 0 && (
        <Card title={`Reuniões em falta (${r?.em_falta.length})`}>
          <TabelaComModos<EmFalta> idVista="reunioes-falta" rowKey={(l) => `${l.colaborador_id}-${l.periodo_referencia}`} size="small" columns={colFalta} dataSource={r?.em_falta ?? []} modos={false}
            impressao={{ titulo: 'Reuniões de acompanhamento em falta' }} />
        </Card>
      )}
      <Card title={`Reuniões registadas (${r?.registadas.length ?? 0})`}>
        <TabelaComModos<Registada> idVista="reunioes-registadas" rowKey="id" size="small" loading={carregando} columns={colReg} dataSource={r?.registadas ?? []} modos={false}
          impressao={{ titulo: 'Reuniões de acompanhamento registadas' }}
          vazio={{ icone: 'comments', titulo: 'Ainda sem reuniões registadas', texto: 'A chefia regista-as no Portal (A minha equipa).' }} />
      </Card>
    </Space>
  );
}

/** «Comunicado»: texto do comunicado de abertura e confirmações de leitura (quem falta confirmar). */
export function Comunicado({ dados, nomeCiclo }: { dados?: Acompanhamento360; nomeCiclo: string }) {
  const c = dados?.comunicado;
  type Leitura = Acompanhamento360['comunicado']['confirmacoes'][number];
  const total = (c?.confirmacoes.length ?? 0) + (c?.por_confirmar.length ?? 0);
  return (
    <Space direction="vertical" style={{ width: '100%' }} size={12}>
      <Card title={<Space><IconeFa nome="bullhorn" />{c?.titulo || nomeCiclo}</Space>}>
        {c?.texto ? <div style={{ whiteSpace: 'pre-line' }}>{c.texto}</div> : <Typography.Text type="secondary">Sem texto de comunicado (Configuração da avaliação › Comunicado e abertura).</Typography.Text>}
      </Card>
      <Card title={`Confirmações de leitura: ${c?.confirmacoes.length ?? 0} / ${total}`}>
        {(c?.por_confirmar.length ?? 0) > 0
          ? <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`Por confirmar (${c?.por_confirmar.length}): ${c?.por_confirmar.map((p) => p.nome).join(', ')}`} />
          : total > 0 && <Alert type="success" showIcon style={{ marginBottom: 12 }} message="Todos confirmaram a leitura." />}
        <TabelaComModos<Leitura> idVista="leituras-comunicado" rowKey="colaborador_id" size="small" modos={false} dataSource={c?.confirmacoes ?? []}
          columns={[
            { title: 'Colaborador', dataIndex: 'nome', render: (v: string | null) => v ?? '—' },
            { title: 'Confirmado em', dataIndex: 'em', render: (v: string | null) => (v ? formatarDataHora(v) : '—') },
          ]}
          vazio={{ icone: 'bullhorn', titulo: 'Ainda ninguém confirmou a leitura' }} />
      </Card>
    </Space>
  );
}

/** Rótulo de separador com contador (como no antigo: «Reuniões de acompanhamento (17)»). */
export function RotuloSeparador({ icone, texto, contador }: { icone: string; texto: string; contador?: number }) {
  return <span><IconeFa nome={icone} /> {texto}{contador ? <Tag color="red" style={{ marginInlineStart: 6 }}>{contador}</Tag> : null}</span>;
}
