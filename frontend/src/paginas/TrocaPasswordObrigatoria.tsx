import { Alert, Button, Card, Divider, Flex, Form, Input, Progress, Typography, message } from 'antd';
import { KeyOutlined, LockOutlined } from '@ant-design/icons';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { MINIMO_PALAVRA_PASSE, forcaPalavraPasse, problemaPalavraPasse } from '@/utilitarios/palavraPasse';

interface Valores {
  palavra_passe_actual: string;
  palavra_passe_nova: string;
  palavra_passe_nova_confirmation: string;
}

/**
 * Ecrã bloqueante (sem menu nem layout): a password foi gerada pelo sistema (criação, reposição pelo administrador
 * ou recuperação) e o utilizador é obrigado a trocá-la antes de continuar — não há forma de ignorar ou dispensar
 * este ecrã; só sair (logout) ou trocar a password avançam (backend: ExigirTrocaPassword, 423 em todo o resto da API).
 */
export function TrocaPasswordObrigatoria() {
  const { sair, actualizarUtilizador, utilizador } = useSessao();
  const [form] = Form.useForm<Valores>();
  const nova = Form.useWatch('palavra_passe_nova', form) as string | undefined;
  // a nova palavra-passe não pode conter o utilizador nem a parte local do e-mail (o servidor aplica a mesma regra)
  const evitar = [utilizador?.nome_utilizador, typeof utilizador?.email === 'string' ? utilizador.email.split('@')[0] : null];
  const forca = forcaPalavraPasse(nova ?? '', evitar);
  const [aAlterar, setAAlterar] = useState(false);
  const [aSair, setASair] = useState(false);

  const submeter = async (v: Valores) => {
    setAAlterar(true);
    try {
      const r = await enviar<{ sessoes_terminadas: number }>('put', '/autenticacao/palavra-passe', v);
      message.success(r.mensagem);
      actualizarUtilizador({ deve_alterar_password: false });
    } catch (e) {
      notificarErro(e, 'Não foi possível alterar a palavra-passe');
    } finally {
      setAAlterar(false);
    }
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada">
      <Card className="erp-cartao-entrada" style={{ width: '100%', maxWidth: 460 }} styles={{ body: { padding: 'clamp(24px, 6vw, 44px)' } }}>
        <Flex vertical align="center" gap={8} style={{ marginBottom: 20, textAlign: 'center' }}>
          <KeyOutlined style={{ fontSize: 32, color: '#b91c1c' }} />
          <h1 className="erp-entrada-titulo">Troca de password obrigatória</h1>
          <Typography.Text type="secondary">
            A sua password foi gerada pelo sistema e enviada por email. Por segurança, tem de definir uma nova antes de continuar.
          </Typography.Text>
        </Flex>

        <Alert type="warning" showIcon style={{ marginBottom: 16 }} title="Não é possível aceder a mais nenhuma área do sistema enquanto não trocar a password." />

        <Form form={form} layout="vertical" onFinish={(v) => void submeter(v)} requiredMark={false}>
          <Form.Item name="palavra_passe_actual" label="Password actual (a que recebeu por email)" rules={[{ required: true, message: 'Indique a password actual.' }]}>
            <Input.Password size="large" prefix={<LockOutlined />} autoComplete="current-password" maxLength={255} autoFocus />
          </Form.Item>
          <Form.Item
            name="palavra_passe_nova"
            label="Nova password"
            rules={[
              { required: true, message: 'Indique a nova password.' },
              { min: MINIMO_PALAVRA_PASSE, message: `Pelo menos ${MINIMO_PALAVRA_PASSE} caracteres.` },
              { validator: (_, v?: string) => { const p = v ? problemaPalavraPasse(v, evitar) : null; return p ? Promise.reject(new Error(p)) : Promise.resolve(); } },
              ({ getFieldValue }) => ({
                validator: (_, v) => (v && v === getFieldValue('palavra_passe_actual') ? Promise.reject(new Error('Tem de ser diferente da actual.')) : Promise.resolve()),
              }),
            ]}
            hasFeedback
          >
            <Input.Password size="large" prefix={<LockOutlined />} autoComplete="new-password" maxLength={128} />
          </Form.Item>
          {nova ? (
            <div aria-live="polite" style={{ marginTop: -14, marginBottom: 16 }}>
              <Progress percent={forca.percentagem} status={forca.estado} showInfo={false} size="small" aria-label="Força da palavra-passe" />
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>Força: {forca.rotulo}</Typography.Text>
            </div>
          ) : null}
          <Form.Item
            name="palavra_passe_nova_confirmation"
            label="Confirmar a nova password"
            dependencies={['palavra_passe_nova']}
            hasFeedback
            rules={[
              { required: true, message: 'Repita a nova password.' },
              ({ getFieldValue }) => ({
                validator: (_, v) => (!v || v === getFieldValue('palavra_passe_nova') ? Promise.resolve() : Promise.reject(new Error('Não coincide com a nova password.'))),
              }),
            ]}
          >
            <Input.Password size="large" prefix={<LockOutlined />} autoComplete="new-password" />
          </Form.Item>
          <Button type="primary" htmlType="submit" block size="large" loading={aAlterar} style={{ fontWeight: 700 }}>
            Trocar password e continuar
          </Button>
        </Form>

        <Divider style={{ margin: '20px 0 16px' }} />

        <Flex vertical align="center" gap={10}>
          <Button
            type="text"
            danger
            loading={aSair}
            onClick={async () => {
              setASair(true);
              try {
                await sair();
              } finally {
                setASair(false);
              }
            }}
          >
            Sair sem trocar agora
          </Button>
        </Flex>
      </Card>
    </main>
  );
}
