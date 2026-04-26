<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeCheckout;
use Stripe\Webhook;

/**
 * SubscriptionController
 * ─────────────────────────────────────────────────────────────────────────────
 * Handles LoveyDovey Plus subscription via Stripe Checkout.
 *
 * ROUTES to add in api.php:
 *   Route::post('/subscribe/checkout', [SubscriptionController::class, 'checkout'])->middleware('auth:sanctum');
 *   Route::post('/webhooks/stripe', [SubscriptionController::class, 'webhook']);   // NO auth middleware
 *
 * ENV VARS needed:
 *   STRIPE_SECRET_KEY=sk_live_...
 *   STRIPE_WEBHOOK_SECRET=whsec_...
 *   STRIPE_PLUS_PRICE_ID=price_...   (create in Stripe dashboard: $4.99/mo recurring)
 *
 * INSTALL:
 *   composer require stripe/stripe-php
 */
class SubscriptionController extends Controller
{
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'success_url' => 'required|url',
            'cancel_url'  => 'required|url',
        ]);

        Stripe::setApiKey(config('services.stripe.secret'));

        $user = $request->user();

        $session = StripeCheckout::create([
            'mode'                => 'subscription',
            'payment_method_types' => ['card'],
            'line_items'          => [[
                'price'    => config('services.stripe.plus_price_id'), // STRIPE_PLUS_PRICE_ID
                'quantity' => 1,
            ]],
            'customer_email'      => $user->email,
            'client_reference_id' => (string) $user->id,  // so webhook knows which user
            'success_url'         => $data['success_url'],
            'cancel_url'          => $data['cancel_url'],
            'metadata'            => [
                'user_id' => $user->id,
            ],
        ]);

        return response()->json(['url' => $session->url]);
    }

    /**
     * Stripe sends POST to this webhook when:
     *  - checkout.session.completed  → mark user as Plus
     *  - customer.subscription.deleted → remove Plus
     *  - invoice.payment_failed → optionally notify user
     */
    public function webhook(Request $request)
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Exception $e) {
            Log::warning('Stripe webhook signature failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
            'customer.subscription.deleted' => $this->handleSubscriptionCancelled($event->data->object),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $userId = $session->client_reference_id ?? $session->metadata->user_id ?? null;
        if (!$userId) return;

        $user = User::find($userId);
        if (!$user) return;

        // Store Stripe subscription ID so we can cancel later
        $user->update([
            'is_plus'              => true,
            'stripe_subscription_id' => $session->subscription ?? null,
            'plus_expires_at'      => now()->addMonth(), // rough fallback; webhook will keep updating
        ]);

        Log::info("LoveyDovey Plus activated for user {$userId}");
    }

    private function handleSubscriptionCancelled(object $subscription): void
    {
        $user = User::where('stripe_subscription_id', $subscription->id)->first();
        if (!$user) return;

        $user->update([
            'is_plus'         => false,
            'plus_expires_at' => null,
        ]);

        Log::info("LoveyDovey Plus cancelled for user {$user->id}");
    }
}