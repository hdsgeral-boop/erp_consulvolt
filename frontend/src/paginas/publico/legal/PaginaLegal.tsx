import { type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import '../publico.css';

/** Casca partilhada pelas páginas legais/institucionais (Termos, Privacidade, Conformidade Fiscal) — mesma identidade da landing page. */
export function PaginaLegal({ titulo, actualizadoEm, children }: { titulo: string; actualizadoEm: string; children: ReactNode }) {
  return (
    <div className="cv-landing">
      <header className="cv-header">
        <div className="cv-header-container">
          <div className="cv-header-left">
            <Link to="/" className="cv-brand">
              <span className="cv-brand-text">
                consulvolt<span>ERP</span>
              </span>
            </Link>
          </div>
          <div className="cv-header-right">
            <Link to="/" className="cv-btn-pill-outline">← Voltar ao site</Link>
          </div>
        </div>
      </header>

      <section style={{ padding: '64px 24px 96px' }}>
        <div style={{ maxWidth: 820, margin: '0 auto' }}>
          <h1 className="cv-section-title" style={{ textAlign: 'left', marginBottom: 4 }}>{titulo}</h1>
          <p style={{ color: 'var(--cv-slate-500)', fontSize: 13, marginBottom: 40 }}>Última actualização: {actualizadoEm}</p>
          <div className="cv-legal-conteudo">{children}</div>
        </div>
      </section>

      <footer className="cv-footer">
        <div className="cv-footer-container">
          <div className="cv-footer-bottom" style={{ borderTop: 'none', paddingTop: 0 }}>
            <div>© {new Date().getFullYear()} Consulvolt ERP. Todos os direitos reservados.</div>
            <div><Link to="/" style={{ color: '#94a3b8' }}>Voltar à página inicial</Link></div>
          </div>
        </div>
      </footer>
    </div>
  );
}
