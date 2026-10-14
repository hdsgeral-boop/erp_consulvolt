import { Form, Input, Modal } from 'antd';
import { useEffect } from 'react';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal } from '@/componentes/responsivo';
import { corpoTerceiro } from '@/modulos/compras/comum/GestaoTerceiros';
import { SeletorConta } from '@/modulos/contab/comum/Seletores';
import { prefixosConta } from '@/modulos/contab/tabelasAux/contasTerceiro';

interface FormCliente {
  nome: string;
  nif?: string;
  telefone?: string;
  email?: string;
  endereco?: string;
  conta_cliente?: string;
}

/**
 * «Criar Novo Cliente» da frente de caixa (showPOSCreateCustomerModal do legado): ficha mínima do cliente
 * (POST /terceiros, papel CLIENTE — o servidor valida o NIF, a conta e a permissão de gerir clientes). A conta de cliente
 * (classe 31) é obrigatória, como no legado novo («Conta Contabilística *», ui_sales.js:8792) — sem contas por defeito.
 */
export function NovoClienteModal({ aberto, aoFechar, aoCriar }: { aberto: boolean; aoFechar: () => void; aoCriar: (id: number) => void }) {
  const [form] = Form.useForm<FormCliente>();
  const criar = useAccao<{ id: number }>({
    invalidar: [['terceiros']],
    tituloErro: 'Não foi possível criar o cliente',
    aoSucesso: (c) => {
      aoCriar(c.id);
      aoFechar();
    },
  });
  useEffect(() => {
    if (aberto) form.resetFields();
  }, [aberto, form]);
  return (
    <Modal
      open={aberto}
      title="Criar Novo Cliente"
      okText="Criar cliente"
      cancelText="Cancelar"
      onCancel={aoFechar}
      onOk={() => form.submit()}
      confirmLoading={criar.isPending}
      width={larguraModal(520)}
      destroyOnHidden
    >
      <Form form={form} layout="vertical" onFinish={(v) => criar.mutate({ url: '/terceiros', dados: corpoTerceiro('CLIENTE', v) })}>
        <Form.Item name="nome" label="Nome" rules={[{ required: true, whitespace: true, message: 'Indique o nome do cliente.' }]}>
          <Input maxLength={255} autoFocus />
        </Form.Item>
        <Form.Item name="nif" label="NIF">
          <Input maxLength={20} />
        </Form.Item>
        <Form.Item name="telefone" label="Telefone">
          <Input maxLength={30} />
        </Form.Item>
        <Form.Item name="email" label="Email" rules={[{ type: 'email', message: 'Email inválido.' }]}>
          <Input maxLength={255} />
        </Form.Item>
        <Form.Item name="endereco" label="Endereço">
          <Input maxLength={500} />
        </Form.Item>
        <Form.Item name="conta_cliente" label="Conta de cliente (classe 31)" rules={[{ required: true, message: 'Obrigatória: conta do cliente no plano de contas da empresa.' }]}>
          <SeletorConta prefixos={prefixosConta('CLIENTE')} placeholder="Obrigatória — ex.: 31.1.1" allowClear style={{ width: '100%' }} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
