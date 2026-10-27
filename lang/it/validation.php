<?php

return [
    'array' => 'Il campo :attribute deve contenere un elenco valido.',
    'between' => ['numeric' => 'Il campo :attribute deve essere compreso tra :min e :max.'],
    'confirmed' => 'La conferma del campo :attribute non corrisponde.',
    'current_password' => 'La password attuale non è corretta.',
    'email' => 'Il campo :attribute deve contenere un indirizzo email valido.',
    'integer' => 'Il campo :attribute deve essere un numero intero.',
    'max' => ['string' => 'Il campo :attribute non può superare :max caratteri.'],
    'min' => ['string' => 'Il campo :attribute deve contenere almeno :min caratteri.'],
    'required' => 'Il campo :attribute è obbligatorio.',
    'string' => 'Il campo :attribute deve essere un testo.',
    'unique' => 'Il valore del campo :attribute è già in uso.',
    'password' => [
        'letters' => 'La password deve contenere almeno una lettera.',
        'mixed' => 'La password deve contenere almeno una lettera maiuscola e una minuscola.',
        'numbers' => 'La password deve contenere almeno un numero.',
        'symbols' => 'La password deve contenere almeno un simbolo.',
        'uncompromised' => 'Questa password è comparsa in una violazione di dati. Scegline una diversa.',
    ],
    'attributes' => [
        'name' => 'nome',
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'conferma password',
        'current_password' => 'password attuale',
        'code' => 'codice',
        'recovery_code' => 'codice di recupero',
    ],
];
