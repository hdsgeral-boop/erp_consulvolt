import { DownOutlined, RightOutlined } from '@ant-design/icons';
import { theme } from 'antd';
import { useId, useState, type ReactNode } from 'react';

/**
 * Secção recolhível da vista Grade (os campos «abrem ou fecham»). O conteúdo continua montado quando está recolhido
 * (só fica escondido), para os `Form.Item` manterem os valores e as regras de validação.
 */
export function SeccaoFormulario({ titulo, children, recolhida = false, extra }: { titulo: ReactNode; children: ReactNode; recolhida?: boolean; extra?: ReactNode }) {
  const { token } = theme.useToken();
  const [fechada, setFechada] = useState(recolhida);
  const id = useId();
  return (
    <section className="erp-seccao-formulario" style={{ margin: '4px 0 8px' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: fechada ? 0 : 12 }}>
        <button
          type="button"
          aria-expanded={!fechada}
          aria-controls={id}
          onClick={() => setFechada((f) => !f)}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: 'none', border: 'none', padding: '2px 0', cursor: 'pointer', color: token.colorText, font: 'inherit', fontWeight: 500 }}
        >
          {fechada ? <RightOutlined style={{ fontSize: 11 }} /> : <DownOutlined style={{ fontSize: 11 }} />}
          {titulo}
        </button>
        <span aria-hidden style={{ flex: 1, borderTop: `1px solid ${token.colorSplit}` }} />
        {extra}
      </div>
      <div id={id} hidden={fechada}>{children}</div>
    </section>
  );
}
