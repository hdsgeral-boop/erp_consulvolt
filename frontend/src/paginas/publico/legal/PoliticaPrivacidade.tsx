import { PaginaLegal } from './PaginaLegal';

export function PoliticaPrivacidade() {
  return (
    <PaginaLegal titulo="Política de Privacidade" actualizadoEm="20 de Outubro de 2026">
      <p>
        Esta Política de Privacidade descreve como a Consulvolt recolhe, utiliza e protege os dados pessoais tratados através do ERP Consulvolt,
        em conformidade com a Lei n.º 22/11, de 17 de Junho (Lei da Protecção de Dados Pessoais de Angola) e demais legislação aplicável.
      </p>

      <h2>1. Responsável pelo tratamento</h2>
      <p>
        A Consulvolt, com base operacional na Urbanização Lar do Patriota, Rua 6/8, Luanda, Angola, é responsável pelo tratamento dos dados
        pessoais recolhidos através do registo e utilização da Plataforma. Para os dados que o Cliente introduz sobre os seus próprios
        colaboradores e clientes (ex.: ficha de recursos humanos, clientes de facturação), a Consulvolt actua como subcontratante, seguindo as
        instruções do Cliente enquanto responsável por esses dados.
      </p>

      <h2>2. Dados que recolhemos</h2>
      <ul>
        <li><strong>Dados de registo:</strong> nome, email, telefone e dados fiscais da empresa (NIF, morada, província) indicados no momento do registo.</li>
        <li><strong>Dados de utilização:</strong> registos de acesso, endereço IP e agente do navegador, para segurança e auditoria.</li>
        <li><strong>Dados de negócio do Cliente:</strong> introduzidos directamente na Plataforma (facturação, stocks, recursos humanos, contabilidade), tratados exclusivamente para prestar o serviço contratado.</li>
        <li><strong>Comunicações:</strong> mensagens trocadas através do centro de suporte e os emails enviados sobre a conta (boas-vindas, licença, segurança).</li>
      </ul>

      <h2>3. Finalidades do tratamento</h2>
      <ul>
        <li>Criar e gerir a conta do Cliente e autenticar os seus utilizadores.</li>
        <li>Prestar as funcionalidades do ERP contratadas, incluindo a emissão de documentos fiscais certificados pela AGT.</li>
        <li>Comunicar informações essenciais sobre a conta: confirmação de registo, segurança (alteração de password), estado da licença e respostas de suporte.</li>
        <li>Garantir a segurança da Plataforma e prevenir utilização fraudulenta ou não autorizada.</li>
        <li>Cumprir obrigações legais e fiscais aplicáveis em Angola.</li>
      </ul>

      <h2>4. Base legal</h2>
      <p>
        O tratamento assenta na execução do contrato de subscrição (Termos de Utilização), no cumprimento de obrigações legais (nomeadamente
        fiscais) e, quando aplicável, no consentimento do titular dos dados ou no interesse legítimo da Consulvolt na segurança e melhoria da
        Plataforma.
      </p>

      <h2>5. Partilha de dados</h2>
      <p>
        Os dados não são vendidos a terceiros. Podem ser partilhados apenas com: (i) prestadores de infraestrutura cloud que alojam a Plataforma,
        sob obrigações contratuais de confidencialidade; (ii) a Administração Geral Tributária (AGT), na medida exigida pela certificação fiscal
        dos documentos emitidos; (iii) autoridades competentes, quando legalmente exigido.
      </p>

      <h2>6. Conservação dos dados</h2>
      <p>
        Os dados de negócio do Cliente são conservados enquanto a conta estiver activa e, após o encerramento, pelo prazo necessário ao cumprimento
        de obrigações legais (nomeadamente fiscais e contabilísticas), findo o qual são eliminados ou anonimizados.
      </p>

      <h2>7. Segurança</h2>
      <p>
        A Plataforma utiliza encriptação em trânsito, isolamento lógico dos dados de cada cliente (multi-tenant com controlo de acesso ao nível da
        base de dados) e cópias de segurança regulares. O acesso interno aos dados dos clientes é restrito à equipa técnica estritamente necessária
        para prestar suporte.
      </p>

      <h2>8. Direitos do titular dos dados</h2>
      <p>
        Nos termos da lei angolana, o titular dos dados pode solicitar o acesso, a rectificação, o apagamento ou a limitação do tratamento dos seus
        dados pessoais, dirigindo o pedido a <a href="mailto:global@consulvolt.pt">global@consulvolt.pt</a>. Quando os dados forem geridos por um
        Cliente da Plataforma (ex.: dados de um colaborador), o pedido deve em primeiro lugar ser dirigido a esse Cliente, enquanto responsável
        principal pelo tratamento.
      </p>

      <h2>9. Alterações a esta política</h2>
      <p>
        Esta política pode ser actualizada para reflectir alterações legais ou operacionais. A data da última actualização está indicada no topo
        desta página.
      </p>

      <h2>10. Contacto</h2>
      <p>
        Para qualquer questão sobre esta Política de Privacidade, contacte-nos através de <a href="mailto:global@consulvolt.pt">global@consulvolt.pt</a>.
      </p>
    </PaginaLegal>
  );
}
