const connection = `import { Facturac } from '@vapsolucoes.ao/facturac';

const facturac = new Facturac({
  baseUrl: 'https://facturac.ao',
  token: process.env.FACTURAC_TOKEN!,
  workspacePublicId: process.env.FACTURAC_WORKSPACE_ID!,
  entityPublicId: process.env.FACTURAC_ENTITY_ID!,
  environment: 'production',
});`;

export interface DeveloperExample {
    id: string;
    label: string;
    file: string;
    scope: string;
    description: string;
    code: string;
    note: string;
}

export const developerExamples: readonly DeveloperExample[] = [
    {
        id: 'documents',
        label: 'Ler documentos',
        file: 'reconciliar.ts',
        scope: 'documents:read',
        description:
            'Percorre todas as páginas de documentos para os reconciliar no seu ERP.',
        code: `${connection}

for await (const document of facturac.documents.iterate({ per_page: 25 })) {
  console.log(document.public_id, document.document_no,
    document.currency_code, document.gross_total_minor);
}`,
        note: 'Os valores vêm em unidades mínimas: 150000 são 1 500,00 AOA. Um rascunho não é receita, por isso filtre o estado antes de contabilizar.',
    },
    {
        id: 'customers',
        label: 'Criar um cliente',
        file: 'crm.ts',
        scope: 'customers:create',
        description:
            'Liga o CRM sem duplicar o cliente quando o pedido se repete.',
        code: `${connection}

const customer = await facturac.customers.create(
  { name: process.env.CUSTOMER_NAME!,
    tax_identification_number: process.env.CUSTOMER_NIF!, country_code: 'AO' },
  { idempotencyKey: 'crm-customer-123' },
);
console.log(customer.data);`,
        note: 'Ao repetir um pedido sem resposta, envie a mesma chave de idempotência com os mesmos dados. O SDK não repete pedidos sozinho.',
    },
];

export const demoCommand =
    'npm exec --yes --package=@vapsolucoes.ao/facturac@0.2.0 -- facturac-export --demo ./facturac-demo.csv';

export const installCommand = 'npm install @vapsolucoes.ao/facturac';

/**
 * What the demo really prints and writes (examples/export-documents.mjs in
 * the SDK), cut to the columns a reader checks first.
 */
export const demoSession = [
    { kind: 'command', text: demoCommand },
    { kind: 'output', text: 'DEMO — synthetic data. Exported 2 documents.' },
    { kind: 'blank', text: '' },
    { kind: 'command', text: 'cut -d, -f2,7,8 facturac-demo.csv' },
    { kind: 'output', text: 'document_no,gross_total_minor,issue_status' },
    { kind: 'output', text: '"FT DEMO/3",150000,"issued"' },
    { kind: 'output', text: '"",50000,"draft"' },
] as const;

export type CodeToken = { kind: 'keyword' | 'string' | 'plain'; text: string };

const KEYWORDS = new Set([
    'import',
    'from',
    'const',
    'new',
    'for',
    'await',
    'of',
]);

/** Runs of the same kind become one token, so plain code stays plain text. */
function push(
    tokens: CodeToken[],
    kind: CodeToken['kind'],
    text: string,
): void {
    const previous = tokens.at(-1);

    if (previous?.kind === kind) {
        previous.text += text;
    } else {
        tokens.push({ kind, text });
    }
}

/**
 * Just enough colour to read the examples: keywords and strings. A full
 * highlighter would weigh more than the section it decorates.
 */
export function tokenize(source: string): CodeToken[] {
    const tokens: CodeToken[] = [];
    const pattern = /('[^']*'|`[^`]*`|[A-Za-z_$][\w$]*)/g;
    let last = 0;

    for (const match of source.matchAll(pattern)) {
        const text = match[0];
        const at = match.index ?? 0;

        if (at > last) {
            push(tokens, 'plain', source.slice(last, at));
        }

        push(
            tokens,
            text.startsWith("'") || text.startsWith('`')
                ? 'string'
                : KEYWORDS.has(text)
                  ? 'keyword'
                  : 'plain',
            text,
        );
        last = at + text.length;
    }

    if (last < source.length) {
        push(tokens, 'plain', source.slice(last));
    }

    return tokens;
}
