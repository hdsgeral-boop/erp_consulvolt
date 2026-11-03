import { vi } from 'vitest';
import { configure } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';

// O Ant Design usa matchMedia (respostas por tamanho de ecrã), que o jsdom não implementa.
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => undefined,
    removeListener: () => undefined,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    dispatchEvent: () => false,
  }),
});

// O Ant Design 6 observa o tamanho de vários elementos (@rc-component/resize-observer); o jsdom não tem ResizeObserver.
if (typeof window.ResizeObserver === 'undefined') {
  class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
  Object.defineProperty(window, 'ResizeObserver', { writable: true, value: ResizeObserverStub });
}

// O jsdom 30 passou a declarar TransitionEvent e as propriedades CSS com prefixo Webkit (WebkitAnimation…), por isso o
// @rc-component/motion (animações do AntD: Modal, Drawer, Select, Tabs…) julga que o browser suporta transições e fica à
// espera de eventos transitionend/animationend que o jsdom nunca dispara — os modais nunca acabavam de fechar
// (destroyOnHidden não corria e as confirmações estáticas acumulavam-se no DOM). Retirar as propriedades de animação do
// protótipo do estilo repõe a detecção do jsdom 25: sem suporte, as animações ficam desligadas e os componentes montam
// e desmontam de imediato.
{
  const estilo = (window as unknown as { CSSStyleProperties?: { prototype: Record<string, unknown> } }).CSSStyleProperties?.prototype;
  for (const propriedade of ['animation', 'WebkitAnimation', 'MozAnimation', 'OAnimation', 'msAnimation']) {
    if (estilo && propriedade in estilo) delete estilo[propriedade];
  }
}

// Reforço do mesmo princípio para os pacotes que o Vitest transforma (o módulo de detecção do @rc-component/motion é
// substituído por uma cópia que declara «sem suporte a transições»).
vi.mock('@rc-component/motion/es/util/motion', async (importarOriginal) => ({
  ...(await importarOriginal<typeof import('@rc-component/motion/es/util/motion')>()),
  supportTransition: false,
}));

// O jsdom é lento com o AntD (tabelas, modais, selects): 1 s por omissão nos findBy*/waitFor fica curto e dava falhas
// intermitentes; 5 s não atrasa os testes que passam (as esperas terminam assim que a condição se cumpre).
configure({ asyncUtilTimeout: 5_000 });
