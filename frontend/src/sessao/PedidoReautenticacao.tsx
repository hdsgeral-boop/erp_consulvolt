import { useEffect, useRef, useState } from 'react';
import { Alert, Form, Input, Modal } from 'antd';
import { enviar } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { registarOuvinteReautenticacao, type MetodoReautenticacao } from '@/sessao/reautenticacao';

/**
 * Pedido de confirmação de identidade (step-up, ADR-085). Aparece quando o servidor recusa uma operação crítica com
 * 403 `MFA_REQUERIDO`: pede o código da aplicação de autenticação (ou de recuperação) se o MFA está activo, senão a
 * palavra-passe actual. Em caso de sucesso a operação original é repetida pelo cliente HTTP. Montar uma única vez, dentro
 * do `AntApp`.
 */
export function PedidoReautenticacao() {
  const [metodo, setMetodo] = useState<MetodoReautenticacao | null>(null);
  const [valor, setValor] = useState('');
  const [erro, setErro] = useState<string | null>(null);
  const [aEnviar, setAEnviar] = useState(false);
  const resolverRef = useRef<((confirmado: boolean) => void) | null>(null);

  useEffect(
    () =>
      registarOuvinteReautenticacao(({ metodo: m, resolver }) => {
        resolverRef.current = resolver;
        setValor('');
        setErro(null);
        setMetodo(m);
      }),
    [],
  );

  const fechar = (confirmado: boolean) => {
    resolverRef.current?.(confirmado);
    resolverRef.current = null;
    setMetodo(null);
    setValor('');
  };

  const confirmar = async () => {
    if (!valor.trim() || !metodo) return;
    setAEnviar(true);
    setErro(null);
    try {
      await enviar('post', '/autenticacao/reautenticar', metodo === 'codigo_mfa' ? { codigo: valor.trim() } : { palavra_passe: valor });
      fechar(true);
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : 'Não foi possível confirmar a identidade.');
    } finally {
      setAEnviar(false);
    }
  };

  const porCodigo = metodo === 'codigo_mfa';

  return (
    <Modal
      open={metodo !== null}
      title="Confirme a sua identidade"
      okText="Confirmar"
      cancelText="Cancelar"
      centered
      destroyOnHidden
      maskClosable={false}
      confirmLoading={aEnviar}
      okButtonProps={{ disabled: !valor.trim() }}
      onOk={confirmar}
      onCancel={() => fechar(false)}
    >
      <p style={{ marginTop: 0 }}>
        Esta operação é sensível e exige uma confirmação recente.{' '}
        {porCodigo ? 'Introduza o código da sua aplicação de autenticação (ou um código de recuperação).' : 'Introduza a sua palavra-passe actual.'}
      </p>
      <Form layout="vertical" onFinish={confirmar}>
        <Form.Item label={porCodigo ? 'Código de autenticação' : 'Palavra-passe actual'} style={{ marginBottom: 0 }}>
          {porCodigo ? (
            <Input
              autoFocus
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={16}
              value={valor}
              onChange={(e) => setValor(e.target.value)}
              onPressEnter={confirmar}
              aria-label="Código de autenticação"
            />
          ) : (
            <Input.Password autoFocus autoComplete="current-password" value={valor} onChange={(e) => setValor(e.target.value)} onPressEnter={confirmar} aria-label="Palavra-passe actual" />
          )}
        </Form.Item>
      </Form>
      {erro && <Alert type="error" showIcon title={erro} style={{ marginTop: 12 }} />}
    </Modal>
  );
}
