import { Alert, Card, Checkbox, Form } from 'antd';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { formatarKz } from '@/utilitarios/formatacao';
import type { FluxoCaixa } from '../api';
import { eZero } from '../comum/decimal';
import { TabelaDemonstracao } from '../comum/Demonstracao';
import { linhasFluxo } from '../comum/demonstracoes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { useMapa } from '../comum/useMapa';

/** Mapas › Demonstração de fluxos de caixa (ecrã contab_mapa_fluxo), pelo método directo (notas de fluxo). */
export default function MapaFluxo() {
  const mapa = useMapa<FluxoCaixa>('fluxo', '/contabilidade/relatorios/fluxo-caixa');
  const d = mapa.data;
  const comparativo = !!mapa.parametros?.comparativo;

  return (
    <>
      <CabecalhoPagina titulo="Fluxos de caixa" subtitulo="Método directo, por notas de fluxo de caixa" />
      <Card style={{ marginBottom: 16 }}>
        <FiltrosMapa
          modo="periodo"
          aCalcular={mapa.isFetching}
          aoCalcular={mapa.calcular}
          avancados={{ unidade: true, centro: true, diario: true }}
          extra={
            <Form.Item name="comparativo" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Comparativo</Checkbox>
            </Form.Item>
          }
        />
      </Card>
      {d && (
        <>
          {!eZero(d.controlo.nao_explicado) && (
            <Alert
              type="warning"
              showIcon
              style={{ marginBottom: 16 }}
              message={`Variação não explicada pelas notas de fluxo: ${formatarKz(d.controlo.nao_explicado, true)}`}
              description={`Variação da classe 4 no período: ${formatarKz(d.controlo.variacao_classe_4, true)}. Associe as notas de fluxo aos movimentos de caixa e bancos.`}
            />
          )}
          <Card>
            <TabelaDemonstracao
              linhas={linhasFluxo(d)}
              comparativo={comparativo}
              rotuloAtual={String(d.ano)}
              rotuloAnterior={String(d.ano - 1)}
              tipoNota="fluxo"
              parametros={mapa.parametros ?? {}}
              nomeCsv={`fluxos_caixa_${d.ano}`}
            />
          </Card>
        </>
      )}
    </>
  );
}
