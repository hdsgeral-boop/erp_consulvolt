import { CheckOutlined, CopyOutlined, DownOutlined, FileExcelOutlined, FilePdfOutlined, LayoutOutlined, PrinterOutlined } from '@ant-design/icons';
import { Button, Checkbox, Dropdown, Modal, Popover, Space, Spin, Tooltip, type ButtonProps, type MenuProps } from 'antd';
import { useMemo, useState, type ReactNode } from 'react';
import { colunasDoConteudo, copiarTexto, tabelaParaTexto } from './copiar';
import {
  aplicarPreferencia, chavePorOmissao, descreverPreferencia, gravarPreferencia, lerPreferencia, ROTULOS_ORIENTACAO, ROTULOS_PAPEL,
} from './preferencias';
import type { Orientacao, Papel, PreferenciaPagina } from './tipos';
import { DICA_PDF, useImpressao, useMensagem, type PedidoImpressao } from './useImpressao';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { notificarErro } from '@/utilitarios/erros';

/** Opções do documento, calculadas no momento do clique (pode ser assíncrono: ex. ir buscar todas as páginas). */
export type ObterPedido = () => PedidoImpressao | null | undefined | Promise<PedidoImpressao | null | undefined>;

type Modo = 'imprimir' | 'pdf' | 'excel' | 'copiar';

interface PropsBotao {
  obterPedido: ObterPedido;
  modo?: Modo;
  texto?: string;
  desactivado?: boolean;
  tamanho?: ButtonProps['size'];
  tipo?: ButtonProps['type'];
  /**
   * Chave da preferência de página (orientação/papel) lembrada no navegador. Por omissão: o ecrã actual e o texto do
   * botão (os identificadores numéricos do caminho são ignorados — todos os recibos partilham a escolha).
   */
  chave?: string;
}

export const DICA_EXCEL = 'Exporta para Excel (.xlsx) o mesmo conteúdo da impressão, com os filtros aplicados.';
export const DICA_PAGINA = 'Orientação e papel da impressão/PDF. «Automática»: o sistema escolhe pela largura do conteúdo (mapas com muitas colunas passam a horizontal).';
export const DICA_COPIAR = 'Copia a tabela para a área de transferência, pronta a colar no Excel ou noutra aplicação.';

const caminhoActual = () => (typeof window !== 'undefined' ? window.location.pathname : '');

/** Preferência de página do documento (lida do navegador; alterá-la grava-a para a próxima vez). */
function usePreferenciaPagina(chave: string) {
  const [pref, setPref] = useState<PreferenciaPagina>(() => lerPreferencia(chave));
  const mudar = (p: PreferenciaPagina) => {
    setPref(p);
    gravarPreferencia(chave, p);
  };
  return { pref, mudar };
}

/** Copia a tabela inteira do pedido (todas as colunas); avisa se não encontrar nenhuma tabela para copiar. */
async function copiarTabela(pedido: PedidoImpressao, mensagem: ReturnType<typeof useMensagem>): Promise<void> {
  const texto = tabelaParaTexto(pedido.conteudo);
  if (!texto) {
    mensagem.warning('Não foi encontrada nenhuma tabela para copiar.');
    return;
  }
  const ok = await copiarTexto(texto);
  mensagem[ok ? 'success' : 'error'](ok ? 'Tabela copiada. Já pode colar no Excel.' : 'Não foi possível copiar: o navegador bloqueou o acesso à área de transferência.');
}

function useExecutar(obterPedido: ObterPedido, pref: PreferenciaPagina) {
  const { imprimir, exportarExcel } = useImpressao();
  const mensagem = useMensagem();
  const [ocupado, setOcupado] = useState<Modo | null>(null);
  const executar = async (modo: Modo) => {
    setOcupado(modo);
    try {
      const pedido = await obterPedido();
      if (!pedido) return;
      if (modo === 'excel') await exportarExcel(pedido);
      else if (modo === 'copiar') await copiarTabela(pedido, mensagem);
      else await imprimir({ ...aplicarPreferencia(pedido, pref), modo });
    } finally {
      setOcupado(null);
    }
  };
  return { executar, ocupado };
}

const ICONES: Record<Modo, ReactNode> = { imprimir: <PrinterOutlined />, pdf: <FilePdfOutlined />, excel: <FileExcelOutlined />, copiar: <CopyOutlined /> };
const TEXTOS: Record<Modo, string> = { imprimir: 'Imprimir', pdf: 'PDF', excel: 'Excel', copiar: 'Copiar' };

/**
 * Selector da página: «Automática» (decisão do motor), «Vertical», «Horizontal» e o papel (A4/A3). Mostra a escolha
 * actual no próprio botão.
 */
export function SeletorPagina({ valor, aoMudar, tamanho, desactivado, compacto }: { valor: PreferenciaPagina; aoMudar: (p: PreferenciaPagina) => void; tamanho?: ButtonProps['size']; desactivado?: boolean; /** só o ícone (telemóvel) */ compacto?: boolean }) {
  const marca = (activo: boolean) => (activo ? <CheckOutlined /> : <span style={{ display: 'inline-block', width: 14 }} />);
  const itens: MenuProps['items'] = [
    {
      type: 'group',
      label: 'Orientação',
      children: (['auto', 'retrato', 'paisagem'] as Orientacao[]).map((o) => ({ key: `o:${o}`, icon: marca(valor.orientacao === o), label: ROTULOS_ORIENTACAO[o] })),
    },
    { type: 'divider' },
    {
      type: 'group',
      label: 'Papel',
      children: (['auto', 'A4', 'A3'] as Papel[]).map((p) => ({ key: `p:${p}`, icon: marca(valor.papel === p), label: ROTULOS_PAPEL[p] })),
    },
  ];
  const aoClicar: MenuProps['onClick'] = ({ key }) => {
    const [tipo, v] = String(key).split(':');
    aoMudar(tipo === 'o' ? { ...valor, orientacao: v as Orientacao } : { ...valor, papel: v as Papel });
  };
  return (
    <Dropdown trigger={['click']} disabled={desactivado} menu={{ items: itens, onClick: aoClicar, selectable: false }}>
      <Tooltip title={DICA_PAGINA}>
        <Button size={tamanho} icon={<LayoutOutlined />} disabled={desactivado} aria-label={`Orientação da página: ${descreverPreferencia(valor)}`}>
          {compacto ? null : <>{descreverPreferencia(valor)} <DownOutlined style={{ fontSize: 10 }} /></>}
        </Button>
      </Tooltip>
    </Dropdown>
  );
}

/** Um botão «Imprimir» (ou «PDF», ou «Excel») com o motor comum (usa a orientação/papel lembrados para o documento). */
export function BotaoImprimir({ obterPedido, modo = 'imprimir', texto, desactivado, tamanho, tipo, chave }: PropsBotao) {
  const chaveFinal = useMemo(() => chave ?? chavePorOmissao(caminhoActual(), texto ?? TEXTOS[modo]), [chave, texto, modo]);
  const { executar, ocupado } = useExecutar(obterPedido, lerPreferencia(chaveFinal));
  const botao = (
    <Button size={tamanho} type={tipo} icon={ICONES[modo]} disabled={desactivado} loading={ocupado !== null} onClick={() => void executar(modo)}>
      {texto ?? TEXTOS[modo]}
    </Button>
  );
  return modo === 'imprimir' ? botao : <Tooltip title={modo === 'pdf' ? DICA_PDF : modo === 'copiar' ? DICA_COPIAR : DICA_EXCEL}>{botao}</Tooltip>;
}

/**
 * Link «Copiar só algumas colunas…»: ao abrir, vai buscar o pedido (mesma função do resto da modal) só para ler os
 * títulos das colunas (`colunasDoConteudo`); a cópia em si reaproveita esse pedido já obtido, sem o pedir outra vez.
 */
function SeletorColunasCopiar({ obterPedido, tamanho, desactivado }: { obterPedido: ObterPedido; tamanho?: ButtonProps['size']; desactivado?: boolean }) {
  const mensagem = useMensagem();
  const [aberto, setAberto] = useState(false);
  const [aCarregar, setACarregar] = useState(false);
  const [pedido, setPedido] = useState<PedidoImpressao | null>(null);
  const [colunas, setColunas] = useState<string[]>([]);
  const [escolhidas, setEscolhidas] = useState<number[]>([]);

  const aoAbrir = async (abrir: boolean) => {
    setAberto(abrir);
    if (!abrir || pedido) return;
    setACarregar(true);
    try {
      const p = await obterPedido();
      if (!p) {
        setAberto(false);
        return;
      }
      const cols = colunasDoConteudo(p.conteudo);
      if (!cols.length) {
        mensagem.warning('Não foi encontrada nenhuma tabela para copiar.');
        setAberto(false);
        return;
      }
      setPedido(p);
      setColunas(cols);
    } catch (e) {
      notificarErro(e, 'Não foi possível obter as colunas');
      setAberto(false);
    } finally {
      setACarregar(false);
    }
  };

  const copiar = async () => {
    if (!pedido || !escolhidas.length) return;
    const texto = tabelaParaTexto(pedido.conteudo, [...escolhidas].sort((a, b) => a - b));
    const ok = await copiarTexto(texto);
    mensagem[ok ? 'success' : 'error'](ok ? `${escolhidas.length} coluna(s) copiada(s). Já pode colar no Excel.` : 'Não foi possível copiar: o navegador bloqueou o acesso à área de transferência.');
    if (ok) setAberto(false);
  };

  return (
    <Popover
      trigger="click"
      open={aberto}
      onOpenChange={aoAbrir}
      placement="right"
      content={
        <div style={{ width: 230 }}>
          {aCarregar ? (
            <Spin size="small" />
          ) : (
            <>
              <Checkbox.Group
                style={{ display: 'flex', flexDirection: 'column', gap: 4, maxHeight: 220, overflowY: 'auto' }}
                value={escolhidas}
                onChange={(v) => setEscolhidas(v as number[])}
                options={colunas.map((c, i) => ({ label: c || `Coluna ${i + 1}`, value: i }))}
              />
              <Button type="primary" size="small" block style={{ marginTop: 8 }} disabled={!escolhidas.length} onClick={() => void copiar()}>
                Copiar{escolhidas.length ? ` (${escolhidas.length})` : ''}
              </Button>
            </>
          )}
        </div>
      }
    >
      <Button type="link" size={tamanho} disabled={desactivado} style={{ paddingLeft: 0, height: 'auto' }}>
        Copiar só algumas colunas…
      </Button>
    </Popover>
  );
}

interface PropsConteudoOpcoes {
  textoImprimir: string;
  textoPdf: string;
  excel: boolean;
  textoExcel: string;
  copiar: boolean;
  seletorPagina: boolean;
  tamanho?: ButtonProps['size'];
  pref: PreferenciaPagina;
  mudar: (p: PreferenciaPagina) => void;
  ocupado: Modo | null;
  executar: (m: Modo) => Promise<void>;
  obterPedido: ObterPedido;
  /** Fecha a modal depois de a opção escolhida terminar (sucesso ou falha). */
  aoConcluir: () => void;
}

/** Opções dentro da modal «Imprimir»: um botão grande por opção, cheio à largura. */
function ConteudoOpcoesExportar({ textoImprimir, textoPdf, excel, textoExcel, copiar, seletorPagina, tamanho, pref, mudar, ocupado, executar, obterPedido, aoConcluir }: PropsConteudoOpcoes) {
  const outro = (m: Modo) => ocupado !== null && ocupado !== m;
  const executarEFechar = (m: Modo) => void executar(m).finally(aoConcluir);
  return (
    <Space direction="vertical" style={{ width: '100%' }}>
      <Button block size={tamanho} icon={<PrinterOutlined />} disabled={outro('imprimir')} loading={ocupado === 'imprimir'} onClick={() => executarEFechar('imprimir')}>
        {textoImprimir}
      </Button>
      <Tooltip title={DICA_PDF} placement="right">
        <Button block size={tamanho} icon={<FilePdfOutlined />} disabled={outro('pdf')} loading={ocupado === 'pdf'} onClick={() => executarEFechar('pdf')}>
          {textoPdf}
        </Button>
      </Tooltip>
      {seletorPagina && <SeletorPagina valor={pref} aoMudar={mudar} tamanho={tamanho} desactivado={ocupado !== null} />}
      {excel && (
        <Tooltip title={DICA_EXCEL} placement="right">
          <Button block size={tamanho} icon={<FileExcelOutlined />} disabled={outro('excel')} loading={ocupado === 'excel'} onClick={() => executarEFechar('excel')}>
            {textoExcel}
          </Button>
        </Tooltip>
      )}
      {copiar && (
        <>
          <Tooltip title={DICA_COPIAR} placement="right">
            <Button block size={tamanho} icon={<CopyOutlined />} disabled={outro('copiar')} loading={ocupado === 'copiar'} onClick={() => executarEFechar('copiar')}>
              Copiar
            </Button>
          </Tooltip>
          <SeletorColunasCopiar obterPedido={obterPedido} tamanho={tamanho} desactivado={ocupado !== null} />
        </>
      )}
    </Space>
  );
}

/**
 * Um único botão «Imprimir», que abre uma modal com as opções Imprimir / PDF / Automática (orientação e papel) /
 * Excel (o mesmo documento; o PDF sugere o nome do ficheiro e mostra a dica; o Excel gera um .xlsx com o cabeçalho da
 * empresa, os filtros e as tabelas — números como números, datas como datas, totais), lembradas por documento.
 * `excel={false}` esconde a opção Excel (ex.: documentos comerciais em que só faz sentido o PDF);
 * `seletorPagina={false}` esconde o selector (a orientação fica a do ecrã/motor).
 */
export function BotoesExportar({
  obterPedido,
  desactivado,
  tamanho,
  textoImprimir = 'Imprimir',
  textoPdf = 'PDF',
  excel = true,
  textoExcel = 'Excel',
  copiar = true,
  seletorPagina = true,
  chave,
}: Omit<PropsBotao, 'modo' | 'texto' | 'tipo'> & { textoImprimir?: string; textoPdf?: string; excel?: boolean; textoExcel?: string; copiar?: boolean; seletorPagina?: boolean }) {
  const chaveFinal = useMemo(() => chave ?? chavePorOmissao(caminhoActual(), textoImprimir), [chave, textoImprimir]);
  const { pref, mudar } = usePreferenciaPagina(chaveFinal);
  const { executar, ocupado } = useExecutar(obterPedido, pref);
  const [aberto, setAberto] = useState(false);
  return (
    <>
      <Button size={tamanho} icon={<PrinterOutlined />} disabled={desactivado} onClick={() => setAberto(true)}>
        {textoImprimir}
      </Button>
      <Modal title={textoImprimir} open={aberto} onCancel={() => setAberto(false)} footer={null} width={300} destroyOnHidden>
        <ConteudoOpcoesExportar
          textoImprimir={textoImprimir} textoPdf={textoPdf} excel={excel} textoExcel={textoExcel} copiar={copiar} seletorPagina={seletorPagina}
          tamanho={tamanho} pref={pref} mudar={mudar} ocupado={ocupado} executar={executar} obterPedido={obterPedido} aoConcluir={() => setAberto(false)}
        />
      </Modal>
    </>
  );
}

/**
 * Um único botão «Imprimir» (ícone do sistema anterior), que abre a mesma modal de opções de `BotoesExportar` — em
 * vez dos botões separados do antigo (DataTables Buttons: Excel · PDF · Imprimir). Mesmo motor e mesma preferência
 * de página.
 */
export function BotoesExportarLegado({
  obterPedido,
  desactivado,
  tamanho = 'small',
  excel = true,
  copiar = true,
  seletorPagina = true,
  chave,
  textoImprimir = 'Imprimir',
}: Omit<PropsBotao, 'modo' | 'texto' | 'tipo'> & { excel?: boolean; copiar?: boolean; seletorPagina?: boolean; textoImprimir?: string }) {
  const chaveFinal = useMemo(() => chave ?? chavePorOmissao(caminhoActual(), textoImprimir), [chave, textoImprimir]);
  const { pref, mudar } = usePreferenciaPagina(chaveFinal);
  const { executar, ocupado } = useExecutar(obterPedido, pref);
  const [aberto, setAberto] = useState(false);
  return (
    <>
      <Button size={tamanho} icon={<IconeFa nome="print" />} disabled={desactivado} onClick={() => setAberto(true)}>
        {textoImprimir}
      </Button>
      <Modal title={textoImprimir} open={aberto} onCancel={() => setAberto(false)} footer={null} width={300} destroyOnHidden>
        <ConteudoOpcoesExportar
          textoImprimir={textoImprimir} textoPdf="PDF" excel={excel} textoExcel="Excel" copiar={copiar} seletorPagina={seletorPagina}
          tamanho={tamanho} pref={pref} mudar={mudar} ocupado={ocupado} executar={executar} obterPedido={obterPedido} aoConcluir={() => setAberto(false)}
        />
      </Modal>
    </>
  );
}
