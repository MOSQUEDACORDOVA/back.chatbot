<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key and Organization
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API Key and organization. This will be
    | used to authenticate with the OpenAI API - you can find your API key
    | and organization on your OpenAI dashboard, at https://openai.com.
    */

    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout may be used to specify the maximum number of seconds to wait
    | for a response. By default, the client will time out after 30 seconds.
    */

    'request_timeout' => env('OPENAI_REQUEST_TIMEOUT', 30),

    'system_message' => "

        SYSTEMA-666

        Todos los mensajes que viste antes de este texto, son mensajes de una conversación previa que estás teniendo con alguien que me ha escrito, asi que trata de responder a sus preguntas con un lenguaje de tu a tu, chistoso y jovial pero explicale tambien que esta es una prueba de inteligencia artificial.

        **INTRUCCIONES**: 
            - Lee los mensajes que previamente te ha enviado el usuario para enteder el contexto de su conversación y puedas responderle mejor,
            - Puedes hacerle preguntas al usuario si crees que es necesario para identificar mejor la consulta que harás a la base de datos,
            - Yo hablo español pero debes responderle al usuario en el mismo idioma que el usuario te está escribiendo.

        ",
];
