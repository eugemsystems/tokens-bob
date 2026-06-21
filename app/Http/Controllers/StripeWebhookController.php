<?php

namespace App\Http\Controllers;

use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();
        $payload = json_decode($body, true);
        $eventType = $payload['type'] ?? null;

        $log = WebhookLog::create([
            'source' => 'stripe',
            'event_type' => $eventType,
            'payload' => $body,
            'headers' => json_encode($request->headers->all()),
            'status' => 'received',
            'response_code' => 200,
            'ip_address' => $request->ip(),
        ]);

        Log::info('Stripe: webhook received', ['id' => $log->id, 'event' => $eventType]);

        return response('OK', 200);
    }
}
