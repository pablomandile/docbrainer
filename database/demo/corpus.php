<?php

/*
 * Qué tiene la demo: los proyectos, sus documentos (en database/demo/corpus,
 * todos de dominio público), el resumen de cada proyecto y las conversaciones
 * de ejemplo. `docbrainer:demo-armar` lo procesa con la IA de verdad y lo
 * congela en database/demo/fixture.json, que es lo que siembra el reset.
 *
 * Si se cambia algo acá, hay que volver a armar el fixture.
 */
return [
    [
        'name' => 'Constituciones argentinas',
        'description' => 'La Constitución Nacional vigente (con la reforma de 1994) y el texto original de 1853, para consultarlas y compararlas.',
        'documents' => [
            ['file' => 'constitucion-nacional-1994.pdf', 'title' => 'Constitución Nacional (1994)'],
            ['file' => 'constitucion-1853.md', 'title' => 'Constitución de la Confederación Argentina (1853)'],
        ],
        'summary' => ['length' => 'medio', 'style' => 'divulgativo', 'language' => 'es'],
        'conversations' => [
            [
                '¿Qué dice la Constitución sobre la libertad de prensa?',
                '¿Y el Congreso puede dictar leyes que la restrinjan?',
            ],
            ['¿Cómo se elegía al presidente en 1853 y cómo se elige desde la reforma de 1994?'],
        ],
    ],
    [
        'name' => 'Martín Fierro',
        'description' => 'El poema de José Hernández completo: «El gaucho Martín Fierro» (1872) y «La vuelta de Martín Fierro» (1879).',
        'documents' => [
            ['file' => 'martin-fierro-ida.md', 'title' => 'El gaucho Martín Fierro (1872)'],
            ['file' => 'martin-fierro-vuelta.md', 'title' => 'La vuelta de Martín Fierro (1879)'],
        ],
        'summary' => ['length' => 'medio', 'style' => 'divulgativo', 'language' => 'es'],
        'conversations' => [
            ['¿Qué consejos les da Martín Fierro a sus hijos?'],
            ['¿Quién es el viejo Vizcacha y qué consejos le da al hijo segundo de Martín Fierro?'],
        ],
    ],
];
