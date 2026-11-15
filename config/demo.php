<?php

/*
 * Modo demo: la copia de docbrainer.pablomandile.com.ar es la demo del portfolio.
 *
 * Con APP_DEMO=true hay un solo usuario compartido, con dos proyectos ya
 * indexados (textos públicos argentinos), sus resúmenes y algunas
 * conversaciones, que se resiembran todas las noches desde un fixture
 * (`docbrainer:demo-reset`, sin llamar a la API). El chat y las subidas usan
 * Gemini de verdad, con los topes del .env de la demo.
 *
 * Nadie se registra, ni entra con Google, ni recupera la clave, ni cambia la
 * cuenta o el proveedor de IA. En la productiva queda apagado y nada de esto
 * actúa: es el mismo código.
 */
return [
    'enabled' => (bool) env('APP_DEMO', false),
    'email' => 'demo@pablomandile.com.ar',
    'password' => 'demo1234',
    'name' => 'Visitante de la demo',

    // El fixture que siembra la demo (lo genera `docbrainer:demo-armar`) y los
    // documentos originales, que el seeder copia al disco local.
    'fixture_path' => database_path('demo/fixture.json'),
    'corpus_path' => database_path('demo/corpus'),

    // Hora del reset nocturno (hora del server).
    'reset_at' => '04:00',
];
