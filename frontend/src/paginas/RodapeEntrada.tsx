import { Typography } from 'antd';

/** Rodapé fixo dos ecrãs de acesso (login e redefinição de senha). */
export function RodapeEntrada() {
  return (
    <footer style={{ position: 'fixed', left: 0, right: 0, bottom: 0, padding: '10px 16px', textAlign: 'center', background: 'rgba(0, 0, 0, 0.35)' }}>
      <Typography.Text style={{ color: '#fff', fontSize: 12 }}>©2026 MEU ERP - MEU SOLUÇÕES. Todos os direitos reservados.</Typography.Text>
    </footer>
  );
}
