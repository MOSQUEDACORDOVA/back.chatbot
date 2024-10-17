<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Client; // Asegúrate de tener un modelo Client
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client as ClientWhatsApp;

class CheckInsuranceExpiry extends Command
{
    // El nombre y descripción del comando en Artisan
    protected $signature = 'insurance:check-expiry';
    protected $description = 'Check which clients have insurance expiring in the next 5 days';

    public function __construct()
    {
        parent::__construct();
    }
    
    public function handle()
    {
        // Obtener la fecha actual
        $today = Carbon::now();
        
        // Obtener la fecha dentro de 5 días (por ejemplo)
        $nextExpiryDays = $today->addDays(2);
        
        // Consultar los clientes cuyo seguro vence en los próximos 5 días (por ejemplo)
        $clients = Client::whereBetween('insurance_expiry_date', [now(), $nextExpiryDays])
                         ->get();

        if ($clients->isEmpty()) {
            $this->info('No clients with insurance expiring in the next 2 days.');
        } else {
            foreach ($clients as $client) {
                // Aquí puedes agregar tu lógica para notificar al cliente o hacer otra acción
                // Ejemplo: Guardar en un log o enviar un mensaje
                Log::info("Client {$client->name} with WhatsApp number {$client->whatsapp_number} has insurance expiring on {$client->insurance_expiry_date}.");
                
                // Otras acciones como enviar un mensaje por WhatsApp, correo, etc.
                // Formatear el mensaje
                $message = "Hola {$client->name}, te recordamos que tu seguro vence el {$client->insurance_expiry_date}. ¡Asegúrate de renovarlo a tiempo! Si necesitas ayuda, estamos aquí para asistirte.";
                $to = "whatsapp:".$client->whatsapp_number;

                $this->sendWhatsAppMessage($message, $to);
            }

            $this->info('Checked clients with expiring insurance.');

        }
    }

    public function sendWhatsAppMessage(string $message, string $recipient)
    {
        $twilio_whatsapp_number = getenv('TWILIO_WHATSAPP_NUMBER');
        $account_sid = getenv("TWILIO_SID");
        $auth_token = getenv("TWILIO_AUTH_TOKEN");

        $client = new ClientWhatsApp($account_sid, $auth_token);
        return $client->messages->create($recipient, array('from' => "whatsapp:$twilio_whatsapp_number", 'body' => $message));
    }
}
