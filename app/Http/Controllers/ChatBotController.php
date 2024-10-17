<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Twilio\Rest\Client;

class ChatBotController extends Controller
{
    public function listenToReplies()
    {
        $to = "whatsapp:+51945692831";

        $this->sendWhatsAppMessage("ePALE isaac 3", $to);
        return response()->json(['status' => 'El chat bot funciona']);
    }

    /**
     * Sends a WhatsApp message  to user using
     * @param string $message Body of sms
     * @param string $recipient Number of recipient
     */
    public function sendWhatsAppMessage(string $message, string $recipient)
    {
        $twilio_whatsapp_number = getenv('TWILIO_WHATSAPP_NUMBER');
        $account_sid = getenv("TWILIO_SID");
        $auth_token = getenv("TWILIO_AUTH_TOKEN");

        $client = new Client($account_sid, $auth_token);
        return $client->messages->create($recipient, array('from' => "whatsapp:$twilio_whatsapp_number", 'body' => $message));
    }
}