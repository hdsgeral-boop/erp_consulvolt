import { useCallback, useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Button, Modal, Typography } from 'antd';
import { CompassOutlined, LeftOutlined, RightOutlined, RocketOutlined } from '@ant-design/icons';
import { useSessao } from '@/sessao/SessaoContexto';
import { useEstadoLicenca } from '@/modulos/config/Licenca';
import { usePreferencia } from '@/componentes/preferencias/usePreferencias';

/**
 * Sessão (sessionStorage) definida pelo registo de empresa (RegistarEmpresa.tsx) para o tour arrancar de imediato,
 * sem esperar pela primeira leitura das preferências do servidor.
 */
export const CHAVE_SESSAO_MOSTRAR_TOUR = 'cv.mostrar_tour_boas_vindas';

/** Evento global: reabre o tour a pedido (ex.: botão "Repetir tutorial" no menu do utilizador). */
const EVENTO_REINICIAR = 'erp:tour:reiniciar';

export function reiniciarTourBoasVindas(): void {
  window.dispatchEvent(new Event(EVENTO_REINICIAR));
}

interface PassoTour {
  alvo: string;
  titulo: string;
  texto: string;
}

const PASSOS: PassoTour[] = [
  {
    alvo: '.erp-sider-topo, #erp-tour-marca',
    titulo: 'Bem-vindo(a) ao Consulvolt ERP',
    texto: 'Este é o seu espaço de trabalho. Vamos percorrer rapidamente os pontos principais do ecrã para começar com o pé direito.',
  },
  {
    alvo: '#erp-menu-lateral',
    titulo: 'Menu principal',
    texto: 'Aqui encontra todos os módulos do sistema: Vendas, Compras, Stock, Contabilidade, Tesouraria, Recursos Humanos e muitos outros. Clique para abrir, ou recolha o menu para ganhar espaço.',
  },
  {
    alvo: '.erp-cabecalho-pesquisa',
    titulo: 'Pesquisa rápida',
    texto: 'Procure qualquer ecrã do sistema pelo nome, sem precisar de navegar pelos menus. Atalho útil para o dia a dia.',
  },
  {
    alvo: '.erp-trocar-empresa',
    titulo: 'Trocar de empresa',
    texto: 'Se gerir mais do que uma empresa na mesma conta, pode alternar entre elas a qualquer momento por aqui.',
  },
  {
    alvo: '#erp-tour-licenca',
    titulo: 'Período de demonstração e licença',
    texto: 'Acompanhe aqui quantos dias restam do seu período de demonstração e o estado da sua licença. Quando estiver pronto, pode subscrever um plano comercial sem perder os seus dados.',
  },
  {
    alvo: '#erp-tour-notificacoes',
    titulo: 'Notificações',
    texto: 'Avisos importantes sobre a sua conta e as suas operações aparecem aqui, como documentos pendentes ou alertas de licença.',
  },
  {
    alvo: '#erp-tour-ajuda',
    titulo: 'Ajuda contextual',
    texto: 'Sempre que tiver dúvidas sobre um ecrã específico, clique aqui (ou prima F1) para ver a ajuda dedicada a essa página.',
  },
  {
    alvo: '.erp-utilizador-botao',
    titulo: 'A sua conta',
    texto: 'Aceda aos seus dados pessoais, ao portal do colaborador e termine a sessão a partir deste menu.',
  },
  {
    alvo: '.erp-conteudo',
    titulo: 'Área de trabalho',
    texto: 'É aqui que vai trabalhar: cada módulo mostra os seus ecrãs, listas e formulários nesta área principal.',
  },
];

interface Retangulo {
  top: number;
  left: number;
  width: number;
  height: number;
}

function medirAlvo(seletor: string): Retangulo | null {
  const alvos = seletor.split(',').map((s) => s.trim());
  for (const s of alvos) {
    const el = document.querySelector<HTMLElement>(s);
    if (el) {
      const r = el.getBoundingClientRect();
      if (r.width > 0 && r.height > 0) return { top: r.top, left: r.left, width: r.width, height: r.height };
    }
  }
  return null;
}

/**
 * Tour de boas-vindas guiado: um pequeno conjunto de passos com um "holofote" sobre cada zona principal do
 * layout (menu, pesquisa, licença, notificações, ajuda, utilizador, área de trabalho) e um balão com explicação.
 * Mostra-se uma só vez por utilizador (preferência `interface/tour_boas_vindas_v1`, guardada no servidor — por
 * isso persiste entre postos de trabalho) e pode ser repetido a qualquer momento com `reiniciarTourBoasVindas()`.
 */
export function TourBoasVindas() {
  const { estado, utilizador, empresa } = useSessao();
  const { data: licenca } = useEstadoLicenca(!!empresa);
  const [visto, definirVisto, aCarregarPref] = usePreferencia<boolean>('interface', 'tour_boas_vindas_v1', false);
  const [fase, setFase] = useState<'oculto' | 'boas_vindas' | 'passo'>('oculto');
  const [indice, setIndice] = useState(0);
  const [rect, setRect] = useState<Retangulo | null>(null);

  useEffect(() => {
    if (estado !== 'autenticado') return;
    let viaSessao = false;
    try {
      viaSessao = sessionStorage.getItem(CHAVE_SESSAO_MOSTRAR_TOUR) === '1';
      if (viaSessao) sessionStorage.removeItem(CHAVE_SESSAO_MOSTRAR_TOUR);
    } catch {
      /* modo privado ou armazenamento bloqueado: segue só pela preferência do servidor */
    }
    if (viaSessao) {
      setIndice(0);
      setFase('boas_vindas');
      return;
    }
    if (!aCarregarPref && !visto) {
      setIndice(0);
      setFase('boas_vindas');
    }
  }, [estado, aCarregarPref, visto]);

  useEffect(() => {
    const reiniciar = () => {
      setIndice(0);
      setFase('boas_vindas');
    };
    window.addEventListener(EVENTO_REINICIAR, reiniciar);
    return () => window.removeEventListener(EVENTO_REINICIAR, reiniciar);
  }, []);

  const passoAtual = PASSOS[indice];

  const medir = useCallback(() => {
    if (fase !== 'passo' || !passoAtual) {
      setRect(null);
      return;
    }
    const r = medirAlvo(passoAtual.alvo);
    setRect(r);
    if (r) {
      const centroY = r.top + r.height / 2;
      if (centroY < 100 || centroY > window.innerHeight - 100) {
        document.querySelector<HTMLElement>(passoAtual.alvo.split(',')[0].trim())?.scrollIntoView({ block: 'center', behavior: 'auto' });
      }
    }
  }, [fase, passoAtual]);

  useEffect(() => {
    medir();
    if (fase !== 'passo') return;
    const aoRedimensionar = () => medir();
    window.addEventListener('resize', aoRedimensionar);
    window.addEventListener('scroll', aoRedimensionar, true);
    const intervalo = window.setInterval(medir, 400);
    return () => {
      window.removeEventListener('resize', aoRedimensionar);
      window.removeEventListener('scroll', aoRedimensionar, true);
      window.clearInterval(intervalo);
    };
  }, [fase, medir]);

  const concluirTour = useCallback(() => {
    setFase('oculto');
    definirVisto(true);
  }, [definirVisto]);

  const avancar = useCallback(() => {
    // Salta passos cujo alvo não existe neste ecrã (ex.: pesquisa de topo escondida em telemóvel).
    let proximo = indice + 1;
    while (proximo < PASSOS.length && !medirAlvo(PASSOS[proximo].alvo)) proximo += 1;
    if (proximo >= PASSOS.length) concluirTour();
    else setIndice(proximo);
  }, [indice, concluirTour]);

  const recuar = useCallback(() => {
    let anterior = indice - 1;
    while (anterior >= 0 && !medirAlvo(PASSOS[anterior].alvo)) anterior -= 1;
    if (anterior >= 0) setIndice(anterior);
  }, [indice]);

  useEffect(() => {
    if (fase !== 'passo') return;
    const aoTeclar = (ev: KeyboardEvent) => {
      if (ev.key === 'Escape') concluirTour();
      else if (ev.key === 'ArrowRight' || ev.key === 'Enter') avancar();
      else if (ev.key === 'ArrowLeft') recuar();
    };
    window.addEventListener('keydown', aoTeclar);
    return () => window.removeEventListener('keydown', aoTeclar);
  }, [fase, concluirTour, avancar, recuar]);

  const iniciarPassos = useCallback(() => {
    let primeiro = 0;
    while (primeiro < PASSOS.length && !medirAlvo(PASSOS[primeiro].alvo)) primeiro += 1;
    if (primeiro >= PASSOS.length) {
      concluirTour();
      return;
    }
    setIndice(primeiro);
    setFase('passo');
  }, [concluirTour]);

  const diasTeste = licenca?.dias_teste;
  const primeiroNome = useMemo(() => (utilizador?.nome_completo ?? utilizador?.nome_utilizador ?? '').split(' ')[0] || null, [utilizador]);

  if (fase === 'oculto') return null;

  if (fase === 'boas_vindas') {
    return (
      <Modal
        open
        closable={false}
        maskClosable={false}
        footer={null}
        width={480}
        centered
      >
        <div style={{ textAlign: 'center', padding: '8px 4px' }}>
          <div
            style={{
              width: 64, height: 64, borderRadius: '50%', background: '#eff6ff', color: '#1677ff',
              display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 16px', fontSize: 30,
            }}
          >
            <RocketOutlined />
          </div>
          <Typography.Title level={4} style={{ marginBottom: 8 }}>
            {primeiroNome ? `Bem-vindo(a), ${primeiroNome}!` : 'Bem-vindo(a) ao Consulvolt ERP!'}
          </Typography.Title>
          <Typography.Paragraph type="secondary" style={{ marginBottom: diasTeste ? 4 : 20 }}>
            A sua conta está pronta. Preparámos um pequeno tour guiado para mostrar onde encontra os módulos, a
            pesquisa, as notificações e a ajuda — leva menos de um minuto.
          </Typography.Paragraph>
          {diasTeste ? (
            <Typography.Paragraph type="secondary" style={{ marginBottom: 20, fontSize: 13 }}>
              O seu período de demonstração gratuito tem <strong>{diasTeste} dia(s)</strong> de duração, sem necessidade
              de cartão de crédito.
            </Typography.Paragraph>
          ) : null}
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
            <Button onClick={concluirTour}>Agora não</Button>
            <Button type="primary" icon={<CompassOutlined />} onClick={iniciarPassos}>
              Iniciar tour guiado
            </Button>
          </div>
        </div>
      </Modal>
    );
  }

  if (!rect) return null;

  const PADDING = 8;
  const topo = rect.top - PADDING;
  const esquerda = rect.left - PADDING;
  const largura = rect.width + PADDING * 2;
  const altura = rect.height + PADDING * 2;

  const espacoAbaixo = window.innerHeight - (topo + altura);
  const mostrarAbaixo = espacoAbaixo > 180 || topo < 180;
  const LARGURA_CARTAO = 320;
  let cartaoEsquerda = esquerda + largura / 2 - LARGURA_CARTAO / 2;
  cartaoEsquerda = Math.max(16, Math.min(cartaoEsquerda, window.innerWidth - LARGURA_CARTAO - 16));
  const cartaoTopo = mostrarAbaixo ? Math.min(topo + altura + 14, window.innerHeight - 220) : undefined;
  const cartaoFundo = !mostrarAbaixo ? window.innerHeight - topo + 14 : undefined;

  const ultimoPasso = (() => {
    let p = PASSOS.length - 1;
    while (p >= 0 && !medirAlvo(PASSOS[p].alvo)) p -= 1;
    return p === indice;
  })();
  const numeroVisivel = PASSOS.slice(0, indice + 1).filter((p) => medirAlvo(p.alvo)).length;
  const totalVisivel = PASSOS.filter((p) => medirAlvo(p.alvo)).length;

  return createPortal(
    <div aria-live="polite">
      <div
        style={{
          position: 'fixed', inset: 0, zIndex: 2000, pointerEvents: 'none',
          transition: 'all .25s ease',
          top: topo, left: esquerda, width: largura, height: altura,
          borderRadius: 10,
          boxShadow: '0 0 0 9999px rgba(15, 23, 42, 0.6)',
          border: '2px solid #1677ff',
        }}
      />
      <div
        role="dialog"
        aria-label={passoAtual.titulo}
        style={{
          position: 'fixed', zIndex: 2001, width: LARGURA_CARTAO,
          left: cartaoEsquerda, top: cartaoTopo, bottom: cartaoFundo,
          background: '#fff', borderRadius: 12, boxShadow: '0 12px 32px rgba(15,23,42,.28)',
          padding: '16px 18px', pointerEvents: 'auto',
        }}
      >
        <div style={{ fontSize: 11, fontWeight: 700, color: '#1677ff', letterSpacing: '.04em', textTransform: 'uppercase', marginBottom: 4 }}>
          Passo {numeroVisivel} de {totalVisivel}
        </div>
        <Typography.Title level={5} style={{ marginTop: 0, marginBottom: 6 }}>
          {passoAtual.titulo}
        </Typography.Title>
        <Typography.Paragraph style={{ marginBottom: 14, fontSize: 13.5, color: '#334155' }}>
          {passoAtual.texto}
        </Typography.Paragraph>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <Button size="small" type="text" onClick={concluirTour}>
            Saltar tour
          </Button>
          <div style={{ display: 'flex', gap: 6 }}>
            {indice > 0 && (
              <Button size="small" icon={<LeftOutlined />} onClick={recuar}>
                Anterior
              </Button>
            )}
            <Button size="small" type="primary" onClick={avancar} icon={!ultimoPasso ? <RightOutlined /> : undefined} iconPosition="end">
              {ultimoPasso ? 'Concluir' : 'Seguinte'}
            </Button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}
