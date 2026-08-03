<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="public/brand/facturac-ao-dark.svg">
  <img src="public/brand/facturac-ao.svg" width="280" alt="facturac.ao">
</picture>

# facturac.ao

**Facturação electrónica para Angola — fiscal, sem ruído.**

Uma plataforma SaaS multiempresa para emitir documentos fiscais, comunicar com a AGT, cobrar subscrições por Referência EMIS e migrar dados com segurança.

![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Vue 3](https://img.shields.io/badge/Vue-3-42B883?logo=vuedotjs&logoColor=white)
![Inertia 3](https://img.shields.io/badge/Inertia-3-9553E9?logo=inertia&logoColor=white)
![Tailwind CSS 4](https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?logo=tailwindcss&logoColor=white)
![Pest 5](https://img.shields.io/badge/Pest-5-F5A623)

</div>

> [!IMPORTANT]
> O facturac.ao está em desenvolvimento activo e preparado para trabalho de homologação. Este repositório não declara certificação, validação ou autorização de produção pela AGT. As ligações de produção à AGT e à Pay4All permanecem bloqueadas por defeito até existirem credenciais, contratos, testes e aprovações aplicáveis.

## O produto

O facturac.ao foi desenhado para empreendedores, pequenas e médias empresas e equipas de contabilidade em Angola. Combina uma experiência simples em português com controlos fiscais, rastreabilidade e isolamento rigoroso entre empresas.

### Capacidades implementadas

| Área                     | O que já funciona                                                                                                                                     |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Identidade e acesso      | Registo, verificação de email, recuperação de palavra-passe, MFA/TOTP, códigos de recuperação, confirmação de palavra-passe e login Google            |
| SaaS multiempresa        | Workspaces, entidades legais, estabelecimentos, mudança de empresa, papéis e políticas de autorização                                                 |
| Preparação fiscal        | Perfil do contribuinte, NIF, regime fiscal, CAE, moeda AOA, fuso horário de Luanda e estabelecimento sede                                             |
| Ligação AGT              | Ambiente de homologação, credenciais protegidas, referências para chaves PEM fora da base de dados, testes de conectividade e sincronização de séries |
| Documentos fiscais       | Rascunhos, linhas, descontos, impostos, totais determinísticos, numeração de séries e emissão imutável                                                |
| Submissões AGT           | Outbox transaccional, assinatura JWS, filas, repetição segura, idempotência, consulta de estado e monitor operacional                                 |
| Cobrança SaaS            | Planos, subscrições e Referências EMIS através da fronteira Pay4All e+; simulador local seguro enquanto a API comercial não está autorizada           |
| Importação de dados      | Clientes, produtos e serviços via XLSX, XLS ou CSV, com mapeamento, validação por linha, SHA-256, staging privado e confirmação idempotente           |
| Auditoria e notificações | Registo de actividades atribuível, eventos fiscais, histórico de pagamentos e notificações persistentes                                               |
| Experiência              | Interface responsiva, acessível, com modo escuro, temas, componentes Tailwind Plus, ícones Lucide e linguagem orientada ao utilizador                 |

## Arquitectura

```mermaid
flowchart LR
    UI["Vue 3 + Inertia 3<br/>Tailwind CSS 4"] --> APP["Laravel 13"]

    APP --> ID["Fortify + Socialite<br/>MFA e autorização"]
    APP --> TENANT["Workspaces<br/>Entidades e estabelecimentos"]
    APP --> FISCAL["Domínio fiscal<br/>Cálculo e emissão"]
    APP --> IMPORTS["Importação privada<br/>Excel e CSV"]
    APP --> BILLING["Subscrições<br/>Referência EMIS"]

    FISCAL --> OUTBOX["Outbox + filas<br/>entrega idempotente"]
    OUTBOX --> AGT["Serviços AGT<br/>homologação por defeito"]
    BILLING --> PAY4ALL["Pay4All e+<br/>produção bloqueada por defeito"]

    ID --> DB[("Base de dados")]
    TENANT --> DB
    FISCAL --> DB
    IMPORTS --> DB
    BILLING --> DB
```

O backend mantém as regras fiscais no servidor. A interface nunca é a fonte de verdade para totais, permissões, numeração ou estado de submissão.

## Estado das fases

|    Fase | Entrega                                                                    | Estado                    |
| ------: | -------------------------------------------------------------------------- | ------------------------- |
|       0 | Investigação, matriz de conformidade, decisões de arquitectura e protótipo | Implementada              |
|       1 | Identidade, MFA, login social, tenancy e onboarding                        | Implementada              |
|       2 | Configuração e verificação da ligação AGT                                  | Implementada              |
|       3 | Cadastro e rascunhos de documentos fiscais                                 | Implementada              |
|       4 | Emissão, assinatura, submissão e monitor AGT                               | Implementada              |
|       5 | Subscrições por Pay4All e+ / Referência EMIS                               | Implementada em simulação |
|       6 | Migração assistida de clientes, produtos e serviços                        | Implementada              |
| Próxima | SAF-T (AO), validação de esquema e fluxo controlado de entrega             | Planeada                  |

Os documentos de governação e conformidade estão em [`docs/phase-0`](docs/phase-0/README.md).

## Stack

- PHP 8.4 e Laravel 13
- Vue 3, TypeScript e Inertia.js 3
- Tailwind CSS 4, Tailwind Plus e Lucide
- Laravel Fortify, Socialite e Wayfinder
- Spatie Laravel Activitylog
- Laravel Excel / PhpSpreadsheet
- ECharts 6
- Pest 5, Larastan/PHPStan, Pint, ESLint e Prettier

## Instalação local

### Requisitos

- PHP 8.4 com as extensões exigidas pelo Laravel e PhpSpreadsheet
- Composer 2
- Node.js `^20.19.0` ou `>=22.12.0`
- npm

### Preparar o projecto

Depois de clonar o repositório:

```bash
cd vap-invoice
touch database/database.sqlite
composer setup
```

O comando instala as dependências, cria o `.env`, gera a chave da aplicação, executa as migrações e produz os recursos frontend.

Inicie o ambiente de desenvolvimento:

```bash
composer dev
```

Abra [http://localhost:8000](http://localhost:8000) e crie uma conta. O processo de desenvolvimento inicia o servidor, o frontend, o worker de filas e os logs. Para executar também as tarefas periódicas da AGT e da cobrança:

```bash
php artisan schedule:work
```

## Configuração

O [`.env.example`](.env.example) contém valores locais seguros e usa Angola como contexto inicial:

| Grupo                 | Variáveis principais                                                                       |
| --------------------- | ------------------------------------------------------------------------------------------ |
| Aplicação             | `APP_URL`, `APP_TIMEZONE`, `APP_LOCALE`                                                    |
| Base de dados e filas | `DB_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`                                |
| Google OAuth          | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`                          |
| AGT                   | `AGT_DEFAULT_ENVIRONMENT`, `AGT_SCHEMA_VERSION`, `AGT_*_BASE_URL`, timeouts e limites      |
| Produção AGT          | `AGT_PRODUCTION_ENABLED` — deve permanecer `false` até à autorização operacional           |
| Pay4All e+            | `PAY4ALL_ENVIRONMENT`, dados do comerciante, validade e limites da Referência EMIS         |
| Produção Pay4All      | `PAY4ALL_PRODUCTION_ENABLED` — deve permanecer `false` até à integração comercial assinada |

As chaves privadas de assinatura da AGT devem permanecer fora da raiz pública e fora da base de dados. A aplicação guarda apenas uma referência ao ficheiro configurado.

## Processos de fundo

Em produção, configure pelo menos:

- workers persistentes para a fila Laravel;
- `php artisan schedule:run` a cada minuto;
- HTTPS, armazenamento privado e gestão externa de segredos;
- retenção de logs, monitorização, alertas e cópias de segurança testadas;
- uma política de rotação das chaves e credenciais da AGT e da Pay4All.

O scheduler despacha submissões AGT pendentes, consulta resultados e expira Referências EMIS não pagas.

## Qualidade

Execute a verificação completa antes de integrar alterações:

```bash
composer ci:check
npm run build
```

O gate inclui Pint, PHPStan/Larastan, Pest, ESLint, Prettier e verificação TypeScript. Alterações funcionais devem ser acompanhadas por testes Pest focados no comportamento e no isolamento entre empresas.

## Princípios de segurança fiscal

- nenhuma consulta ou mutação pode atravessar a fronteira do workspace;
- documentos emitidos não são reescritos silenciosamente;
- numeração, totais e impostos são calculados e confirmados no backend;
- operações sensíveis exigem autorização, palavra-passe recente e MFA quando configurado;
- chamadas externas usam idempotência, filas e histórico de tentativas;
- respostas externas são reduzidas a mensagens seguras antes de chegar à interface;
- ficheiros importados não recebem URL pública e são eliminados depois da confirmação ou cancelamento;
- produção externa permanece fechada até concluir homologação e readiness operacional.

## Referências

- [Portal do Parceiro — documentação de Facturação Electrónica da AGT](https://portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica/1/index.html)
- [Matriz de conformidade](docs/phase-0/compliance-matrix.md)
- [Plano de certificação](docs/phase-0/certification-plan.md)
- [Registo de fontes](docs/phase-0/source-register.md)
- [Decisões de arquitectura](docs/phase-0/adr)

---

<div align="center">

**facturac.ao** · Construído em Angola para empresas angolanas.

</div>
