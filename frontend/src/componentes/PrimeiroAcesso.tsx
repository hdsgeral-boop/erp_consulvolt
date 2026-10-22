import { Alert, Button, Form, Input, Modal, Space, message } from 'antd';
import { LockOutlined } from '@ant-design/icons';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { gerarPalavraPasse, problemaPalavraPasse } from '@/utilitarios/palavraPasse';

interface FormAlteracao {
  palavra_passe_actual: string;
  palavra_passe_nova: string;
  palavra_passe_nova_confirmation: string;
}

/**
 * Primeiro acesso (pedido de 2026-10-22): com uma palavra-passe provisória (conta criada ou palavra-passe reposta por
 * um administrador, `alterar_palavra_passe`), pede-se a alteração logo ao entrar. «Agora não» adia — o servidor deixa
 * de pedir (PUT /autenticacao/palavra-passe/dispensar), por isso só aparece nesse primeiro acesso.
 */
export function PrimeiroAcesso() {
  const { utilizador, actualizarUtilizador } = useSessao();
  const [form] = Form.useForm<FormAlteracao>();
  const [ocupado, setOcupado] = useState(false);
  if (utilizador?.alterar_palavra_passe !== true) return null;

  const fechar = () => actualizarUtilizador({ alterar_palavra_passe: false });
  const adiar = async () => {
    setOcupado(true);
    try {
      await enviar('put', '/autenticacao/palavra-passe/dispensar', {});
      fechar();
    } catch (e) {
      notificarErro(e);
    } finally {
      setOcupado(false);
    }
  };
  const alterar = async (v: FormAlteracao) => {
    setOcupado(true);
    try {
      await enviar('put', '/autenticacao/palavra-passe', v);
      message.success('Palavra-passe alterada.');
      fechar();
    } catch (e) {
      notificarErro(e, 'A palavra-passe não foi alterada');
    } finally {
      setOcupado(false);
    }
  };
  const regraForca = { validator: (_: unknown, v?: string) => { const p = v ? problemaPalavraPasse(v) : null; return p ? Promise.reject(new Error(p)) : Promise.resolve(); } };

  return (
    <Modal
      open
      closable={false}
      maskClosable={false}
      title={<Space><LockOutlined /> Altere a sua palavra-passe</Space>}
      footer={
        <Space>
          <Button onClick={() => void adiar()} disabled={ocupado}>Agora não</Button>
          <Button type="primary" loading={ocupado} onClick={() => form.submit()}>Alterar palavra-passe</Button>
        </Space>
      }
    >
      <Alert type="info" showIcon style={{ marginBottom: 16 }} message="A sua palavra-passe foi definida por um administrador. Por segurança, escolha uma só sua. Se adiar, pode alterá-la mais tarde em «Gerir Dados Pessoais»." />
      <Form form={form} layout="vertical" onFinish={(v) => void alterar(v)} requiredMark={false}>
        <Form.Item name="palavra_passe_actual" label="Palavra-passe actual (a que recebeu)" rules={[{ required: true, message: 'Indique a palavra-passe actual.' }]}>
          <Input.Password autoComplete="current-password" maxLength={255} />
        </Form.Item>
        <Form.Item label="Nova palavra-passe" required>
          <Space.Compact style={{ width: '100%' }}>
            <Form.Item name="palavra_passe_nova" noStyle rules={[{ required: true, message: 'Indique a nova palavra-passe.' }, regraForca]}>
              <Input.Password autoComplete="new-password" maxLength={255} />
            </Form.Item>
            <Button
              onClick={() => {
                const p = gerarPalavraPasse();
                form.setFieldsValue({ palavra_passe_nova: p, palavra_passe_nova_confirmation: p });
                void navigator.clipboard?.writeText(p).then(() => message.info('Palavra-passe gerada e copiada.'), () => undefined);
              }}
            >
              Gerar
            </Button>
          </Space.Compact>
        </Form.Item>
        <Form.Item
          name="palavra_passe_nova_confirmation"
          label="Confirmar a nova palavra-passe"
          dependencies={['palavra_passe_nova']}
          rules={[
            { required: true, message: 'Confirme a nova palavra-passe.' },
            ({ getFieldValue }) => ({ validator: (_, v) => (v === getFieldValue('palavra_passe_nova') ? Promise.resolve() : Promise.reject(new Error('As palavras-passe não coincidem.'))) }),
          ]}
        >
          <Input.Password autoComplete="new-password" maxLength={255} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
