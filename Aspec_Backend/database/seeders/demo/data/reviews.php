<?php

/**
 * Dados de demonstração (ASPEC-466): avaliações entre perfis de demonstração. Comentários fictícios em
 * português de Portugal, sem marcas. Chaves = chave do email (membro05 → demo.membro05@aspec.test).
 *
 * - visible: [autor, avaliado, estrelas, dias atrás, comentário]. O seeder nunca põe a data antes da
 *   criação da conta do autor nem da do avaliado (puxa-a para o dia seguinte à mais recente).
 * - moderated: avaliações com estado Flagged/Hidden ou com denúncias já resolvidas, com as linhas de
 *   review_reports como a API as grava. source automatic = sinalização do filtro de linguagem
 *   (reason language_filter); source member = denúncia de um membro (por omissão a empresa avaliada).
 *   resolved = denúncia já tratada pelo admin.
 */
return [
    'visible' => [
        // demo.admin: Mendes Consultoria de Gestão (Lisboa)
        ['membro16', 'admin', 5, 160, 'Ajudaram-nos a reorganizar a empresa depois da saída de um sócio. Muito práticos e sempre disponíveis.'],
        ['membro23', 'admin', 5, 120, 'Plano de negócio claro e realista, que o banco aceitou à primeira.'],
        ['membro18', 'admin', 4, 75, 'Bons conselhos sobre preços e margens. As reuniões às vezes alongavam-se mais do que o necessário.'],
        ['membro35', 'admin', 5, 33, 'Sessões de acompanhamento mensais que mudaram a forma como olho para os números da clínica.'],

        // demo.membro: Ferreira Arquitetura e Interiores (Porto); a sinalizada e a oculta estão em moderated
        ['membro02', 'membro', 5, 6, 'Projeto de remodelação do nosso escritório impecável, cumpriram prazos e orçamento.'],
        ['membro05', 'membro', 5, 4, 'Muito profissionais, ouviram as nossas ideias e melhoraram-nas.'],
        ['membro07', 'membro', 4, 140, 'Bom trabalho de interiores; a obra atrasou uma semana, mas avisaram com tempo.'],
        ['membro51', 'membro', 5, 95, 'Transformaram um T2 antigo num apartamento que vendemos em duas semanas.'],
        ['membro29', 'membro', 4, 52, 'Trabalhámos juntas num projeto de loja. Muito rigor nos desenhos e boa comunicação com os empreiteiros.'],

        // demo.membro01: Carvalho & Filhos Construções (Braga, em trial)
        ['membro06', 'membro01', 4, 5, 'Obra bem executada e equipa organizada.'],
        ['membro10', 'membro01', 3, 3, 'Trabalho correto, mas a comunicação durante a obra podia ser melhor.'],

        // demo.membro02: Contas Certas Contabilidade (Coimbra, em trial)
        ['membro', 'membro02', 5, 8, 'Contabilidade sempre em dia e respostas rápidas às dúvidas.'],
        ['membro03', 'membro02', 5, 6, 'Ajudaram-nos a organizar as contas do restaurante desde o primeiro mês.'],
        ['membro04', 'membro02', 5, 4, 'Explicam tudo com clareza e sem termos técnicos.'],

        // demo.membro03: Sabores do Algarve Restaurante (Faro, em trial)
        ['membro', 'membro03', 4, 7, 'Excelente jantar de equipa, peixe muito fresco.'],
        ['membro53', 'membro03', 4, 5, 'Boa comida e atendimento simpático; ao fim de semana convém reservar.'],
        ['membro09', 'membro03', 5, 3, 'Cataplana memorável, voltaremos com clientes.'],

        // demo.membro05: Martins Advogados Associados (Leiria, em trial)
        ['membro36', 'membro05', 5, 6, 'Resolveram um problema com um contrato de fornecimento que se arrastava há meses.'],
        ['membro24', 'membro05', 4, 3, 'Atendimento cuidadoso e honorários explicados à partida.'],

        // demo.membro06: Clínica Bem Cuidar (Setúbal, em trial)
        ['membro01', 'membro06', 3, 4, 'Atendimento simpático, mas esperei bastante para a consulta.'],
        ['membro04', 'membro06', 4, 3, 'Clínica limpa e profissionais atenciosos.'],
        ['membro28', 'membro06', 3, 2, 'Razoável; a marcação por telefone é difícil.'],

        // demo.membro07: Rota Segura Transportes (Santarém)
        ['membro36', 'membro07', 5, 170, 'Fazem as entregas da nossa fruta para Lisboa há mais de um ano sem uma falha.'],
        ['membro46', 'membro07', 4, 110, 'Pontuais e cuidadosos com a carga. O preço subiu este ano, mas continuam competitivos.'],
        ['membro27', 'membro07', 2, 45, 'Duas entregas chegaram com dias de atraso e ninguém nos avisou. Depois de reclamarmos, melhoraram.'],

        // demo.membro09: Ideia Viva Comunicação (Lisboa, em trial)
        ['membro18', 'membro09', 5, 5, 'Fizeram a nova imagem da mercearia com muito bom gosto e respeitaram a história da loja.'],
        ['membro31', 'membro09', 4, 2, 'Campanha bem pensada; os relatórios mensais podiam ser mais simples.'],

        // demo.membro13: Lacerda Reabilitação Urbana (Lisboa)
        ['membro26', 'membro13', 5, 150, 'Parceiros de confiança em reabilitação. Seguem o projeto ao pormenor e resolvem imprevistos sem dramas.'],
        ['membro51', 'membro13', 4, 100, 'Boa obra num prédio antigo com muitas limitações. A limpeza no fim deixou a desejar.'],
        ['membro23', 'membro13', 5, 60, 'Recuperaram o nosso escritório num edifício pombalino sem nunca pararmos de trabalhar.'],

        // demo.membro14: Vértice Sistemas de Informação (Lisboa)
        ['admin', 'membro14', 5, 175, 'Migraram todos os nossos sistemas para a nuvem num fim de semana. Na segunda-feira estava tudo a funcionar.'],
        ['membro23', 'membro14', 5, 130, 'Apoio informático rápido e paciente com quem não percebe nada de tecnologia.'],
        ['membro35', 'membro14', 5, 85, 'Software de marcações à medida da clínica, entregue no prazo e com formação à equipa.'],
        ['membro31', 'membro14', 5, 11, 'Resolveram um problema de segurança no nosso email no próprio dia.'],

        // demo.membro15: Ribeira Lima Imobiliária (Viana do Castelo)
        ['membro22', 'membro15', 4, 130, 'Venderam a casa dos meus pais com cuidado e muita paciência com a família.'],
        ['membro45', 'membro15', 3, 70, 'Simpáticos, mas o processo demorou mais do que o prometido.'],
        ['membro30', 'membro15', 4, 25, 'Bom conhecimento do mercado da região e avaliação realista.'],

        // demo.membro16: Sado Gestão e Estratégia (Setúbal)
        ['membro28', 'membro16', 5, 165, 'Ajudaram-nos a definir prioridades para o ano. Simples, direto e útil.'],
        ['membro13', 'membro16', 4, 80, 'Boa análise de custos; algumas recomendações eram difíceis de aplicar numa empresa pequena.'],
        ['membro14', 'membro16', 5, 30, 'Facilitaram a reunião anual de estratégia da nossa equipa com muito profissionalismo.'],

        // demo.membro17: Amaral Consultores (Coimbra)
        ['membro19', 'membro17', 4, 90, 'Ajudaram o centro de estudos a preparar uma candidatura a apoios. Trabalho cuidado.'],
        ['membro02', 'membro17', 5, 5, 'Excelente parceiro para clientes que precisam de reorganizar a empresa.'],

        // demo.membro18: Mercearia da Estrela (Lisboa)
        ['membro33', 'membro18', 5, 25, 'Os melhores produtos regionais do bairro e um atendimento que já não se encontra.'],
        ['membro26', 'membro18', 4, 55, 'Boa seleção de queijos e enchidos. Os preços não são os mais baixos, mas a qualidade compensa.'],
        ['membro35', 'membro18', 5, 3, 'Encomendámos um cabaz para a equipa e chegou no próprio dia, muito bem apresentado.'],

        // demo.membro19: Centro de Estudos Mondego (Coimbra)
        ['membro17', 'membro19', 5, 150, 'Os meus dois filhos melhoraram imenso a matemática. Professores próximos e exigentes.'],
        ['membro02', 'membro19', 4, 7, 'Bom acompanhamento e comunicação regular com os pais.'],
        ['membro47', 'membro19', 3, 60, 'Bons professores, mas as salas são pequenas para o número de alunos.'],

        // demo.membro20: Sampaio Engenharia de Climatização (Lisboa)
        ['membro13', 'membro20', 4, 115, 'Parceiro habitual nas nossas obras. Cumprem, embora às vezes falte gente para tantas obras.'],
        ['membro35', 'membro20', 5, 65, 'Resolveram um problema de ventilação na clínica que outros não conseguiram.'],
        ['membro18', 'membro20', 3, 14, 'O trabalho ficou bem feito, mas tivemos de insistir várias vezes para marcar a visita.'],

        // demo.membro21: Atelier Granito (Viseu)
        ['membro29', 'membro21', 5, 170, 'Projeto de habitação com muita sensibilidade para os materiais da região.'],
        ['membro43', 'membro21', 5, 120, 'Desenharam a remodelação da taberna sem lhe tirar a alma. Os clientes notaram logo.'],
        ['membro58', 'membro21', 4, 65, 'Bom projeto e boa relação com a câmara no licenciamento.'],

        // demo.membro22: Traço Fino Design e Comunicação (Braga)
        ['membro27', 'membro22', 5, 140, 'Criaram o logótipo e a montra da retrosaria. Ficou bonito e com a nossa cara.'],
        ['membro30', 'membro22', 4, 95, 'Bons designers. Os prazos são apertados quando têm muitos clientes.'],
        ['membro45', 'membro22', 5, 22, 'Material de apresentação do escritório feito com muito cuidado e rapidez.'],

        // demo.membro23: Albuquerque Leal Advogados (Lisboa)
        ['admin', 'membro23', 5, 155, 'Apoio jurídico sério na entrada de um novo sócio. Tudo explicado ponto a ponto.'],
        ['membro14', 'membro23', 4, 90, 'Contratos de prestação de serviços bem redigidos. Respostas por vezes demoradas.'],
        ['membro31', 'membro23', 5, 38, 'Acompanharam uma negociação difícil com um fornecedor e chegámos a um acordo justo.'],

        // demo.membro25: Casa de Pasto O Lagar (Madeira)
        ['membro32', 'membro25', 5, 180, 'Mandamos para lá todos os nossos hóspedes. Comida caseira e um acolhimento impecável.'],
        ['membro38', 'membro25', 5, 135, 'A melhor espetada que comi na ilha, servida com simpatia.'],
        ['membro44', 'membro25', 4, 90, 'Excelente comida tradicional. Ao almoço é preciso paciência porque enche depressa.'],
        ['membro33', 'membro25', 4, 12, 'Muito bom; a sobremesa da casa vale a visita.'],

        // demo.membro26: Nogueira e Matos Arquitetos (Lisboa)
        ['membro13', 'membro26', 5, 100, 'Projeto de reabilitação com soluções inteligentes para um prédio muito estreito.'],
        ['membro51', 'membro26', 4, 15, 'Boa arquitetura e preocupação com o orçamento do cliente.'],

        // demo.membro27: Retrosaria Domingues (Braga)
        ['membro22', 'membro27', 5, 160, 'Uma loja como já há poucas, com um atendimento que conhece cada cliente pelo nome.'],
        ['membro45', 'membro27', 4, 70, 'Boa variedade e preços honestos. Horário de almoço um pouco longo.'],
        ['membro48', 'membro27', 5, 28, 'Encomenda de fitas e botões enviada para Santarém em dois dias, muito bem embalada.'],

        // demo.membro28: Esteves & Rebelo Contabilidade (Setúbal)
        ['membro16', 'membro28', 5, 175, 'Contabilidade organizada e alertas atempados sobre prazos fiscais.'],
        ['membro46', 'membro28', 4, 120, 'Serviço sólido há muitos anos. Gostava de ter os relatórios mais cedo no mês.'],
        ['membro13', 'membro28', 3, 21, 'Competentes, mas difíceis de contactar em época de entrega de declarações.'],

        // demo.membro29: Ria Interiores (Aveiro)
        ['membro41', 'membro29', 5, 100, 'Projeto de interiores do nosso escritório: funcional, luminoso e dentro do orçamento.'],
        ['membro37', 'membro29', 4, 20, 'Bom gosto e boas sugestões de materiais. A montagem de mobiliário atrasou alguns dias.'],
        ['membro', 'membro29', 5, 150, 'Parceria excelente num projeto de loja. Cumprem o que prometem.'],

        // demo.membro30: Magalhães Consultores (Braga)
        ['membro27', 'membro30', 4, 150, 'Ajudaram a retrosaria a vender online sem perder os clientes da loja.'],
        ['membro45', 'membro30', 5, 105, 'Consultoria séria e com os pés na terra. Muito recomendável.'],
        ['membro55', 'membro30', 3, 60, 'Algumas boas ideias, mas a proposta inicial era demasiado genérica.'],

        // demo.membro31: Henriques Gestão Financeira (Lisboa)
        ['membro18', 'membro31', 5, 80, 'Pôs ordem na tesouraria da mercearia e ensinou-me a prever os meses fracos.'],
        ['membro33', 'membro31', 4, 6, 'Bom apoio na preparação do pedido de financiamento.'],

        // demo.membro32: Quinta das Levadas Alojamento (Madeira)
        ['membro25', 'membro32', 5, 170, 'Recomendamos sempre aos nossos clientes. Alojamento tranquilo e anfitriões muito atentos.'],
        ['membro38', 'membro32', 5, 115, 'Um sítio para descansar a sério, com uma vista incrível sobre a serra.'],
        ['membro44', 'membro32', 4, 60, 'Muito agradável; o acesso de carro é estreito e convém chegar de dia.'],

        // demo.membro35: Clínica Valente Fisioterapia (Lisboa)
        ['membro14', 'membro35', 5, 150, 'Recuperei de uma lesão nas costas em poucas semanas. Profissionais muito competentes.'],
        ['membro20', 'membro35', 4, 55, 'Bom tratamento; o estacionamento na zona é complicado.'],
        ['membro33', 'membro35', 5, 4, 'Ajudaram-me depois de uma entorse. Recomendo sem reservas.'],

        // demo.membro36: Pomares Guerreiro (Leiria)
        ['membro24', 'membro36', 5, 40, 'Fruta de qualidade, colhida no ponto certo. Os cabazes para a empresa são sempre um sucesso.'],
        ['membro46', 'membro36', 4, 125, 'Bons produtos e preços justos. A entrega podia ter horários mais flexíveis.'],

        // demo.membro38: Hortênsia Estética (Açores)
        ['membro32', 'membro38', 5, 175, 'Um espaço calmo e profissionais atenciosas. Saí como nova.'],
        ['membro44', 'membro38', 4, 70, 'Serviço muito bom; a marcação online seria uma ajuda.'],
        ['membro48', 'membro38', 5, 30, 'Partilhamos clientes e ideias há algum tempo. Trabalho sempre cuidadoso.'],

        // demo.membro39: Centro de Estudos Lacerda (Porto)
        ['membro42', 'membro39', 5, 150, 'Os explicadores ajudaram a minha filha a entrar no curso que queria.'],
        ['membro52', 'membro39', 4, 85, 'Bom acompanhamento e boas instalações. Os preços subiram este ano.'],
        ['membro40', 'membro39', 4, 25, 'Ambiente de estudo tranquilo e professores dedicados.'],

        // demo.membro40: Consultório Dentário Morais Tavares (Porto)
        ['membro42', 'membro40', 5, 140, 'Fui com medo e saí tranquilo. Muito cuidado e boas explicações.'],
        ['membro54', 'membro40', 5, 30, 'Atendem a família toda há anos. Sempre impecáveis.'],

        // demo.membro41: Xavier Engenharia e Automação (Aveiro)
        ['membro37', 'membro41', 5, 20, 'Automatizaram uma linha de corte da metalúrgica com resultados visíveis logo no primeiro mês.'],
        ['membro29', 'membro41', 4, 75, 'Bom projeto de domótica para um cliente nosso. Documentação muito completa.'],

        // demo.membro42: Transportes Campos Rebelo (Porto)
        ['membro54', 'membro42', 5, 150, 'Transporte de materiais para as nossas obras sempre a horas.'],
        ['membro52', 'membro42', 4, 90, 'Serviço fiável; o seguimento das encomendas podia ser mais fácil.'],
        ['membro40', 'membro42', 3, 30, 'Entregas corretas, mas a faturação teve erros duas vezes seguidas.'],

        // demo.membro43: Taberna da Serra (Vila Real)
        ['membro21', 'membro43', 5, 160, 'Cabrito assado como em casa da avó. Voltamos sempre que passamos em Vila Real.'],
        ['membro44', 'membro43', 4, 50, 'Comida excelente e doses generosas. O serviço demorou num sábado cheio.'],
        ['membro55', 'membro43', 5, 5, 'Vale a viagem. Vinhos da região muito bem escolhidos.'],

        // demo.membro44: Rotas do Baixo Alentejo (Beja)
        ['membro11', 'membro44', 5, 8, 'Levaram o nosso grupo de clientes a conhecer a planície. Guia simpático e muito bem preparado.'],
        ['membro47', 'membro44', 4, 95, 'Passeio muito interessante pela história da região. Faltou só uma pausa mais longa ao almoço.'],
        ['membro32', 'membro44', 5, 45, 'Programa à medida para os nossos hóspedes que queriam conhecer o Alentejo.'],

        // demo.membro45: Rebelo Pinheiro & Associados (Braga)
        ['membro30', 'membro45', 5, 140, 'Apoio jurídico rigoroso na compra das novas instalações.'],
        ['membro27', 'membro45', 4, 75, 'Resolveram um problema de arrendamento com bom senso.'],
        ['membro22', 'membro45', 4, 28, 'Contratos claros e honorários bem explicados.'],

        // demo.membro46: Drogaria Matos (Setúbal)
        ['membro28', 'membro46', 5, 165, 'Encontra-se de tudo e dão sempre o conselho certo.'],
        ['membro47', 'membro46', 4, 70, 'Bom atendimento; fecham cedo ao sábado.'],
        ['membro57', 'membro46', 5, 3, 'Tintas e materiais de qualidade e bons conselhos para cada obra.'],

        // demo.membro47: Calado & Vieira Fisioterapia (Évora)
        ['membro44', 'membro47', 5, 90, 'Tratamento muito profissional depois de uma cirurgia ao ombro.'],
        ['membro11', 'membro47', 4, 6, 'Equipa simpática e horários flexíveis para quem trabalha no campo.'],

        // demo.membro48: Atelier Sofia Resende (Santarém)
        ['membro38', 'membro48', 5, 150, 'Trabalho de maquilhagem para noivas muito bonito. Recomendo.'],
        ['membro07', 'membro48', 4, 95, 'Bom serviço e ambiente acolhedor.'],
        ['membro27', 'membro48', 5, 20, 'Uma profissional muito cuidadosa e pontual.'],

        // demo.membro51: Casa Machado Mediação Imobiliária (Porto)
        ['membro', 'membro51', 5, 160, 'Parceiros de confiança: avaliações realistas e honestidade com os compradores.'],
        ['membro40', 'membro51', 4, 55, 'Ajudaram-me a encontrar o espaço para o consultório. Processo bem acompanhado.'],
        ['membro42', 'membro51', 3, 15, 'Atendimento simpático, mas as visitas nem sempre eram confirmadas a tempo.'],

        // demo.membro52: Nó Cego Software (Porto)
        ['membro42', 'membro52', 5, 165, 'Desenvolveram a aplicação de gestão da frota. Funciona sem falhas há meses.'],
        ['membro51', 'membro52', 4, 60, 'Equipa competente. O projeto demorou mais um mês do que o previsto.'],
        ['membro40', 'membro52', 5, 9, 'Muito bom apoio depois da entrega, respondem no próprio dia.'],

        // demo.membro54: Freitas Carneiro Engenharia e Construção (Porto)
        ['membro51', 'membro54', 5, 150, 'Construíram um prédio de habitação que vendemos todo antes do fim da obra.'],
        ['membro21', 'membro54', 4, 80, 'Bom construtor, cumpre o projeto. A comunicação nas alterações podia ser mais rápida.'],
        ['membro42', 'membro54', 2, 35, 'Pagamentos das nossas faturas de transporte sempre com muito atraso.'],

        // demo.membro55: Raiano Digital Informática (Castelo Branco)
        ['membro30', 'membro55', 5, 120, 'Instalaram a rede do escritório e dão assistência remota muito rápida.'],
        ['membro58', 'membro55', 4, 60, 'Bom serviço técnico e preços justos para o interior.'],
        ['membro24', 'membro55', 4, 16, 'Parceiros fiáveis em projetos maiores. Recomendo.'],

        // demo.membro58: Sampaio Consultores (Bragança)
        ['membro43', 'membro58', 4, 90, 'Ajudaram a taberna a candidatar-se a um apoio à modernização.'],
        ['membro55', 'membro58', 5, 20, 'Conhecem muito bem as empresas do interior e os seus problemas.'],

        // Notas baixas visíveis, em perfis com outras avaliações boas
        ['membro41', 'membro37', 1, 40, 'Encomenda de peças entregue com medidas erradas e sem solução durante semanas.'],
        ['membro29', 'membro37', 4, 12, 'Serralharia de qualidade para um projeto de interiores. Bom acabamento.'],
        ['membro20', 'membro37', 5, 25, 'Estruturas metálicas feitas com muito rigor e entregues no dia combinado.'],
        ['membro13', 'membro37', 4, 8, 'Bom fornecedor para obra. Recomendo.'],
        ['membro45', 'membro23', 2, 50, 'Experiência que não correu bem: o processo arrastou-se e senti falta de acompanhamento.'],
        ['membro35', 'membro25', 1, 70, 'Fomos mal atendidos num jantar de grupo e esperámos mais de uma hora pelos pratos.'],
        ['membro24', 'membro14', 2, 140, 'Proposta interessante, mas nunca recebemos resposta às dúvidas sobre o contrato.'],
        ['membro28', 'membro20', 2, 95, 'A manutenção anual foi adiada três vezes.'],
        ['membro48', 'membro32', 3, 35, 'Alojamento bonito, mas a limpeza do quarto não estava ao nível do resto.'],
        ['membro55', 'membro42', 2, 10, 'Uma entrega urgente falhou e não nos avisaram a tempo.'],
        ['membro26', 'membro21', 3, 8, 'Bom projeto, mas o orçamento final ficou muito acima do previsto.'],
    ],

    'moderated' => [
        // demo.membro: a sinalizada (filtro + a própria empresa) e a oculta (justificação do admin)
        [
            'by' => 'membro11', 'to' => 'membro', 'rating' => 1, 'days_ago' => 5, 'status' => 'Flagged',
            'comment' => 'Nunca mais! Cambada de vigaristas, fujam desta empresa!',
            'reports' => [
                ['source' => 'automatic'],
                ['source' => 'member', 'reason' => 'offensive_or_disrespectful_content', 'comment' => 'Nunca trabalhámos com esta empresa e a avaliação é ofensiva para a nossa equipa.'],
            ],
        ],
        [
            'by' => 'membro08', 'to' => 'membro', 'rating' => 1, 'days_ago' => 3, 'status' => 'Hidden',
            'comment' => 'Péssimos, só querem dinheiro. Serviço de porcaria, não percam tempo com esta gente.',
            'hidden_reason' => 'Linguagem desrespeitosa e sem relação comercial demonstrada.',
            'reports' => [['source' => 'automatic', 'resolved' => true]],
        ],

        // Mais duas sinalizadas pelo filtro de linguagem, à espera do admin
        [
            'by' => 'membro53', 'to' => 'membro57', 'rating' => 1, 'days_ago' => 2, 'status' => 'Flagged',
            'comment' => 'Um trabalho que é puro lixo: deixaram a obra a meio. Não recomendo a ninguém.',
            'reports' => [['source' => 'automatic']],
        ],
        [
            'by' => 'membro42', 'to' => 'membro07', 'rating' => 1, 'days_ago' => 4, 'status' => 'Flagged',
            'comment' => 'O motorista foi um imbecil com a nossa equipa e ainda perderam metade da carga.',
            'reports' => [['source' => 'automatic']],
        ],

        // Duas sinalizadas só por denúncia de um membro (o texto passa no filtro)
        [
            'by' => 'membro34', 'to' => 'membro09', 'rating' => 1, 'days_ago' => 1, 'status' => 'Flagged',
            'comment' => 'Copiaram a nossa campanha e apresentaram-na como trabalho original.',
            'reports' => [['source' => 'member', 'reason' => 'false_or_unsubstantiated_claims', 'comment' => 'Não temos nenhuma relação com esta empresa e a acusação é falsa. Pedimos que seja retirada.']],
        ],
        [
            'by' => 'membro49', 'to' => 'membro22', 'rating' => 2, 'days_ago' => 6, 'status' => 'Flagged',
            'comment' => 'Visitem antes a nossa agência: fazemos o mesmo trabalho por metade do preço.',
            'reports' => [['source' => 'member', 'reason' => 'spam_or_advertising', 'comment' => 'É publicidade a outra empresa, não uma avaliação.']],
        ],

        // Mais três ocultas pelo admin, com a justificação que o avaliado vê
        [
            'by' => 'membro50', 'to' => 'membro23', 'rating' => 1, 'days_ago' => 30, 'status' => 'Hidden',
            'comment' => 'Escritório concorrente que só sabe atrasar processos. Escolham outro.',
            'hidden_reason' => 'Avaliação de uma empresa concorrente, sem relação comercial com o avaliado.',
            'reports' => [['source' => 'member', 'reason' => 'no_business_relationship', 'comment' => 'Somos concorrentes diretos e nunca fomos clientes um do outro.', 'resolved' => true]],
        ],
        [
            'by' => 'membro57', 'to' => 'membro13', 'rating' => 1, 'days_ago' => 4, 'status' => 'Hidden',
            'comment' => 'Contactem-nos para obras de pintura com desconto especial para membros.',
            'hidden_reason' => 'Publicidade, não é uma avaliação do serviço.',
            'reports' => [['source' => 'member', 'reason' => 'spam_or_advertising', 'resolved' => true]],
        ],
        [
            'by' => 'membro24', 'to' => 'membro41', 'rating' => 1, 'days_ago' => 15, 'status' => 'Hidden',
            'comment' => 'Tratei com um desgraçado sem palavra. Uma vergonha para o setor.',
            'hidden_reason' => 'Linguagem ofensiva.',
            'reports' => [['source' => 'automatic', 'resolved' => true]],
        ],

        // Duas visíveis com denúncias já resolvidas pelo admin (mantidas)
        [
            'by' => 'membro47', 'to' => 'membro35', 'rating' => 3, 'days_ago' => 40, 'status' => 'Visible',
            'comment' => 'O tratamento resultou, mas tive de remarcar duas consultas por falta de fisioterapeutas.',
            'reports' => [['source' => 'member', 'reason' => 'false_or_unsubstantiated_claims', 'comment' => 'Nunca desmarcámos consultas a esta pessoa.', 'resolved' => true]],
        ],
        [
            'by' => 'membro58', 'to' => 'membro30', 'rating' => 3, 'days_ago' => 55, 'status' => 'Visible',
            'comment' => 'Bons consultores, mas cobraram uma reunião que pensávamos estar incluída.',
            'reports' => [['source' => 'member', 'reason' => 'other', 'comment' => 'A reunião estava descrita na proposta aceite pelo cliente.', 'resolved' => true]],
        ],
    ],
];
