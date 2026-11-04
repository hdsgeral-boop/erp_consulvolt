import { Typography } from 'antd';

/** Rodapé fixo dos ecrãs de acesso (entrada e recuperação da palavra-passe). */
export function RodapeEntrada() {
  return (
    <footer style={{ position: 'fixed', left: 0, right: 0, bottom: 0, padding: '10px 16px', textAlign: 'center', background: 'rgba(0, 0, 0, 0.35)' }}>
      <Typography.Text style={{ color: '#fff', fontSize: 12 }}>©{new Date().getFullYear()} CONSULVOLT ERP. Todos os direitos reservados.</Typography.Text>
    </footer>
  );
}
