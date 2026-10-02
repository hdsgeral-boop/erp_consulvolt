import { Alert, Button, Card, ColorPicker, Dropdown, Empty, Flex, Input, Modal, Select, Space, Table, Tag, Typography, theme } from 'antd';
import { DeleteOutlined, MoreOutlined, PlusOutlined, SettingOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { formatarData } from '@/utilitarios/formatacao';
import { BarraExecucao } from '../comum/componentes';
import { tarefaAtrasada } from '../comum/regras';
import type { ColunaKanban, Kanban, TarefaKanban } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useRef } from 'react';
import { ImpressaoSeparador } from '../comum/ImpressaoSeparador';

/** Quadro Kanban configurável: arrastar um cartão para outra coluna (ou usar o menu do cartão) muda o estado da tarefa. */
export function SeparadorKanban({ projecto, acc }: PropsSeparador) {
  const { token } = theme.useToken();
  const q = useQuery({ queryKey: ['projectos', 'kanban', projecto.id], queryFn: () => obter<Kanban>(`/projetos/${projecto.id}/kanban`) });
  const mover = useAccao({ invalidar: [['projectos']] });
  const [arrastar, setArrastar] = useState<number | null>(null);
  const [sobre, setSobre] = useState<string | null>(null);
  const [configurar, setConfigurar] = useState(false);
  const podeMover = acc.execucao;
  const refQuadro = useRef<HTMLDivElement>(null);

  const moverPara = (tarefa: number, coluna: string) => mover.mutate({ url: `/projetos/${projecto.id}/kanban/mover`, dados: { tarefa_id: tarefa, coluna } });

  const cartao = (t: TarefaKanban, colunas: ColunaKanban[], actual: string) => (
    <Card
      key={t.id}
      size="small"
      draggable={podeMover}
      onDragStart={() => setArrastar(t.id)}
      onDragEnd={() => { setArrastar(null); setSobre(null); }}
      style={{ marginBottom: 8, cursor: podeMover ? 'grab' : undefined, opacity: arrastar === t.id ? 0.5 : 1 }}
      styles={{ body: { padding: 10 } }}
    >
      <Flex justify="space-between" gap={4}>
        <Typography.Text strong style={{ fontSize: 13 }}>{t.codigo ? `${t.codigo} · ` : ''}{t.nome}</Typography.Text>
        {podeMover && (
          <Dropdown trigger={['click']} menu={{ items: colunas.filter((c) => c.id !== actual).map((c) => ({ key: c.id, label: `Mover para «${c.titulo}»` })), onClick: ({ key }) => moverPara(t.id, key) }}>
            <Button size="small" type="text" icon={<MoreOutlined />} />
          </Dropdown>
        )}
      </Flex>
      <Typography.Text type={tarefaAtrasada(t) ? 'danger' : 'secondary'} style={{ fontSize: 12 }}>
        {formatarData(t.data_inicio)} → {formatarData(t.data_fim)}{tarefaAtrasada(t) ? ' (atrasada)' : ''}
      </Typography.Text>
      <BarraExecucao valor={t.execucao} largura={200} />
    </Card>
  );

  const k = q.data;
  return (
    <Card loading={q.isLoading} extra={<Space wrap>{acc.gerir && <Button icon={<SettingOutlined />} onClick={() => setConfigurar(true)}>Colunas</Button>}<ImpressaoSeparador alvo={refQuadro} titulo="Quadro Kanban" projecto={projecto} orientacao="paisagem" /></Space>}>
      {k && k.por_mapear.length > 0 && (
        <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`${k.por_mapear.length} tarefa(s) sem coluna: ${k.por_mapear.map((t) => t.nome).join(', ')}`} />
      )}
      <div ref={refQuadro} className="erp-deslocar-x" style={{ display: 'flex', gap: 12, paddingBottom: 8 }}>
        {k?.colunas.map((c) => (
          <div
            key={c.id}
            onDragOver={(e) => { if (podeMover && arrastar) { e.preventDefault(); setSobre(c.id); } }}
            onDragLeave={() => setSobre(null)}
            onDrop={(e) => {
              e.preventDefault();
              const origem = k.colunas.find((x) => x.tarefas.some((t) => t.id === arrastar));
              if (arrastar && origem?.id !== c.id) moverPara(arrastar, c.id);
              setArrastar(null);
              setSobre(null);
            }}
            style={{
              flex: '0 0 280px', background: sobre === c.id ? token.colorPrimaryBg : token.colorFillQuaternary, borderRadius: token.borderRadiusLG,
              borderTop: `4px solid ${c.cor || token.colorBorder}`, padding: 10, minHeight: 240,
            }}
          >
            <Flex justify="space-between" style={{ marginBottom: 8 }}>
              <Typography.Text strong>{c.titulo}</Typography.Text>
              <Tag>{c.tarefas.length}</Tag>
            </Flex>
            {c.tarefas.length ? c.tarefas.map((t) => cartao(t, k.colunas, c.id)) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sem tarefas" />}
          </div>
        ))}
      </div>
      {k && <ModalColunas aberto={configurar} projectoId={projecto.id} colunas={k.colunas} aoFechar={() => setConfigurar(false)} />}
    </Card>
  );
}

interface ColunaEditavel {
  id: string;
  titulo: string;
  cor: string;
  estado: string | null;
}

const ESTADOS = [{ value: 'PENDENTE', label: 'Pendente' }, { value: 'EM_CURSO', label: 'Em curso' }, { value: 'CONCLUIDA', label: 'Concluída' }, { value: 'BLOQUEADA', label: 'Bloqueada' }];

function ModalColunas({ aberto, projectoId, colunas, aoFechar }: { aberto: boolean; projectoId: number; colunas: ColunaKanban[]; aoFechar: () => void }) {
  const [linhas, setLinhas] = useState<ColunaEditavel[]>([]);
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (aberto) setLinhas(colunas.map((c) => ({ id: c.id, titulo: c.titulo, cor: c.cor, estado: c.estado ?? null })));
  }, [aberto, colunas]);
  const mudar = (i: number, campo: keyof ColunaEditavel, v: string | null) => setLinhas((s) => s.map((l, n) => (n === i ? { ...l, [campo]: v } : l)));
  const valido = linhas.length > 0 && linhas.every((l) => /^[A-Z0-9_]{1,30}$/.test(l.id)) && new Set(linhas.map((l) => l.id)).size === linhas.length;

  return (
    <Modal title="Colunas do Kanban" open={aberto} onCancel={aoFechar} width={larguraModal(760)} okText="Gravar" cancelText="Cancelar" okButtonProps={{ disabled: !valido }} confirmLoading={accao.isPending}
      onOk={() => accao.mutate({ metodo: 'put', url: `/projetos/${projectoId}/kanban/colunas`, dados: { colunas: linhas } })}>
      <Typography.Paragraph type="secondary">Cada coluna pode corresponder a um estado da tarefa: mover um cartão para essa coluna muda o estado.</Typography.Paragraph>
      <Table<ColunaEditavel> scroll={scrollTabela()}
        size="small"
        rowKey={(l) => JSON.stringify(l)}
        pagination={false}
        dataSource={linhas}
        columns={[
          { title: 'Identificador', key: 'id', render: (_, l, i) => <Input size="small" value={l.id} onChange={(e) => mudar(i, 'id', e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_'))} style={{ width: 130 }} /> },
          { title: 'Título', key: 't', render: (_, l, i) => <Input size="small" value={l.titulo} onChange={(e) => mudar(i, 'titulo', e.target.value)} style={{ width: 180 }} /> },
          { title: 'Cor', key: 'c', render: (_, l, i) => <ColorPicker size="small" value={l.cor} onChange={(c) => mudar(i, 'cor', c.toHexString().slice(0, 7))} /> },
          { title: 'Estado', key: 'e', render: (_, l, i) => <Select size="small" allowClear value={l.estado ?? undefined} onChange={(v) => mudar(i, 'estado', v ?? null)} options={ESTADOS} style={{ width: 140 }} /> },
          { title: '', key: 'x', render: (_, __, i) => <Button size="small" danger icon={<DeleteOutlined />} disabled={linhas.length === 1} onClick={() => setLinhas((s) => s.filter((_, n) => n !== i))} /> },
        ]}
      />
      <Space wrap style={{ marginTop: 8 }}>
        <Button icon={<PlusOutlined />} onClick={() => setLinhas((s) => [...s, { id: `COLUNA_${s.length + 1}`, titulo: 'Nova coluna', cor: '#64748b', estado: null }])}>Coluna</Button>
      </Space>
    </Modal>
  );
}
