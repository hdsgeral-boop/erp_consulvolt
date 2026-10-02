import { FilePdfOutlined, PrinterOutlined } from '@ant-design/icons';
import { Button, Space, Tooltip, type ButtonProps } from 'antd';
import { useState } from 'react';
import { DICA_PDF, useImpressao, type PedidoImpressao } from './useImpressao';

/** Opções do documento, calculadas no momento do clique (pode ser assíncrono: ex. ir buscar todas as páginas). */
export type ObterPedido = () => PedidoImpressao | null | undefined | Promise<PedidoImpressao | null | undefined>;

interface PropsBotao {
  obterPedido: ObterPedido;
  modo?: 'imprimir' | 'pdf';
  texto?: string;
  desactivado?: boolean;
  tamanho?: ButtonProps['size'];
  tipo?: ButtonProps['type'];
}

function useExecutar(obterPedido: ObterPedido) {
  const { imprimir } = useImpressao();
  const [ocupado, setOcupado] = useState<'imprimir' | 'pdf' | null>(null);
  const executar = async (modo: 'imprimir' | 'pdf') => {
    setOcupado(modo);
    try {
      const pedido = await obterPedido();
      if (pedido) await imprimir({ ...pedido, modo });
    } finally {
      setOcupado(null);
    }
  };
  return { executar, ocupado };
}

/** Um botão «Imprimir» (ou «PDF») com o motor comum. */
export function BotaoImprimir({ obterPedido, modo = 'imprimir', texto, desactivado, tamanho, tipo }: PropsBotao) {
  const { executar, ocupado } = useExecutar(obterPedido);
  const pdf = modo === 'pdf';
  const botao = (
    <Button size={tamanho} type={tipo} icon={pdf ? <FilePdfOutlined /> : <PrinterOutlined />} disabled={desactivado} loading={ocupado !== null} onClick={() => void executar(modo)}>
      {texto ?? (pdf ? 'PDF' : 'Imprimir')}
    </Button>
  );
  return pdf ? <Tooltip title={DICA_PDF}>{botao}</Tooltip> : botao;
}

/** Par de botões «Imprimir» + «PDF» (o mesmo documento; o PDF sugere o nome do ficheiro e mostra a dica). */
export function BotoesExportar({ obterPedido, desactivado, tamanho, textoImprimir = 'Imprimir', textoPdf = 'PDF' }: Omit<PropsBotao, 'modo' | 'texto' | 'tipo'> & { textoImprimir?: string; textoPdf?: string }) {
  const { executar, ocupado } = useExecutar(obterPedido);
  return (
    <Space.Compact>
      <Button size={tamanho} icon={<PrinterOutlined />} disabled={desactivado || ocupado === 'pdf'} loading={ocupado === 'imprimir'} onClick={() => void executar('imprimir')}>
        {textoImprimir}
      </Button>
      <Tooltip title={DICA_PDF}>
        <Button size={tamanho} icon={<FilePdfOutlined />} disabled={desactivado || ocupado === 'imprimir'} loading={ocupado === 'pdf'} onClick={() => void executar('pdf')}>
          {textoPdf}
        </Button>
      </Tooltip>
    </Space.Compact>
  );
}
