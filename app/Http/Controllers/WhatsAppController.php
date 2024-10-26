<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Twilio\Rest\Client as ClientWhatsApp;
use GuzzleHttp\Client;
use App\Models\ConversationHistory; // Modelo para la tabla del historial
use Illuminate\Support\Facades\Http; // Asegúrate de importar el facade Http

class WhatsAppController extends Controller
{

    public function receiveMessage(Request $request)
    {
        // Registrar toda la información que llega desde Baileys
        \Log::info('Datos recibidos desde Baileys: ' . json_encode($request->all()));
    
        // Verificar si el mensaje está presente
        if ($request->has('Body')) {
            $from = $request->input('From'); // Número de quien envía el mensaje
            $body = $request->input('Body'); // Cuerpo del mensaje recibido
    
            // Guardar el mensaje del usuario en la base de datos
            $this->storeMessage($from, 'user', $body);
    
            // Llamar a ChatGPT
            $this->chatGpt($body, $from);
    
            // Log de éxito
            \Log::info("Mensaje recibido de $from: $body");
            
            return response()->json(['status' => 'Message received and processed']);
        }
    
        // Log de no válido
        \Log::warning("Mensaje no válido recibido: " . json_encode($request->all()));
    
        return response()->json(['status' => 'No valid message received'], 400);
    }
    
    public function chatGpt(string $promt, string $from)
    {
        
        $apiKey = env('OPENAI_API_KEY');
        
        $client = new Client();
        // Obtener el historial de mensajes desde la base de datos
        $chatHistory = $this->getChatHistory($from);

        // Añadir el mensaje del sistema desde el archivo de configuración
        $principal_system_message = [
            'role' => 'system',
            'content' => config('openai.principal_system_message'), // Obtener el mensaje desde el archivo de configuración
        ];

        $system_message_informacion_de_los_productos = [
            'role' => 'system',
            'content' => config('openai.system_message_informacion_de_los_productos'), // Obtener el mensaje desde el archivo de configuración
        ];

        $objetivo_principal = [
            'role' => 'system',
            'content' => config('openai.objetivo_principal'), // Obtener el mensaje desde el archivo de configuración
        ];

        $instrucciones_principales = [
            'role' => 'system',
            'content' => config('openai.instrucciones_principales'), // Obtener el mensaje desde el archivo de configuración
        ];

        
        // Añadir el mensaje del sistema al historial de chat
        $chatHistory[] = $principal_system_message;
        $chatHistory[] = $system_message_informacion_de_los_productos;
        $chatHistory[] = $objetivo_principal;
        $chatHistory[] = $instrucciones_principales;

        // Añadir el nuevo mensaje del usuario al historial
        $chatHistory[] = ['role' => 'user', 'content' => $promt];

        // Generar log del historial de chat
       \Log::info('Historial de chat antes del primer promt de ChatGPT: ' . json_encode($chatHistory));


        try {
            $response = $client->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => 'gpt-4o', // Cambiar a 'gpt-4' si es necesario
                    'messages' => $chatHistory, // Enviar el historial completo
                ],
            ]);

            $responseData = json_decode($response->getBody(), true);
            $reply = $responseData['choices'][0]['message']['content'];

            // Registrar la respuesta para debugging
            \Log::info('Respuesta de ChatGPT: ' . $reply);

            // Verificar si ChatGPT solicitó una consulta a la base de datos
            if (preg_match('/\{query_database:"([^"]+)"\}/', $reply, $matches)) {
                $condition = $matches[1];
                \Log::info('Condición encontrada: ' . $condition);

                // Si la condición ya tiene operadores complejos, como < o >, no se aplica más procesamiento
                // Detectamos si el valor está entre comillas y solo procesamos las que tengan valores directos
                if (preg_match('/[\'"]/', $condition) === 0) {
                    // Si no tiene comillas simples, añadimos comillas alrededor de los valores no numéricos (valores de texto)
                    if (preg_match('/(\w+)\s*=\s*([^\s]+)/', $condition, $valueMatches)) {
                        $column = $valueMatches[1];
                        $value = $valueMatches[2];

                        // Si el valor no es un número, le añadimos comillas
                        if (!is_numeric($value)) {
                            $condition = "$column = '$value'";
                        }
                    }
                }

                \Log::info('Condición SQL ajustada: ' . $condition);

                try{
                    // Realizar la consulta a la base de datos
                    $data = \DB::table('properties')->whereRaw($condition)->get();

                    if ($data->isEmpty() || $data->count() === 0) {
                        $chatHistory[] = ['role' => 'system', 'content' => 'SYSTEMA-666: No tenemos esa información.'];
                        \Log::error('SYSTEMA-666: No tenemos esa información');

                    } else {
                        // Añadir los resultados obtenidos como un nuevo mensaje en el historial
                        $chatHistory[] = ['role' => 'system', 'content' => 'SYSTEMA-666: Esta es la información que tenemos:' . $data];
                        // Convertir $data a JSON para imprimirlo en el log
                        $dataJson = $data->toJson();
                        \Log::error('SYSTEMA-666: 111 Todo ok.'. $dataJson);
                    }

                } catch (\Exception $e) {
                    \Log::error('Error en la base de datos: ' . $e->getMessage());
                    $chatHistory[] = ['role' => 'system', 'content' => 'SYSTEMA-666: Dile al usuario que tienes un problema de conexion con nuestros servicios, Error 888 | Error en la base de datos.'];
                }
                

                // Volver a llamar a ChatGPT para que genere una respuesta utilizando esos datos
                $response = $client->request('POST', 'https://api.openai.com/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => 'gpt-4o',
                        'messages' => $chatHistory,
                    ],
                ]);
                $responseData = json_decode($response->getBody(), true);
                $reply = $responseData['choices'][0]['message']['content'];
                \Log::error('Igual ChatGPT le responde al usuario: '.$reply);
            }

            // Guardar la respuesta del asistente en la base de datos
            $this->storeMessage($from, 'assistant', $reply);

            // Enviar la respuesta vía WhatsApp
            $this->sendWhatsAppMessage($reply, $from);

            return response()->json(['reply' => $reply]);

        } catch (\Exception $e) {

            \Log::error('Error contacting OpenAI API: ' . $e->getMessage());
            
            $reply = "Lo siento, tu consulta es muy extensa, ¿podrias darme más detalles por favor?";
            
            // Guardar la respuesta del asistente en la base de datos
            $this->storeMessage($from, 'assistant', $reply);

            // Enviar la respuesta vía WhatsApp
            $this->sendWhatsAppMessage($reply, $from);

            return response()->json(['error' => 'Error al comunicarse con la API'], 500);

        }
    }

    public function sendWhatsAppMessage(string $message, string $recipient, string $type = 'text', string $caption = '')
    {
        try {
            // URL de tu API de Baileys
            $url = env('BAILEYS_API_URL') . '/send'; // Define esta URL en tu archivo .env

            // Preparar la solicitud
            $response = Http::post($url, [
                'chatId' => $recipient,
                'message' => $message,
                'type' => $type,
                'caption' => $caption, // El caption es opcional
            ]);

            // Verifica si la respuesta fue exitosa
            if ($response->successful()) {
                return response()->json(['status' => 'success', 'message' => 'Mensaje enviado']);
            } else {
                \Log::error("Error sending WhatsApp message: " . $response->body());
                return response()->json(['error' => 'Failed to send message'], 500);
            }
        } catch (\Exception $e) {
            // Maneja el error según sea necesario
            \Log::error("Error sending WhatsApp message: " . $e->getMessage());
            return response()->json(['error' => 'Failed to send message'], 500);
        }
    }

    // Función para almacenar mensajes en la base de datos
    private function storeMessage(string $userPhone, string $role, string $message)
    {
        ConversationHistory::create([
            'user_phone' => $userPhone,
            'role' => $role,
            'message' => $message,
        ]);
    }

    // Función para recuperar el historial de conversación de la base de datos
    private function getChatHistory(string $userPhone)
    {
        // Obtener todos los mensajes previos de este usuario ordenados por creación
        $history = ConversationHistory::where('user_phone', $userPhone)
            ->orderBy('created_at', 'asc')
            ->get(['role', 'message']);

        // Convertir el formato de los mensajes para enviarlos a la API de OpenAI
        return $history->map(function ($message) {
            return [
                'role' => $message->role,
                'content' => $message->message,
            ];
        })->toArray();
    }
}
