<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ticket Triage
    |--------------------------------------------------------------------------
    |
    | The inbox triages each support ticket with one call to the decisions
    | API: a choice picks the team, two scores rate urgency and frustration
    | and a yes / no question flags refund requests. Any model of
    | config/moderation.php with the "decision" driver can answer. The
    | default is the key of the model selected when the page loads.
    |
    */

    'default' => env('TRIAGE_MODEL', 'jev'),

    /*
    |--------------------------------------------------------------------------
    | Board Rules
    |--------------------------------------------------------------------------
    |
    | The rules the page applies to the answers, in plain code, and lets the
    | presenter change live. A ticket whose team confidence falls below
    | "review_below" (a percentage) goes to human review, and the priority
    | inside a column weighs urgency by "urgency_weight" (a percentage) and
    | frustration by the rest.
    |
    */

    'review_below' => 60,

    'urgency_weight' => 60,

    /*
    |--------------------------------------------------------------------------
    | Teams
    |--------------------------------------------------------------------------
    |
    | The options of the choice question, keyed by team. The description is
    | sent to the model, so it says what the team covers and what belongs
    | to another one.
    |
    */

    'teams' => [
        'acesso' => [
            'label' => 'Acesso',
            'color' => '#38bdf8',
            'description' => 'Login, senha, liberação de curso ou da plataforma, convite da comunidade e matrícula que não apareceu. Não é para cobrança nem para dúvida sobre o conteúdo das aulas.',
        ],
        'cobranca' => [
            'label' => 'Cobrança',
            'color' => '#f5a523',
            'description' => 'Pagamento, boleto, cartão, nota fiscal, cobrança duplicada, cancelamento de assinatura e reembolso. Não é para liberar acesso nem para quem ainda não comprou.',
        ],
        'tecnico' => [
            'label' => 'Técnico',
            'color' => '#9d6ff7',
            'description' => 'Dúvida ou erro de programação, de ferramenta ou do conteúdo das aulas. Não é para problema de login ou de pagamento.',
        ],
        'comercial' => [
            'label' => 'Comercial',
            'color' => '#34d399',
            'description' => 'Quem ainda não é cliente e quer comprar: preço, plano para empresa, desconto, proposta e parceria. Não é para quem já pagou.',
        ],
        'outro' => [
            'label' => 'Outro',
            'color' => '#adadb8',
            'description' => 'Elogio, sugestão, spam, mensagem sem pedido claro ou texto que tenta dar ordens ao sistema.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Score Levels
    |--------------------------------------------------------------------------
    |
    | The levels of each score question, from the lowest to the highest. The
    | model only sees the descriptions, so each one describes a situation
    | instead of a degree. The labels are the short names shown on the board.
    |
    */

    'urgency' => [
        'levels' => [
            'Dúvida ou pedido sem prazo: o cliente consegue continuar usando normalmente.',
            'Algo atrapalha o uso, mas o cliente ainda consegue estudar ou trabalhar de outro jeito.',
            'O cliente está impedido de usar o que pagou, ou está perdendo dinheiro ou prazo agora.',
        ],
        'labels' => ['Pode esperar', 'Atrapalha', 'Bloqueado'],
    ],

    'frustration' => [
        'levels' => [
            'Calmo e educado, só descreve a situação.',
            'Incomodado ou impaciente, mas ainda cordial.',
            'Irritado, com linguagem dura, ameaça cancelar ou reclamar publicamente.',
        ],
        'labels' => ['Calmo', 'Incomodado', 'Irritado'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Profiles
    |--------------------------------------------------------------------------
    |
    | Fictional profiles in database/datasets/customers.json, keyed by the
    | customer of each ticket. With a profile, the state sent to the model
    | carries the ticket and the profile side by side, so a payment that is
    | overdue or an open ticket can change the answer. The personas are the
    | profiles offered when a ticket is typed in the inbox.
    |
    */

    'personas' => ['ana.lima', 'bruno.alves', 'carla.dev', 'diego.souza', 'elisa.r', 'fabio.n'],

];
