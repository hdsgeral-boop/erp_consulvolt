import { Alert, Button, Empty, Flex, Input, InputNumber, Modal, Result, Statistic, Tag, Tooltip, Typography, message, type GetRef, type InputRef } from 'antd';
import { useQueryClient } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { useAtalhos } from '../comum/atalhos';
import {
  adicionarProduto,
  alterarPreco,
  alterarQuantidade,
  exigeDesconto,
  formatarCentimos,
  linhasParaApi,
  pagamentosParaApi,
  removerLinha,
  resumirPagamentos,
  totaisCarrinho,
  totalUnidades,
  type ItemCarrinho,
  type Pagamento,
} from '../comum/calculos';
import { useCatalogoPOS, useCategoriasPOS } from '../comum/dados';
import { PREFERENCIAS_FRENTE_PADRAO, useImagensProdutos, useStockFrente, type PreferenciasFrente } from '../comum/frente';
import { htmlTalaoGenerico, htmlTalaoVenda, imprimirHtml, lerPreferencias, reimprimir, useCabecalhoTalao } from '../comum/impressao';
import { carrinhoDaConta, linhasDaConta, type ContaMesa, type MesaPOS } from './mesas';
import { PainelMesas } from './PainelMesas';
import { useEstadia } from '../hotelaria/Estadia';
import { Checkout as CheckoutHotel } from '../hotelaria/Checkout';
import type { Quarto } from '../hotelaria/tipos';
import { CheckinQuarto, EstadiaQuarto, NotaBalcao } from './PainelEstadia';
import { PainelQuartos, useQuartos } from './PainelQuartos';
import { carrinhoDosConsumos, consumosDoCarrinho } from './quartos';
import { acrescentarPagamento, meiosActivos, PainelPagamentos } from '../comum/PainelPagamentos';
import { lerCodigo, produtosVendaveis } from '../comum/produtos';
import type { ProdutoPOS, Terminal, VendaEmitida } from '../comum/tipos';
import { GrelhaProdutos } from './GrelhaProdutos';
import { NovoClienteModal } from './NovoClienteModal';

interface Props {
  terminal: Terminal;
  sessaoId: number;
  /** Parte esquerda da barra superior (modo, terminal, fundo, vendas, sessão, relatório X, fechar caixa). */
  barraSessao?: ReactNode;
  /** 1 coluna (telemóvel) ou 2 (carrinho à esquerda, produtos à direita). */
  colunas?: 1 | 2;
  preferencias?: PreferenciasFrente;
  aoMudarPreferencias?: (p: Partial<PreferenciasFrente>) => void;
}

const linhaTotais = { display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', color: '#64748b', fontWeight: 600 } as const;

/**
 * Venda rápida com a disposição do legado (renderPOSTab, js/ui_sales.js:6460-6650): barra superior da sessão com o
 * cliente à direita, carrinho À ESQUERDA (Limpar, Desconto (%), Subtotal Líquido, IVA / Impostos, «Total Geral», «Checkout»)
 * e produtos à direita (pesquisa, Com/Sem Stock, Ocultar Imagens, grelha/lista, chips de categoria, cartões).
 * Pesquisa (ou leitor de códigos: «3*COD»), pagamento misto e emissão da factura-recibo (POST /pos/sessoes/{id}/vendas).
 * Atalhos: F2 pesquisa · F4 cliente · F8 desconto · F9 checkout/emitir · Esc limpa a pesquisa.
 * Terminal RESTAURANTE (M-15): mapa de mesas; a conta de cada mesa fica no servidor («Suspender»), «Consulta» imprime o
 * talão de consulta e «Checkout» emite a FR da mesa e fecha a conta.
 * Terminal HOTELARIA (js/hotelaria.js): mapa de quartos como as mesas; «Balcão» é a venda directa; no quarto livre o carrinho
 * mostra o check-in; no quarto ocupado o carrinho são os consumos da estadia (gravados sozinhos, PUT …/consumos) e «Checkout»
 * abre o check-out da hotelaria.
 */
export function PainelVenda({ terminal, sessaoId, barraSessao, colunas = 2, preferencias: prefs, aoMudarPreferencias }: Props) {
  const { pode, empresa } = useSessao();
  const cabecalho = useCabecalhoTalao();
  const catalogo = useCatalogoPOS();
  const categorias = useCategoriasPOS();
  const [prefsLocais, setPrefsLocais] = useState<PreferenciasFrente>(PREFERENCIAS_FRENTE_PADRAO);
  const preferencias = prefs ?? prefsLocais;
  const mudarPreferencias = aoMudarPreferencias ?? ((p: Partial<PreferenciasFrente>) => setPrefsLocais((a) => ({ ...a, ...p })));
  const stock = useStockFrente(terminal.id);
  const imagens = useImagensProdutos(!preferencias.ocultarImagens);
  const [pesquisa, setPesquisa] = useState('');
  const [carrinho, setCarrinho] = useState<ItemCarrinho[]>([]);
  const [desconto, setDesconto] = useState<number>(0);
  const [cliente, setCliente] = useState<number | undefined>();
  const [novoCliente, setNovoCliente] = useState(false);
  const [cobrar, setCobrar] = useState(false);
  const [pagamentos, setPagamentos] = useState<Pagamento[]>([]);
  const [observacoes, setObservacoes] = useState('');
  const [emitida, setEmitida] = useState<VendaEmitida | null>(null);
  const refPesquisa = useRef<InputRef>(null);
  const refDesconto = useRef<GetRef<typeof InputNumber>>(null);
  const refCliente = useRef<HTMLDivElement>(null);
  const cliente_q = useQueryClient();
  const restaurante = terminal.tipo === 'RESTAURANTE';
  const [mesa, setMesa] = useState<MesaPOS | null>(null);
  const [versao, setVersao] = useState<number | null>(null);
  const [aMudarMesa, setAMudarMesa] = useState(false);
  // hotelaria: quarto activo (produto do quarto; null = Balcão), como _posActiveTable do legado
  const hotelaria = terminal.tipo === 'HOTELARIA';
  const quartos = useQuartos(terminal.id, hotelaria);
  const [quartoId, setQuartoId] = useState<number | null>(null);
  const [aMudarQuarto, setAMudarQuarto] = useState(false);
  const [checkoutHotel, setCheckoutHotel] = useState<{ id: number; nome_quarto: string }[] | null>(null);
  const quarto: Quarto | null = hotelaria && quartoId !== null ? (quartos.data?.find((q) => q.produto_id === quartoId) ?? null) : null;
  const estadiaId = quarto?.estadia?.id ?? null;
  const estadia = useEstadia(estadiaId);
  /** Estadia cujos consumos estão no carrinho e último estado gravado (para só gravar o que mudou). */
  const carregadaPara = useRef<number | null>(null);
  const gravado = useRef<{ estadia: number; linhas: string } | null>(null);
  const temporizador = useRef<number | undefined>(undefined);
  const filaGravacao = useRef<Promise<boolean>>(Promise.resolve(true));
  const balcao = useRef<{ carrinho: ItemCarrinho[]; desconto: number; cliente: number | undefined; observacoes: string } | null>(null);

  const podeDesconto = pode('pos_desconto');
  const podeCriarCliente = pode('vendas_clientes_gerir', 'aux_gerir');
  const meios = meiosActivos(terminal.meios_pagamento);
  // legado (renderPOSProducts): produtos e categorias pela ordem de criação (id), não por nome
  const produtos = useMemo(() => [...produtosVendaveis(catalogo.data ?? [])].sort((a, b) => a.id - b.id), [catalogo.data]);
  const nomes = useMemo(() => new Map(produtos.map((p) => [p.id, p.nome])), [produtos]);
  const totais = totaisCarrinho(carrinho, desconto);
  const resumo = resumirPagamentos(pagamentos, totais.total);

  const imprimir = (v: VendaEmitida, manual = false) => {
    const p = lerPreferencias(empresa?.id);
    const html = htmlTalaoVenda(v, cabecalho({ terminal: `${terminal.codigo} — ${terminal.nome}` }), p, nomes);
    if (manual) reimprimir(html, p);
    else imprimirHtml(html);
  };

  const vender = useAccao<VendaEmitida>({
    invalidar: [['pos'], ['logistica', 'stock']],
    tituloErro: 'Não foi possível registar a venda',
    aoSucesso: (v) => {
      setEmitida(v);
      setCobrar(false);
      setCarrinho([]);
      setDesconto(0);
      setCliente(undefined);
      setPagamentos([]);
      setObservacoes('');
      setMesa(null);
      setVersao(null);
      if (lerPreferencias(empresa?.id).automatico) imprimir(v);
    },
  });

  const acrescentar = (p: ProdutoPOS, q = 1) => {
    // addPOSProduct da hotelaria (js/hotelaria.js:184-192)
    if (hotelaria) {
      if (p.e_quarto) return void message.warning('O alojamento lança-se pelo check-in no mapa de quartos.');
      if (quartoId !== null && !estadiaId) return void message.warning('Faça o check-in do quarto antes de lançar consumos.');
      if (quartoId !== null && carregadaPara.current !== estadiaId) return void message.info('A carregar os consumos do quarto…');
    }
    setCarrinho((c) => adicionarProduto(c, p, q));
  };

  const lerPesquisa = () => {
    const r = lerCodigo(produtos, pesquisa);
    if (r) {
      acrescentar(r.produto, r.quantidade);
      setPesquisa('');
    } else if (hotelaria && lerCodigo((catalogo.data ?? []).filter((p) => p.e_quarto), pesquisa)) {
      void message.warning('O alojamento lança-se pelo check-in no mapa de quartos.');
    }
  };

  const abrirCobranca = () => {
    if (hotelaria && quartoId !== null) return void checkoutQuarto();
    if (!carrinho.length || vender.isPending) return;
    const numerario = meios.find((m) => m.tipo === 'NUMERARIO') ?? meios[0];
    setPagamentos(numerario ? acrescentarPagamento([], numerario, totais.total) : []);
    setCobrar(true);
  };

  const emitir = () => {
    if (!resumo.valido || vender.isPending) return;
    vender.mutate({
      // mesa (M-15): a FR leva o nome da mesa e a conta fecha-se na mesma transacção
      url: mesa ? `/pos/sessoes/${sessaoId}/mesas/${mesa.id}/cobrar` : `/pos/sessoes/${sessaoId}/vendas`,
      dados: {
        cliente_id: cliente,
        percentagem_desconto: desconto || undefined,
        observacoes: observacoes.trim() || undefined,
        linhas: linhasParaApi(carrinho),
        pagamentos: pagamentosParaApi(pagamentos),
        ...(mesa && versao ? { versao } : {}),
      },
    });
  };

  // ───────────── mesas (M-15) ─────────────
  const limparCarrinho = () => {
    setCarrinho([]);
    setDesconto(0);
    setCliente(undefined);
    setObservacoes('');
  };
  /** Grava a conta da mesa activa no servidor; devolve false se falhou. */
  const gravarMesa = async (m: MesaPOS, itens: ItemCarrinho[] = carrinho): Promise<boolean> => {
    try {
      const { dados } = await enviar<ContaMesa | null>('put', `/pos/mesas/${m.id}/conta`, {
        linhas: linhasDaConta(itens),
        percentagem_desconto: desconto || undefined,
        cliente_id: cliente,
        observacoes: observacoes.trim() || undefined,
        ...(versao ? { versao } : {}),
      });
      setVersao(dados?.versao ?? null);
      void cliente_q.invalidateQueries({ queryKey: ['pos', 'mesas'] });
      return true;
    } catch (e) {
      notificarErro(e, `Não foi possível guardar a conta da ${m.nome}`);
      return false;
    }
  };
  const abrirMesa = async (m: MesaPOS | null, juntar: ItemCarrinho[] = []) => {
    if (!m) {
      setMesa(null);
      setVersao(null);
      limparCarrinho();
      return;
    }
    const conta = await obter<ContaMesa | null>(`/pos/mesas/${m.id}/conta`);
    const r = conta ? carrinhoDaConta(conta.linhas, produtos) : { carrinho: [], emFalta: 0 };
    let itens = r.carrinho;
    for (const i of juntar) {
      const p = produtos.find((x) => x.id === i.produto_id);
      if (p) itens = adicionarProduto(itens, p, i.quantidade);
    }
    if (r.emFalta) void message.warning(`${r.emFalta} artigo(s) da conta já não estão no catálogo e foram retirados.`);
    setMesa(m);
    setVersao(conta?.versao ?? null);
    setCarrinho(itens);
    setDesconto(Number(conta?.percentagem_desconto ?? 0) || 0);
    setCliente(conta?.cliente_id ?? undefined);
    setObservacoes(conta?.observacoes ?? '');
    if (juntar.length) await gravarMesaComVersao(m, itens, conta?.versao ?? null);
  };
  const gravarMesaComVersao = async (m: MesaPOS, itens: ItemCarrinho[], v: number | null) => {
    try {
      const { dados } = await enviar<ContaMesa | null>('put', `/pos/mesas/${m.id}/conta`, { linhas: linhasDaConta(itens), ...(v ? { versao: v } : {}) });
      setVersao(dados?.versao ?? null);
      void cliente_q.invalidateQueries({ queryKey: ['pos', 'mesas'] });
    } catch (e) {
      notificarErro(e, `Não foi possível guardar a conta da ${m.nome}`);
    }
  };
  /** Mudar de mesa: guarda a conta da mesa actual (como o legado) e carrega a da nova; o carrinho do balcão pode ir para a mesa. */
  const escolherMesa = async (m: MesaPOS | null) => {
    if (aMudarMesa || (m?.id ?? null) === (mesa?.id ?? null)) return;
    setAMudarMesa(true);
    try {
      if (mesa && !(await gravarMesa(mesa))) return;
      if (!mesa && m && carrinho.length) {
        const juntar = await new Promise<boolean | null>((resolve) =>
          Modal.confirm({
            title: 'O carrinho do balcão tem artigos',
            content: `Juntar os artigos à conta da ${m.nome}?`,
            okText: 'Juntar à mesa',
            cancelText: 'Cancelar',
            onOk: () => resolve(true),
            onCancel: () => resolve(null),
          }),
        );
        if (!juntar) return;
        await abrirMesa(m, carrinho);
        return;
      }
      await abrirMesa(m);
    } catch (e) {
      notificarErro(e, 'Não foi possível abrir a mesa');
    } finally {
      setAMudarMesa(false);
    }
  };
  /** «Suspender» do legado: guarda a conta da mesa e volta ao balcão. */
  const suspender = async () => {
    if (!mesa) return;
    setAMudarMesa(true);
    const ok = await gravarMesa(mesa);
    setAMudarMesa(false);
    if (!ok) return;
    void message.success(`Conta da ${mesa.nome} guardada.`);
    setMesa(null);
    setVersao(null);
    limparCarrinho();
  };
  /** Talão de consulta da mesa (printPOSConsultation). */
  const consulta = () => {
    if (!carrinho.length) return void message.warning('O carrinho está vazio: adicione produtos antes de gerar a consulta de mesa.');
    const p = lerPreferencias(empresa?.id);
    const html = htmlTalaoGenerico(
      'Consulta de mesa',
      {
        dados: [mesa ? `Mesa: ${mesa.nome}` : 'Balcão', formatarDataHora(new Date().toISOString())],
        aviso: 'Documento de consulta — não serve de factura',
        linhas: carrinho.map((i) => ({ descricao: i.nome, quantidade: i.quantidade, preco: (i.preco / 100).toFixed(2), total: (Math.round(i.quantidade * i.preco) / 100).toFixed(2) })),
        totais: [
          ['Subtotal', (totais.bruto / 100).toFixed(2)],
          ...(totais.desconto ? ([['Desconto', (-totais.desconto / 100).toFixed(2)]] as [string, string][]) : []),
          ['TOTAL', (totais.total / 100).toFixed(2), true],
        ],
      },
      cabecalho({ terminal: `${terminal.codigo} — ${terminal.nome}` }),
      p,
    );
    reimprimir(html, p);
  };

  // ───────────── hotelaria (js/hotelaria.js) ─────────────
  const actual = useRef({ carrinho, estadiaId, nome: quarto?.nome ?? '' });
  actual.current = { carrinho, estadiaId, nome: quarto?.nome ?? '' };
  /** Grava os consumos do quarto activo se mudaram (gravarConsumos, hotelaria.js:107-126); em fila, para não se cruzarem. */
  const gravarConsumos = (): Promise<boolean> => {
    window.clearTimeout(temporizador.current);
    const { carrinho: itens, estadiaId: id, nome } = actual.current;
    filaGravacao.current = filaGravacao.current.then(async () => {
      if (!id || carregadaPara.current !== id) return true;
      const linhas = consumosDoCarrinho(itens);
      const chave = JSON.stringify(linhas);
      if (gravado.current?.estadia === id && gravado.current.linhas === chave) return true;
      try {
        await enviar('put', `/pos/hotelaria/estadias/${id}/consumos`, { linhas });
        if (carregadaPara.current === id) gravado.current = { estadia: id, linhas: chave };
        return true;
      } catch (e) {
        notificarErro(e, `Os consumos do ${nome} não foram gravados`);
        return false;
      } finally {
        void cliente_q.invalidateQueries({ queryKey: ['pos', 'hotelaria', 'quartos'] });
      }
    });
    return filaGravacao.current;
  };
  // carrinho do quarto: carrega os consumos da estadia uma vez por estadia; quarto livre (ou fechado noutro posto) fica vazio
  useEffect(() => {
    if (!hotelaria || quartoId === null) return;
    if (!estadiaId) {
      carregadaPara.current = null;
      gravado.current = null;
      setCarrinho([]);
      return;
    }
    const e = estadia.data;
    if (!e || e.id !== estadiaId || carregadaPara.current === estadiaId || !catalogo.isSuccess) return;
    const itens = carrinhoDosConsumos(e.itens, produtos);
    carregadaPara.current = estadiaId;
    gravado.current = { estadia: estadiaId, linhas: JSON.stringify(consumosDoCarrinho(itens)) };
    setCarrinho(itens);
    setDesconto(0);
    setCliente(e.cliente_hospede_id ?? undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hotelaria, quartoId, estadiaId, estadia.data, catalogo.isSuccess]);
  // hóspede trocado em «Alterar»: o cliente da venda passa a ser o novo hóspede (setPOSTable, hotelaria.js:170-174)
  useEffect(() => {
    if (estadia.data && estadia.data.id === estadiaId && estadia.data.cliente_hospede_id) setCliente(estadia.data.cliente_hospede_id);
  }, [estadia.data?.cliente_hospede_id]); // eslint-disable-line react-hooks/exhaustive-deps
  // gravação automática com pequeno atraso (agendarGravacao, hotelaria.js:102-105)
  useEffect(() => {
    if (!hotelaria || !estadiaId || carregadaPara.current !== estadiaId) return;
    const chave = JSON.stringify(consumosDoCarrinho(carrinho));
    if (gravado.current?.estadia === estadiaId && gravado.current.linhas === chave) return;
    window.clearTimeout(temporizador.current);
    temporizador.current = window.setTimeout(() => void gravarConsumos(), 400);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [carrinho, estadiaId]);
  // ao sair da venda, grava o que estiver pendente
  useEffect(
    () => () => {
      if (actual.current.estadiaId) void gravarConsumos();
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );
  /** Mudar de quarto (setPOSTable, hotelaria.js:163-176): grava os consumos do quarto actual; o carrinho do Balcão fica guardado. */
  const escolherQuarto = async (q: Quarto | null) => {
    const novo = q?.produto_id ?? null;
    if (aMudarQuarto || novo === quartoId) return;
    setAMudarQuarto(true);
    try {
      if (quartoId !== null) await gravarConsumos();
      else balcao.current = { carrinho, desconto, cliente, observacoes };
      // sem a cópia em cache: os consumos do quarto carregam-se sempre do servidor (podem ter mudado aqui ou noutro posto)
      if (q?.estadia) cliente_q.removeQueries({ queryKey: ['pos', 'hotelaria', 'estadia', q.estadia.id], exact: true });
      carregadaPara.current = null;
      gravado.current = null;
      if (novo === null) {
        const b = balcao.current;
        setCarrinho(b?.carrinho ?? []);
        setDesconto(b?.desconto ?? 0);
        setCliente(b?.cliente);
        setObservacoes(b?.observacoes ?? '');
        balcao.current = null;
      } else {
        setCarrinho([]);
        setDesconto(0);
      }
      setQuartoId(novo);
    } finally {
      setAMudarQuarto(false);
    }
  };
  /** Checkout no quarto (showPOSCheckout, hotelaria.js:194-204): abre o check-out da hotelaria. */
  const checkoutQuarto = async () => {
    if (!estadiaId || !quarto) return void message.warning('O quarto não tem check-in. Faça o check-in ou seleccione o Balcão para uma venda directa.');
    if (!pode('hotel_checkout')) return void message.warning('Não tem permissão para fazer o check-out (hotel_checkout).');
    if (!(await gravarConsumos())) return;
    setCheckoutHotel([{ id: estadiaId, nome_quarto: quarto.nome }]);
  };

  useAtalhos(
    {
      F2: () => refPesquisa.current?.focus(),
      F4: () => refCliente.current?.querySelector('input')?.focus(),
      F8: () => podeDesconto && refDesconto.current?.focus(),
      F9: abrirCobranca,
      Escape: () => setPesquisa(''),
    },
    !cobrar && !emitida && !novoCliente && !checkoutHotel,
  );
  useAtalhos({ F9: emitir }, cobrar);

  const limpar = () =>
    Modal.confirm({
      title: 'Limpar o carrinho?',
      okText: 'Limpar',
      cancelText: 'Cancelar',
      onOk: () => {
        setCarrinho([]);
        setDesconto(0);
      },
    });

  const carrinhoCartao = (
    <section aria-label="Carrinho" className="pos-carrinho" style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 20, display: 'flex', flexDirection: 'column', overflow: 'hidden', boxShadow: '0 10px 15px -3px rgba(0,0,0,0.05)', minWidth: 0 }}>
      <div style={{ padding: '1.25rem', background: '#f8fafc', borderBottom: '1px solid #e2e8f0', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
        <h3 style={{ margin: 0, fontSize: '1rem', fontWeight: 800, color: '#1e293b', display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
          <IconeFa nome="shopping-basket" style={{ color: '#2563eb' }} /> Carrinho
          <span style={{ fontSize: '0.75rem', fontWeight: 600, color: '#64748b' }}>· {totalUnidades(carrinho)} un.</span>
          {restaurante && <Tag color={mesa ? 'green' : 'default'}>{mesa ? mesa.nome : 'Balcão'}</Tag>}
          {hotelaria && <Tag color={quartoId !== null ? 'green' : 'default'}>{quarto ? quarto.nome : 'Balcão'}</Tag>}
        </h3>
        <Button size="small" danger disabled={!carrinho.length} onClick={limpar} icon={<IconeFa nome="trash-alt" />} style={{ borderRadius: 8 }}>
          Limpar
        </Button>
      </div>

      {/* painel de hospedagem (renderPOSHotelStayPanel, js/ui_sales.js:6554-6555) */}
      {hotelaria &&
        (quartoId === null ? (
          <NotaBalcao />
        ) : !quarto ? null : quarto.estadia ? (
          <EstadiaQuarto
            quarto={quarto}
            estadia={estadia.data?.id === estadiaId ? estadia.data : undefined}
            consumos={totais.bruto}
            temConsumos={carrinho.length > 0}
            terminal={terminal}
            aoCheckout={() => void checkoutQuarto()}
          />
        ) : (
          <CheckinQuarto key={quarto.produto_id} quarto={quarto} terminal={terminal} clienteActual={cliente} aoConcluir={(h) => setCliente(h)} />
        ))}

      <div style={{ flex: 1, overflowY: 'auto', padding: '1.25rem', display: 'flex', flexDirection: 'column', gap: '0.75rem', background: '#fafbfd', maxHeight: colunas === 2 ? 420 : undefined, minHeight: 160 }}>
        {carrinho.length === 0 ? (
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', flex: 1, color: '#94a3b8', padding: '2rem 0' }}>
            <IconeFa nome="cash-register" style={{ fontSize: '2.5rem', marginBottom: '1rem', color: '#cbd5e1' }} />
            <span style={{ fontWeight: 600, fontSize: '0.9rem' }}>Carrinho Vazio</span>
            <span style={{ fontSize: '0.75rem', textAlign: 'center', marginTop: 4 }}>Selecione produtos na grelha à direita para faturar.</span>
          </div>
        ) : (
          carrinho.map((i) => (
            <div key={i.produto_id} className="pos-item-carrinho" style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: '0.75rem 1rem', display: 'flex', alignItems: 'center', gap: '0.5rem', justifyContent: 'space-between' }}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <strong style={{ fontSize: '0.85rem', color: '#1e293b', display: 'block', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }} title={i.nome}>
                  {i.nome}
                </strong>
                {/* preço unitário editável em linha, como o legado (ui_sales.js:6820-6824); inválido repõe o do catálogo */}
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem', marginTop: 2 }}>
                  <InputNumber<number>
                    aria-label={`Preço ${i.nome}`}
                    className="pos-preco-linha"
                    size="small"
                    min={0}
                    controls={false}
                    disabled={!podeDesconto}
                    title={podeDesconto ? 'Alterar o preço' : 'Sem permissão para alterar preços'}
                    style={{ width: 90, fontSize: '0.75rem', fontWeight: 700, background: '#f8fafc', borderRadius: 6 }}
                    value={i.preco / 100}
                    onChange={(v) => setCarrinho((c) => alterarPreco(c, i.produto_id, v === null || v < 0 ? i.preco_catalogo : Math.round(v * 100)))}
                  />
                  <span style={{ fontSize: '0.7rem', color: '#94a3b8', fontWeight: 600 }}>Kz</span>
                </div>
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.25rem', background: '#f1f5f9', borderRadius: 8, padding: 2 }}>
                <Button size="small" type="text" aria-label="Menos" onClick={() => setCarrinho((c) => alterarQuantidade(c, i.produto_id, i.quantidade - 1))}>
                  −
                </Button>
                <InputNumber<number>
                  aria-label={`Quantidade ${i.nome}`}
                  size="small"
                  min={0}
                  controls={false}
                  variant="borderless"
                  className="pos-qtd-linha"
                  style={{ width: 40, fontWeight: 700, fontSize: '0.8rem' }}
                  value={i.quantidade}
                  onChange={(v) => v !== null && setCarrinho((c) => alterarQuantidade(c, i.produto_id, v))}
                />
                <Button size="small" type="text" aria-label="Mais" onClick={() => setCarrinho((c) => alterarQuantidade(c, i.produto_id, i.quantidade + 1))}>
                  +
                </Button>
              </div>
              <strong style={{ fontSize: '0.85rem', color: '#1e293b', minWidth: 85, textAlign: 'right', whiteSpace: 'nowrap' }}>{formatarCentimos(Math.round(i.quantidade * i.preco))} Kz</strong>
              <Button type="text" size="small" aria-label="Retirar" onClick={() => setCarrinho((c) => removerLinha(c, i.produto_id))} icon={<IconeFa nome="trash-alt" style={{ color: '#94a3b8' }} />} />
            </div>
          ))
        )}
      </div>

      <div style={{ padding: '1.25rem', background: '#f8fafc', borderTop: '1px solid #e2e8f0', display: 'flex', flexDirection: 'column', gap: '0.85rem' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <span style={{ fontSize: '0.8rem', fontWeight: 700, color: '#475569' }}>
            <IconeFa nome="tag" style={{ color: '#f59e0b' }} /> Desconto (%):
          </span>
          <Tooltip title={quarto ? 'No quarto, o desconto aplica-se no check-out.' : podeDesconto ? 'F8' : 'Sem permissão para aplicar descontos'}>
            <InputNumber<number>
              ref={refDesconto}
              aria-label="Desconto global"
              disabled={!podeDesconto || !!quarto}
              min={0}
              max={100}
              precision={2}
              decimalSeparator=","
              style={{ width: 90, fontWeight: 700 }}
              value={desconto || null}
              placeholder="0"
              onChange={(v) => setDesconto(v ?? 0)}
            />
          </Tooltip>
        </div>
        {exigeDesconto(carrinho, desconto) && !podeDesconto && <Alert type="warning" showIcon message="A venda tem preços alterados: precisa da permissão de descontos." />}
        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.4rem', padding: '0.6rem 0', borderTop: '1px solid #e2e8f0', borderBottom: '1px solid #e2e8f0' }}>
          <div style={linhaTotais}>
            <span>Subtotal Líquido:</span>
            <span>{formatarCentimos(totais.liquido)} Kz</span>
          </div>
          <div style={linhaTotais}>
            <span>IVA / Impostos:</span>
            <span>{formatarCentimos(totais.imposto)} Kz</span>
          </div>
          {/* só com desconto lançado: sem desconto, a diferença de 1 cêntimo é o arredondamento AGT do IVA, não um desconto */}
          {desconto > 0 && totais.desconto !== 0 && (
            <div style={{ ...linhaTotais, color: '#ef4444', fontWeight: 700 }}>
              <span>Desconto Aplicado:</span>
              <span>-{formatarCentimos(totais.desconto)} Kz</span>
            </div>
          )}
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '1.2rem', fontWeight: 800, color: '#1e293b', alignItems: 'center' }}>
          <span>Total Geral:</span>
          <span className="pos-total-geral" data-total={totais.total} style={{ color: '#2563eb', fontSize: '1.4rem' }}>
            {formatarCentimos(totais.total)} Kz
          </span>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: restaurante ? '1fr 1.2fr 1.2fr' : '1fr', gap: '0.5rem' }}>
          {restaurante && (
            <>
              <Button size="large" disabled={!mesa} loading={aMudarMesa} onClick={() => void suspender()} title="Guardar a conta da mesa e voltar ao balcão" icon={<IconeFa nome="pause-circle" />} style={{ borderRadius: 12, fontWeight: 600 }}>
                Suspender
              </Button>
              <Button size="large" disabled={!carrinho.length} onClick={consulta} title="Imprimir o talão de consulta da mesa" icon={<IconeFa nome="print" />} style={{ borderRadius: 12, fontWeight: 600, color: '#d97706', borderColor: '#f59e0b' }}>
                Consulta
              </Button>
            </>
          )}
          <Button type="primary" size="large" disabled={hotelaria && quartoId !== null ? aMudarQuarto : !carrinho.length} onClick={abrirCobranca} title="Checkout (F9)" icon={<IconeFa nome="check-double" />} style={{ borderRadius: 12, fontWeight: 800 }}>
            Checkout
          </Button>
        </div>
      </div>
    </section>
  );

  return (
    <div className="pos-wrapper" style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <div
        className="pos-topbar"
        style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: '#f8fafc', border: '1px solid #e2e8f0', padding: '0.8rem 1.25rem', borderRadius: 15, flexWrap: 'wrap', gap: '1rem', boxShadow: '0 4px 6px -1px rgba(0,0,0,0.05)' }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>{barraSessao}</div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', minWidth: 0, flex: '1 1 300px', maxWidth: 450 }}>
          <span style={{ fontSize: '0.85rem', fontWeight: 700, color: '#475569', whiteSpace: 'nowrap' }}>
            <IconeFa nome="user-tag" /> Cliente:
          </span>
          <div ref={refCliente} style={{ flex: 1, minWidth: 0 }}>
            <SeletorTerceiro
              papel="CLIENTE"
              value={cliente}
              onChange={(v) => setCliente(v)}
              placeholder={terminal.cliente_padrao_id ? 'Cliente padrão do terminal (F4)' : 'Consumidor Final (NIF: 999999999) — F4'}
              style={{ width: '100%' }}
              aria-label="Cliente"
            />
          </div>
          {podeCriarCliente && (
            <Button aria-label="Criar Novo Cliente" title="Criar Novo Cliente" onClick={() => setNovoCliente(true)} icon={<IconeFa nome="user-plus" />} style={{ borderColor: '#2563eb', color: '#2563eb', borderRadius: 8 }} />
          )}
        </div>
      </div>

      {restaurante && <PainelMesas terminal={terminal.id} activa={mesa?.id ?? null} aoEscolher={(m) => void escolherMesa(m)} ocupado={aMudarMesa || vender.isPending} />}
      {hotelaria && (
        <PainelQuartos quartos={quartos.data} carregando={quartos.isLoading} erro={quartos.isError} activo={quartoId} aoEscolher={(q) => void escolherQuarto(q)} ocupado={aMudarQuarto || vender.isPending} />
      )}

      <div className="pos-main-layout" style={{ display: 'grid', gridTemplateColumns: colunas === 2 ? 'minmax(300px, 420px) minmax(0, 1fr)' : 'minmax(0, 1fr)', gap: '1.25rem', alignItems: 'start' }}>
        {carrinhoCartao}
        <GrelhaProdutos
          produtos={produtos}
          categorias={categorias.data ? [...categorias.data].sort((a, b) => a.id - b.id) : undefined}
          stock={stock.data?.stock}
          imagens={imagens.data}
          pesquisa={pesquisa}
          aoPesquisar={setPesquisa}
          aoLer={lerPesquisa}
          aoEscolher={(p) => acrescentar(p)}
          preferencias={preferencias}
          aoMudarPreferencias={mudarPreferencias}
          refPesquisa={refPesquisa}
          carregando={catalogo.isLoading}
          erro={catalogo.isError}
        />
      </div>
      {catalogo.isSuccess && produtos.length === 0 && <Empty description="Não há produtos activos no catálogo." />}

      {hotelaria && <CheckoutHotel aberto={!!checkoutHotel} estadias={checkoutHotel ?? []} terminal={terminal} aoFechar={() => setCheckoutHotel(null)} />}

      <NovoClienteModal aberto={novoCliente} aoFechar={() => setNovoCliente(false)} aoCriar={(id) => setCliente(id)} />

      <Modal
        open={cobrar}
        onCancel={() => setCobrar(false)}
        title={`Pagamento · ${formatarCentimos(totais.total)} Kz`}
        width={larguraModal(760)}
        maskClosable={false}
        destroyOnHidden
        footer={[
          <Button key="c" size="large" onClick={() => setCobrar(false)}>
            Voltar
          </Button>,
          <Button key="e" size="large" type="primary" loading={vender.isPending} disabled={!resumo.valido} onClick={emitir}>
            Emitir factura-recibo (F9)
          </Button>,
        ]}
      >
        <PainelPagamentos grande meios={meios} total={totais.total} pagamentos={pagamentos} onChange={setPagamentos} desactivado={vender.isPending} />
        <Input.TextArea style={{ marginTop: 12 }} rows={2} maxLength={2000} placeholder="Observações (opcional)" value={observacoes} onChange={(e) => setObservacoes(e.target.value)} />
        <Typography.Paragraph type="secondary" style={{ marginTop: 8, marginBottom: 0 }}>
          Os valores são uma estimativa: o servidor recalcula o total, numera e sela a factura-recibo.
        </Typography.Paragraph>
      </Modal>

      <Modal open={!!emitida} onCancel={() => setEmitida(null)} footer={null} width={larguraModal(520)} destroyOnHidden>
        {emitida && (
          <Result
            status="success"
            title={`Factura-recibo ${emitida.numero_documento}`}
            subTitle={`Total ${formatarKz(emitida.total_bruto)} Kz${emitida.nome_tabela ? ` · ${emitida.nome_tabela}` : ''}${Number(emitida.arredondamento_agt ?? 0) !== 0 ? ` · arredondamento AGT ${formatarKz(emitida.arredondamento_agt)} Kz` : ''}`}
            extra={[
              <Statistic key="t" title="Troco" value={formatarKz(emitida.pos_troco ?? '0')} suffix="Kz" valueStyle={{ fontSize: 40, color: '#3f8600' }} style={{ marginBottom: 16 }} />,
              <Flex key="b" gap={8} justify="center" wrap>
                <Button size="large" icon={<IconeFa nome="print" />} onClick={() => imprimir(emitida, true)}>
                  Imprimir talão
                </Button>
                <Button
                  size="large"
                  type="primary"
                  autoFocus
                  onClick={() => {
                    setEmitida(null);
                    window.setTimeout(() => refPesquisa.current?.focus(), 50);
                  }}
                >
                  Nova venda
                </Button>
              </Flex>,
            ]}
          />
        )}
      </Modal>
    </div>
  );
}
