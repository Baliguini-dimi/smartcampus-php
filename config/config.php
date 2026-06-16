<?php
// =============================================================================
// SmartCampus SaaS — config/config.php
// Point d'entrée de la configuration : charge .env, résout le tenant,
// initialise la session et définit toutes les constantes de l'application.
// =============================================================================

declare(strict_types=1);

// -----------------------------------------------------------------------------
// 1. CHARGEMENT DU FICHIER .env
// -----------------------------------------------------------------------------
$envFile = dirname(__DIR__) . '/.env';

if (!file_exists($envFile)) {
    die('[SmartCampus] Fichier .env introuvable. Copiez .env.example en .env et configurez-le.');
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    $line = trim($line);
    // Ignorer les commentaires et lignes vides
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (str_contains($line, '=')) {
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Helper pour lire .env avec valeur par défaut
function env(string $key, mixed $default = null): mixed
{
    $val = $_ENV[$key] ?? getenv($key);
    if ($val === false || $val === '') {
        return $default;
    }
    // Convertir les booléens texte
    return match (strtolower((string)$val)) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        default => $val,
    };
}

// -----------------------------------------------------------------------------
// 2. CONSTANTES APPLICATION
// -----------------------------------------------------------------------------
define('APP_NAME',    env('APP_NAME', 'SmartCampus'));
define('APP_ENV',     env('APP_ENV',  'local'));
define('APP_DEBUG',   env('APP_DEBUG', false));
define('APP_URL',     rtrim((string)env('APP_URL', 'http://localhost/smartcampus_php/public'), '/'));
define('APP_SECRET',  env('APP_SECRET', 'changez_moi'));
define('ROOT_PATH',   dirname(__DIR__));
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');

// -----------------------------------------------------------------------------
// 3. CONSTANTES BASE DE DONNÉES
// -----------------------------------------------------------------------------
define('DB_HOST',    env('DB_HOST',    '127.0.0.1'));
define('DB_PORT',    env('DB_PORT',    '3306'));
define('DB_NAME',    env('DB_NAME',    'smartcampus'));
define('DB_USER',    env('DB_USER',    'root'));
define('DB_PASS',    env('DB_PASS',    ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// -----------------------------------------------------------------------------
// 4. CONSTANTES SÉCURITÉ
// -----------------------------------------------------------------------------
define('SESSION_LIFETIME',   (int)env('SESSION_LIFETIME',   7200));
define('SESSION_NAME',       env('SESSION_NAME', 'smartcampus_session'));
define('LOGIN_MAX_ATTEMPTS', (int)env('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_TIME', (int)env('LOGIN_LOCKOUT_TIME', 900));
define('CSRF_TOKEN_LENGTH',  32);

// -----------------------------------------------------------------------------
// 5. CONSTANTES UPLOADS
// -----------------------------------------------------------------------------
define('UPLOAD_MAX_SIZE',      (int)env('UPLOAD_MAX_SIZE', 2097152));
define('UPLOAD_ALLOWED_TYPES', explode(',', (string)env('UPLOAD_ALLOWED_TYPES', 'jpg,jpeg,png,webp')));

// -----------------------------------------------------------------------------
// 6. CONSTANTES API IA
// -----------------------------------------------------------------------------
define('GROQ_API_KEY',    env('GROQ_API_KEY',    ''));
define('GROQ_MODEL',      env('GROQ_MODEL',      'llama-3.3-70b-versatile'));
define('GROQ_MAX_TOKENS', (int)env('GROQ_MAX_TOKENS', 1024));

// -----------------------------------------------------------------------------
// 7. CONSTANTES PAGINATION ET SUPER ADMIN
// -----------------------------------------------------------------------------
define('ITEMS_PER_PAGE',        (int)env('ITEMS_PER_PAGE', 15));
define('SUPER_ADMIN_EMAIL',     env('SUPER_ADMIN_EMAIL', ''));

// -----------------------------------------------------------------------------
// 8. CONFIGURATION PHP — AFFICHAGE DES ERREURS
// -----------------------------------------------------------------------------
if (APP_DEBUG && APP_ENV === 'local') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
    // Logger les erreurs dans un fichier en production
    ini_set('log_errors', '1');
    ini_set('error_log', ROOT_PATH . '/logs/error.log');
}

// -----------------------------------------------------------------------------
// 9. CONFIGURATION SESSION SÉCURISÉE
// -----------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (APP_ENV === 'production'),   // HTTPS uniquement en prod
        'httponly' => true,                          // Inaccessible via JS
        'samesite' => 'Strict',                     // Protection CSRF
    ]);
    session_start();
}

// -----------------------------------------------------------------------------
// 10. RÉSOLUTION DU TENANT (Multitenant SaaS)
// -----------------------------------------------------------------------------
// Stratégie B+ : slug dans l'URL + support sous-domaine
//
// URL slug    : smartcampus.com/public/iua/dashboard.php
//               → $_GET['tenant'] = 'iua' (via .htaccess RewriteRule)
//               → ou segment de l'URL courant
//
// Sous-domaine: iua.smartcampus.com
//               → extrait depuis $_SERVER['HTTP_HOST']

function resolveTenantSlug(): ?string
{
    // Priorité 1 : paramètre GET injecté par .htaccess
    if (!empty($_GET['_tenant'])) {
        return preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['_tenant']));
    }

    // Priorité 2 : sous-domaine (iua.smartcampus.com)
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $parts = explode('.', $host);
    if (count($parts) >= 3) {
        $sub = strtolower($parts[0]);
        // Exclure www et les sous-domaines système
        if (!in_array($sub, ['www', 'api', 'admin', 'super'], true)) {
            return preg_replace('/[^a-z0-9\-]/', '', $sub);
        }
    }

    // Priorité 3 : segment de l'URI courante
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // Supprime le préfixe du dossier d'installation si besoin
    $basePath = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    $uri = '/' . ltrim(str_replace($basePath, '', $uri), '/');
    // Pattern : /slug/page.php
    if (preg_match('#^/([a-z0-9\-]+)/[a-z]#', $uri, $m)) {
        $reserved = ['super_admin', 'css', 'js', 'uploads', 'assets'];
        if (!in_array($m[1], $reserved, true)) {
            return $m[1];
        }
    }

    return null;
}

// Détecter si on est sur une page publique (landing, register, super_admin)
function isPublicRoute(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $publicPrefixes = ['/super_admin', '/css', '/js', '/assets'];
    $publicFiles    = ['index.php', 'pricing.php', 'register_tenant.php', '404.php', '403.php'];

    foreach ($publicPrefixes as $prefix) {
        if (str_contains($uri, $prefix)) return true;
    }
    foreach ($publicFiles as $file) {
        if (str_contains($uri, $file) && !str_contains($uri, '/iua/') && !str_contains($uri, '/uvci/')) {
            // Heuristique simple : si le fichier n'est pas précédé d'un slug
            $slug = resolveTenantSlug();
            if ($slug === null) return true;
        }
    }
    return false;
}

// Charger le tenant actif depuis la session ou l'URL
$GLOBALS['current_tenant'] = null;
$GLOBALS['tenant_slug']    = resolveTenantSlug();

// Le tenant sera chargé depuis la BDD dans auth.php une fois PDO disponible
// On définit ici seulement les helpers d'accès

function getCurrentTenant(): ?array
{
    return $GLOBALS['current_tenant'] ?? null;
}

function getCurrentTenantId(): ?int
{
    $t = getCurrentTenant();
    return $t ? (int)$t['id'] : null;
}

function getTenantSlug(): ?string
{
    return $GLOBALS['tenant_slug'] ?? null;
}

// URL helper tenant-aware
function tenantUrl(string $page, array $params = []): string
{
    $slug = getTenantSlug();
    $base = APP_URL . ($slug ? "/$slug" : '');
    $url  = "$base/$page";
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

// -----------------------------------------------------------------------------
// 11. AUTOLOAD MANUEL (fallback si Composer absent)
// Priorité à Composer PSR-4 si vendor/ existe
// -----------------------------------------------------------------------------
$composerAutoload = ROOT_PATH . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Autoload des modèles maison
spl_autoload_register(function (string $class): void {
    $file = ROOT_PATH . '/app/models/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
