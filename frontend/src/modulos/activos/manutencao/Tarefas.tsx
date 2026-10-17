import { Button, Card, Checkbox, Collapse, Empty, Input, Progress, Space, Switch, Tag, theme, Timeline, Tooltip, Typography } from 'antd';
import { useState } from 'react';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos';
import { formatarData } from '@/utilitarios/formatacao';
import { ACCOES_HISTORICO, progressoTarefas, SITUACOES_TAREFAS, textoProgresso } from './regras';
import type { AccaoHistorico, DetalheOrdem, EventoOrdem, ProgressoTarefas, Tarefa } from './tipos';

const INVALIDAR = [['activos']];

/** Data e hora curtas («16/10/2026 14:05»). */
const quando = (iso?: string | null) => (iso ? `${formatarData(iso)} ${iso.slice(11, 16)}`.trim() : '');

/** Barra «N de M» com a situação derivada (r3_activos). */
export function ProgressoOrdem({ progresso, compacto }: { progresso: ProgressoTarefas; compacto?: boolean }) {
  const s = SITUACOES_TAREFAS[progresso.situacao];
  if (!progresso.total) return compacto ? <Typography.Text type="secondary">—</Typography.Text> : null;
  return (
    <Space size={8} style={{ minWidth: compacto ? 150 : undefined, width: compacto ? undefined : '100%' }} wrap={!compacto}>
      <Progress percent={progresso.percentagem} size={compacto ? 'small' : 'default'} style={{ width: compacto ? 70 : 220, margin: 0 }} showInfo={false}
        status={progresso.situacao === 'PRONTA' ? 'success' : 'active'} aria-label={`Tarefas: ${textoProgresso(progresso)}`} />
      <span style={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>{textoProgresso(progresso)}</span>
      {!compacto && <Tag color={s.cor}>{s.rotulo}</Tag>}
    </Space>
  );
}

/**
 * Checklist da ordem (r3_activos — boas práticas de gestão de tarefas): cada tarefa marca-se uma a uma com quem/quando;
 * obrigatórias e opcionais; acrescentar/retirar com a ordem aberta; numa ordem concluída, desmarcar uma tarefa reabre a
 * ordem (pede o motivo). A ordem fica pendente enquanto houver obrigatórias por fazer.
 */
export function ChecklistTarefas({ ordem, aberta, gerir }: { ordem: DetalheOrdem; aberta: boolean; gerir: boolean }) {
  const { token } = theme.useToken();
  const accao = useAccao({ invalidar: INVALIDAR });
  const [nova, setNova] = useState('');
  const [obrigatoria, setObrigatoria] = useState(true);
  const [reabrir, setReabrir] = useState<Tarefa | null>(null);
  const tarefas = ordem.tarefas ?? [];
  const progresso = ordem.progresso ?? progressoTarefas(tarefas);
  const concluida = ordem.estado === 'CONCLUIDA';
  const base = `/ativos/manutencao/ordens/${ordem.id}/tarefas`;

  const marcar = (t: Tarefa, feito: boolean) => {
    if (!t.id) return;
    if (concluida && !feito) { setReabrir(t); return; }
    accao.mutate({ url: `${base}/${t.id}/estado`, dados: { feito } });
  };
  const acrescentar = () => {
    const texto = nova.trim();
    if (!texto) return;
    accao.mutate({ url: base, dados: { texto, obrigatoria } }, { onSuccess: () => { setNova(''); setObrigatoria(true); } });
  };
  const podeMarcar = gerir && (aberta || concluida);

  return (
    <Card size="small" style={{ marginBottom: 12 }}
      title={<Space wrap><IconeFa nome="tasks" /> Tarefas</Space>}
      extra={progresso.total ? <ProgressoOrdem progresso={progresso} /> : null}>
      {!tarefas.length && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sem tarefas. Acrescente os passos do trabalho para acompanhar o progresso." />}
      <ul style={{ listStyle: 'none', margin: 0, padding: 0 }} aria-label="Tarefas da ordem">
        {tarefas.map((t, k) => (
          <li key={t.id ?? k} style={{ display: 'flex', alignItems: 'flex-start', gap: 8, padding: '6px 4px', borderBottom: `1px solid ${token.colorBorderSecondary}` }}>
            <Checkbox checked={t.feito} disabled={!podeMarcar || accao.isPending} onChange={(e) => marcar(t, e.target.checked)}
              aria-label={`${t.feito ? 'Reabrir' : 'Concluir'} a tarefa «${t.texto}»`} style={{ marginTop: 2 }} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <span style={{ textDecoration: t.feito ? 'line-through' : undefined, color: t.feito ? token.colorTextSecondary : token.colorText }}>{t.texto}</span>
              {t.obrigatoria === false && <Tag style={{ marginInlineStart: 6 }}>opcional</Tag>}
              {t.feito && (t.feito_por || t.feito_em) && (
                <div style={{ fontSize: 12, color: token.colorTextSecondary }}>
                  <IconeFa nome="check-circle" /> Feita{t.feito_por ? ` por ${t.feito_por}` : ''}{t.feito_em ? ` em ${quando(t.feito_em)}` : ''}
                </div>
              )}
            </div>
            {aberta && gerir && !t.feito && t.id && (
              <Tooltip title="Retirar a tarefa">
                <Button size="small" type="text" danger aria-label={`Retirar a tarefa «${t.texto}»`} icon={<IconeFa nome="times" />}
                  onClick={() => accao.mutate({ metodo: 'delete', url: `${base}/${t.id}` })} />
              </Tooltip>
            )}
          </li>
        ))}
      </ul>
      {aberta && gerir && (
        <Space.Compact style={{ width: '100%', marginTop: 10 }}>
          <Input value={nova} maxLength={500} placeholder="Nova tarefa (ex.: verificar o nível do óleo)" aria-label="Nova tarefa"
            onChange={(e) => setNova(e.target.value)} onPressEnter={acrescentar} />
          <Button icon={<IconeFa nome="plus" />} onClick={acrescentar} disabled={!nova.trim()} loading={accao.isPending}>Acrescentar</Button>
        </Space.Compact>
      )}
      {aberta && gerir && (
        <div style={{ marginTop: 6, fontSize: 12 }}>
          <Switch size="small" checked={obrigatoria} onChange={setObrigatoria} aria-label="Tarefa obrigatória" /> <span>{obrigatoria ? 'Obrigatória (a ordem só conclui com ela feita)' : 'Opcional'}</span>
        </div>
      )}
      {concluida && gerir && !!tarefas.length && (
        <Typography.Paragraph type="secondary" style={{ fontSize: 12, margin: '8px 0 0' }}>
          Desmarcar uma tarefa reabre a ordem (volta a «Em curso» e sai do histórico do activo até ser concluída outra vez).
        </Typography.Paragraph>
      )}
      <ModalMotivo aberto={!!reabrir} titulo={`Reabrir a tarefa «${reabrir?.texto ?? ''}»`} textoOk="Reabrir tarefa e ordem" carregando={accao.isPending}
        aviso="A ordem concluída volta a ficar em curso: a conclusão sai do histórico do activo e o plano volta à data anterior. Indique porquê."
        aoConfirmar={(motivo) => reabrir?.id && accao.mutate({ url: `${base}/${reabrir.id}/estado`, dados: { feito: false, motivo } }, { onSuccess: () => setReabrir(null) })}
        aoFechar={() => setReabrir(null)} />
    </Card>
  );
}

/** Histórico da ordem e das tarefas (quem fez o quê e quando), do mais recente para o mais antigo. */
export function HistoricoOrdem({ eventos }: { eventos: EventoOrdem[] | null | undefined }) {
  const lista = [...(eventos ?? [])].reverse();
  if (!lista.length) return null;
  const cor = (a: string) => (a === 'ORDEM_CONCLUIDA' || a === 'TAREFA_CONCLUIDA' ? 'green' : a === 'ORDEM_REABERTA' || a === 'TAREFA_REABERTA' ? 'orange'
    : a === 'ORDEM_CANCELADA' || a === 'TAREFA_RETIRADA' ? 'red' : 'blue');
  return (
    <Collapse size="small" style={{ marginTop: 12 }} items={[{
      key: 'h', label: <span><IconeFa nome="history" /> Histórico ({lista.length})</span>,
      children: (
        <Timeline items={lista.map((e, i) => ({
          key: i, color: cor(e.accao),
          children: (
            <div>
              <strong>{ACCOES_HISTORICO[e.accao as AccaoHistorico] ?? e.accao}</strong>{e.texto ? ` — ${e.texto}` : ''}
              <div style={{ fontSize: 12, opacity: 0.75 }}>{quando(e.em)}{e.por ? ` · ${e.por}` : ''}{e.motivo ? ` · ${e.motivo}` : ''}</div>
            </div>
          ),
        }))} />
      ),
    }]} />
  );
}
