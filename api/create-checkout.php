<?php
/**
 * create-checkout.php
 * Crée une session Stripe Checkout et retourne l'URL de paiement.
 * 
 * POST /api/create-checkout.php
 * Body JSON : { "type": "abonnement" | "rapport", "email": "...", "metadata": {...} }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Répondre aux preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit();
}

// ── Configuration ──────────────────────────────────────────────
// ⚠️  NE JAMAIS committer sk_live_... sur GitHub
// Mettez la clé secrète dans un fichier .env.php NON versionné
// ou dans les variables d'environnement OVH
$config_file = __DIR__ . '/../.env.php';
if (file_exists($config_file)) {
    require_once $config_file;
} else {
    // Fallback : variables d'environnement serveur
    define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');
    define('APP_URL', getenv('APP_URL') ?: 'https://votre-domaine.com');
}

$stripe_secret = STRIPE_SECRET_KEY;
if (empty($stripe_secret)) {
    http_response_code(500);
    echo json_encode(['error' => 'Clé Stripe non configurée']);
    exit();
}

// ── Price IDs Stripe ──────────────────────────────────────────
$PRICES = [
    'abonnement' => 'price_1T9vFj8RcM1rCQsNegMXfjs2', // 29 €/mois
    'rapport'    => 'price_1T9vHL8RcM1rCQsN1D1aGttZ', // 10 € unique
];

// ── Lecture du body JSON ──────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$type     = $body['type']  ?? '';
$email    = $body['email'] ?? '';
$metadata = $body['metadata'] ?? [];

if (!isset($PRICES[$type])) {
    http_response_code(400);
    echo json_encode(['error' => 'Type invalide. Utilisez "abonnement" ou "rapport"']);
    exit();
}

// ── Construction de la session Checkout ──────────────────────
$price_id = $PRICES[$type];
$is_subscription = ($type === 'abonnement');

$session_params = [
    'payment_method_types' => ['card'],
    'line_items' => [[
        'price'    => $price_id,
        'quantity' => 1,
    ]],
    'mode'        => $is_subscription ? 'subscription' : 'payment',
    'success_url' => APP_URL . '/merci.html?session_id={CHECKOUT_SESSION_ID}&type=' . $type,
    'cancel_url'  => APP_URL . '/app.html?payment=cancel',
    'metadata'    => array_merge($metadata, ['type' => $type]),
];

// Pré-remplir l'email si fourni
if (!empty($email)) {
    $session_params['customer_email'] = $email;
}

// ── Appel API Stripe ──────────────────────────────────────────
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_USERPWD        => $stripe_secret . ':',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => http_build_query_nested($session_params),
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur réseau : ' . $curl_error]);
    exit();
}

$data = json_decode($response, true);

if ($http_code !== 200) {
    http_response_code($http_code);
    echo json_encode([
        'error' => $data['error']['message'] ?? 'Erreur Stripe inconnue'
    ]);
    exit();
}

// ── Succès : retourner l'URL de checkout ─────────────────────
echo json_encode([
    'url'        => $data['url'],
    'session_id' => $data['id'],
]);

// ── Helper : http_build_query pour tableaux imbriqués ────────
function http_build_query_nested($data, $prefix = '') {
    $result = [];
    foreach ($data as $key => $value) {
        $full_key = $prefix ? "{$prefix}[{$key}]" : $key;
        if (is_array($value)) {
            $result[] = http_build_query_nested($value, $full_key);
        } else {
            $result[] = urlencode($full_key) . '=' . urlencode($value);
        }
    }
    return implode('&', $result);
}
