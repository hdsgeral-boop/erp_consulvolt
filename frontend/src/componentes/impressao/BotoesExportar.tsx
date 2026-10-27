import { CheckOutlined, DownOutlined, FileExcelOutlined, FilePdfOutlined, LayoutOutlined, PrinterOutlined } from '@ant-design/icons';
import { Button, Dropdown, Modal, Space, Tooltip, type ButtonProps, type MenuProps } from 'antd';
import { useMemo, useState, type ReactNode } from 'react';
import {
  aplicarPreferencia, chavePorOmissao, descreverPreferencia, gravarPreferencia, lerPreferencia, ROTULOS_ORIENTACAO, ROTULOS_PAPEL,
} from './preferencias';
import type { Orientacao, Papel, PreferenciaPagina } from './tipos';
import { DICA_PDF, useImpressao, type PedidoImpressao } from './useImpressao';
import { IconeFa } from '@/componentes/fluxos/IconeFa';

/** Opções do documento, calculadas no momento do clique (pode ser assíncrono: ex. ir buscar todas as páginas). */
export type ObterPedido = () => PedidoImpressao | null | undefined | Promise<PedidoImpressao | null | undefined>;

type Modo = 'imprimir' | 'pdf' | 'excel';

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

function useExecutar(obterPedido: ObterPedido, pref: PreferenciaPagina) {
  const { imprimir, exportarExcel } = useImpressao();
  const [ocupado, setOcupado] = useState<Modo | null>(null);
  const executar = async (modo: Modo) => {
    setOcupado(modo);
    try {
      const pedido = await obterPedido();
      if (!pedido) return;
      if (modo === 'excel') await exportarExcel(pedido);
      else await imprimir({ ...aplicarPreferencia(pedido, pref), modo });
    } finally {
      setOcupado(null);
    }
  };
  return { executar, ocupado };
}

const ICONES: Record<Modo, ReactNode> = { imprimir: <PrinterOutlined />, pdf: <FilePdfOutlined />, excel: <FileExcelOutlined /> };
const TEXTOS: Record<Modo, string> = { imprimir: 'Imprimir', pdf: 'PDF', excel: 'Excel' };

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
  return modo === 'imprimir' ? botao : <Tooltip title={modo === 'pdf' ? DICA_PDF : DICA_EXCEL}>{botao}</Tooltip>;
}

interface PropsConteudoOpcoes {
  textoImprimir: string;
  textoPdf: string;
  excel: boolean;
  textoExcel: string;
  seletorPagina: boolean;
  tamanho?: ButtonProps['size'];
  pref: PreferenciaPagina;
  mudar: (p: PreferenciaPagina) => void;
  ocupado: Modo | null;
  executar: (m: Modo) => Promise<void>;
  /** Fecha a modal depois de a opção escolhida terminar (sucesso ou falha). */
  aoConcluir: () => void;
}

/** Opções dentro da modal «Imprimir»: um botão grande por opção, cheio à largura. */
function ConteudoOpcoesExportar({ textoImprimir, textoPdf, excel, textoExcel, seletorPagina, tamanho, pref, mudar, ocupado, executar, aoConcluir }: PropsConteudoOpcoes) {
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
  seletorPagina = true,
  chave,
}: Omit<PropsBotao, 'modo' | 'texto' | 'tipo'> & { textoImprimir?: string; textoPdf?: string; excel?: boolean; textoExcel?: string; seletorPagina?: boolean }) {
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
          textoImprimir={textoImprimir} textoPdf={textoPdf} excel={excel} textoExcel={textoExcel} seletorPagina={seletorPagina}
          tamanho={tamanho} pref={pref} mudar={mudar} ocupado={ocupado} executar={executar} aoConcluir={() => setAberto(false)}
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
  seletorPagina = true,
  chave,
  textoImprimir = 'Imprimir',
}: Omit<PropsBotao, 'modo' | 'texto' | 'tipo'> & { excel?: boolean; seletorPagina?: boolean; textoImprimir?: string }) {
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
          textoImprimir={textoImprimir} textoPdf="PDF" excel={excel} textoExcel="Excel" seletorPagina={seletorPagina}
          tamanho={tamanho} pref={pref} mudar={mudar} ocupado={ocupado} executar={executar} aoConcluir={() => setAberto(false)}
        />
      </Modal>
    </>
  );
}
