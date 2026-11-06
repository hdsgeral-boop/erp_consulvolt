import { Alert, Button, Card, Divider, Flex, Form, Input, Typography } from 'antd';
import { CustomerServiceOutlined, LockOutlined, UserOutlined } from '@ant-design/icons';
import { useEffect } from 'react';
import { Link, useNavigate } from 'react-router';
import { useSessao } from '@/sessao/SessaoContexto';
import { PassoMfa } from '@/paginas/PassoMfa';
import { useEntrada } from '@/paginas/useEntrada';
import { podeBackofficeSuporte } from '@/sessao/areas';

const ELEGIVEL = podeBackofficeSuporte;

/**
 * Login dedicado ao backoffice de suporte (ADR-076) — só agentes de suporte e super-administradores. Um utilizador
 * comum do ERP que tente entrar aqui com as suas credenciais válidas autentica normalmente (é a mesma conta), mas é
 * imediatamente desligado outra vez, com um motivo claro — nunca fica "preso" num estado autenticado fora do sítio.
 */
export function SuporteLogin() {
  const { sair, utilizador, motivoSaida } = useSessao();
  const navigate = useNavigate();
  const { erro, aEntrar, passoMfa, submeter: entrarComSenha, submeterCodigo, cancelarMfa } = useEntrada('Falha ao autenticar no backoffice de suporte.');

  useEffect(() => {
    if (ELEGIVEL(utilizador)) navigate('/suporte', { replace: true });
  }, [utilizador, navigate]);

  const depoisDeEntrar = async (u: Awaited<ReturnType<typeof entrarComSenha>>) => {
    if (!u) return;
    if (!ELEGIVEL(u)) {
      await sair('Este acesso é reservado à equipa de suporte da plataforma. Entre pela página normal do ERP.');
      return;
    }
    navigate('/suporte', { replace: true });
  };
  const submeter = async (v: { nome_utilizador: string; palavra_passe: string }) => depoisDeEntrar(await entrarComSenha(v.nome_utilizador, v.palavra_passe));
  const submeterCodigoMfa = async (codigo: string) => depoisDeEntrar(await submeterCodigo(codigo));

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada">
      <Card className="erp-cartao-entrada" style={{ width: '100%', maxWidth: 440 }} styles={{ body: { padding: 'clamp(24px, 6vw, 44px)' } }}>
        <Flex vertical align="center" gap={8} style={{ marginBottom: 24, textAlign: 'center' }}>
          <div
            style={{
              width: 52,
              height: 52,
              borderRadius: 14,
              background: 'linear-gradient(135deg, #1947b3 0%, #0f172a 100%)',
              color: '#ffffff',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 26,
              marginBottom: 4,
            }}
          >
            <CustomerServiceOutlined />
          </div>
          <h1 className="erp-entrada-titulo">Central de Suporte</h1>
          <Typography.Text type="secondary">Acesso restrito à equipa de suporte Consulvolt</Typography.Text>
        </Flex>

        {motivoSaida && <Alert type="info" showIcon title={motivoSaida} style={{ marginBottom: 16 }} />}
        {erro && <Alert type="error" showIcon title={erro} style={{ marginBottom: 16 }} />}

        {passoMfa ? (
          <PassoMfa aEnviar={aEntrar} onSubmeter={(c) => void submeterCodigoMfa(c)} onCancelar={cancelarMfa} />
        ) : (
          <Form layout="vertical" onFinish={(v) => void submeter(v)} requiredMark={false} aria-label="Entrar na central de suporte">
            <Form.Item name="nome_utilizador" label="Utilizador" rules={[{ required: true, whitespace: true, message: 'Indique o utilizador ou o e-mail.' }, { max: 150, message: 'Máximo de 150 caracteres.' }]}>
              <Input size="large" prefix={<UserOutlined />} autoComplete="username" autoCapitalize="none" autoCorrect="off" spellCheck={false} maxLength={150} autoFocus placeholder="agente" />
            </Form.Item>
            <Form.Item name="palavra_passe" label="Palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }, { max: 255, message: 'Máximo de 255 caracteres.' }]}>
              <Input.Password size="large" prefix={<LockOutlined />} autoComplete="current-password" maxLength={255} />
            </Form.Item>
            <Button type="primary" htmlType="submit" block size="large" loading={aEntrar} style={{ fontWeight: 700 }}>
              Entrar na Central de Suporte
            </Button>
          </Form>
        )}

        <Divider style={{ margin: '20px 0 16px' }} />

        <Flex vertical align="center" gap={10}>
          <Link to="/" className="erp-link-voltar">
            Voltar ao site público
          </Link>
        </Flex>
      </Card>
    </main>
  );
}
