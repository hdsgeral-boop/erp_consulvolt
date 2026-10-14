import { AutoComplete, Button, Col, Empty, Form, Input, Modal, Radio, Row, Space, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import type { ContaBancariaTerceiro } from '@/modulos/compras/comum/referencias';
import { formatarIban, ibanValido } from '@/modulos/rh/comum/regras';

interface ValoresConta {
  banco?: string;
  numero_conta?: string;
  iban: string;
  titular?: string;
}

interface Banco {
  id: number;
  nome: string;
  codigo: string | null;
}

/**
 * «Contas bancárias» na ficha do terceiro (auxDesenharContasBancarias / auxAdicionarContaBancaria /
 * auxContaBancariaPredefinida / auxEliminarContaBancaria, Versão Actual js/ui_aux.js:920-1005): lista com a predefinida
 * primeiro (rádio para mudar), eliminar, e «Adicionar conta bancária» (Banco, Nº de conta, IBAN, Titular). Só com o
 * terceiro já gravado. As regras (IBAN válido e não repetido, uma só predefinida) são validadas no servidor.
 */
export function ContasBancariasTerceiro({ terceiroId, podeGerir }: { terceiroId: number | null; podeGerir: boolean }) {
  const [form] = Form.useForm<ValoresConta>();
  const chave = ['terceiros', 'contas-bancarias', terceiroId];
  const contas = useQuery({
    queryKey: chave,
    queryFn: () => obter<ContaBancariaTerceiro[]>(`/terceiros/${terceiroId}/contas-bancarias`),
    enabled: terceiroId !== null,
  });
  const bancos = useQuery({ queryKey: ['terceiros', 'bancos'], queryFn: () => obter<Banco[]>('/terceiros/bancos'), staleTime: 600_000, retry: false, enabled: podeGerir && terceiroId !== null });
  const accao = useAccao({ invalidar: [chave, ['terceiros', 'lista'], ['terceiros', 'ficha-completa']], tituloErro: 'Não foi possível gravar a conta bancária' });
  const adicionar = useAccao({
    invalidar: [chave, ['terceiros', 'lista'], ['terceiros', 'ficha-completa']],
    aoSucesso: () => form.resetFields(),
    tituloErro: 'Não foi possível adicionar a conta bancária',
  });

  if (terceiroId === null) return null;
  const url = `/terceiros/${terceiroId}/contas-bancarias`;
  const lista = contas.data ?? [];

  return (
    <div style={{ border: '1px solid #e2e8f0', borderRadius: 4, padding: '0.9rem', marginBottom: 16, background: '#f8fafc' }}>
      <Typography.Title level={5} style={{ margin: '0 0 0.2rem', fontSize: '0.92rem' }}>
        <IconeFa nome="university" style={{ color: '#0891b2' }} /> Contas bancárias
      </Typography.Title>
      <Typography.Text type="secondary" style={{ display: 'block', marginBottom: '0.7rem', fontSize: 12 }}>
        Sem conta bancária aqui, este terceiro não pode receber carta de ordem de pagamento.
      </Typography.Text>
      {lista.length === 0 ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sem contas bancárias registadas." style={{ margin: '4px 0', border: '1px dashed #cbd5e1', borderRadius: 4, padding: 8 }} />
      ) : (
        lista.map((c) => (
          <div
            key={c.id}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '0.5rem',
              padding: '0.45rem 0.5rem',
              border: `1px solid ${c.predefinida ? '#86efac' : '#e2e8f0'}`,
              borderRadius: 4,
              background: c.predefinida ? '#f0fdf4' : '#fff',
              marginBottom: '0.35rem',
            }}
          >
            <Radio
              checked={c.predefinida}
              disabled={!podeGerir || accao.isPending}
              title="Usar esta conta por omissão na carta"
              aria-label={`Predefinir a conta ${formatarIban(c.iban)}`}
              onChange={() => accao.mutate({ url: `${url}/${c.id}/predefinida` })}
            />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontWeight: 600, fontSize: '0.85rem' }}>
                {c.banco || '—'}
                {c.predefinida && <span style={{ color: '#16a34a', fontWeight: 500, fontSize: '0.75rem' }}> · predefinida</span>}
              </div>
              <div style={{ fontFamily: 'monospace', fontSize: '0.8rem', color: '#475569', wordBreak: 'break-all' }}>
                {formatarIban(c.iban)}
                {c.numero_conta ? ` · ${c.numero_conta}` : ''}
                {c.codigo_moeda ? ` · ${c.codigo_moeda}` : ''}
              </div>
              {c.titular && <div style={{ fontSize: '0.75rem', color: '#64748b' }}>Titular: {c.titular}</div>}
            </div>
            {podeGerir && (
              <Button
                size="small"
                danger
                title="Eliminar conta bancária"
                aria-label="Eliminar conta bancária"
                icon={<IconeFa nome="trash" />}
                onClick={() =>
                  Modal.confirm({
                    title: `Eliminar a conta ${formatarIban(c.iban)}${c.banco ? ` (${c.banco})` : ''}?`,
                    content: 'As cartas já emitidas com esta conta não são alteradas.',
                    okText: 'Eliminar',
                    okButtonProps: { danger: true },
                    cancelText: 'Cancelar',
                    onOk: () => accao.mutateAsync({ metodo: 'delete', url: `${url}/${c.id}` }),
                  })
                }
              />
            )}
          </div>
        ))
      )}
      {podeGerir && (
        <Form<ValoresConta>
          form={form}
          layout="vertical"
          style={{ marginTop: '0.7rem' }}
          onFinish={(v) =>
            adicionar.mutate({
              url,
              dados: { banco: v.banco?.trim() || null, numero_conta: v.numero_conta?.trim() || null, iban: v.iban.replace(/\s+/g, '').toUpperCase(), titular: v.titular?.trim() || null },
            })
          }
        >
          <Row gutter={8}>
            <Col xs={24} sm={12}>
              <Form.Item name="banco" style={{ marginBottom: 8 }} rules={[{ max: 150 }]}>
                <AutoComplete
                  placeholder="Banco"
                  aria-label="Banco"
                  options={(bancos.data ?? []).map((b) => ({ value: b.nome }))}
                  filterOption={(i, o) => String(o?.value ?? '').toLowerCase().includes(i.toLowerCase())}
                />
              </Form.Item>
            </Col>
            <Col xs={24} sm={12}>
              <Form.Item name="numero_conta" style={{ marginBottom: 8 }} rules={[{ max: 50 }]}>
                <Input placeholder="Nº de conta (opcional)" aria-label="Número de conta" />
              </Form.Item>
            </Col>
            <Col span={24}>
              <Form.Item
                name="iban"
                style={{ marginBottom: 8 }}
                rules={[
                  { required: true, message: 'O IBAN é obrigatório — é ele que o banco usa para transferir.' },
                  { validator: (_, v: string) => (!v || ibanValido(v) ? Promise.resolve() : Promise.reject(new Error('IBAN inválido (dígitos de controlo).'))) },
                ]}
              >
                <Input placeholder="IBAN" aria-label="IBAN" maxLength={40} style={{ fontFamily: 'monospace' }} />
              </Form.Item>
            </Col>
            <Col span={24}>
              <Form.Item name="titular" style={{ marginBottom: 8 }} rules={[{ max: 150 }]}>
                <Input placeholder="Titular (se diferente do nome)" aria-label="Titular" />
              </Form.Item>
            </Col>
          </Row>
          <Space style={{ width: '100%' }} direction="vertical">
            <Button block htmlType="submit" icon={<IconeFa nome="plus" />} loading={adicionar.isPending}>
              Adicionar conta bancária
            </Button>
          </Space>
        </Form>
      )}
    </div>
  );
}
