import { Alert, Button, Col, Form, Input, InputNumber, Modal, Row, Skeleton, Table } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import type { Escalao } from '../comum/tipos';

/** Valida os escalões como o servidor: 1 a 4 níveis com nome; limites positivos e crescentes; o último sem limite. */
export function validarEscaloes(niveis: { nome?: string; limite?: number | null }[]): string | null {
  if (niveis.length < 1 || niveis.length > 4) return 'Defina entre 1 e 4 níveis de aprovação.';
  let anterior = 0;
  for (let i = 0; i < niveis.length; i++) {
    if (!niveis[i].nome?.trim()) return 'Todos os níveis têm de ter nome.';
    if (i === niveis.length - 1) break;
    const limite = Number(niveis[i].limite ?? 0);
    if (!(limite > anterior)) return 'Os limites têm de ser positivos e crescentes.';
    anterior = limite;
  }
  return null;
}

/** Escalões de deliberação dos pedidos por valor estimado (consulta; edição com compras_deliberacao_config). */
export function ModalEscaloes({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const { pode } = useSessao();
  const podeEditar = pode('compras_deliberacao_config');
  const [form] = Form.useForm<{ niveis: Escalao[] }>();
  const consulta = useQuery({ queryKey: ['compras', 'escaloes'], queryFn: () => obter<Escalao[]>('/compras/deliberacao/escaloes'), enabled: aberto });
  const gravar = useAccao({ invalidar: [['compras', 'escaloes']], aoSucesso: aoFechar });

  useEffect(() => {
    if (consulta.data) form.setFieldsValue({ niveis: consulta.data });
  }, [consulta.data, form]);

  const niveis = Form.useWatch('niveis', form) ?? [];
  const erro = podeEditar ? validarEscaloes(niveis) : null;

  return (
    <Modal
      title="Escalões de aprovação por valor"
      open={aberto}
      onCancel={aoFechar}
      width={640}
      okText="Gravar"
      cancelText="Fechar"
      okButtonProps={{ disabled: !podeEditar || !!erro, style: podeEditar ? undefined : { display: 'none' } }}
      confirmLoading={gravar.isPending}
      onOk={() => form.submit()}
    >
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 16 }}
        message="O valor estimado do pedido define os níveis que têm de aprovar, por ordem. O nível 1 é o responsável da unidade do requisitante; ninguém aprova os próprios pedidos."
      />
      {consulta.isLoading ? (
        <Skeleton active />
      ) : podeEditar ? (
        <Form
          form={form}
          layout="vertical"
          onFinish={(v) =>
            gravar.mutate({
              metodo: 'put',
              url: '/compras/deliberacao/escaloes',
              dados: { niveis: v.niveis.map((n, i) => ({ nome: n.nome.trim(), limite: i === v.niveis.length - 1 ? null : n.limite })) },
            })
          }
        >
          <Form.List name="niveis">
            {(campos, { add, remove }) => (
              <>
                {campos.map(({ key, name }, i) => (
                  <Row key={key} gutter={8}>
                    <Col span={13}>
                      <Form.Item name={[name, 'nome']} label={i === 0 ? 'Nível' : undefined}>
                        <Input placeholder={`Nível ${i + 1}`} maxLength={100} />
                      </Form.Item>
                    </Col>
                    <Col span={9}>
                      <Form.Item name={[name, 'limite']} label={i === 0 ? 'Até (Kz)' : undefined}>
                        <InputNumber min={0} precision={2} style={{ width: '100%' }} disabled={i === campos.length - 1} placeholder={i === campos.length - 1 ? 'Sem limite' : ''} />
                      </Form.Item>
                    </Col>
                    <Col span={2}>
                      <Button danger type="text" icon={<DeleteOutlined />} disabled={campos.length <= 1} onClick={() => remove(name)} aria-label="Remover nível" style={{ marginTop: i === 0 ? 30 : 0 }} />
                    </Col>
                  </Row>
                ))}
                {campos.length < 4 && (
                  <Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ nome: '', limite: null })}>
                    Acrescentar nível
                  </Button>
                )}
              </>
            )}
          </Form.List>
          {erro && <Alert type="warning" style={{ marginTop: 12 }} message={erro} />}
        </Form>
      ) : (
        <Table<Escalao>
          size="small"
          rowKey="nome"
          pagination={false}
          dataSource={consulta.data ?? []}
          columns={[
            { title: 'Nível', render: (_, __, i) => i + 1 },
            { title: 'Nome', dataIndex: 'nome' },
            { title: 'Até (Kz)', dataIndex: 'limite', align: 'right', render: (v: number | null) => (v === null ? 'Sem limite' : formatarKz(v)) },
          ]}
        />
      )}
    </Modal>
  );
}
