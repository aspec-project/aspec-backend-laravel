<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mensagens de validação (pt-PT)
    |--------------------------------------------------------------------------
    |
    | Tradução das mensagens por defeito do validador do Laravel. Servem todos
    | os Form Requests; `messages()` num Form Request só para casos específicos.
    |
    */

    'accepted' => 'O campo :attribute tem de ser aceite.',
    'accepted_if' => 'O campo :attribute tem de ser aceite quando :other é :value.',
    'active_url' => 'O campo :attribute tem de ser um URL válido.',
    'after' => 'O campo :attribute tem de ser posterior a :date.',
    'after_or_equal' => 'O campo :attribute tem de ser igual ou posterior a :date.',
    'alpha' => 'O campo :attribute só pode conter letras.',
    'alpha_dash' => 'O campo :attribute só pode conter letras, números, hífenes e underscores.',
    'alpha_num' => 'O campo :attribute só pode conter letras e números.',
    'any_of' => 'O campo :attribute é inválido.',
    'array' => 'O campo :attribute tem de ser uma lista.',
    'array_keys' => 'O campo :attribute só pode conter as seguintes chaves: :values.',
    'ascii' => 'O campo :attribute só pode conter caracteres alfanuméricos e símbolos simples.',
    'base64' => 'O campo :attribute tem de estar codificado em Base64.',
    'before' => 'O campo :attribute tem de ser anterior a :date.',
    'before_or_equal' => 'O campo :attribute tem de ser igual ou anterior a :date.',
    'between' => [
        'array' => 'O campo :attribute tem de ter entre :min e :max elementos.',
        'file' => 'O ficheiro :attribute tem de ter entre :min e :max kilobytes.',
        'numeric' => 'O campo :attribute tem de estar entre :min e :max.',
        'string' => 'O campo :attribute tem de ter entre :min e :max caracteres.',
    ],
    'boolean' => 'O campo :attribute tem de ser verdadeiro ou falso.',
    'can' => 'O campo :attribute contém um valor não autorizado.',
    'confirmed' => 'A confirmação do campo :attribute não corresponde.',
    'contains' => 'Falta um valor obrigatório no campo :attribute.',
    'current_password' => 'A password atual está incorreta.',
    'date' => 'O campo :attribute tem de ser uma data válida.',
    'date_equals' => 'O campo :attribute tem de ser uma data igual a :date.',
    'date_format' => 'O campo :attribute tem de estar no formato :format.',
    'decimal' => 'O campo :attribute tem de ter :decimal casas decimais.',
    'declined' => 'O campo :attribute tem de ser recusado.',
    'declined_if' => 'O campo :attribute tem de ser recusado quando :other é :value.',
    'different' => 'Os campos :attribute e :other têm de ser diferentes.',
    'digits' => 'O campo :attribute tem de ter :digits dígitos.',
    'digits_between' => 'O campo :attribute tem de ter entre :min e :max dígitos.',
    'dimensions' => 'A imagem :attribute tem dimensões inválidas.',
    'distinct' => 'O campo :attribute tem um valor repetido.',
    'doesnt_contain' => 'O campo :attribute não pode conter nenhum dos seguintes valores: :values.',
    'doesnt_end_with' => 'O campo :attribute não pode terminar com: :values.',
    'doesnt_start_with' => 'O campo :attribute não pode começar com: :values.',
    'email' => 'O campo :attribute tem de ser um endereço de email válido.',
    'encoding' => 'O campo :attribute tem de estar codificado em :encoding.',
    'ends_with' => 'O campo :attribute tem de terminar com: :values.',
    'enum' => 'O valor selecionado para :attribute é inválido.',
    'exists' => 'O valor selecionado para :attribute é inválido.',
    'extensions' => 'O ficheiro :attribute tem de ter uma das seguintes extensões: :values.',
    'file' => 'O campo :attribute tem de ser um ficheiro.',
    'filled' => 'O campo :attribute tem de ter um valor.',
    'gt' => [
        'array' => 'O campo :attribute tem de ter mais de :value elementos.',
        'file' => 'O ficheiro :attribute tem de ter mais de :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser maior que :value.',
        'string' => 'O campo :attribute tem de ter mais de :value caracteres.',
    ],
    'gte' => [
        'array' => 'O campo :attribute tem de ter :value elementos ou mais.',
        'file' => 'O ficheiro :attribute tem de ter :value kilobytes ou mais.',
        'numeric' => 'O campo :attribute tem de ser maior ou igual a :value.',
        'string' => 'O campo :attribute tem de ter :value caracteres ou mais.',
    ],
    'hex_color' => 'O campo :attribute tem de ser uma cor hexadecimal válida.',
    'image' => 'O campo :attribute tem de ser uma imagem.',
    'in' => 'O valor selecionado para :attribute é inválido.',
    'in_array' => 'O campo :attribute tem de existir em :other.',
    'in_array_keys' => 'O campo :attribute tem de conter pelo menos uma das seguintes chaves: :values.',
    'integer' => 'O campo :attribute tem de ser um número inteiro.',
    'ip' => 'O campo :attribute tem de ser um endereço IP válido.',
    'ipv4' => 'O campo :attribute tem de ser um endereço IPv4 válido.',
    'ipv6' => 'O campo :attribute tem de ser um endereço IPv6 válido.',
    'json' => 'O campo :attribute tem de ser um JSON válido.',
    'list' => 'O campo :attribute tem de ser uma lista.',
    'lowercase' => 'O campo :attribute tem de estar em minúsculas.',
    'lt' => [
        'array' => 'O campo :attribute tem de ter menos de :value elementos.',
        'file' => 'O ficheiro :attribute tem de ter menos de :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser menor que :value.',
        'string' => 'O campo :attribute tem de ter menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'O campo :attribute não pode ter mais de :value elementos.',
        'file' => 'O ficheiro :attribute tem de ter :value kilobytes ou menos.',
        'numeric' => 'O campo :attribute tem de ser menor ou igual a :value.',
        'string' => 'O campo :attribute tem de ter :value caracteres ou menos.',
    ],
    'mac_address' => 'O campo :attribute tem de ser um endereço MAC válido.',
    'max' => [
        'array' => 'O campo :attribute não pode ter mais de :max elementos.',
        'file' => 'O ficheiro :attribute não pode ter mais de :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
        'string' => 'O campo :attribute não pode ter mais de :max caracteres.',
    ],
    'max_digits' => 'O campo :attribute não pode ter mais de :max dígitos.',
    'mimes' => 'O ficheiro :attribute tem de ser do tipo: :values.',
    'mimetypes' => 'O ficheiro :attribute tem de ser do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute tem de ter pelo menos :min elementos.',
        'file' => 'O ficheiro :attribute tem de ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute tem de ser pelo menos :min.',
        'string' => 'O campo :attribute tem de ter pelo menos :min caracteres.',
    ],
    'min_digits' => 'O campo :attribute tem de ter pelo menos :min dígitos.',
    'missing' => 'O campo :attribute não pode ser enviado.',
    'missing_if' => 'O campo :attribute não pode ser enviado quando :other é :value.',
    'missing_unless' => 'O campo :attribute não pode ser enviado, exceto quando :other é :value.',
    'missing_with' => 'O campo :attribute não pode ser enviado quando :values está presente.',
    'missing_with_all' => 'O campo :attribute não pode ser enviado quando :values estão presentes.',
    'multiple_of' => 'O campo :attribute tem de ser múltiplo de :value.',
    'not_in' => 'O valor selecionado para :attribute é inválido.',
    'not_regex' => 'O formato do campo :attribute é inválido.',
    'numeric' => 'O campo :attribute tem de ser um número.',
    'password' => [
        'letters' => 'O campo :attribute tem de conter pelo menos uma letra.',
        'mixed' => 'O campo :attribute tem de conter pelo menos uma letra maiúscula e uma minúscula.',
        'numbers' => 'O campo :attribute tem de conter pelo menos um número.',
        'symbols' => 'O campo :attribute tem de conter pelo menos um símbolo.',
        'uncompromised' => 'O valor de :attribute apareceu numa fuga de dados. Escolha outro valor para :attribute.',
    ],
    'portuguese_nif' => 'O campo :attribute tem de ser um NIF português válido.',
    'portuguese_phone' => 'O campo :attribute tem de ser um número de telefone português válido.',
    'present' => 'O campo :attribute tem de estar presente.',
    'present_if' => 'O campo :attribute tem de estar presente quando :other é :value.',
    'present_unless' => 'O campo :attribute tem de estar presente, exceto quando :other é :value.',
    'present_with' => 'O campo :attribute tem de estar presente quando :values está presente.',
    'present_with_all' => 'O campo :attribute tem de estar presente quando :values estão presentes.',
    'prohibited' => 'O campo :attribute não é permitido.',
    'prohibited_if' => 'O campo :attribute não é permitido quando :other é :value.',
    'prohibited_if_accepted' => 'O campo :attribute não é permitido quando :other é aceite.',
    'prohibited_if_declined' => 'O campo :attribute não é permitido quando :other é recusado.',
    'prohibited_unless' => 'O campo :attribute não é permitido, exceto quando :other está em :values.',
    'prohibits' => 'O campo :attribute impede que :other seja enviado.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_array_keys' => 'O campo :attribute tem de conter valores para: :values.',
    'required_if' => 'O campo :attribute é obrigatório quando :other é :value.',
    'required_if_accepted' => 'O campo :attribute é obrigatório quando :other é aceite.',
    'required_if_declined' => 'O campo :attribute é obrigatório quando :other é recusado.',
    'required_unless' => 'O campo :attribute é obrigatório, exceto quando :other está em :values.',
    'required_with' => 'O campo :attribute é obrigatório quando :values está presente.',
    'required_with_all' => 'O campo :attribute é obrigatório quando :values estão presentes.',
    'required_without' => 'O campo :attribute é obrigatório quando :values não está presente.',
    'required_without_all' => 'O campo :attribute é obrigatório quando nenhum de :values está presente.',
    'same' => 'O campo :attribute tem de corresponder a :other.',
    'size' => [
        'array' => 'O campo :attribute tem de ter :size elementos.',
        'file' => 'O ficheiro :attribute tem de ter :size kilobytes.',
        'numeric' => 'O campo :attribute tem de ser :size.',
        'string' => 'O campo :attribute tem de ter :size caracteres.',
    ],
    'starts_with' => 'O campo :attribute tem de começar com: :values.',
    'string' => 'O campo :attribute tem de ser texto.',
    'timezone' => 'O campo :attribute tem de ser um fuso horário válido.',
    'unique' => 'O valor do campo :attribute já está a ser utilizado.',
    'uploaded' => 'Falhou o envio do ficheiro :attribute.',
    'uppercase' => 'O campo :attribute tem de estar em maiúsculas.',
    'url' => 'O campo :attribute tem de ser um URL válido.',
    'ulid' => 'O campo :attribute tem de ser um ULID válido.',
    'uuid' => 'O campo :attribute tem de ser um UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensagens específicas por campo
    |--------------------------------------------------------------------------
    |
    | Formato "campo.regra" => "mensagem".
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Nomes dos campos
    |--------------------------------------------------------------------------
    |
    | Substituem :attribute nas mensagens por um nome legível em pt-PT.
    |
    */

    'attributes' => [
        'name' => 'nome profissional',
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'confirmação da password',
        'current_password' => 'password atual',
        'phone' => 'telefone',
        'business_name' => 'nome da empresa',
        'sector_id' => 'setor',
        'location_id' => 'localização',
        'congregation' => 'congregação',
        'role_in_congregation' => 'função na congregação',
        'description' => 'descrição',
        'website_url' => 'website',
        'commercial_contacts' => 'contactos comerciais',
        'address' => 'morada',
        'business_hours' => 'horário de funcionamento',
        'business_hours.*.week_day_id' => 'dia da semana',
        'business_hours.*.open_time' => 'hora de abertura',
        'business_hours.*.close_time' => 'hora de fecho',
        'social_links' => 'redes sociais',
        'social_links.*.platform_id' => 'plataforma',
        'social_links.*.url' => 'URL da rede social',
        'logo' => 'logótipo',
        'image' => 'imagem',
        'nif' => 'NIF',
        'billing_name' => 'nome de faturação',
        'billing_address' => 'morada de faturação',
        'billing_postal_code' => 'código postal',
        'billing_city' => 'localidade',
    ],

];
