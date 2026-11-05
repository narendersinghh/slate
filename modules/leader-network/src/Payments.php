<?php
declare(strict_types=1);

namespace SlateModules\LeaderNetwork;

use Slate\DB;
use Slate\Http;
use Slate\Posts;

/**
 * Checkout for featured placements: Stripe Checkout, Razorpay Payment Links or manual (admin confirms).
 * A placement is only activated after the provider confirms payment (return URL check or signed webhook).
 */
final class Payments
{
    /** Start payment for a pending placement. @return array{url?:string, manual?:bool, error?:string} */
    public static function checkout(array $order, array $user): array
    {
        $provider = (string)Network::get('payment_provider');
        $post = Posts::find((int)$order['post_id']);
        $label = 'Featured placement: ' . ($post['title'] ?? 'profile') . ' (' . $order['days'] . ' days)';
        if ($provider === 'stripe' && Network::secret('stripe_secret') !== '') {
            $r = Http::request('POST', 'https://api.stripe.com/v1/checkout/sessions', ['Authorization: Bearer ' . Network::secret('stripe_secret'),
                'Content-Type: application/x-www-form-urlencoded'], http_build_query([
                'mode' => 'payment',
                'client_reference_id' => (string)$order['id'],
                'customer_email' => $user['email'],
                'metadata' => ['ln_featured' => (string)$order['id']],
                'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($order['currency']), 'unit_amount' => (int)$order['amount'] * 100,
                    'product_data' => ['name' => mb_substr($label, 0, 250)]]]],
                'success_url' => site_url('/account/featured/return/?provider=stripe&order=' . $order['id'] . '&session={CHECKOUT_SESSION_ID}'),
                'cancel_url' => site_url('/account/'),
            ]), 30);
            $d = json_decode($r['body'], true) ?: [];
            if (empty($d['url'])) {
                return ['error' => 'Payment could not be started: ' . ($d['error']['message'] ?? $r['error'] ?? 'unknown error')];
            }
            DB::update('ln_featured', ['provider' => 'stripe', 'payment_ref' => (string)$d['id']], ['id' => (int)$order['id']]);
            return ['url' => (string)$d['url']];
        }
        if ($provider === 'razorpay' && Network::get('razorpay_key_id') && Network::secret('razorpay_key_secret') !== '') {
            $r = Http::json('POST', 'https://api.razorpay.com/v1/payment_links', [
                'Authorization: Basic ' . base64_encode(Network::get('razorpay_key_id') . ':' . Network::secret('razorpay_key_secret'))], [
                'amount' => (int)$order['amount'] * 100,
                'currency' => $order['currency'],
                'description' => mb_substr($label, 0, 2000),
                'reference_id' => 'lnf-' . $order['id'],
                'customer' => ['name' => mb_substr((string)$user['display_name'], 0, 100), 'email' => $user['email']],
                'notify' => ['email' => true],
                'callback_url' => site_url('/account/featured/return/?provider=razorpay&order=' . $order['id']),
                'callback_method' => 'get',
            ], 30);
            $d = $r['json'] ?? [];
            if (empty($d['short_url'])) {
                return ['error' => 'Payment could not be started: ' . ($d['error']['description'] ?? $r['error'] ?? 'unknown error')];
            }
            DB::update('ln_featured', ['provider' => 'razorpay', 'payment_ref' => (string)$d['id']], ['id' => (int)$order['id']]);
            return ['url' => (string)$d['short_url']];
        }
        DB::update('ln_featured', ['provider' => 'manual'], ['id' => (int)$order['id']]);
        return ['manual' => true];
    }

    /** Return from the provider: confirm with the provider before activating. */
    public static function confirmReturn(array $order, array $q): bool
    {
        if ($order['status'] === 'active') {
            return true;
        }
        if (($q['provider'] ?? '') === 'stripe' && $order['provider'] === 'stripe') {
            $sid = (string)($q['session'] ?? '');
            if ($sid === '' || $sid !== $order['payment_ref']) {
                return false;
            }
            $r = Http::request('GET', 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sid), ['Authorization: Bearer ' . Network::secret('stripe_secret')], null, 30);
            $d = json_decode($r['body'], true) ?: [];
            if (($d['payment_status'] ?? '') === 'paid' && (string)($d['client_reference_id'] ?? '') === (string)$order['id']) {
                Network::activateFeatured((int)$order['id'], (string)($d['payment_intent'] ?? $sid));
                return true;
            }
            return false;
        }
        if (($q['provider'] ?? '') === 'razorpay' && $order['provider'] === 'razorpay') {
            $linkId = (string)($q['razorpay_payment_link_id'] ?? '');
            $ref = (string)($q['razorpay_payment_link_reference_id'] ?? '');
            $status = (string)($q['razorpay_payment_link_status'] ?? '');
            $pay = (string)($q['razorpay_payment_id'] ?? '');
            $sig = (string)($q['razorpay_signature'] ?? '');
            $expected = hash_hmac('sha256', $linkId . '|' . $ref . '|' . $status . '|' . $pay, Network::secret('razorpay_key_secret'));
            if ($status === 'paid' && $linkId === $order['payment_ref'] && $ref === 'lnf-' . $order['id'] && hash_equals($expected, $sig)) {
                Network::activateFeatured((int)$order['id'], $pay);
                return true;
            }
        }
        return false;
    }

    /** Signed webhooks (POST /ln/webhook/stripe and /ln/webhook/razorpay). */
    public static function webhook(string $provider): never
    {
        $body = (string)file_get_contents('php://input');
        $ok = false;
        if ($provider === 'stripe' && ($secret = Network::secret('stripe_webhook_secret')) !== '') {
            $hdr = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
            $parts = [];
            foreach (explode(',', $hdr) as $kv) {
                [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                $parts[$k][] = $v;
            }
            $t = (int)($parts['t'][0] ?? 0);
            $expected = hash_hmac('sha256', $t . '.' . $body, $secret);
            $valid = abs(time() - $t) < 600 && array_filter($parts['v1'] ?? [], static fn($s) => hash_equals($expected, $s));
            $e = json_decode($body, true) ?: [];
            if ($valid && ($e['type'] ?? '') === 'checkout.session.completed' && ($e['data']['object']['payment_status'] ?? '') === 'paid') {
                $id = (int)($e['data']['object']['client_reference_id'] ?? 0);
                $order = DB::row('SELECT * FROM {{ln_featured}} WHERE id = :id', ['id' => $id]);
                if ($order && $order['payment_ref'] === (string)($e['data']['object']['id'] ?? '')) {
                    Network::activateFeatured($id, (string)($e['data']['object']['payment_intent'] ?? ''));
                }
            }
            $ok = (bool)$valid;
        }
        if ($provider === 'razorpay' && ($secret = Network::secret('razorpay_webhook_secret')) !== '') {
            $valid = hash_equals(hash_hmac('sha256', $body, $secret), (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? ''));
            $e = json_decode($body, true) ?: [];
            if ($valid && ($e['event'] ?? '') === 'payment_link.paid') {
                $link = $e['payload']['payment_link']['entity'] ?? [];
                if (preg_match('/^lnf-(\d+)$/', (string)($link['reference_id'] ?? ''), $m)) {
                    $order = DB::row('SELECT * FROM {{ln_featured}} WHERE id = :id', ['id' => (int)$m[1]]);
                    if ($order && $order['payment_ref'] === (string)($link['id'] ?? '')) {
                        Network::activateFeatured((int)$m[1], (string)($e['payload']['payment']['entity']['id'] ?? ''));
                    }
                }
            }
            $ok = $valid;
        }
        http_response_code($ok ? 200 : 400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        exit;
    }
}
