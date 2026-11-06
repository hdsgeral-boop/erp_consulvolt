import { Button, Modal, Result, Select, Space, Typography } from 'antd';
import { useEffect, useRef, useState } from 'react';
import { useImpressao } from '@/componentes/impressao';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { imprimirHtml, lerPreferencias, useCabecalhoTalao } from '@/modulos/pos/comum/impressao';
import { pedidoDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { useSessao } from '@/sessao/SessaoContexto';
import { dadosGuiaSaidaPOS, fraseConclusao, htmlTalaoLevantamento, mensagemConclusao, type ConclusaoPOS } from './impressao';
import { anunciarSeLigada } from './voz';

/** O que se imprime sozinho ao confirmar a saída (preferência do posto; legado: guia A4 e talão, sempre). */
export type ImpressaoAuto = 'ambos' | 'guia' | 'talao' | 'nenhum';

export const CHAVE_IMPRESSAO_AUTO = 'pos_wh_impressao_auto';
export const OPCOES_AUTO: { value: ImpressaoAuto; label: string }[] = [
  { value: 'ambos', label: 'Guia A4 e talão' },
  { value: 'guia', label: 'Só a guia A4' },
  { value: 'talao', label: 'Só o talão' },
  { value: 'nenhum', label: 'Nada' },
];

export function lerImpressaoAuto(): ImpressaoAuto {
  try {
    const v = localStorage.getItem(CHAVE_IMPRESSAO_AUTO) as ImpressaoAuto | null;
    return v && OPCOES_AUTO.some((o) => o.value === v) ? v : 'ambos';
  } catch {
    return 'ambos';
  }
}

/** Impressão dos dois impressos da conclusão (guia A4 pelo motor comum; talão térmico/A4 pelas preferências do posto). */
export function useImprimirConclusao() {
  const { empresa } = useSessao();
  const { imprimir } = useImpressao();
  const cabecalho = useCabecalhoTalao();
  return {
    guia: (c: ConclusaoPOS) => imprimir(pedidoDocumentoComercial(dadosGuiaSaidaPOS(c))),
    talao: (c: ConclusaoPOS) => imprimirHtml(htmlTalaoLevantamento(c, cabecalho(), lerPreferencias(empresa?.id))),
  };
}

/**
 * Janela «Saída concluída» do balcão e do picking: mensagem do legado, voz («Atendimento de X concluído com sucesso. Guia N
 * gerada.»), impressão automática da Guia de Saída A4 e do Talão de Levantamento (conforme a preferência do posto) e
 * botões para os reimprimir.
 */
export function Conclusao({ conclusao, aviso, aoFechar }: { conclusao: ConclusaoPOS | null; aviso?: string | null; aoFechar: () => void }) {
  const impressos = useImprimirConclusao();
  const [auto, setAuto] = useState<ImpressaoAuto>(lerImpressaoAuto);
  const feito = useRef<ConclusaoPOS | null>(null);
  const impressosRef = useRef(impressos);
  impressosRef.current = impressos;

  // uma só vez por conclusão: voz e impressão automática
  useEffect(() => {
    if (!conclusao || feito.current === conclusao) return;
    feito.current = conclusao;
    anunciarSeLigada(fraseConclusao(conclusao));
    const modo = lerImpressaoAuto();
    void (async () => {
      if (modo === 'ambos' || modo === 'guia') await impressosRef.current.guia(conclusao);
      if (modo === 'ambos' || modo === 'talao') impressosRef.current.talao(conclusao);
    })();
  }, [conclusao]);

  const mudarAuto = (v: ImpressaoAuto) => {
    setAuto(v);
    try {
      localStorage.setItem(CHAVE_IMPRESSAO_AUTO, v);
    } catch {
      /* sem armazenamento */
    }
  };

  const msg = conclusao ? mensagemConclusao(conclusao) : null;
  return (
    <Modal
      open={!!conclusao}
      onCancel={aoFechar}
      title={null}
      footer={
        <Space wrap style={{ justifyContent: 'flex-end' }}>
          <Button onClick={() => conclusao && void impressos.guia(conclusao)} icon={<IconeFa nome="print" />}>Imprimir Documento A4</Button>
          <Button onClick={() => conclusao && impressos.talao(conclusao)} icon={<IconeFa nome="receipt" />}>Imprimir Talão</Button>
          <Button type="primary" onClick={aoFechar}>Fechar</Button>
        </Space>
      }
    >
      {conclusao && msg && (
        <>
          <Result
            status={aviso ? 'warning' : 'success'}
            title={msg.titulo}
            subTitle={
              <>
                {msg.texto} {aviso ? `Custo das mercadorias por contabilizar: ${aviso}` : 'Stock actualizado.'}
              </>
            }
          />
          <Space style={{ width: '100%', justifyContent: 'center' }} wrap>
            <Typography.Text type="secondary">Ao expedir, imprimir:</Typography.Text>
            <Select size="small" style={{ width: 170 }} value={auto} onChange={mudarAuto} options={OPCOES_AUTO} aria-label="Impressão automática ao expedir" />
          </Space>
        </>
      )}
    </Modal>
  );
}
