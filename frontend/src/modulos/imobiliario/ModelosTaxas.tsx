import { Tabs } from 'antd';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeparadorModelos } from './SeparadorModelos';
import { SeparadorTaxas } from './SeparadorTaxas';
import './imobiliario.css';

const SEPARADORES = ['taxas', 'modelos'] as const;

/**
 * Gestão Imobiliária › Modelos e Taxas (ecrã imo_modelos): as taxas fiscais do ano (editáveis, por validar) e os modelos dos
 * documentos (contratos, recibos, cartas). Ver: imo_modelos_view · modelos: imo_modelo_editar · taxas: imo_taxas_editar.
 */
export default function ModelosTaxas() {
  const { pode } = useSessao();
  const [separador, mudar] = useSeparadorUrl(SEPARADORES, 'taxas');
  return (
    <>
      <CabecalhoModulo titulo="Modelos e Taxas" icone="file-contract"
        subtitulo="Os modelos de documentos e as taxas fiscais do ano. As taxas guardam-se por ano porque mudam com o Orçamento Geral do Estado." />
      <Tabs activeKey={separador} onChange={mudar} items={[
        { key: 'taxas', label: 'Taxas fiscais', children: <SeparadorTaxas podeEditar={pode('imo_taxas_editar')} /> },
        { key: 'modelos', label: 'Modelos de documentos', children: <SeparadorModelos podeEditar={pode('imo_modelo_editar')} /> },
      ]} />
    </>
  );
}
