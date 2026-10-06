import { FileExcelOutlined, FilePdfOutlined, PrinterOutlined } from '@ant-design/icons';
import { Button, Space, Tooltip, type ButtonProps } from 'antd';
import { useState, type ReactNode } from 'react';
import { DICA_PDF, useImpressao, type PedidoImpressao } from './useImpressao';

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
}

export const DICA_EXCEL = 'Exporta para Excel (.xlsx) o mesmo conteúdo da impressão, com os filtros aplicados.';

function useExecutar(obterPedido: ObterPedido) {
  const { imprimir, exportarExcel } = useImpressao();
  const [ocupado, setOcupado] = useState<Modo | null>(null);
  const executar = async (modo: Modo) => {
    setOcupado(modo);
    try {
      const pedido = await obterPedido();
      if (!pedido) return;
      if (modo === 'excel') await exportarExcel(pedido);
      else await imprimir({ ...pedido, modo });
    } finally {
      setOcupado(null);
    }
  };
  return { executar, ocupado };
}

const ICONES: Record<Modo, ReactNode> = { imprimir: <PrinterOutlined />, pdf: <FilePdfOutlined />, excel: <FileExcelOutlined /> };
const TEXTOS: Record<Modo, string> = { imprimir: 'Imprimir', pdf: 'PDF', excel: 'Excel' };

/** Um botão «Imprimir» (ou «PDF», ou «Excel») com o motor comum. */
export function BotaoImprimir({ obterPedido, modo = 'imprimir', texto, desactivado, tamanho, tipo }: PropsBotao) {
  const { executar, ocupado } = useExecutar(obterPedido);
  const botao = (
    <Button size={tamanho} type={tipo} icon={ICONES[modo]} disabled={desactivado} loading={ocupado !== null} onClick={() => void executar(modo)}>
      {texto ?? TEXTOS[modo]}
    </Button>
  );
  return modo === 'imprimir' ? botao : <Tooltip title={modo === 'pdf' ? DICA_PDF : DICA_EXCEL}>{botao}</Tooltip>;
}

/**
 * Botões «Imprimir» + «PDF» + «Excel» (o mesmo documento; o PDF sugere o nome do ficheiro e mostra a dica; o Excel gera
 * um .xlsx com o cabeçalho da empresa, os filtros e as tabelas — números como números, datas como datas, totais).
 * `excel={false}` esconde o botão Excel (ex.: documentos comerciais em que só faz sentido o PDF).
 */
export function BotoesExportar({
  obterPedido,
  desactivado,
  tamanho,
  textoImprimir = 'Imprimir',
  textoPdf = 'PDF',
  excel = true,
  textoExcel = 'Excel',
}: Omit<PropsBotao, 'modo' | 'texto' | 'tipo'> & { textoImprimir?: string; textoPdf?: string; excel?: boolean; textoExcel?: string }) {
  const { executar, ocupado } = useExecutar(obterPedido);
  const outro = (m: Modo) => ocupado !== null && ocupado !== m;
  return (
    <Space.Compact>
      <Button size={tamanho} icon={<PrinterOutlined />} disabled={desactivado || outro('imprimir')} loading={ocupado === 'imprimir'} onClick={() => void executar('imprimir')}>
        {textoImprimir}
      </Button>
      <Tooltip title={DICA_PDF}>
        <Button size={tamanho} icon={<FilePdfOutlined />} disabled={desactivado || outro('pdf')} loading={ocupado === 'pdf'} onClick={() => void executar('pdf')}>
          {textoPdf}
        </Button>
      </Tooltip>
      {excel && (
        <Tooltip title={DICA_EXCEL}>
          <Button size={tamanho} icon={<FileExcelOutlined />} disabled={desactivado || outro('excel')} loading={ocupado === 'excel'} onClick={() => void executar('excel')}>
            {textoExcel}
          </Button>
        </Tooltip>
      )}
    </Space.Compact>
  );
}
