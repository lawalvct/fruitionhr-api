<?php

namespace App\Modules\Billing\Controllers;

use App\Modules\Billing\Gateways\PaymentGatewayManager;
use App\Modules\Billing\Jobs\ProcessPaymentWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Gateway webhooks. Unauthenticated by necessity — the caller is Paystack or
 * Nomba, not a user — so the HMAC signature is the only thing standing between
 * this endpoint and a forged "payment successful".
 *
 * With no browser to redirect in an API, webhooks are the reliable path;
 * the client-side verify is the optimistic one.
 */
class PaymentWebhookController extends Controller
{
    private const SIGNATURE_HEADERS = [
        PaymentGatewayManager::PAYSTACK => 'X-Paystack-Signature',
        PaymentGatewayManager::NOMBA => 'X-Nomba-Signature',
    ];

    public function __invoke(Request $request, string $gateway, PaymentGatewayManager $gateways): Response
    {
        if (! $gateways->supports($gateway)) {
            return response()->noContent(404);
        }

        $driver = $gateways->driver($gateway);

        // Signature must be checked against the RAW body. Re-encoding the
        // parsed array changes key order and whitespace, and the HMAC fails.
        $valid = $driver->verifyWebhookSignature(
            $request->getContent(),
            $request->header(self::SIGNATURE_HEADERS[$gateway]),
        );

        if (! $valid) {
            Log::warning('Rejected payment webhook with a bad signature', [
                'gateway' => $gateway,
                'ip' => $request->ip(),
            ]);

            return response()->noContent(401);
        }

        $reference = $driver->referenceFromWebhook($request->json()->all());

        if ($reference !== null) {
            // Queue it: gateways retry on slow responses, and re-verification
            // means an outbound HTTP call we should not make inline.
            ProcessPaymentWebhook::dispatch($reference);
        }

        // Acknowledge immediately so the gateway stops retrying.
        return response()->noContent();
    }
}
