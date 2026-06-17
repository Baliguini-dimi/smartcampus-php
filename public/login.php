<?php
// =============================================================================
// SmartCampus SaaS — public/login.php
// Connexion + Inscription sécurisée avec tenant résolu
// =============================================================================
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/app/models/Database.php';
require_once dirname(__DIR__) . '/app/models/Tenant.php';
require_once dirname(__DIR__) . '/app/models/User.php';
require_once dirname(__DIR__) . '/app/models/Student.php';
require_once dirname(__DIR__) . '/app/models/Professor.php';
require_once dirname(__DIR__) . '/app/models/ActivityLog.php';

// Si déjà connecté → dashboard
if (isLoggedIn()) {
    redirect(tenantUrl('dashboard.php'));
}

$tenant = getCurrentTenant();
if (!$tenant) {
    redirect(APP_URL . '/index.php');
}

$tenantId  = (int)$tenant['id'];
$userModel = new User();
$logModel  = new ActivityLog();
$ip        = getClientIp();
$error     = '';
$success   = '';
$activeTab = $_GET['tab'] ?? 'login';

// =============================================================================
// TRAITEMENT DES FORMULAIRES
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Vérification CSRF
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Requête invalide. Veuillez réessayer.';
    }

    // -----------------------------------------------------------------------
    // CONNEXION
    // -----------------------------------------------------------------------
    elseif (isset($_POST['action']) && $_POST['action'] === 'login') {
        $activeTab = 'login';
        $email     = clean($_POST['email']    ?? '');
        $password  = $_POST['password']       ?? '';

        // Rate limiting
        if (isRateLimited($ip)) {
            $remaining = getRateLimitRemainingSeconds($ip);
            $minutes   = ceil($remaining / 60);
            $error     = "Trop de tentatives. Réessayez dans {$minutes} minute(s).";
        } elseif (empty($email) || empty($password)) {
            $error = 'Email et mot de passe requis.';
        } elseif (!isValidEmail($email)) {
            $error = 'Adresse email invalide.';
        } else {
            $user = $userModel->authenticate($email, $password, $tenantId);

            if (!$user) {
                recordFailedAttempt($ip);
                $remaining = getRemainingAttempts($ip);
                $error = 'Email ou mot de passe incorrect.';
                if ($remaining <= 2 && $remaining > 0) {
                    $error .= " ($remaining tentative(s) restante(s) avant blocage)";
                }
                $logModel->log($tenantId, null, 'login_failed', "Échec connexion : $email", 'user');
            } else {
                clearFailedAttempts($ip);
                createUserSession($user, $tenantId);
                $logModel->log($tenantId, $user['id'], 'login', 'Connexion réussie', 'user', $user['id']);

                // Redirection selon le rôle
                redirect(tenantUrl('dashboard.php'));
            }
        }
    }

    // -----------------------------------------------------------------------
    // INSCRIPTION
    // -----------------------------------------------------------------------
    elseif (isset($_POST['action']) && $_POST['action'] === 'register') {
        $activeTab  = 'register';
        $firstName  = clean($_POST['first_name'] ?? '');
        $lastName   = clean($_POST['last_name']  ?? '');
        $email      = clean($_POST['email']       ?? '');
        $password   = $_POST['password']          ?? '';
        $password2  = $_POST['password_confirm']  ?? '';

        // ⚠️ Sécurité critique : on n'accepte jamais 'admin' via inscription publique
        $roleInput  = $_POST['role'] ?? 'student';
        $role       = in_array($roleInput, ['student', 'professor'], true) ? $roleInput : 'student';

        // Validations
        if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
            $error = 'Tous les champs sont obligatoires.';
        } elseif (!isValidEmail($email)) {
            $error = 'Adresse email invalide.';
        } elseif (!isStrongPassword($password)) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères, 1 majuscule et 1 chiffre.';
        } elseif ($password !== $password2) {
            $error = 'Les mots de passe ne correspondent pas.';
        } elseif ($userModel->emailExists($email, $tenantId)) {
            $error = 'Cette adresse email est déjà utilisée.';
        } else {
            // Vérifier les limites du plan pour les étudiants
            $tenantModel = new Tenant();
            if ($role === 'student' && !$tenantModel->canAddStudent($tenantId, $tenant['plan'])) {
                $error = 'Le quota d\'étudiants de cet établissement est atteint.';
            } else {
                $userId = $userModel->create([
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'email'      => $email,
                    'password'   => $password,
                    'role'       => $role,
                ], $tenantId);

                if ($userId) {
                    // Créer le profil student/professor
                    if ($role === 'student') {
                        $studentModel = new Student();
                        $studentModel->create([
                            'first_name' => $firstName,
                            'last_name'  => $lastName,
                            'email'      => $email,
                            'password'   => $password,
                        ], $tenantId);
                    } elseif ($role === 'professor') {
                        $profModel = new Professor();
                        $profModel->create([
                            'first_name' => $firstName,
                            'last_name'  => $lastName,
                            'email'      => $email,
                            'password'   => $password,
                        ], $tenantId);
                    }

                    $logModel->log($tenantId, $userId, 'register', "Inscription : $email ($role)", 'user', $userId);
                    $success   = 'Compte créé avec succès ! Vous pouvez maintenant vous connecter.';
                    $activeTab = 'login';
                } else {
                    $error = 'Erreur lors de la création du compte. Veuillez réessayer.';
                }
            }
        }
    }
}

$pageTitle = 'Connexion';
$slug      = getTenantSlug() ?? '';
?>
<!DOCTYPE html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($pageTitle); ?> — <?php echo escape($tenant['name']); ?></title>
    <link rel="icon" type="image/png" href="<?php echo APP_URL; ?>/css/img/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo APP_URL; ?>/css/style.css" rel="stylesheet">
    <?php echo tenantCssVars($tenant); ?>
    <style>
        body { background: var(--sc-primary); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-wrapper { width: 100%; max-width: 460px; }
        .login-brand { text-align: center; margin-bottom: 28px; }
        .login-brand__icon { width: 60px; height: 60px; background: var(--sc-secondary); border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; font-size: 26px; color: var(--sc-primary); margin-bottom: 12px; }
        .login-brand__name { font-family: 'Space Grotesk', sans-serif; font-size: 26px; font-weight: 700; color: #fff; }
        .login-brand__tenant { font-size: 13px; color: rgba(255,255,255,.6); margin-top: 2px; }
        .login-card { background: var(--sc-surface); border-radius: 20px; padding: 32px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
        .login-tabs { display: flex; background: var(--sc-surface-2); border-radius: 10px; padding: 4px; margin-bottom: 24px; gap: 4px; }
        .login-tab { flex: 1; padding: 9px; text-align: center; border-radius: 8px; cursor: pointer; font-size: 13.5px; font-weight: 600; color: var(--sc-text-muted); border: none; background: transparent; transition: all .2s; }
        .login-tab.active { background: var(--sc-surface); color: var(--sc-primary); box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .password-wrapper { position: relative; }
        .password-toggle { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--sc-text-muted); cursor: pointer; font-size: 14px; padding: 4px; }
        .login-footer { text-align: center; margin-top: 20px; font-size: 12px; color: rgba(255,255,255,.5); }
        .strength-bar { height: 3px; border-radius: 2px; margin-top: 5px; transition: all .3s; background: var(--sc-border); }
        .strength-bar.weak   { background: var(--sc-danger); width: 33%; }
        .strength-bar.medium { background: var(--sc-warning); width: 66%; }
        .strength-bar.strong { background: var(--sc-success); width: 100%; }
    </style>
</head>
<body>
<div class="login-wrapper">
    <!-- Logo + Nom établissement -->
    <div class="login-brand">
        <div class="login-brand__icon"><i class="fas fa-graduation-cap"></i></div>
        <div class="login-brand__name"><?php echo APP_NAME; ?></div>
        <div class="login-brand__tenant"><?php echo escape($tenant['name']); ?></div>
    </div>

    <div class="login-card">
        <!-- Onglets -->
        <div class="login-tabs">
            <button class="login-tab <?php echo $activeTab === 'login'    ? 'active' : ''; ?>"
                    onclick="switchTab('login')">
                <i class="fas fa-sign-in-alt me-1"></i> Connexion
            </button>
            <button class="login-tab <?php echo $activeTab === 'register' ? 'active' : ''; ?>"
                    onclick="switchTab('register')">
                <i class="fas fa-user-plus me-1"></i> Inscription
            </button>
        </div>

        <!-- Alertes -->
        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" role="alert">
                <i class="fas fa-circle-exclamation"></i>
                <div><?php echo escape($error); ?></div>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 mb-3" role="alert">
                <i class="fas fa-circle-check"></i>
                <div><?php echo escape($success); ?></div>
            </div>
        <?php endif; ?>

        <!-- ===== FORMULAIRE CONNEXION ===== -->
        <div id="tab-login" class="<?php echo $activeTab !== 'login' ? 'd-none' : ''; ?>">
            <form method="POST" action="">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="login">

                <div class="mb-3">
                    <label class="sc-form-label"><i class="fas fa-envelope me-1 text-muted"></i> Email</label>
                    <input type="email" name="email"
                           class="sc-form-control"
                           placeholder="votre@email.com"
                           value="<?php echo escape($_POST['email'] ?? ''); ?>"
                           required autocomplete="email">
                </div>

                <div class="mb-4">
                    <label class="sc-form-label"><i class="fas fa-lock me-1 text-muted"></i> Mot de passe</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="loginPassword"
                               class="sc-form-control"
                               placeholder="••••••••"
                               required autocomplete="current-password">
                        <button type="button" class="password-toggle" onclick="togglePassword('loginPassword', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="sc-btn sc-btn--primary w-100 justify-content-center py-2">
                    <i class="fas fa-sign-in-alt"></i> Se connecter
                </button>
            </form>

            <div class="text-center mt-3">
                <small class="text-muted">Mot de passe oublié ?
                    <a href="<?php echo tenantUrl('reset_password.php'); ?>" class="fw-semibold">Réinitialiser</a>
                </small>
            </div>

            <!-- Comptes de test (dev uniquement) -->
            <?php if (APP_DEBUG && APP_ENV === 'local'): ?>
            <div class="mt-4 p-3 rounded" style="background:var(--sc-surface-2);font-size:12px;">
                <div class="fw-bold text-muted mb-2"><i class="fas fa-flask me-1"></i> Comptes de test</div>
                <div><b>Admin :</b> admin@iua.ci</div>
                <div><b>Prof :</b> prof.kone@iua.ci</div>
                <div><b>Étudiant :</b> etu.coulibaly@iua.ci</div>
                <div class="mt-1"><b>Mot de passe :</b> SmartCampus@2025</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ===== FORMULAIRE INSCRIPTION ===== -->
        <div id="tab-register" class="<?php echo $activeTab !== 'register' ? 'd-none' : ''; ?>">
            <form method="POST" action="">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="register">

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="sc-form-label">Prénom</label>
                        <input type="text" name="first_name" class="sc-form-control"
                               placeholder="Prénom"
                               value="<?php echo escape($_POST['first_name'] ?? ''); ?>"
                               required>
                    </div>
                    <div class="col-6">
                        <label class="sc-form-label">Nom</label>
                        <input type="text" name="last_name" class="sc-form-control"
                               placeholder="NOM"
                               value="<?php echo escape($_POST['last_name'] ?? ''); ?>"
                               required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="sc-form-label"><i class="fas fa-envelope me-1 text-muted"></i> Email</label>
                    <input type="email" name="email" class="sc-form-control"
                           placeholder="votre@email.com"
                           value="<?php echo escape($_POST['email'] ?? ''); ?>"
                           required autocomplete="email">
                </div>

                <div class="mb-3">
                    <label class="sc-form-label"><i class="fas fa-user-tag me-1 text-muted"></i> Je suis</label>
                    <select name="role" class="sc-form-control">
                        <option value="student"   <?php echo ($_POST['role'] ?? '') === 'student'   ? 'selected' : ''; ?>>
                            🎓 Étudiant
                        </option>
                        <option value="professor" <?php echo ($_POST['role'] ?? '') === 'professor' ? 'selected' : ''; ?>>
                            📚 Professeur
                        </option>
                        <!-- Admin ne peut PAS s'auto-inscrire -->
                    </select>
                </div>

                <div class="mb-3">
                    <label class="sc-form-label"><i class="fas fa-lock me-1 text-muted"></i> Mot de passe</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="regPassword"
                               class="sc-form-control"
                               placeholder="Min. 8 cars, 1 majuscule, 1 chiffre"
                               oninput="checkStrength(this.value)"
                               required autocomplete="new-password">
                        <button type="button" class="password-toggle" onclick="togglePassword('regPassword', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="strength-bar" id="strengthBar"></div>
                    <div id="strengthLabel" style="font-size:11px;margin-top:3px;color:var(--sc-text-muted);"></div>
                </div>

                <div class="mb-4">
                    <label class="sc-form-label">Confirmer le mot de passe</label>
                    <div class="password-wrapper">
                        <input type="password" name="password_confirm" id="regPassword2"
                               class="sc-form-control"
                               placeholder="Répétez le mot de passe"
                               required autocomplete="new-password">
                        <button type="button" class="password-toggle" onclick="togglePassword('regPassword2', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="sc-btn sc-btn--secondary w-100 justify-content-center py-2"
                        style="color: var(--sc-primary);">
                    <i class="fas fa-user-plus"></i> Créer mon compte
                </button>
            </form>
        </div>
    </div>

    <div class="login-footer">
        © <?php echo date('Y'); ?> SmartCampus — Propulsé par l'IA 🤖
        <br><a href="<?php echo APP_URL; ?>/index.php" style="color:rgba(255,255,255,.4);">Retour à l'accueil</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function switchTab(tab) {
    document.getElementById('tab-login').classList.toggle('d-none', tab !== 'login');
    document.getElementById('tab-register').classList.toggle('d-none', tab !== 'register');
    document.querySelectorAll('.login-tab').forEach((btn, i) => {
        btn.classList.toggle('active', (i === 0 && tab === 'login') || (i === 1 && tab === 'register'));
    });
}

function togglePassword(id, btn) {
    const input = document.getElementById(id);
    const icon  = btn.querySelector('i');
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    icon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
}

function checkStrength(pw) {
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    if (!pw) { bar.className = 'strength-bar'; label.textContent = ''; return; }
    const hasUpper  = /[A-Z]/.test(pw);
    const hasDigit  = /[0-9]/.test(pw);
    const hasSpecial= /[^A-Za-z0-9]/.test(pw);
    const long      = pw.length >= 8;
    const score     = [hasUpper, hasDigit, hasSpecial, long].filter(Boolean).length;
    if (score <= 1) { bar.className = 'strength-bar weak';   label.textContent = '⚠ Faible';  }
    else if (score === 2 || score === 3) { bar.className = 'strength-bar medium'; label.textContent = '👍 Moyen'; }
    else { bar.className = 'strength-bar strong';  label.textContent = '✅ Fort'; }
}
</script>
</body>
</html>
