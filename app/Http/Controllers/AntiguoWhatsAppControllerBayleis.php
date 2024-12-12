<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Twilio\Rest\Client as ClientWhatsApp;
use GuzzleHttp\Client;
use App\Models\ConversationHistory; // Modelo para la tabla del historial
use Illuminate\Support\Facades\Http; // Asegúrate de importar el facade Http
use App\Models\ConversationConfiguration;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{

    public function receiveMessage(Request $request)
    {
        // Registrar toda la información que llega desde Baileys
        \Log::info('Datos recibidos desde Baileys: ' . json_encode($request->all()));
    
        // Verificar si el mensaje está presente
        if ($request->has('Body') and $request->input('From')!='status@broadcast') {
            $from = $request->input('From'); // Número de quien envía el mensaje
            $body = $request->input('Body') ?? 'Ve al grano y ofrece el producto'; // Cuerpo del mensaje recibido
            $name = $request->input('Name', 'Desconocido'); // Obtener el nombre, o usar 'Desconocido' si no está presente

            // Guardar el mensaje del usuario en la base de datos
            $this->storeMessage($from, 'user', $body, $name);

            // Log de éxito
            \Log::info("Mensaje recibido de $from: $body para enviar a ChatGPT");
    
            $conversationConfiguration = ConversationConfiguration::where('user_phone', $from)->first(['conversation_enabled', 'thread_id']);

            // Verificar si el bot está APAGADO para este número
            if ($conversationConfiguration && !$conversationConfiguration->conversation_enabled) {
                Log::info("El bot está APAGADO para el número $from. No se procesará el mensaje.");
                return response()->json(['status' => 'El bot está APAGADO para el número $from. No se procesará el mensaje.'], 403);
            }
            
            if (!$conversationConfiguration) {
                $threadId = $this->createNewThread();
                Log::info("Nuevo hilo creado para el número $from con thread_id: $threadId");
            
                $conversationConfiguration = ConversationConfiguration::create([
                    'user_phone' => $from,
                    'conversation_enabled' => true,
                    'thread_id' => $threadId,
                ]);
            }else {
                \Log::info("Configuración existente encontrada para $from. thread_id: " . $conversationConfiguration->thread_id);
            }

            // Llamar a ChatGPT utilizando el thread_id
            $this->chatGpt($body, $from, $conversationConfiguration->thread_id);

            
            return response()->json(['status' => 'Message received and processed']);
        }
    
        // Log de no válido
        \Log::warning("Mensaje no válido recibido: " . json_encode($request->all()));
    
        return response()->json(['status' => 'No valid message received'], 400);
    }
    
    public function chatGpt(string $promt, string $from, string $thread_id)
    {
        // Agregar el mensaje al hilo existente
        $sendMessageResult = $this->sendMessageToThread($thread_id, 'user', $promt);

        if ($sendMessageResult) {
            // Verificar que el mensaje se haya agregado con éxito
            \Log::info("Mensaje agregado al hilo $thread_id correctamente. Ejecutando run...");

            // Ejecutar el run después de agregar el mensaje
            $runResult = $this->runThread($thread_id);

            if ($runResult['success']) {
                $runData = $runResult['data'];
                \Log::info("Run ejecutado correctamente. ID del run: " . $runData['id']);

                $messageValue = $this->getMessageByRunId($thread_id, $runData['id']);
                
                if ($messageValue) {
                        \Log::info("Mensaje del run {$runData['id']}: $messageValue");
                } else {
                        \Log::warning("No se encontró un mensaje asociado con el run {$runData['id']}");
                    }

            } else {
                \Log::error("Fallo al ejecutar el run para el hilo $thread_id. Error: " . json_encode($runResult['error']));
                return null; // Manejo de error
            }
        } else {
            \Log::error("No se pudo agregar el mensaje al hilo $thread_id. No se ejecutará el run.");
            return null; // Manejo de error
        }
        

        try {
            
            // Obtener el contenido del mensaje de respuesta
            $replyContent = $messageValue;

            // Eliminar delimitadores de bloque de código y extraer el bloque JSON
            preg_match('/\{.*\}/s', $replyContent, $matches);
            $cleanedContent = $matches[0] ?? '';

            // Intentar decodificar el contenido como JSON una sola vez
            $replyData = json_decode($cleanedContent, true);

            // Registrar el contenido decodificado para debugging
            \Log::info('Contenido decodificado en $replyData: ' . print_r($replyData, true));

            // Verificar si el formato es JSON y contiene mensajes
            if (isset($replyData['mensajes']) && is_array($replyData['mensajes'])) {
                // Iterar sobre los mensajes y enviarlos individualmente
                foreach ($replyData['mensajes'] as $msg) {

                    $messageType = $msg['type'];
                    // Lógica para determinar el contenido del mensaje
                    if (in_array($messageType, ['video', 'audio', 'image'])) {
                        // Si el tipo es video, audio o imagen, la URL se encuentra en 'message'
                        $messageContent = $msg['url'];
                    } else {
                        // Para otros tipos, el contenido del mensaje es simplemente el texto
                        $messageContent = $msg['message'];
                    }
                    $caption = $msg['caption'] ?? null; // Opcional

                    // Lógica de envío basada en el tipo de mensaje
                    $this->sendWhatsAppMessage($messageContent, $from, $messageType, $caption);

                    // Guardar cada mensaje en la base de datos
                    //importante, la bd no entiende con tipo de mensaje solo texto, corregir
                    $this->storeMessage($from, 'assistant', $messageContent, 'assistant');
                }
            } else {
                // Si la respuesta no es JSON o no tiene múltiples mensajes, envíala como un solo mensaje
                $this->sendWhatsAppMessage("Hola, en unos minutos te envío toda la información.", $from);
                $this->storeMessage($from, 'assistant', $replyContent, 'assistant');
                // Registrar la respuesta para debugging
                \Log::error('ChatGPT no respondió con un JSON ' . $replyContent);

                //se debe enviar un mensaje de error al admin
                $solicitudHuman = 'El cliente: '.$from.' Necesita ayuda. . .';
                $this->sendWhatsAppMessage($solicitudHuman, "51945692831@c.us");
                \Log::error('Se solicitó ayuda al administrador ');

            }

            if (isset($replyData['acciones']) && is_array($replyData['acciones'])) {
                foreach ($replyData['acciones'] as $action) {
                    $actionType = $action['type'];
                    $formattedFrom = '+' . explode('@', $from)[0];

                    $actionMessage = $action['message']." | Número de cliente: ".$formattedFrom;
                    
                    \Log::info('Se detectó una solicitud de acción: ' . $actionMessage . 'Cliente: ' . $formattedFrom);
                        
                    if ($actionType === 'solicitud_de_intervencion_humana') {
                        // Notificar a los agentes o realizar otra acción necesaria
                        \Log::info('Intervención humana requerida: ' . $actionMessage . 'Cliente: ' . $formattedFrom);
                        
                        // Aquí podrías enviar un mensaje a un agente, registrar una alerta, etc.
                        $this->sendWhatsAppMessage($actionMessage, "51945692831@c.us");
                    }
                }
            }
            return response()->json(['reply' => $replyContent]);

        } catch (\Exception $e) {

            \Log::error('Error contacting OpenAI API: ' . $e->getMessage());
            
            $reply = "Hola, en unos minutos te envío toda la información.";
            
            // Guardar la respuesta del asistente en la base de datos
            $this->storeMessage($from, 'assistant', $reply, 'assistant');

            // Enviar la respuesta vía WhatsApp
            $this->sendWhatsAppMessage($reply, $from);

            //se debe enviar un mensaje de error al admin
            $solicitudHuman = 'El cliente: '.$from.' Necesita ayuda. . .';
            $this->sendWhatsAppMessage($solicitudHuman, "51945692831@c.us");

            return response()->json(['error' => 'Error al comunicarse con la API'], 500);

        }
    }

    public function sendWhatsAppMessage(string $message, string $recipient, string $type = 'text', string $caption = null)
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

    protected function sendMessageToThread($threadId, $role, $content)
    {
    
        try {
            $response = \Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'), // Agregar el token de la API
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'assistants=v2', // Encabezado adicional requerido
            ])->post("https://api.openai.com/v1/threads/{$threadId}/messages", [
                'role' => $role, // Por ejemplo, 'user' o 'assistant'
                'content' => $content,
            ]);
            
            if ($response->successful()) {
                $responseBody = $response->json();
                \Log::info("Mensaje enviado al hilo $threadId.");
                return $responseBody; // Devuelve la respuesta completa
            } else {
                $error = $response->json();
                \Log::error("Error al enviar mensaje al hilo $threadId: " . json_encode($error));
                return null;
            }
        } catch (\Exception $e) {
            \Log::error("Error en sendMessageToThread(): " . $e->getMessage());
            return null;
        }
    }

    protected function runThread($threadId)
    {
        try {
            // Configurar encabezados y datos de la solicitud
            $response = \Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'), // Token de la API
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'assistants=v2', // Requisito para v2
            ])->post("https://api.openai.com/v1/threads/{$threadId}/runs", [
                'assistant_id' => 'asst_0NzgeR0AD6MNiIiJ4MSGBm28', // Asistente asociado al run
            ]);

            // Procesar respuesta
            if ($response->successful()) {
                $responseBody = $response->json();
                \Log::info("Run iniciado exitosamente para threadId: $threadId ");
                return [
                    'success' => true,
                    'data' => $responseBody,
                ];
            }

            // Manejo de errores HTTP
            $error = $response->json() ?? $response->body();
            \Log::error("Error al iniciar run para threadId: $threadId. Error: " . json_encode($error));
            return [
                'success' => false,
                'error' => $error,
            ];
        } catch (\Exception $e) {
            // Manejo de excepciones generales
            \Log::error("Excepción al iniciar run: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getMessagesFromThread($threadId)
    {
        try {
            $response = \Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
                'OpenAI-Beta' => 'assistants=v2',
            ])->get("https://api.openai.com/v1/threads/{$threadId}/messages");

            if ($response->successful()) {
                //\Log::info("Respuesta completa del hilo $threadId: " . json_encode($response->json()));
                return $response->json()['data']; // Devuelve la lista de mensajes
            } else {
                $error = $response->json();
                \Log::error("Error al obtener mensajes del hilo $threadId: " . json_encode($error));
                return null;
            }
        } catch (\Exception $e) {
            \Log::error("Error en getMessagesFromThread(): " . $e->getMessage());
            return null;
        }
    }

    protected function getMessageByRunId($threadId,  $runId)
    {
        $foundMessage = false;

        while (!$foundMessage) {

            // Obtener todos los mensajes del hilo
            $messages = $this->getMessagesFromThread($threadId);

            if ($messages) {
                foreach ($messages as $message) {
                    // Asegurarse de que el mensaje tenga un `run_id` y coincida con el que buscamos
                    if (isset($message['run_id']) && $message['run_id'] === $runId) {
                        // Extraer el valor del contenido
                        \Log::info("Si consigue el mensaje");
                        foreach ($message['content'] as $contentItem) {
                            if ($contentItem['type'] === 'text') {
                                return $contentItem['text']['value']; // Devuelve el valor del texto
                            }
                        }
                    }
                }
            }
            sleep(2);  // Esperar 1 segundo antes de hacer otro intento
        }  
    
    }


}
