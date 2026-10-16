import { render } from '@testing-library/react';
import { Button, ConfigProvider, Layout } from 'antd';
import { TEMA } from './tema';
import { TEMA_NOCTURNO } from '@/layout/temaNocturno';

/** Texto de todas as folhas de estilo injectadas pelo AntD (cssinjs) no <head>. */
const estilos = () => [...document.head.querySelectorAll('style')].map((s) => s.textContent ?? '').join('\n');

describe('tema: variáveis CSS do AntD (cssVar)', () => {
  it('o tema claro e a tela nocturna ligam as variáveis (o nocturno tem inherit: false)', () => {
    expect(TEMA.cssVar).toBeTruthy();
    expect(TEMA_NOCTURNO.cssVar).toBeTruthy();
  });

  it('declara --ant-color-primary na raiz dos componentes e muda com a tela nocturna', () => {
    const { container } = render(
      <ConfigProvider theme={TEMA}>
        <Layout className="claro"><Button>A</Button></Layout>
        <ConfigProvider theme={TEMA_NOCTURNO}>
          <Layout className="escuro"><Button>B</Button></Layout>
        </ConfigProvider>
      </ConfigProvider>,
    );
    const claro = container.querySelector('.claro')!;
    const escuro = container.querySelector('.escuro')!;
    const classe = (el: Element) => [...el.classList].find((c) => c.startsWith('css-var-'));
    expect(classe(claro)).toBeTruthy();
    expect(classe(escuro)).toBeTruthy();
    expect(classe(claro)).not.toBe(classe(escuro));
    const css = estilos().toLowerCase();
    expect(css).toMatch(/--ant-color-primary:\s*#2563eb/);
    // na tela nocturna o darkAlgorithm deriva a primária (#3b82f6 → #3572d4, a mesma dos botões); texto e fundo são os do ERP
    expect(css).toMatch(/--ant-color-primary:\s*#3572d4/);
    expect(css).toMatch(/--ant-color-text:\s*#e2e8f0/);
    expect(css).toMatch(/--ant-color-bg-container:\s*#111a2b/);
  });
});
