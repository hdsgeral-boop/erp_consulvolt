import { Button, Form, Input, Space, message, type FormInstance } from 'antd';
import { gerarPalavraPasse, problemaPalavraPasse } from '@/utilitarios/palavraPasse';

/**
 * Campo «Palavra-passe» da plataforma com o botão «Gerar» e a regra de força do servidor (mínimo próprio da plataforma:
 * 10 caracteres). A gerada é copiada para a área de transferência, para a passar ao utilizador.
 */
export function CampoPalavraPasse({ form, obrigatorio, rotulo, minimo = 10, nome = 'palavra_passe' }: { form: FormInstance; obrigatorio: boolean; rotulo: string; minimo?: number; nome?: string }) {
  const validar = (_: unknown, v?: string) => {
    if (!v) return obrigatorio ? Promise.reject(new Error('Indique a palavra-passe.')) : Promise.resolve();
    if (v.length < minimo) return Promise.reject(new Error(`Pelo menos ${minimo} caracteres.`));
    const p = problemaPalavraPasse(v);
    return p ? Promise.reject(new Error(p)) : Promise.resolve();
  };
  return (
    <Form.Item label={rotulo} required={obrigatorio}>
      <Space.Compact style={{ width: '100%' }}>
        <Form.Item name={nome} noStyle rules={[{ validator: validar }]}>
          <Input.Password maxLength={255} autoComplete="new-password" />
        </Form.Item>
        <Button
          onClick={() => {
            const p = gerarPalavraPasse(Math.max(12, minimo));
            form.setFields([{ name: nome, value: p, errors: [] }]);
            void navigator.clipboard?.writeText(p).then(() => message.info('Palavra-passe gerada e copiada.'), () => undefined);
          }}
        >
          Gerar
        </Button>
      </Space.Compact>
    </Form.Item>
  );
}
