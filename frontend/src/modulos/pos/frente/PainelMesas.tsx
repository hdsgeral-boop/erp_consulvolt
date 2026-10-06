import { Button, Card, Empty, Form, Input, Modal, Spin, Typography, theme } from 'antd';
import { AppstoreOutlined, PlusOutlined, ShopOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { estadoMesa, type MesaPOS } from './mesas';

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
          borderRadius: 10,
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
        pode('pos_venda', 'pos_terminais_gerir') && (
          <Button size="small" type="primary" ghost icon={<PlusOutlined />} onClick={() => setCriar(true)}>
            Criar mesa
          </Button>
        )
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
      <Modal
        title="Criar mesa"
        open={criar}
        onCancel={() => setCriar(false)}
        okText="Adicionar"
        cancelText="Cancelar"
        confirmLoading={nova.isPending}
        onOk={() => form.submit()}
        width={larguraModal(400)}
      >
        <Form form={form} layout="vertical" onFinish={(v) => nova.mutate({ url: `/pos/terminais/${terminal}/mesas`, dados: { nome: v.nome.trim() } })}>
          <Form.Item name="nome" label="Nome da mesa" rules={[{ required: true, whitespace: true, message: 'Introduza um nome para a mesa.' }]}>
            <Input maxLength={60} placeholder="Ex.: Mesa 9, Terraço 3" autoFocus />
          </Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
