import { Alert, Card, Checkbox, Form } from 'antd';
import { useState } from 'react';
import { TituloMapa } from './TituloMapa';
import { DemonstracaoPorEmpresa, InterruptorPorEmpresa, usePorEmpresa } from './PorEmpresa';
import { formatarKz } from '@/utilitarios/formatacao';
import type { FluxoCaixa } from '../api';
import { eZero } from '@/utilitarios/decimal';
import { TabelaDemonstracao } from '../comum/Demonstracao';
import { linhasFluxo } from '../comum/demonstracoes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { filtrosDosParametros, periodoDosParametros } from '../comum/impressao';
import { useMapa } from '../comum/useMapa';

/** Mapas › Demonstração de fluxos de caixa (ecrã contab_mapa_fluxo), pelo método directo (notas de fluxo). */
export default function MapaFluxo() {
  const mapa = useMapa<FluxoCaixa>('fluxo', '/contabilidade/relatorios/fluxo-caixa');
  const d = mapa.data;
  const comparativo = !!mapa.parametros?.comparativo;
  const [porEmpresa, setPorEmpresa] = useState(false);
  const pe = usePorEmpresa('fluxo', mapa.parametros, porEmpresa);

  return (
    <>
      {/* legado: <h3>Demonstração de fluxo de caixa</h3> (js/ui_reports.js:1490-1499) */}
      <TituloMapa titulo="Demonstração de fluxo de caixa" subtitulo={periodoDosParametros(mapa.parametros) ?? 'Método directo, por notas de fluxo de caixa'} accoes={<InterruptorPorEmpresa ligado={porEmpresa} aoMudar={setPorEmpresa} />} />
      <Card style={{ marginBottom: 16 }}>
        <FiltrosMapa
          modo="periodo"
          aCalcular={mapa.isFetching}
          aoCalcular={mapa.calcular}
          avancados={{ unidade: true, centro: true, diario: true }}
          // o legado mostra o ano anterior por omissão (reportOptions.showPrevious = true)
          valoresIniciais={{ comparativo: true }}
          extra={
            <Form.Item name="comparativo" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Comparativo</Checkbox>
            </Form.Item>
          }
        />
      </Card>
      {porEmpresa && d && <DemonstracaoPorEmpresa linhas={linhasFluxo(d)} dados={pe.data} titulo="Demonstração de fluxo de caixa" periodo={periodoDosParametros(mapa.parametros)} carregando={pe.isFetching} />}
      {!porEmpresa && d && (
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
              demonstracao="fluxo"
              linhas={linhasFluxo(d)}
              comparativo={comparativo}
              rotuloAtual={`Ano ${d.ano}`}
              rotuloAnterior={`Ano ${d.ano - 1}`}
              tipoNota="fluxo"
              parametros={mapa.parametros ?? {}}
              nomeCsv={`fluxos_caixa_${d.ano}`}
              impressao={{ titulo: 'Demonstração dos fluxos de caixa', periodo: periodoDosParametros(mapa.parametros), filtros: [...filtrosDosParametros(mapa.parametros), comparativo ? 'Comparativo com o ano anterior' : null] }}
            />
          </Card>
        </>
      )}
    </>
  );
}
