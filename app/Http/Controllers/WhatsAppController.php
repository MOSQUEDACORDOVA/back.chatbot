<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Twilio\Rest\Client as ClientWhatsApp;
use GuzzleHttp\Client;
use App\Models\ConversationHistory; // Modelo para la tabla del historial
use Illuminate\Support\Facades\Http; // Asegúrate de importar el facade Http
use App\Models\ConversationConfiguration;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\CURLFile;
use App\Services\OpenAIAssistantServiceDamaris;

class WhatsAppController extends Controller
{

    protected $assistantService;

    public function __construct(OpenAIAssistantServiceDamaris $assistantService)
    {
        $this->assistantService = $assistantService;
    }
    
    public function mensajeRecibido(Request $request)
    {
        // Registrar toda la información que llega desde WhatsApp
        \Log::info('Datos recibidos desde WhatsApp: ' . json_encode($request->all()));

        // Verificar si el mensaje está presente en la estructura de WhatsApp
        if (isset($request->entry[0]['changes'][0]['value']['messages'][0])) {
            
            // Obtener el mensaje recibido
            $message = $request->entry[0]['changes'][0]['value']['messages'][0];        
            
            // Extraer el número del remitente y el contenido del mensaje
            $from = $message['from']; // Número de quien envía el mensaje

            // Verificar si el bot está habilitado para este número
            $conversationConfiguration = ConversationConfiguration::where('user_phone', $from)->first(['conversation_enabled', 'thread_id']);

            // Verificar si el bot está APAGADO para este número
            if ($conversationConfiguration && !$conversationConfiguration->conversation_enabled) {
                \Log::info("El bot está APAGADO para el número $from. No se procesará el mensaje.");
                return response()->json(['status' => "El bot está APAGADO para el número $from. No se procesará el mensaje."], 403);
            }

            // Si no existe configuración, crear un nuevo hilo de conversación
            if (!$conversationConfiguration) {
                $threadId = $this->createNewThread();
                \Log::info("Nuevo hilo creado para el número $from con thread_id: $threadId");

                $conversationConfiguration = ConversationConfiguration::create([
                    'user_phone' => $from,
                    'conversation_enabled' => true,
                    'thread_id' => $threadId,
                ]);
            } else {
                \Log::info("Configuración existente encontrada para $from. thread_id: " . $conversationConfiguration->thread_id);
            }

            // Extraer el nombre del remitente (si está disponible)
            $name = isset($request->entry[0]['changes'][0]['value']['contacts'][0]['profile']['name'])
                    ? $request->entry[0]['changes'][0]['value']['contacts'][0]['profile']['name']
                    : 'Desconocido'; // Si no hay nombre, se usa 'Desconocido'

            

            switch ($message['type']) {
                case 'text':
                    $body = $message['text']['body'] ?? 'Ve al grano y ofrece el producto'; // Cuerpo del mensaje recibido
                    // Llamar a ChatGPT utilizando el thread_id
                    $this->chatGpt($body, $from, $conversationConfiguration->thread_id);
                    break;

                case 'audio':
                    $audioId = $message['audio']['id'];
                    $audioUrl = $this->getMediaUrl($audioId); // Función para obtener la URL del archivo de audio
                    $transcription = $this->transcribeAudio($audioUrl);
                    $body = $transcription;
                    $this->chatGpt($body, $from, $conversationConfiguration->thread_id);
                    break;

                default:
                    $body = 'El usuario envió un tipo de mensaje no soportado, quizá un video o una imagen o algo por el estilo, explicale que no logras entender el mensaje que envió.'; // Cuerpo del mensaje recibido
                    // Llamar a ChatGPT utilizando el thread_id
                    $this->chatGpt($body, $from, $conversationConfiguration->thread_id);
                    \Log::info("Tipo de mensaje no manejado: " . $message['type']);
                    break;
            }
            
            return response()->json(['status' => 'Mensaje recibido y procesado']);
        }

        // Log de mensaje no válido
        //\Log::warning("Mensaje no válido recibido: " . json_encode($request->all()));

        //return response()->json(['status' => 'No se recibió un mensaje válido'], 400);
    }
  
    public function twilioReceiveMessage(Request $request)
    {
        // Registrar toda la información que llega desde Twilio
        \Log::info('Datos recibidos desde Twilio: ' . json_encode($request->all()));

        // Verificar si es un mensaje de texto normal (SMS/WhatsApp) y no un evento de conversación
        if ($request->has('SmsMessageSid')) {
            $from = $request->input('From', $request->input('Author')); // Asignar 'From' o 'Author' // Número de quien envía el mensaje
            $name = $request->input('ProfileName', null); // Nombre del remitente, si está disponible
            $body = $request['Body']; // Cuerpo del mensaje recibido
            $MessageSid = $request['MessageSid'];
            $MessageType = $request['MessageType'];
            // Verificar si hay configuración para este número
            $conversationConfiguration = ConversationConfiguration::where('user_phone', $from)->first(['conversation_enabled', 'thread_id']);
            
            // Verificar si el bot está APAGADO para este número
            if ($conversationConfiguration && !$conversationConfiguration->conversation_enabled) {
                \Log::info("El bot está APAGADO para el número $from. No se procesará el mensaje.");
                return response()->json(['status' => "El bot está APAGADO para el número $from. No se procesará el mensaje."], 403);
            }

            // Si no existe configuración, crear un nuevo hilo de conversación
            if (!$conversationConfiguration) {
                $threadId = $this->createNewThread();
                \Log::info("Nuevo hilo creado para el número $from con thread_id: $threadId");

                $conversationConfiguration = ConversationConfiguration::create([
                    'user_phone' => $from,
                    'conversation_enabled' => true,
                    'thread_id' => $threadId,
                ]);
            } else {
                \Log::info("Configuración existente encontrada para $from. thread_id: " . $conversationConfiguration->thread_id);
            }

            switch ($MessageType) {
                case 'text':
                    // Llamar a ChatGPT utilizando el thread_id
                    $this->chatGpt($body, $from, $conversationConfiguration->thread_id);
                    break;

                case 'audio':
                    $audioId = $MessageSid;
                    $audioUrl = $this->getMediaUrl($audioId); // Función para obtener la URL del archivo de audio
                    $transcription = $this->transcribeAudio($audioUrl);
                    $body = $transcription;
                    $this->chatGpt($body, $from, $conversationConfiguration->thread_id);
                    break;

                default:
                    $body = 'El usuario envió un tipo de mensaje no soportado, quizá un video o una imagen o algo por el estilo, explicale que no logras entender el mensaje que envió.'; // Cuerpo del mensaje recibido
                    // Llamar a ChatGPT utilizando el thread_id
                    $this->chatGpt($body, $from, $conversationConfiguration->thread_id);
                    \Log::info("Tipo de mensaje no manejado: " . $MessageType);
                    break;
            }

            return response()->json(['status' => 'Mensaje recibido y procesado']);
        }
        
    }

    private function chatGpt($promt, string $from, string $thread_id, string $assistants_id = null, string $role = 'user')
    {

        // Si $assistants_id es nulo, asigna el valor predeterminado desde el .env
        $assistants_id = $assistants_id ?? env('PRIMARY_ASSISTANT_ID');
        \Log::info("Assistant ID: " . $assistants_id);
        
        $messageValue = $this->assistantService->handleMessage($promt,$assistants_id,$thread_id, $role);

        

        if($messageValue){
            try {
                $messageData = json_decode($messageValue, true); // El segundo parámetro 'true' convierte el JSON a un array asociativo

                // Asignar el tipo y el mensaje a variables separadas
                $message = $messageData['message']; // El mensaje real
                $type = $messageData['type'];       // El tipo del mensaje (ej. "text")

                Log::info('Respuesta de ChatGpt: ' . $message);

                // Dividir el mensaje por saltos de línea
                $messageParts = explode("\n", $message);

                if($type == 'text'){
                    // Enviar cada fragmento como un mensaje independiente
                    foreach ($messageParts as $part) {
                        if (trim($part) !== '') { // Asegurarse de que no se envíen líneas vacías
                            $this->sendWhatsAppMessage($part, $from, $type);
                            //importante, la bd solo entiende con tipo de mensaje solo texto, corregir
                            $this->storeMessage($from, 'assistant', $messageValue, 'assistant');
                        }
                    }
                }
                if($type == 'audio'){
                    \Log::error('Todavia no podemos enviar audio');
                }
                
    
            } catch (\Exception $e) {
    
                \Log::error('Error contacting OpenAI API: ' . $e->getMessage());
                
                $reply = "Hola, en unos minutos te envío toda la información.";
                
                // Guardar la respuesta del asistente en la base de datos
                $this->storeMessage($from, 'assistant', $reply, 'assistant');
    
                // Enviar la respuesta vía WhatsApp
                $this->sendWhatsAppMessage($reply, $from);
    
                //se debe enviar un mensaje de error al admin
                $solicitudHuman = 'El cliente: '.$from.' Necesita ayuda. . .';
                if(env('API_MENSAJES')=="TWILIO"){
                    $this->sendWhatsAppMessage($solicitudHuman, "whatsapp:+51945692831");
                }else{
                    $this->sendWhatsAppMessage($solicitudHuman, "51945692831@c.us");
                }    
            }
        }else{
            \Log::info("Algo malo salió con chatgpt, se debe mejorar este msj de error");
        }
        
    }

    public function sendWhatsAppMessage(string $message, string $recipient, string $type = 'text', string $caption = null)
    {
        $apiMensajeria = env('API_MENSAJES');

        switch ($apiMensajeria) {
            case 'TWILIO':
                $this->sendToTwilio($message, $recipient, $type, $caption);
                break;

            default:
                $this->sendToWhatsAppOficial($message, $recipient, $type, $caption);
                break;
        }
        
    }

    private function sendToTwilio(string $message, string $recipient, string $type = 'text', string $caption = null){
        try {
            $twilio_whatsapp_number = env('TWILIO_WHATSAPP_NUMBER');
            $account_sid = env("TWILIO_SID");
            $auth_token = env("TWILIO_AUTH_TOKEN");
            $client = new ClientWhatsApp($account_sid, $auth_token);
            return $client->messages->create($recipient, [
                'from' => "whatsapp:$twilio_whatsapp_number",
                'body' => $message,
            ]);
        } catch (\Exception $e) {
            // Maneja el error según sea necesario
            \Log::error("TWILIO Error sending WhatsApp message: " . $e->getMessage());
            return response()->json(['error' => 'TWILIO Failed to send message'], 500);
        }
    }

    private function sendToWhatsAppOficial(string $message, string $recipient, string $type = 'text', string $caption = null){
        try {
            // URL de la API oficial de WhatsApp (asegúrate de que la URL esté correctamente configurada)
            $url = "https://graph.facebook.com/v21.0/467585689779303/messages"; // Reemplaza con tu ID de teléfono de WhatsApp Business
            
            // Tu token de acceso
            $accessToken = env('WHATSAPP_ACCESS_TOKEN'); // Asegúrate de definir tu token en .env

            // Preparar los datos del mensaje
            $data = [
                'messaging_product' => 'whatsapp',
                'recipient_type'=> 'individual',
                'to' => $recipient, // Número de teléfono del destinatario
                'type' => $type,    // Tipo de mensaje (texto, imagen, etc.)
            ];

            // Si el tipo de mensaje es texto
            if ($type == 'text') {
                $data['text'] = [
                    'body' => $message,
                ];
            }

            // Si el tipo de mensaje es imagen (opcional)
            if ($type == 'image' && $caption) {
                $data['image'] = [
                    'link' => $message, // URL de la imagen
                    'caption' => $caption, // Opcional: descripción de la imagen
                ];
            }

            // Si el tipo de mensaje es audio
            if ($type == 'audio') {
                $data['audio'] = [
                    'link' => $message, // URL del audio
                ];
            }

            // Enviar la solicitud a la API oficial de WhatsApp
            $response = Http::withToken($accessToken)->post($url, $data);

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
    private function storeMessage(string $userPhone, string $role, string $message, ?string $name = null)
    {
        // Construir el arreglo con los valores obligatorios
        $data = [
            'user_phone' => $userPhone,
            'role' => $role,
            'message' => $message,
            'name' => $name,
        ];

        

        // Crear el registro en la base de datos
        ConversationHistory::create($data);
    }
    
    private function createNewThread()
    {
        try {
            // Realizamos la solicitud POST para crear un nuevo hilo
            $response = \Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'), // Agregar el token de la API
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'assistants=v2', // Encabezado adicional requerido
            ])->post('https://api.openai.com/v1/threads', []);

            $responseData = $response->json();
            \Log::info('Respuesta de ChatGPT: ' . json_encode($responseData));

            // Verifica si la respuesta fue exitosa y devuelve el thread_id
            if ($response->successful() && isset($responseData['id'])) {
                return $responseData['id']; // Devuelve el ID del nuevo hilo
            } else {
                \Log::error('Error al crear un nuevo hilo en ChatGPT: ' . json_encode($responseData));
                throw new \Exception('No se pudo crear un nuevo hilo.');
            }
        } catch (\Exception $e) {
            \Log::error('Error en createNewThread(): ' . $e->getMessage());
            throw $e;
        }
    }

    protected function getMediaUrl($mediaId)
    {
        $accessToken = env('WHATSAPP_ACCESS_TOKEN');
        $url = "https://graph.facebook.com/v21.0/$mediaId";

        $response = Http::withToken($accessToken)->get($url);

        if ($response->successful() && isset($response['url'])) {
            \Log::info("URL del archivo de audio: " . $response['url']);
            return $response['url'];
        }

        \Log::error("Error al obtener la URL del archivo de audio: " . $response->body());
        return null;
    }

    protected function transcribeAudio($audioUrl)
    {
        // Descargar el archivo de audio
        $audioFilePath = $this->downloadMedia($audioUrl);
        
        if (!$audioFilePath) {
            \Log::error("No se pudo descargar el archivo de audio desde la URL: " . $audioFilePath);
            return response()->json(['error' => 'No se pudo descargar el archivo de audio'], 500);
        }
        
        \Log::info("Archivo de audio descargado en: " . $audioFilePath);
    
        $apiKey = env('OPENAI_API_KEY'); // Obtener la clave API desde el archivo .env
        if (!$apiKey) {
            \Log::error("No se encontró la clave API de OpenAI en el archivo .env.");
            return response()->json(['error' => 'API key no configurada'], 500);
        }
    
        // Realizar la solicitud POST
        try {
            \Log::info("Enviando solicitud de transcripción al API de OpenAI.");
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])->attach(
                'file', file_get_contents($audioFilePath), 'audio.mp3'
            )->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => 'whisper-1',
            ]);
    
            // Verificar la respuesta
            if ($response->successful()) {
                \Log::info("Transcripción exitosa: " . $response->json()['text']);
                // Aquí puedes eliminar el archivo temporal si ya no lo necesitas
                unlink($audioFilePath);
                
                return $response->json()['text']; // Devuelve la transcripción
            } else {
                \Log::error("Error en la respuesta de OpenAI: " . $response->body());
                return response()->json(['error' => 'No se pudo transcribir el audio', 'details' => $response->body()], 500);
            }
        } catch (\Exception $e) {
            \Log::error("Excepción al realizar la solicitud: " . $e->getMessage());
            return response()->json(['error' => 'Error interno en el servidor'], 500);
        }
    }
    
    protected function downloadMedia($mediaUrl)
    {
        try {

            // El token de acceso de WhatsApp (deberías almacenarlo en tu archivo .env)
            $accessToken = env('WHATSAPP_ACCESS_TOKEN');

            // Realizar la solicitud GET para descargar el archivo multimedia
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get($mediaUrl);

            // Verificar si la solicitud fue exitosa
            if ($response->successful()) {
                // Obtener el tipo MIME del archivo desde los encabezados de la respuesta
                $contentType = $response->header('Content-Type');

                // Definir la ruta para guardar el archivo (puedes cambiar la extensión según el tipo MIME)
                $filePath = storage_path('app/audio_' . uniqid() . '.ogg'); // O ajusta según el tipo MIME

                // Guardar los datos binarios del archivo en el servidor
                file_put_contents($filePath, $response->body());

                
                // Devolver la ruta donde se guardó el archivo
                return $filePath;
            } else {
                \Log::error("Error al descargar el archivo multimedia: " . $response->body());
                return null;
            }
        } catch (\Exception $e) {
            \Log::error("Excepción al descargar el archivo multimedia: " . $e->getMessage());
            return null;
        }
    }

    protected function convertTextToSpeech($text)
    {

        $model = 'tts-1'; // Modelo definido por OpenAI
        $voice = 'nova'; // Voz predeterminada
        $apiKey = env('OPENAI_API_KEY'); // Asegúrate de tener configurada la clave en tu .env

        try {
            // Realiza la solicitud a la API de OpenAI
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/audio/speech', [
                'model' => $model,
                'input' => $text,
                'voice' => $voice,
            ]);

            // Verifica si la solicitud fue exitosa
            if ($response->successful()) {
                // Definir la ruta para guardar el archivo (puedes cambiar la extensión según el tipo MIME)
                $filePath = storage_path('app/audio_' . uniqid() . '.ogg'); // O ajusta según el tipo MIME

                // Guardar los datos binarios del archivo en el servidor
                file_put_contents($filePath, $response->body());
                
                // Devolver la ruta donde se guardó el archivo
                return $filePath;

            } else {
                // Maneja errores
                return response()->json([
                    'success' => false,
                    'message' => 'Error al convertir el texto a voz.',
                    'error' => $response->json(),
                ], $response->status());
            }
        } catch (\Exception $e) {
            // Manejo de excepciones
            return response()->json([
                'success' => false,
                'message' => 'Error en la solicitud.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    

}