import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Button, Result, Typography } from 'antd';
import { MENSAGEM_INESPERADA } from '@/utilitarios/mensagensErro';

/** Referência curta de um erro do navegador (sem caracteres ambíguos), para o suporte pedir o ponto exacto ao utilizador. */
export function novaReferenciaNavegador(): string {
  const alfabeto = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
  let ref = '';
  for (let i = 0; i < 8; i++) ref += alfabeto[Math.floor(Math.random() * alfabeto.length)];
  return `UI-${ref}`;
}

interface Props {
  children: ReactNode;
}
interface Estado {
  referencia: string | null;
}

/**
 * Último recurso para erros inesperados de renderização no navegador: em vez de um ecrã em branco mostra uma mensagem
 * genérica (nunca o texto da excepção, nem a pilha) com uma referência; a causa vai só para a consola do navegador.
 */
export class FronteiraDeErros extends Component<Props, Estado> {
  state: Estado = { referencia: null };

  static getDerivedStateFromError(): Estado {
    return { referencia: novaReferenciaNavegador() };
  }

  componentDidCatch(erro: Error, info: ErrorInfo): void {
    console.error(`[${this.state.referencia ?? 'UI'}] erro de renderização`, erro, info.componentStack);
  }

  render(): ReactNode {
    const { referencia } = this.state;
    if (!referencia) return this.props.children;
    return (
      <Result
        status="error"
        role="alert"
        title="Algo correu mal neste ecrã"
        subTitle={MENSAGEM_INESPERADA}
        extra={[
          <Button key="tentar" onClick={() => this.setState({ referencia: null })}>
            Tentar novamente
          </Button>,
          <Button key="recarregar" type="primary" onClick={() => window.location.reload()}>
            Recarregar a página
          </Button>,
        ]}
      >
        <Typography.Paragraph type="secondary" style={{ textAlign: 'center', marginBottom: 0 }}>
          Referência para o suporte:{' '}
          <Typography.Text code copyable={{ text: referencia, tooltips: ['Copiar referência', 'Copiada'] }}>
            {referencia}
          </Typography.Text>
        </Typography.Paragraph>
      </Result>
    );
  }
}
