<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Linhas de Validação
|--------------------------------------------------------------------------
|
| Português de Angola. Angola não ratificou o Acordo Ortográfico de 1990,
| pelo que se usa a grafia anterior — «selecção», «acção», «activo»,
| «factura», «correcto» — em linha com a legislação fiscal angolana.
|
| Este ficheiro vive em `pt` e não em `pt_AO` de propósito: APP_LOCALE é
| `pt_AO` e APP_FALLBACK_LOCALE é `pt`, por isso o Laravel encontra estas
| linhas na mesma sem duplicar o ficheiro. Crie `lang/pt_AO/` apenas para
| textos que sejam mesmo específicos de Angola.
|
*/

return [

    'accepted' => 'O campo :attribute tem de ser aceite.',
    'accepted_if' => 'O campo :attribute tem de ser aceite quando :other for :value.',
    'active_url' => 'O campo :attribute não contém um URL válido.',
    'after' => 'O campo :attribute tem de ser uma data posterior a :date.',
    'after_or_equal' => 'O campo :attribute tem de ser uma data posterior ou igual a :date.',
    'alpha' => 'O campo :attribute só pode conter letras.',
    'alpha_dash' => 'O campo :attribute só pode conter letras, números, hífenes e underscores.',
    'alpha_num' => 'O campo :attribute só pode conter letras e números.',
    'any_of' => 'O campo :attribute é inválido.',
    'array' => 'O campo :attribute tem de ser uma lista.',
    'ascii' => 'O campo :attribute só pode conter caracteres alfanuméricos e símbolos de um byte.',
    'base64' => 'O campo :attribute tem de ser uma cadeia em base64 válida.',
    'before' => 'O campo :attribute tem de ser uma data anterior a :date.',
    'before_or_equal' => 'O campo :attribute tem de ser uma data anterior ou igual a :date.',
    'between' => [
        'array' => 'O campo :attribute tem de ter entre :min e :max itens.',
        'file' => 'O ficheiro :attribute tem de ter entre :min e :max kilobytes.',
        'numeric' => 'O campo :attribute tem de estar entre :min e :max.',
        'string' => 'O campo :attribute tem de ter entre :min e :max caracteres.',
    ],
    'boolean' => 'O campo :attribute tem de ser verdadeiro ou falso.',
    'can' => 'O campo :attribute contém um valor não autorizado.',
    'confirmed' => 'A confirmação do campo :attribute não coincide.',
    'contains' => 'Falta um valor obrigatório no campo :attribute.',
    'current_password' => 'A palavra-passe está incorrecta.',
    'date' => 'O campo :attribute não é uma data válida.',
    'date_equals' => 'O campo :attribute tem de ser uma data igual a :date.',
    'date_format' => 'O campo :attribute não corresponde ao formato :format.',
    'decimal' => 'O campo :attribute tem de ter :decimal casas decimais.',
    'declined' => 'O campo :attribute tem de ser recusado.',
    'declined_if' => 'O campo :attribute tem de ser recusado quando :other for :value.',
    'different' => 'Os campos :attribute e :other têm de ser diferentes.',
    'digits' => 'O campo :attribute tem de ter :digits dígitos.',
    'digits_between' => 'O campo :attribute tem de ter entre :min e :max dígitos.',
    'dimensions' => 'As dimensões da imagem :attribute são inválidas.',
    'distinct' => 'O campo :attribute tem um valor duplicado.',
    'doesnt_contain' => 'O campo :attribute não pode conter nenhum dos seguintes valores: :values.',
    'doesnt_end_with' => 'O campo :attribute não pode terminar com nenhum dos seguintes valores: :values.',
    'doesnt_start_with' => 'O campo :attribute não pode começar com nenhum dos seguintes valores: :values.',
    'email' => 'O campo :attribute tem de ser um endereço de email válido.',
    'encoding' => 'O campo :attribute tem de usar a codificação :encoding.',
    'ends_with' => 'O campo :attribute tem de terminar com um dos seguintes valores: :values.',
    'enum' => 'O valor seleccionado em :attribute é inválido.',
    'exists' => 'O valor seleccionado em :attribute é inválido.',
    'extensions' => 'O campo :attribute tem de ter uma das seguintes extensões: :values.',
    'file' => 'O campo :attribute tem de ser um ficheiro.',
    'filled' => 'O campo :attribute tem de ser preenchido.',
    'gt' => [
        'array' => 'O campo :attribute tem de ter mais do que :value itens.',
        'file' => 'O ficheiro :attribute tem de ser maior do que :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser maior do que :value.',
        'string' => 'O campo :attribute tem de ter mais do que :value caracteres.',
    ],
    'gte' => [
        'array' => 'O campo :attribute tem de ter :value itens ou mais.',
        'file' => 'O ficheiro :attribute tem de ser maior ou igual a :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser maior ou igual a :value.',
        'string' => 'O campo :attribute tem de ter :value caracteres ou mais.',
    ],
    'hex_color' => 'O campo :attribute tem de ser uma cor hexadecimal válida.',
    'image' => 'O campo :attribute tem de ser uma imagem.',
    'in' => 'O valor seleccionado em :attribute é inválido.',
    'in_array' => 'O campo :attribute tem de existir em :other.',
    'in_array_keys' => 'O campo :attribute tem de conter pelo menos uma das seguintes chaves: :values.',
    'integer' => 'O campo :attribute tem de ser um número inteiro.',
    'ip' => 'O campo :attribute tem de ser um endereço IP válido.',
    'ipv4' => 'O campo :attribute tem de ser um endereço IPv4 válido.',
    'ipv6' => 'O campo :attribute tem de ser um endereço IPv6 válido.',
    'json' => 'O campo :attribute tem de ser uma cadeia JSON válida.',
    'list' => 'O campo :attribute tem de ser uma lista.',
    'lowercase' => 'O campo :attribute tem de estar em minúsculas.',
    'lt' => [
        'array' => 'O campo :attribute tem de ter menos do que :value itens.',
        'file' => 'O ficheiro :attribute tem de ser menor do que :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser menor do que :value.',
        'string' => 'O campo :attribute tem de ter menos do que :value caracteres.',
    ],
    'lte' => [
        'array' => 'O campo :attribute não pode ter mais do que :value itens.',
        'file' => 'O ficheiro :attribute tem de ser menor ou igual a :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser menor ou igual a :value.',
        'string' => 'O campo :attribute tem de ter :value caracteres ou menos.',
    ],
    'mac_address' => 'O campo :attribute tem de ser um endereço MAC válido.',
    'max' => [
        'array' => 'O campo :attribute não pode ter mais do que :max itens.',
        'file' => 'O ficheiro :attribute não pode ter mais do que :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser maior do que :max.',
        'string' => 'O campo :attribute não pode ter mais do que :max caracteres.',
    ],
    'max_digits' => 'O campo :attribute não pode ter mais do que :max dígitos.',
    'mimes' => 'O campo :attribute tem de ser um ficheiro do tipo: :values.',
    'mimetypes' => 'O campo :attribute tem de ser um ficheiro do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute tem de ter pelo menos :min itens.',
        'file' => 'O ficheiro :attribute tem de ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute tem de ser pelo menos :min.',
        'string' => 'O campo :attribute tem de ter pelo menos :min caracteres.',
    ],
    'min_digits' => 'O campo :attribute tem de ter pelo menos :min dígitos.',
    'missing' => 'O campo :attribute tem de estar ausente.',
    'missing_if' => 'O campo :attribute tem de estar ausente quando :other for :value.',
    'missing_unless' => 'O campo :attribute tem de estar ausente a não ser que :other seja :value.',
    'missing_with' => 'O campo :attribute tem de estar ausente quando :values estiver presente.',
    'missing_with_all' => 'O campo :attribute tem de estar ausente quando :values estiverem presentes.',
    'multiple_of' => 'O campo :attribute tem de ser um múltiplo de :value.',
    'not_in' => 'O valor seleccionado em :attribute é inválido.',
    'not_regex' => 'O formato do campo :attribute é inválido.',
    'numeric' => 'O campo :attribute tem de ser um número.',
    'password' => [
        'letters' => 'A palavra-passe tem de conter pelo menos uma letra.',
        'mixed' => 'A palavra-passe tem de conter pelo menos uma maiúscula e uma minúscula.',
        'numbers' => 'A palavra-passe tem de conter pelo menos um número.',
        'symbols' => 'A palavra-passe tem de conter pelo menos um símbolo.',
        'uncompromised' => 'Esta palavra-passe já apareceu numa fuga de dados. Escolha outra.',
    ],
    'present' => 'O campo :attribute tem de estar presente.',
    'present_if' => 'O campo :attribute tem de estar presente quando :other for :value.',
    'present_unless' => 'O campo :attribute tem de estar presente a não ser que :other seja :value.',
    'present_with' => 'O campo :attribute tem de estar presente quando :values estiver presente.',
    'present_with_all' => 'O campo :attribute tem de estar presente quando :values estiverem presentes.',
    'prohibited' => 'O campo :attribute não é permitido.',
    'prohibited_if' => 'O campo :attribute não é permitido quando :other for :value.',
    'prohibited_if_accepted' => 'O campo :attribute não é permitido quando :other for aceite.',
    'prohibited_if_declined' => 'O campo :attribute não é permitido quando :other for recusado.',
    'prohibited_unless' => 'O campo :attribute não é permitido a não ser que :other esteja em :values.',
    'prohibits' => 'O campo :attribute impede que :other esteja presente.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_array_keys' => 'O campo :attribute tem de conter entradas para: :values.',
    'required_if' => 'O campo :attribute é obrigatório quando :other for :value.',
    'required_if_accepted' => 'O campo :attribute é obrigatório quando :other for aceite.',
    'required_if_declined' => 'O campo :attribute é obrigatório quando :other for recusado.',
    'required_unless' => 'O campo :attribute é obrigatório a não ser que :other esteja em :values.',
    'required_with' => 'O campo :attribute é obrigatório quando :values estiver presente.',
    'required_with_all' => 'O campo :attribute é obrigatório quando :values estiverem presentes.',
    'required_without' => 'O campo :attribute é obrigatório quando :values não estiver presente.',
    'required_without_all' => 'O campo :attribute é obrigatório quando nenhum de :values estiver presente.',
    'same' => 'Os campos :attribute e :other têm de coincidir.',
    'size' => [
        'array' => 'O campo :attribute tem de conter :size itens.',
        'file' => 'O ficheiro :attribute tem de ter :size kilobytes.',
        'numeric' => 'O campo :attribute tem de ser :size.',
        'string' => 'O campo :attribute tem de ter :size caracteres.',
    ],
    'starts_with' => 'O campo :attribute tem de começar com um dos seguintes valores: :values.',
    'string' => 'O campo :attribute tem de ser texto.',
    'timezone' => 'O campo :attribute tem de ser um fuso horário válido.',
    'unique' => 'O valor indicado em :attribute já está a ser utilizado.',
    'uploaded' => 'O carregamento do ficheiro :attribute falhou.',
    'uppercase' => 'O campo :attribute tem de estar em maiúsculas.',
    'url' => 'O campo :attribute tem de ser um URL válido.',
    'ulid' => 'O campo :attribute tem de ser um ULID válido.',
    'uuid' => 'O campo :attribute tem de ser um UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensagens Específicas
    |--------------------------------------------------------------------------
    |
    | Onde a mensagem genérica não explica a regra fiscal por trás dela.
    |
    */

    'custom' => [
        'document_date' => [
            'before_or_equal' => 'A data do documento não pode ser no futuro.',
        ],
        'due_date' => [
            'after_or_equal' => 'O vencimento não pode ser anterior à data do documento.',
        ],
        'references_document_public_id' => [
            'required' => 'Indique a factura que este documento corrige.',
            'exists' => 'Só pode corrigir uma factura já emitida desta empresa.',
        ],
        'settlements' => [
            'required' => 'Indique pelo menos uma factura que este recibo liquida.',
            'max' => 'Só um recibo pode liquidar facturas.',
        ],
        'payment_method' => [
            'required' => 'Indique como foi feito o pagamento.',
        ],
        'adjustment_reason' => [
            'required' => 'A AGT exige um motivo para a correcção.',
            'min' => 'Descreva o motivo com pelo menos :min caracteres.',
        ],
        'tax_identification_number' => [
            'regex' => 'O NIF não tem um formato válido para Angola.',
        ],
        'customer.tax_identification_number' => [
            'regex' => 'O NIF do cliente não tem um formato válido.',
        ],
        'password' => [
            'uncompromised' => 'Esta palavra-passe já apareceu numa fuga de dados. Escolha outra.',
        ],
        'file' => [
            'mimes' => 'Aceitamos apenas ficheiros Excel (.xlsx, .xls) ou CSV.',
            'max' => 'O ficheiro não pode exceder :max kilobytes.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nomes dos Campos
    |--------------------------------------------------------------------------
    |
    | Substitui os nomes técnicos por termos que o utilizador reconhece.
    |
    */

    'attributes' => [
        'address_line' => 'morada',
        'basic_auth_password' => 'palavra-passe de acesso',
        'basic_auth_username' => 'utilizador de acesso',
        'currency_code' => 'moeda',
        'customer' => 'cliente',
        'customer.address_line' => 'morada do cliente',
        'customer.country_code' => 'país do cliente',
        'customer.name' => 'nome do cliente',
        'customer.tax_identification_number' => 'NIF do cliente',
        'customer_public_id' => 'cliente',
        'adjustment_reason' => 'motivo da correcção',
        'document_date' => 'data do documento',
        'document_type' => 'tipo de documento',
        'due_date' => 'data de vencimento',
        'email' => 'email',
        'establishment_code' => 'código do estabelecimento',
        'establishment_name' => 'nome do estabelecimento',
        'establishment_number' => 'número do estabelecimento',
        'establishment_public_id' => 'local de emissão',
        'file' => 'ficheiro',
        'legal_name' => 'denominação social',
        'lines' => 'linhas da factura',
        'lines.*.discount_percentage' => 'desconto da linha',
        'lines.*.operation_type' => 'tipo de operação',
        'lines.*.product_code' => 'código do artigo',
        'lines.*.product_description' => 'descrição do artigo',
        'lines.*.quantity' => 'quantidade',
        'lines.*.tax.code' => 'código do imposto',
        'lines.*.tax.exemption_code' => 'código de isenção',
        'lines.*.tax.percentage' => 'percentagem do imposto',
        'lines.*.tax.type' => 'tipo de imposto',
        'lines.*.unit_of_measure' => 'unidade',
        'lines.*.unit_price' => 'preço unitário',
        'main_cae_code' => 'CAE principal',
        'mapping' => 'correspondência de colunas',
        'municipality' => 'município',
        'name' => 'nome',
        'notes' => 'observações',
        'payment_date' => 'data do pagamento',
        'payment_method' => 'meio de pagamento',
        'password' => 'palavra-passe',
        'plan_public_id' => 'plano',
        'product_id' => 'identificador do produto',
        'product_version' => 'versão do produto',
        'province_code' => 'província',
        'references_document_public_id' => 'documento corrigido',
        'settlements' => 'facturas liquidadas',
        'settlements.*.amount' => 'valor liquidado',
        'settlements.*.document_public_id' => 'factura a liquidar',
        'revision' => 'revisão',
        'series_public_id' => 'série autorizada',
        'software_key_reference' => 'chave do software',
        'software_validation_number' => 'número de validação do software',
        'source' => 'origem dos dados',
        'tax_identification_number' => 'NIF',
        'tax_regime' => 'regime de IVA',
        'taxpayer_key_reference' => 'chave do contribuinte',
        'trade_name' => 'nome comercial',
        'type' => 'tipo',
        'workspace_name' => 'nome do espaço de trabalho',
    ],

];
