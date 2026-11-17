const connection = `import { Facturac } from '@vapsolucoes.ao/facturac';

const facturac = new Facturac({
  baseUrl: 'https://facturac.ao',
  token: process.env.FACTURAC_TOKEN!,
  workspacePublicId: process.env.FACTURAC_WORKSPACE_ID!,
  entityPublicId: process.env.FACTURAC_ENTITY_ID!,
  environment: 'production',
});`;

export const developerExamples = [
    {
        id: 'documents',
        label: 'Ler documentos',
        scope: 'documents:read',
        description:
            'Percorra todas as páginas para reconciliar documentos no seu ERP.',
        code: `${connection}

for await (const document of facturac.documents.iterate({ per_page: 25 })) {
  console.log(document.public_id, document.document_no,
    document.currency_code, document.gross_total_minor);
}`,
        note: 'Valores em unidades monetárias mínimas: 150000 representa 1 500,00 AOA. Filtre os estados antes de contabilizar; um rascunho não é receita.',
    },
    {
        id: 'customers',
        label: 'Criar um cliente',
        scope: 'customers:create',
        description:
            'Ligue o CRM ao facturac sem duplicar um cliente ao repetir o pedido.',
        code: `${connection}

const customer = await facturac.customers.create(
  { name: process.env.CUSTOMER_NAME!,
    tax_identification_number: process.env.CUSTOMER_NIF!, country_code: 'AO' },
  { idempotencyKey: 'crm-customer-123' },
);
console.log(customer.data);`,
        note: 'Defina CUSTOMER_NAME e CUSTOMER_NIF com os dados do cliente. Guarde a chave e o conteúdo antes do primeiro pedido. Ao repetir a mesma operação, reutilize ambos. O SDK não repete pedidos automaticamente.',
    },
] as const;

export const demoCommand =
    'npm exec --yes --package=@vapsolucoes.ao/facturac@0.2.0 -- facturac-export --demo ./facturac-demo.csv';
