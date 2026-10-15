import { Alert, Modal, Typography } from 'antd';
import { useState } from 'react';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';
import { simularReparticao, useCriterios } from './api';
import { AlocacaoUN } from './AlocacaoUN';
import { alocacaoParaApi, alocacaoVazia, validarAlocacao, type Alocacao, type ResultadoSimulacao } from './regras';

export interface PropsModalRepartirLinha {
  /** Valor da linha a repartir (> 0). */
  valor: number;
  subtitulo?: string;
  aoFechar: () => void;
  /** Recebe as parcelas calculadas no servidor (a soma é exactamente o valor). */
  aoRepartir: (r: ResultadoSimulacao) => void;
}

/**
 * «Repartir a linha por unidades» (ReparticaoPagina.escolher do legado novo): as unidades alocam-se na hora, porque a linha
 * é o objecto e não tem alocação guardada. Usado nas linhas do Diário (lançamentos) e da Tesouraria.
 */
export function ModalRepartirLinha({ valor, subtitulo, aoFechar, aoRepartir }: PropsModalRepartirLinha) {
  const criterios = useCriterios(true);
  const [alocacao, setAlocacao] = useState<Alocacao | null>(null);
  const [aCalcular, setACalcular] = useState(false);
  const criterio = (criterios.data ?? []).find((c) => c.id === alocacao?.criterio_id);
  const semCriterios = criterios.isSuccess && criterios.data.length === 0;

  const repartir = async () => {
    if (alocacaoVazia(alocacao) || !criterio) {
      notificarErro(new Error('Escolha o critério e aloque as unidades.'));
      return;
    }
    const erro = validarAlocacao(alocacao, criterio.criterio);
    if (erro) {
      notificarErro(new Error(erro));
      return;
    }
    setACalcular(true);
    try {
      aoRepartir(await simularReparticao(alocacaoParaApi(alocacao, criterio.criterio)!, valor));
    } catch (e) {
      notificarErro(e, 'Não foi possível repartir a linha');
    } finally {
      setACalcular(false);
    }
  };

  return (
    <Modal open title="Repartir a linha por unidades de negócio" okText="Repartir" cancelText="Cancelar" onCancel={aoFechar} onOk={() => void repartir()}
      confirmLoading={aCalcular} okButtonProps={{ disabled: semCriterios || !(valor > 0) }} destroyOnHidden width={680}>
      <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>
        {subtitulo ? `${subtitulo} · ` : ''}Valor a repartir: {formatarKz(valor)}. A linha é substituída por uma por unidade, com o mesmo total.
      </Typography.Paragraph>
      {!(valor > 0) && <Alert type="warning" showIcon message="Indique primeiro o valor da linha a repartir." style={{ marginBottom: 8 }} />}
      {semCriterios ? (
        <Alert type="info" showIcon message="Não há critérios de repartição. Crie um em Configurações › Unidades de negócio › Critérios de repartição." />
      ) : (
        <AlocacaoUN value={alocacao} onChange={setAlocacao} valor={valor} titulo="Alocar as unidades" />
      )}
    </Modal>
  );
}
