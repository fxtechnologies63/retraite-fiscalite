<?php
/**
 * webhook.php
 * Reçoit et traite les événements Stripe (confirmations de paiement).
 * 
 * À configurer dans Stripe Dashboard → Développeurs → Webhooks
 * URL : https://votre-domaine.com/api/webhook.php
 * Événements à écouter :
 *   - checkout.session.completed
 *   - customer.subscription.deleted
 *   - invoice.payment_failed
 */

// Pas de header JSON ici — Stripe attend juste un HTTP 200
$config_file = __DIR__ . '/../.env.php';
if (file_exists($config_file)) {
    require_once $config_file;
} else {
    define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: '');
    define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');
    define('APP_URL', getenv('APP_URL') ?: '');
    define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: '');
}

// ── Lecture du payload brut ───────────────────────────────────
$payload   = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhook_secret = STRIPE_WEBHOOK_SECRET;

// ── Vérification de la signature Stripe ──────────────────────
if (!empty($webhook_secret)) {
    $event = verify_stripe_signature($payload, $sig_header, $webhook_secret);
    if ($event === false) {
        http_response_code(400);
        echo 'Signature invalide';
        exit();
    }
} else {
    // En développement sans secret webhook, on parse directement
    $event = json_decode($payload, true);
    if (!$event) {
        http_response_code(400);
        echo 'Payload invalide';
        exit();
    }
}

// ── Log de l'événement ───────────────────────────────────────
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}
$log_entry = date('Y-m-d H:i:s') . ' | ' . $event['type'] . ' | ' . $event['id'] . PHP_EOL;
@file_put_contents($log_dir . '/stripe-events.log', $log_entry, FILE_APPEND);

// ── Traitement selon le type d'événement ─────────────────────
switch ($event['type']) {

    case 'checkout.session.completed':
        $session = $event['data']['object'];
        $type    = $session['metadata']['type'] ?? 'inconnu';
        $email   = $session['customer_email'] ?? $session['customer_details']['email'] ?? '';

        if ($type === 'abonnement') {
            // Abonnement conseiller activé
            handle_new_subscription($session, $email);
        } elseif ($type === 'rapport') {
            // Rapport client payé — envoyer le PDF
            handle_rapport_payment($session, $email);
        }
        break;

    case 'customer.subscription.deleted':
        // Abonnement résilié
        $subscription = $event['data']['object'];
        handle_subscription_cancelled($subscription);
        break;

    case 'invoice.payment_failed':
        // Paiement mensuel échoué
        $invoice = $event['data']['object'];
        handle_payment_failed($invoice);
        break;

    default:
        // Événement ignoré — on renvoie quand même 200
        break;
}

http_response_code(200);
echo 'OK';

// ════════════════════════════════════════════════════════════════
// HANDLERS
// ════════════════════════════════════════════════════════════════

function handle_new_subscription($session, $email) {
    $subscription_id = $session['subscription'] ?? '';
    $customer_id     = $session['customer'] ?? '';

    // Log
    log_event('NEW_SUBSCRIPTION', [
        'email'           => $email,
        'subscription_id' => $subscription_id,
        'customer_id'     => $customer_id,
    ]);

    // Envoyer email de bienvenue conseiller
    send_email(
        $email,
        'Bienvenue sur Retraite & Fiscalité — Espace Conseiller',
        "Bonjour,\n\nVotre abonnement conseiller est maintenant actif.\n" .
        "Vous pouvez accéder à l'espace conseiller sans limite de simulations.\n\n" .
        "Votre identifiant d'abonnement : {$subscription_id}\n\n" .
        "Cordialement,\nL'équipe Retraite & Fiscalité"
    );

    // Notifier l'admin
    if (ADMIN_EMAIL) {
        send_email(
            ADMIN_EMAIL,
            '[Admin] Nouvel abonnement conseiller',
            "Nouvel abonnement :\nEmail : {$email}\nSubscription : {$subscription_id}\nCustomer : {$customer_id}"
        );
    }
}

function handle_rapport_payment($session, $email) {
    $payment_intent = $session['payment_intent'] ?? '';
    $metadata       = $session['metadata'] ?? [];

    // Log
    log_event('RAPPORT_PAID', [
        'email'          => $email,
        'payment_intent' => $payment_intent,
        'metadata'       => $metadata,
    ]);

    // TODO : Générer et envoyer le PDF rapport
    // Pour l'instant, notifier l'admin pour traitement manuel
    send_email(
        $email,
        'Votre rapport Retraite & Fiscalité',
        "Bonjour,\n\nMerci pour votre paiement.\n" .
        "Votre rapport détaillé vous sera envoyé dans les 24h.\n\n" .
        "Référence : {$payment_intent}\n\n" .
        "Cordialement,\nL'équipe Retraite & Fiscalité"
    );

    if (ADMIN_EMAIL) {
        send_email(
            ADMIN_EMAIL,
            '[Admin] Nouveau rapport client payé — ' . $email,
            "Rapport payé :\nEmail : {$email}\nPayment Intent : {$payment_intent}\n" .
            "Métadonnées :\n" . json_encode($metadata, JSON_PRETTY_PRINT)
        );
    }
}

function handle_subscription_cancelled($subscription) {
    $customer_id = $subscription['customer'] ?? '';
    log_event('SUBSCRIPTION_CANCELLED', ['customer_id' => $customer_id]);

    if (ADMIN_EMAIL) {
        send_email(
            ADMIN_EMAIL,
            '[Admin] Abonnement résilié',
            "Abonnement résilié :\nCustomer ID : {$customer_id}"
        );
    }
}

function handle_payment_failed($invoice) {
    $email       = $invoice['customer_email'] ?? '';
    $customer_id = $invoice['customer'] ?? '';
    log_event('PAYMENT_FAILED', ['email' => $email, 'customer_id' => $customer_id]);

    if ($email) {
        send_email(
            $email,
            'Problème de paiement — Retraite & Fiscalité',
            "Bonjour,\n\nNous n'avons pas pu prélever votre abonnement mensuel.\n" .
            "Veuillez mettre à jour votre moyen de paiement pour continuer à accéder à l'espace conseiller.\n\n" .
            "Cordialement,\nL'équipe Retraite & Fiscalité"
        );
    }
}

// ════════════════════════════════════════════════════════════════
// UTILITAIRES
// ════════════════════════════════════════════════════════════════

function verify_stripe_signature($payload, $sig_header, $secret) {
    $parts = explode(',', $sig_header);
    $timestamp = null;
    $signatures = [];

    foreach ($parts as $part) {
        [$key, $value] = explode('=', $part, 2);
        if ($key === 't') $timestamp = $value;
        if ($key === 'v1') $signatures[] = $value;
    }

    if (!$timestamp || empty($signatures)) return false;

    // Tolérance de 5 minutes
    if (abs(time() - (int)$timestamp) > 300) return false;

    $signed_payload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed_payload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return json_decode($payload, true);
        }
    }

    return false;
}

function send_email($to, $subject, $body) {
    $headers = "From: noreply@retraite-fiscalite.fr\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n" .
               "X-Mailer: PHP/" . phpversion();
    @mail($to, $subject, $body, $headers);
}

function log_event($type, $data) {
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
    $entry = date('Y-m-d H:i:s') . ' | ' . $type . ' | ' . json_encode($data) . PHP_EOL;
    @file_put_contents($log_dir . '/payments.log', $entry, FILE_APPEND);
}
