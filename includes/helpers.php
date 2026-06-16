<?php
// =============================================================================
// SmartCampus SaaS — includes/helpers.php
// Fonctions utilitaires partagées dans toute l'application.
// =============================================================================

declare(strict_types=1);

// =============================================================================
// A. SÉCURITÉ & ENCODAGE
// =============================================================================

/**
 * Échappe une valeur pour l'affichage HTML (protection XSS).
 */
function escape(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Nettoie une entrée texte (trim + strip tags).
 * À utiliser sur les champs texte non-HTML avant stockage.
 */
function clean(string $value): string
{
    return trim(strip_tags($value));
}

/**
 * Valide un email.
 */
function isValidEmail(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Valide un mot de passe (min 8 chars, 1 majuscule, 1 chiffre).
 */
function isStrongPassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password);
}

/**
 * Retourne l'IP réelle du visiteur (prend en compte les proxies).
 */
function getClientIp(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',   // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

// =============================================================================
// B. FORMATAGE DES DONNÉES
// =============================================================================

/**
 * Formate une date en format lisible français.
 * Ex: "15 juin 2025 à 14h30"
 */
function formatDate(string|null $date, bool $withTime = true): string
{
    if (!$date) return '—';
    try {
        $dt = new DateTime($date);
        $formatter = new IntlDateFormatter(
            'fr_CI',
            IntlDateFormatter::LONG,
            $withTime ? IntlDateFormatter::SHORT : IntlDateFormatter::NONE
        );
        // Fallback si intl non disponible
        if (!$formatter) {
            return $withTime
                ? $dt->format('d/m/Y à H\hi')
                : $dt->format('d/m/Y');
        }
        return $formatter->format($dt) ?: $dt->format('d/m/Y');
    } catch (Exception) {
        return $date;
    }
}

/**
 * Formate une date en "il y a X minutes/heures/jours".
 */
function timeAgo(string|null $date): string
{
    if (!$date) return '—';
    try {
        $dt   = new DateTime($date);
        $now  = new DateTime();
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        if ($diff < 60)       return 'À l\'instant';
        if ($diff < 3600)     return 'Il y a ' . floor($diff / 60) . ' min';
        if ($diff < 86400)    return 'Il y a ' . floor($diff / 3600) . 'h';
        if ($diff < 604800)   return 'Il y a ' . floor($diff / 86400) . ' j';
        return formatDate($date, false);
    } catch (Exception) {
        return $date;
    }
}

/**
 * Formate une note sur 20.
 * Retourne "—" si null.
 */
function formatGrade(mixed $score): string
{
    if ($score === null || $score === '') return '—';
    return number_format((float)$score, 2) . '/20';
}

/**
 * Calcule la moyenne en ignorant les null.
 * Ex: calcAverage([15, null, 12]) → 13.5
 */
function calcAverage(array $scores): float|null
{
    $valid = array_filter($scores, fn($s) => $s !== null && $s !== '');
    if (empty($valid)) return null;
    return array_sum($valid) / count($valid);
}

/**
 * Retourne la lettre de note selon le système sur 20.
 * A(≥16), B(14-16), C(12-14), D(10-12), F(<10)
 */
function getLetterGrade(mixed $score): string
{
    if ($score === null || $score === '') return '—';
    $s = (float)$score;
    return match (true) {
        $s >= 16 => 'A',
        $s >= 14 => 'B',
        $s >= 12 => 'C',
        $s >= 10 => 'D',
        default  => 'F',
    };
}

/**
 * Retourne la classe CSS Bootstrap pour la couleur de badge de note.
 */
function getGradeColor(mixed $score): string
{
    if ($score === null || $score === '') return 'secondary';
    $s = (float)$score;
    return match (true) {
        $s >= 16 => 'success',
        $s >= 14 => 'info',
        $s >= 12 => 'primary',
        $s >= 10 => 'warning',
        default  => 'danger',
    };
}

/**
 * Retourne le libellé du statut utilisateur.
 */
function getStatusLabel(string|null $status): string
{
    return match ($status) {
        'active'    => 'Actif',
        'inactive'  => 'Inactif',
        'suspended' => 'Suspendu',
        default     => 'Inconnu',
    };
}

/**
 * Retourne la classe CSS Bootstrap pour la couleur de badge de statut.
 */
function getStatusColor(string|null $status): string
{
    return match ($status) {
        'active'    => 'success',
        'inactive'  => 'secondary',
        'suspended' => 'danger',
        default     => 'secondary',
    };
}

/**
 * Retourne l'icône Font Awesome du rôle.
 */
function getRoleIcon(string $role): string
{
    return match ($role) {
        'admin'     => 'fa-shield-halved',
        'professor' => 'fa-chalkboard-teacher',
        'student'   => 'fa-graduation-cap',
        default     => 'fa-user',
    };
}

/**
 * Retourne le libellé français du rôle.
 */
function getRoleLabel(string $role): string
{
    return match ($role) {
        'admin'     => 'Administrateur',
        'professor' => 'Professeur',
        'student'   => 'Étudiant',
        default     => $role,
    };
}

// =============================================================================
// C. PAGINATION
// =============================================================================

/**
 * Calcule les données de pagination.
 *
 * @return array{
 *   total_pages: int,
 *   offset: int,
 *   has_previous: bool,
 *   has_next: bool,
 *   previous_page: int,
 *   next_page: int,
 *   from: int,
 *   to: int
 * }
 */
function paginate(int $total, int $perPage, int $currentPage): array
{
    $perPage     = max(1, $perPage);
    $totalPages  = (int)ceil($total / $perPage);
    $currentPage = max(1, min($currentPage, $totalPages ?: 1));
    $offset      = ($currentPage - 1) * $perPage;

    return [
        'total_pages'   => $totalPages,
        'current_page'  => $currentPage,
        'offset'        => $offset,
        'has_previous'  => $currentPage > 1,
        'has_next'      => $currentPage < $totalPages,
        'previous_page' => $currentPage - 1,
        'next_page'     => $currentPage + 1,
        'from'          => $total > 0 ? $offset + 1 : 0,
        'to'            => min($offset + $perPage, $total),
    ];
}

/**
 * Génère le HTML de la pagination Bootstrap 5.
 */
function paginationHtml(array $pag, string $baseUrl): string
{
    if ($pag['total_pages'] <= 1) return '';

    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    $html = '<nav aria-label="Pagination"><ul class="pagination justify-content-center">';

    // Première / Précédente
    if ($pag['has_previous']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=1">«</a></li>';
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . $pag['previous_page'] . '">‹</a></li>';
    }

    // Pages numérotées (fenêtre de 5)
    $start = max(1, $pag['current_page'] - 2);
    $end   = min($pag['total_pages'], $pag['current_page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $pag['current_page'] ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . $i . '">' . $i . '</a></li>';
    }

    // Suivante / Dernière
    if ($pag['has_next']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . $pag['next_page'] . '">›</a></li>';
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . $pag['total_pages'] . '">»</a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

// =============================================================================
// D. FICHIERS & UPLOADS
// =============================================================================

/**
 * Retourne l'URL publique d'une photo de profil.
 * Si la photo n'existe pas, retourne l'URL de l'avatar par défaut.
 */
function profilePictureUrl(string|null $picture): string
{
    if (!empty($picture)) {
        $fullPath = ROOT_PATH . '/uploads/' . $picture;
        if (file_exists($fullPath)) {
            return APP_URL . '/uploads/' . ltrim($picture, '/');
        }
    }
    // Avatar généré avec les initiales (service externe léger)
    return APP_URL . '/css/img/default-avatar.png';
}

/**
 * Retourne l'URL du logo d'un tenant.
 */
function tenantLogoUrl(array|null $tenant): string
{
    if (!empty($tenant['logo'])) {
        $fullPath = ROOT_PATH . '/uploads/logos/' . $tenant['logo'];
        if (file_exists($fullPath)) {
            return APP_URL . '/uploads/logos/' . $tenant['logo'];
        }
    }
    return APP_URL . '/css/img/default-logo.png';
}

/**
 * Vérifie le type MIME réel d'un fichier uploadé (pas juste l'extension).
 */
function getFileMimeType(string $tmpPath): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    return $mime ?: '';
}

/**
 * Vérifie qu'un fichier uploadé est une image valide.
 */
function isValidImage(array $file): bool
{
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime = getFileMimeType($file['tmp_name']);
    return in_array($mime, $allowedMimes, true)
        && $file['size'] <= UPLOAD_MAX_SIZE
        && $file['error'] === UPLOAD_ERR_OK;
}

// =============================================================================
// E. REDIRECTIONS & HTTP
// =============================================================================

/**
 * Redirige proprement (code 302 par défaut).
 */
function redirect(string $url, int $code = 302): never
{
    http_response_code($code);
    header("Location: $url");
    exit;
}

/**
 * Redirige vers la page précédente ou vers une URL de secours.
 */
function redirectBack(string $fallback = '/'): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    redirect($ref ?: $fallback);
}

/**
 * Retourne true si la requête est une requête AJAX.
 */
function isAjax(): bool
{
    return (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
        || (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json');
}

/**
 * Envoie une réponse JSON et arrête l'exécution.
 */
function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Envoie une réponse JSON d'erreur.
 */
function jsonError(string $message, int $status = 400): never
{
    jsonResponse(['success' => false, 'error' => $message], $status);
}

// =============================================================================
// F. FLASH MESSAGES (messages d'alerte one-shot)
// =============================================================================

/**
 * Stocke un message flash en session.
 * @param string $type 'success' | 'error' | 'warning' | 'info'
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Retourne et supprime les messages flash de la session.
 */
function getFlashMessages(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * Affiche les messages flash en HTML Bootstrap 5.
 */
function displayFlashMessages(): string
{
    $messages = getFlashMessages();
    if (empty($messages)) return '';

    $map = [
        'success' => ['class' => 'alert-success', 'icon' => 'fa-circle-check'],
        'error'   => ['class' => 'alert-danger',  'icon' => 'fa-circle-exclamation'],
        'warning' => ['class' => 'alert-warning', 'icon' => 'fa-triangle-exclamation'],
        'info'    => ['class' => 'alert-info',    'icon' => 'fa-circle-info'],
    ];

    $html = '';
    foreach ($messages as $msg) {
        $cfg   = $map[$msg['type']] ?? $map['info'];
        $html .= '<div class="alert ' . $cfg['class'] . ' alert-dismissible fade show d-flex align-items-center" role="alert">'
            . '<i class="fas ' . $cfg['icon'] . ' me-2"></i>'
            . '<div>' . escape($msg['message']) . '</div>'
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
            . '</div>';
    }
    return $html;
}

// =============================================================================
// G. GÉNÉRATION D'IDENTIFIANTS
// =============================================================================

/**
 * Génère un matricule étudiant unique.
 * Format : STU-YYYY-XXXXX (ex: STU-2025-00042)
 */
function generateStudentId(int $userId): string
{
    return 'STU-' . date('Y') . '-' . str_pad((string)$userId, 5, '0', STR_PAD_LEFT);
}

/**
 * Génère un matricule professeur unique.
 * Format : PROF-YYYY-XXXXX
 */
function generateEmployeeId(int $userId): string
{
    return 'PROF-' . date('Y') . '-' . str_pad((string)$userId, 5, '0', STR_PAD_LEFT);
}

/**
 * Génère un slug URL-friendly à partir d'un texte.
 * Ex: "Institut Universitaire d'Abidjan" → "institut-universitaire-d-abidjan"
 */
function slugify(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    // Translittérer les caractères accentués
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = preg_replace('/[^a-z0-9\s\-]/', '', $text ?? '');
    $text = preg_replace('/[\s\-]+/', '-', $text ?? '');
    return trim($text ?? '', '-');
}

/**
 * Tronque un texte à N caractères en préservant les mots.
 */
function truncate(string|null $text, int $length = 100, string $suffix = '...'): string
{
    if (!$text) return '';
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length - mb_strlen($suffix)) . $suffix;
}

// =============================================================================
// H. COULEURS TENANT (CSS dynamique)
// =============================================================================

/**
 * Génère les variables CSS du tenant pour personnaliser l'UI.
 */
function tenantCssVars(array|null $tenant): string
{
    $primary   = $tenant['primary_color']   ?? '#0b2b4f';
    $secondary = $tenant['secondary_color'] ?? '#ffb347';
    // Valider que ce sont des couleurs hex valides
    $primary   = preg_match('/^#[0-9a-fA-F]{6}$/', $primary)   ? $primary   : '#0b2b4f';
    $secondary = preg_match('/^#[0-9a-fA-F]{6}$/', $secondary) ? $secondary : '#ffb347';

    return "<style>:root{--sc-primary:{$primary};--sc-secondary:{$secondary};}</style>";
}
