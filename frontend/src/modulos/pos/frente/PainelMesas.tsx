import { Button, Card, Empty, Form, Input, InputNumber, Modal, Popconfirm, Space, Spin, Table, Typography, theme } from 'antd';
import { AppstoreOutlined, DeleteOutlined, EditOutlined, PlusOutlined, SaveOutlined, ShopOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { dadosMesa, estadoMesa, type MesaPOS } from './mesas';

/** Coluna da vista Linhas de «Criar mesa» (várias mesas de uma vez: Mesa 1, Mesa 2, Terraço 3…). */
const CAMPOS_MESA: CampoFormulario[] = [{ nome: 'nome', rotulo: 'Nome da mesa', obrigatorio: true, largura: 260, maxLength: 60, aliases: ['Mesa', 'Nome'], placeholder: 'Ex.: Mesa 9' }];

export function useMesas(terminal: number, activo = true) {
  return useQuery({ queryKey: ['pos', 'mesas', terminal], queryFn: () => obter<MesaPOS[]>(`/pos/terminais/${terminal}/mesas`), enabled: activo, refetchInterval: 30_000 });
}

/**
 * «Mapa de mesas / consumo em aberto» do legado (js/ui_sales.js:6499-6540): grelha de mesas com o estado (activa, ocupada
 * com o total, livre), «Balcão» para venda sem mesa e «Criar mesa». As contas vêm do servidor (partilhadas entre postos).
 */
export function PainelMesas({ terminal, activa, aoEscolher, ocupado }: { terminal: number; activa: number | null; aoEscolher: (m: MesaPOS | null) => void; ocupado?: boolean }) {
  const { token } = theme.useToken();
  const { pode } = useSessao();
  const mesas = useMesas(terminal);
  const [criar, setCriar] = useState(false);
  const [gerir, setGerir] = useState(false);
  const [form] = Form.useForm<{ nome: string }>();
  const nova = useAccao<MesaPOS>({
    invalidar: [['pos', 'mesas', terminal]],
    tituloErro: 'Não foi possível criar a mesa',
    aoSucesso: () => {
      setCriar(false);
      form.resetFields();
    },
  });
  const cartao = (chave: string, titulo: string, subtitulo: string, estado: 'ACTIVA' | 'OCUPADA' | 'LIVRE', onClick: () => void) => {
    const cor = estado === 'ACTIVA' ? token.colorPrimary : estado === 'OCUPADA' ? token.colorWarning : token.colorBorder;
    return (
      <button
        key={chave}
        type="button"
        onClick={onClick}
        disabled={ocupado}
        aria-pressed={estado === 'ACTIVA'}
        style={{
          padding: '10px 8px',
          borderRadius: 4,
          border: `2px solid ${cor}`,
          background: estado === 'ACTIVA' ? token.colorPrimaryBg : estado === 'OCUPADA' ? token.colorWarningBg : token.colorBgContainer,
          cursor: ocupado ? 'wait' : 'pointer',
          textAlign: 'center',
          minHeight: 62,
        }}
      >
        <strong style={{ display: 'block', fontSize: 13, color: estado === 'LIVRE' ? token.colorText : cor }}>{titulo}</strong>
        <span style={{ display: 'block', fontSize: 11, marginTop: 4, fontWeight: 600, color: estado === 'OCUPADA' ? token.colorWarningText : token.colorTextSecondary }}>{subtitulo}</span>
      </button>
    );
  };
  return (
    <Card
      size="small"
      style={{ marginBottom: 16 }}
      title={
        <Typography.Text type="secondary" strong style={{ textTransform: 'uppercase', fontSize: 12, letterSpacing: 0.5 }}>
          <AppstoreOutlined style={{ color: token.colorSuccess }} /> Mapa de mesas / consumo em aberto
        </Typography.Text>
      }
      extra={
        <Space size={6}>
          {pode('pos_terminais_gerir') && !!mesas.data?.length && (
            <Button size="small" icon={<EditOutlined />} onClick={() => setGerir(true)}>
              Gerir mesas
            </Button>
          )}
          {pode('pos_venda', 'pos_terminais_gerir') && (
            <Button size="small" type="primary" ghost icon={<PlusOutlined />} onClick={() => setCriar(true)}>
              Criar mesa
            </Button>
          )}
        </Space>
      }
    >
      {mesas.isLoading ? (
        <Spin />
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(120px, 1fr))', gap: 8 }}>
          {cartao('balcao', 'Balcão', 'Venda sem mesa', activa === null ? 'ACTIVA' : 'LIVRE', () => aoEscolher(null))}
          {(mesas.data ?? []).map((m) => {
            const e = estadoMesa(m, activa);
            return cartao(String(m.id), m.nome, m.conta && m.conta.itens > 0 ? `Ocupada: ${formatarKz(m.conta.total)}` : 'Livre', e, () => aoEscolher(m));
          })}
        </div>
      )}
      {!mesas.isLoading && !mesas.data?.length && <Empty image={<ShopOutlined style={{ fontSize: 32 }} />} description="Sem mesas: crie a primeira." />}
      <ModalFormulario
        chave="pos.mesas"
        titulo="Criar mesa"
        aberto={criar}
        aoFechar={() => setCriar(false)}
        criacao
        form={form}
        campos={CAMPOS_MESA}
        nomeRegistos="mesas"
        larguraLinhas={640}
        invalidar={[['pos', 'mesas', terminal]]}
        gravarLinha={(r) => enviar('post', `/pos/terminais/${terminal}/mesas`, dadosMesa(r))}
        okText="Adicionar"
        cancelText="Cancelar"
        confirmLoading={nova.isPending}
        onOk={() => form.submit()}
        width={larguraModal(400)}
        destroyOnHidden={false}
      >
        <Form form={form} layout="vertical" onFinish={(v) => nova.mutate({ url: `/pos/terminais/${terminal}/mesas`, dados: dadosMesa(v) })}>
          <Form.Item name="nome" label="Nome da mesa" rules={[{ required: true, whitespace: true, message: 'Introduza um nome para a mesa.' }]}>
            <Input maxLength={60} placeholder="Ex.: Mesa 9, Terraço 3" autoFocus />
          </Form.Item>
        </Form>
      </ModalFormulario>
      <GerirMesas terminal={terminal} aberto={gerir} mesas={mesas.data ?? []} aoFechar={() => setGerir(false)} />
    </Card>
  );
}

/** Alterações por gravar de uma mesa (nome e ordem) ou null se não mudou. */
export function alteracaoMesa(m: Pick<MesaPOS, 'nome' | 'ordem'>, rascunho: { nome?: string; ordem?: number | null } | undefined): { nome: string; ordem: number } | null {
  if (!rascunho) return null;
  const nome = (rascunho.nome ?? m.nome).trim();
  const ordem = rascunho.ordem ?? m.ordem;
  return nome && (nome !== m.nome || ordem !== m.ordem) ? { nome, ordem } : null;
}

/**
 * «Gerir mesas» (auditoria backend × frontend, ponto 2): mudar o nome e a ordem de cada mesa (PUT /pos/terminais/{t}/mesas/{m})
 * e eliminá-la (DELETE /pos/mesas/{m} — o servidor só desactiva a que já teve contas e recusa a que tem conta aberta).
 */
function GerirMesas({ terminal, aberto, mesas, aoFechar }: { terminal: number; aberto: boolean; mesas: MesaPOS[]; aoFechar: () => void }) {
  const [rascunho, setRascunho] = useState<Record<number, { nome?: string; ordem?: number | null }>>({});
  const accao = useAccao({ invalidar: [['pos', 'mesas', terminal]], tituloErro: 'Não foi possível alterar a mesa', aoSucesso: (_d, p) => {
    const id = Number(p.url.split('/').pop());
    setRascunho((r) => { const n = { ...r }; delete n[id]; return n; });
  } });
  const mudar = (id: number, v: { nome?: string; ordem?: number | null }) => setRascunho((r) => ({ ...r, [id]: { ...r[id], ...v } }));
  return (
    <Modal open={aberto} title="Gerir mesas" onCancel={aoFechar} footer={<Button onClick={aoFechar}>Fechar</Button>} width={larguraModal(620)} destroyOnHidden>
      <Table<MesaPOS>
        rowKey="id"
        size="small"
        pagination={false}
        dataSource={mesas}
        columns={[
          {
            title: 'Nome',
            render: (_, m) => (
              <Input value={rascunho[m.id]?.nome ?? m.nome} maxLength={60} aria-label={`Nome da mesa ${m.nome}`} onChange={(e) => mudar(m.id, { nome: e.target.value })} />
            ),
          },
          {
            title: 'Ordem',
            width: 100,
            render: (_, m) => (
              <InputNumber<number> min={0} max={100000} precision={0} value={rascunho[m.id]?.ordem ?? m.ordem} aria-label={`Ordem da mesa ${m.nome}`}
                onChange={(v) => mudar(m.id, { ordem: v })} style={{ width: '100%' }} />
            ),
          },
          {
            title: 'Acções',
            width: 110,
            render: (_, m) => {
              const alteracao = alteracaoMesa(m, rascunho[m.id]);
              return (
                <Space size={4}>
                  <Button size="small" type="primary" icon={<SaveOutlined />} disabled={!alteracao} loading={accao.isPending} aria-label={`Gravar a mesa ${m.nome}`}
                    onClick={() => alteracao && accao.mutate({ metodo: 'put', url: `/pos/terminais/${terminal}/mesas/${m.id}`, dados: alteracao })} />
                  <Popconfirm
                    title={`Eliminar a mesa ${m.nome}?`}
                    description="Se já teve contas, fica só desactivada (o histórico mantém-se)."
                    okText="Eliminar"
                    cancelText="Cancelar"
                    okButtonProps={{ danger: true }}
                    disabled={!!m.conta && m.conta.itens > 0}
                    onConfirm={() => accao.mutate({ metodo: 'delete', url: `/pos/mesas/${m.id}` })}
                  >
                    <Button size="small" danger icon={<DeleteOutlined />} aria-label={`Eliminar a mesa ${m.nome}`} disabled={!!m.conta && m.conta.itens > 0}
                      title={m.conta && m.conta.itens > 0 ? 'A mesa tem uma conta aberta: cobre-a ou liberte-a primeiro.' : undefined} />
                  </Popconfirm>
                </Space>
              );
            },
          },
        ]}
      />
    </Modal>
  );
}
