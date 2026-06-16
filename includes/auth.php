<?php
// =============================================================================
// SmartCampus SaaS — includes/auth.php
// Gestion complète : sessions, CSRF, rôles, rate limiting, tenant.
// Ce fichier doit être inclus APRÈS config.php.
// =============================================================================

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/helpers.php';

// On a besoin de la DB pour charger le tenant et l'utilisateur
require_once dirname(__DIR__) . '/app/models/Database.php';
require_once dirname(__DIR__) . '/app/models/Tenant.php';
require_once dirname(__DIR__) . '/app/models/User.php';

// =============================================================================
// A. INITIALISATION DU TENANT
// =============================================================================

/**
 * Charge le tenant depuis la BDD à partir du slug résolu dans config.php.
 * Stocke le tenant dans $GLOBALS['current_tenant'] et en session.
 */
function initTenant(): void
{
    $slug = getTenantSlug();

    // Pages publiques (landing, register, super_admin) → pas de tenant requis
    if ($slug === null || isPublicRoute()) {
        return;
    }

    // Déjà chargé dans cette requête ?
    if (!empty($GLOBALS['current_tenant'])) {
        return;
    }

    // Tentative depuis la session (économise une requête BDD)
    if (!empty($_SESSION['tenant']) && ($_SESSION['tenant']['slug'] ?? '') === $slug) {
        $GLOBALS['current_tenant'] = $_SESSION['tenant'];
        return;
    }

    // Charger depuis la BDD
    $tenantModel = new Tenant();
    $tenant      = $tenantModel->findBySlug($slug);

    if (!$tenant) {
        // Tenant inconnu → 404
        http_response_code(404);
        $notFound = PUBLIC_PATH . '/404.php';
        if (file_exists($notFound)) include $notFound;
        exit;
    }

    if ($tenant['status'] !== 'active') {
        // Tenant suspendu → page d'erreur
        http_response_code(503);
        die('<h1>Cet établissement est temporairement suspendu.</h1><p>Contactez le support SmartCampus.</p>');
    }

    $GLOBALS['current_tenant'] = $tenant;
    $_SESSION['tenant']        = $tenant;
}

// =============================================================================
// B. GESTION DE SESSION UTILISATEUR
// =============================================================================

/**
 * Vérifie si un utilisateur est connecté.
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id'])
        && !empty($_SESSION['user_role'])
        && !empty($_SESSION['tenant_id']);
}

/**
 * Retourne l'ID de l'utilisateur connecté ou null.
 */
function getCurrentUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Retourne les données complètes de l'utilisateur connecté (depuis session).
 */
function getCurrentUser(): ?array
{
    if (!isLoggedIn()) return null;
    return [
        'id'              => (int)$_SESSION['user_id'],
        'email'           => $_SESSION['user_email']           ?? '',
        'role'            => $_SESSION['user_role']            ?? '',
        'first_name'      => $_SESSION['user_first_name']      ?? '',
        'last_name'       => $_SESSION['user_last_name']       ?? '',
        'profile_picture' => $_SESSION['user_profile_picture'] ?? '',
        'tenant_id'       => (int)($_SESSION['tenant_id']      ?? 0),
    ];
}

/**
 * Crée la session utilisateur après authentification réussie.
 * Régénère l'ID de session (protection contre la fixation de session).
 */
function createUserSession(array $user, int $tenantId): void
{
    // Régénérer l'ID pour éviter la fixation de session
    session_regenerate_id(true);

    $_SESSION['user_id']              = (int)$user['id'];
    $_SESSION['user_email']           = $user['email'];
    $_SESSION['user_role']            = $user['role'];
    $_SESSION['user_first_name']      = $user['first_name'];
    $_SESSION['user_last_name']       = $user['last_name'];
    $_SESSION['user_profile_picture'] = $user['profile_picture'] ?? '';
    $_SESSION['tenant_id']            = $tenantId;
    $_SESSION['logged_in_at']         = time();
}

/**
 * Détruit la session complètement.
 */
function logoutUser(): void
{
    // Conserver le tenant pour la redirection
    $slug = $_SESSION['tenant']['slug'] ?? null;

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();

    // Rediriger vers la page de connexion du tenant
    $redirect = $slug
        ? APP_URL . "/$slug/login.php"
        : APP_URL . '/index.php';

    header("Location: $redirect");
    exit;
}

// =============================================================================
// C. CONTRÔLE DES RÔLES
// =============================================================================

/**
 * Vérifie si l'utilisateur a un rôle précis.
 */
function hasRole(string $role): bool
{
    return ($_SESSION['user_role'] ?? '') === $role;
}

/**
 * Vérifie si l'utilisateur a l'un des rôles listés.
 */
function hasAnyRole(array $roles): bool
{
    return in_array($_SESSION['user_role'] ?? '', $roles, true);
}

/**
 * Redirige vers login si non connecté.
 */
function requireLogin(): void
{
    initTenant();
    if (!isLoggedIn()) {
        $slug     = getTenantSlug();
        $redirect = $slug
            ? APP_URL . "/$slug/login.php"
            : APP_URL . '/index.php';
        header("Location: $redirect");
        exit;
    }
    // Vérifier l'expiration de session (double sécurité)
    if (isset($_SESSION['logged_in_at'])
        && (time() - $_SESSION['logged_in_at']) > SESSION_LIFETIME) {
        logoutUser();
    }
}

/**
 * Exige un rôle précis, sinon → 403.
 */
function requireRole(string $role): void
{
    requireLogin();
    if (!hasRole($role)) {
        forbidden();
    }
}

/**
 * Exige l'un des rôles listés, sinon → 403.
 */
function requireAnyRole(array $roles): void
{
    requireLogin();
    if (!hasAnyRole($roles)) {
        forbidden();
    }
}

/**
 * Exige le super admin (rôle système, pas lié à un tenant).
 */
function requireSuperAdmin(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['super_admin'])) {
        header('Location: ' . APP_URL . '/super_admin/login.php');
        exit;
    }
}

/**
 * Affiche la page 403.
 */
function forbidden(): never
{
    http_response_code(403);
    $page = PUBLIC_PATH . '/403.php';
    if (file_exists($page)) {
        include $page;
    } else {
        echo '<h1>403 — Accès interdit</h1>';
    }
    exit;
}

// =============================================================================
// D. PROTECTION CSRF
// =============================================================================

/**
 * Génère ou retourne le token CSRF de la session.
 */
function getCSRFToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie un token CSRF soumis via POST.
 * Utilise hash_equals pour éviter les timing attacks.
 */
function verifyCSRFToken(string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    if ($valid) {
        // Rotation du token après usage (one-time token)
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $valid;
}

/**
 * Génère un champ CSRF caché pour les formulaires HTML.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(getCSRFToken()) . '">';
}

// =============================================================================
// E. RATE LIMITING (protection brute-force sur le login)
// =============================================================================

/**
 * Clé de stockage session pour les tentatives de login d'une IP.
 */
function _rateLimitKey(string $ip): string
{
    return 'rl_' . hash('sha256', $ip);
}

/**
 * Retourne true si l'IP est bloquée (trop de tentatives).
 */
function isRateLimited(string $ip): bool
{
    $key  = _rateLimitKey($ip);
    $data = $_SESSION[$key] ?? null;
    if (!$data) return false;

    // Vérifier si le délai de blocage est écoulé
    if ($data['locked_until'] && time() < $data['locked_until']) {
        return true;
    }

    // Débloquer si le délai est passé
    if ($data['locked_until'] && time() >= $data['locked_until']) {
        unset($_SESSION[$key]);
    }

    return false;
}

/**
 * Enregistre une tentative de login échouée.
 */
function recordFailedAttempt(string $ip): void
{
    $key  = _rateLimitKey($ip);
    $data = $_SESSION[$key] ?? ['attempts' => 0, 'locked_until' => null, 'first_attempt' => time()];

    $data['attempts']++;

    if ($data['attempts'] >= LOGIN_MAX_ATTEMPTS) {
        $data['locked_until'] = time() + LOGIN_LOCKOUT_TIME;
    }

    $_SESSION[$key] = $data;
}

/**
 * Réinitialise le compteur après une connexion réussie.
 */
function clearFailedAttempts(string $ip): void
{
    unset($_SESSION[_rateLimitKey($ip)]);
}

/**
 * Retourne le nombre de secondes restantes avant déverrouillage.
 */
function getRateLimitRemainingSeconds(string $ip): int
{
    $data = $_SESSION[_rateLimitKey($ip)] ?? null;
    if (!$data || !$data['locked_until']) return 0;
    return max(0, $data['locked_until'] - time());
}

/**
 * Retourne le nombre de tentatives restantes avant blocage.
 */
function getRemainingAttempts(string $ip): int
{
    $data = $_SESSION[_rateLimitKey($ip)] ?? null;
    if (!$data) return LOGIN_MAX_ATTEMPTS;
    return max(0, LOGIN_MAX_ATTEMPTS - $data['attempts']);
}

// =============================================================================
// F. INITIALISATION AU CHARGEMENT
// =============================================================================
// Charger le tenant dès l'inclusion de auth.php
initTenant();
