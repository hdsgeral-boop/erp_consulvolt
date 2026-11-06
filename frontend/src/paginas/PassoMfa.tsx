import { Button, Form, Input, Typography } from 'antd';
import { SafetyOutlined } from '@ant-design/icons';

interface Props {
  aEnviar: boolean;
  onSubmeter: (codigo: string) => void;
  onCancelar: () => void;
}

/**
 * 2.º passo do login quando a conta tem autenticação de dois factores: o código de 6 dígitos da aplicação (ou um código
 * de recuperação, «ABCD-EFGH»). `one-time-code` deixa o telemóvel sugerir o código recebido.
 */
export function PassoMfa({ aEnviar, onSubmeter, onCancelar }: Props) {
  return (
    <Form layout="vertical" onFinish={(v: { codigo: string }) => onSubmeter(v.codigo)} requiredMark={false} aria-label="Confirmação em dois passos">
      <Typography.Paragraph type="secondary" style={{ textAlign: 'center' }}>
        Esta conta tem a confirmação em dois passos activa. Introduza o código de 6 dígitos da sua aplicação de autenticação
        (ou um código de recuperação).
      </Typography.Paragraph>
      <Form.Item
        name="codigo"
        label="Código de autenticação"
        rules={[
          { required: true, whitespace: true, message: 'Indique o código.' },
          { max: 32, message: 'O código é demasiado longo.' },
        ]}
      >
        <Input
          size="large"
          prefix={<SafetyOutlined />}
          autoComplete="one-time-code"
          inputMode="text"
          autoCapitalize="characters"
          autoCorrect="off"
          spellCheck={false}
          maxLength={32}
          autoFocus
        />
      </Form.Item>
      <Button type="primary" htmlType="submit" block size="large" loading={aEnviar} style={{ fontWeight: 700 }}>
        Confirmar e entrar
      </Button>
      <Button type="link" block onClick={onCancelar} disabled={aEnviar} style={{ marginTop: 8 }}>
        Voltar
      </Button>
    </Form>
  );
}
