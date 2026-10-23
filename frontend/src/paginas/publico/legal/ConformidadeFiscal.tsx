import { PaginaLegal } from './PaginaLegal';

export function ConformidadeFiscal() {
  return (
    <PaginaLegal titulo="Conformidade Fiscal AGT" actualizadoEm="20 de Outubro de 2026">
      <p>
        O ERP Consulvolt foi desenvolvido para cumprir as regras de facturação electrónica estabelecidas pela Administração Geral Tributária
        (AGT) de Angola, aplicáveis à generalidade dos contribuintes que emitem documentos de facturação por via informática.
      </p>

      <h2>1. Documentos certificados</h2>
      <p>
        A Plataforma emite Facturas, Facturas-Recibo, Notas de Crédito, Proformas e Guias de Transporte com os requisitos técnicos exigidos:
        numeração sequencial por série, assinatura digital (RSA) de cada documento, código único de documento (ATCUD/hash de segurança) e código QR
        de verificação, quando aplicável ao tipo de documento.
      </p>

      <h2>2. Exportação SAF-T (AO)</h2>
      <p>
        O sistema gera automaticamente o ficheiro normalizado SAF-T (Standard Audit File for Tax Purposes), no formato definido pela AGT para
        Angola, contendo os dados de facturação e contabilidade do período seleccionado, pronto para submissão às autoridades fiscais quando
        exigido.
      </p>

      <h2>3. Imutabilidade dos documentos fiscais</h2>
      <p>
        Após emitido e selado, um documento fiscal não pode ser alterado nem eliminado — qualquer correcção é feita através de um documento
        rectificativo (Nota de Crédito ou anulação, conforme aplicável), preservando a rastreabilidade exigida pela legislação fiscal.
      </p>

      <h2>4. Séries e numeração</h2>
      <p>
        Cada empresa gerida na Plataforma mantém as suas próprias séries de numeração, sequenciais e sem interrupções dentro de cada exercício,
        conforme exigido pelas regras da AGT para a facturação electrónica.
      </p>

      <h2>5. Responsabilidade partilhada</h2>
      <p>
        A Consulvolt disponibiliza as ferramentas técnicas para que a facturação emitida pelo Cliente cumpra os requisitos da AGT. A correcta
        configuração fiscal da empresa (regime de IVA, taxas aplicáveis, dados de registo) e a veracidade dos dados introduzidos são da
        responsabilidade do Cliente, enquanto emissor legal dos documentos.
      </p>

      <h2>6. Actualizações regulamentares</h2>
      <p>
        Sempre que a AGT actualiza os requisitos técnicos da facturação electrónica, a Consulvolt actualiza a Plataforma em conformidade, sem
        custos adicionais para os clientes com subscrição activa.
      </p>

      <h2>7. Dúvidas sobre conformidade fiscal</h2>
      <p>
        Para questões técnicas sobre a certificação fiscal da sua empresa na Plataforma, contacte o nosso suporte através do centro de apoio
        integrado no sistema ou por <a href="mailto:global@consulvolt.pt">global@consulvolt.pt</a>. Para questões sobre as suas obrigações fiscais
        concretas, recomendamos a consulta de um contabilista certificado ou directamente da AGT.
      </p>
    </PaginaLegal>
  );
}
