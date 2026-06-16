<?php
// =============================================================================
// SmartCampus SaaS — includes/header.php
// Navbar + ouverture HTML commune à toutes les pages tenant.
// Usage : include __DIR__ . '/../includes/header.php';
// Variables attendues : $pageTitle (string), $activeMenu (string)
// =============================================================================

if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
if (!function_exists('isLoggedIn')) {
    require_once __DIR__ . '/auth.php';
}
if (!function_exists('escape')) {
    require_once __DIR__ . '/helpers.php';
}

$currentUser   = getCurrentUser();
$currentTenant = getCurrentTenant();
$pageTitle     = $pageTitle ?? APP_NAME;
$activeMenu    = $activeMenu ?? '';

// Notifications non lues (chargées en AJAX, juste le count initial depuis session)
$unreadMessages = (int)($_SESSION['unread_messages'] ?? 0);
$slug = getTenantSlug() ?? '';
?>
<!DOCTYPE html>
<html lang="fr" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo escape($pageTitle); ?> — <?php echo escape($currentTenant['name'] ?? APP_NAME); ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php echo APP_URL; ?>/css/img/favicon.png">

    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <!-- SmartCampus CSS -->
    <link href="<?php echo APP_URL; ?>/css/style.css" rel="stylesheet">

    <!-- Variables CSS du tenant (couleurs personnalisées) -->
    <?php echo tenantCssVars($currentTenant); ?>
</head>
<body>

<!-- ============================================================
     NAVBAR FIXE
     ============================================================ -->
<nav class="sc-navbar navbar navbar-expand-lg fixed-top">
    <div class="container-fluid px-4">

        <!-- Logo + Nom établissement -->
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?php echo tenantUrl('dashboard.php'); ?>">
            <?php if (!empty($currentTenant['logo'])): ?>
                <img src="<?php echo escape(tenantLogoUrl($currentTenant)); ?>"
                     alt="Logo <?php echo escape($currentTenant['name']); ?>"
                     class="sc-navbar__logo">
            <?php else: ?>
                <div class="sc-navbar__icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
            <?php endif; ?>
            <div class="sc-navbar__brand-text">
                <span class="sc-navbar__app-name"><?php echo APP_NAME; ?></span>
                <span class="sc-navbar__tenant-name"><?php echo escape($currentTenant['name'] ?? ''); ?></span>
            </div>
        </a>

        <!-- Bouton mobile -->
        <button class="navbar-toggler border-0" type="button"
                data-bs-toggle="collapse" data-bs-target="#navbarContent">
            <i class="fas fa-bars text-white"></i>
        </button>

        <div class="collapse navbar-collapse" id="navbarContent">

            <!-- Barre de recherche globale -->
            <form class="sc-search d-flex mx-auto" action="<?php echo tenantUrl('search.php'); ?>" method="GET">
                <div class="sc-search__wrapper">
                    <i class="fas fa-search sc-search__icon"></i>
                    <input type="search" name="q"
                           class="sc-search__input"
                           placeholder="Rechercher étudiant, cours, professeur..."
                           value="<?php echo escape($_GET['q'] ?? ''); ?>"
                           autocomplete="off">
                </div>
            </form>

            <!-- Actions droite -->
            <div class="ms-auto d-flex align-items-center gap-3">

                <!-- Toggle Dark Mode -->
                <button class="sc-btn-icon" id="darkModeToggle" title="Mode sombre">
                    <i class="fas fa-moon"></i>
                </button>

                <!-- Notifications messages -->
                <div class="dropdown">
                    <button class="sc-btn-icon position-relative" data-bs-toggle="dropdown" title="Messages">
                        <i class="fas fa-envelope"></i>
                        <span class="sc-badge" id="msgBadge"
                              style="<?php echo $unreadMessages === 0 ? 'display:none' : ''; ?>">
                            <?php echo $unreadMessages; ?>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end sc-dropdown" id="msgDropdown">
                        <div class="sc-dropdown__header">
                            <span>Messages</span>
                            <a href="<?php echo tenantUrl('messages.php'); ?>" class="sc-dropdown__link">Voir tout</a>
                        </div>
                        <div id="msgDropdownContent">
                            <div class="sc-dropdown__loading">
                                <i class="fas fa-spinner fa-spin"></i> Chargement...
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Menu profil -->
                <div class="dropdown">
                    <button class="sc-user-btn d-flex align-items-center gap-2"
                            data-bs-toggle="dropdown">
                        <?php if (!empty($currentUser['profile_picture'])): ?>
                            <img src="<?php echo escape(profilePictureUrl($currentUser['profile_picture'])); ?>"
                                 alt="Photo profil" class="sc-user-btn__avatar">
                        <?php else: ?>
                            <div class="sc-user-btn__initials">
                                <?php
                                $initials = strtoupper(
                                    substr($currentUser['first_name'] ?? '', 0, 1) .
                                    substr($currentUser['last_name']  ?? '', 0, 1)
                                );
                                echo escape($initials);
                                ?>
                            </div>
                        <?php endif; ?>
                        <div class="sc-user-btn__info d-none d-lg-block">
                            <div class="sc-user-btn__name">
                                <?php echo escape(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')); ?>
                            </div>
                            <div class="sc-user-btn__role">
                                <?php echo escape(getRoleLabel($currentUser['role'] ?? '')); ?>
                            </div>
                        </div>
                        <i class="fas fa-chevron-down sc-user-btn__arrow d-none d-lg-block"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end sc-dropdown">
                        <li class="sc-dropdown__header">
                            <span><?php echo escape(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')); ?></span>
                            <small><?php echo escape($currentUser['email'] ?? ''); ?></small>
                        </li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li>
                            <a class="dropdown-item sc-dropdown__item" href="<?php echo tenantUrl('profile.php'); ?>">
                                <i class="fas fa-user-cog"></i> Mon profil
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item sc-dropdown__item" href="<?php echo tenantUrl('messages.php'); ?>">
                                <i class="fas fa-envelope"></i> Messages
                                <?php if ($unreadMessages > 0): ?>
                                    <span class="badge bg-danger ms-auto"><?php echo $unreadMessages; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li>
                            <a class="dropdown-item sc-dropdown__item sc-dropdown__item--danger"
                               href="<?php echo tenantUrl('logout.php'); ?>">
                                <i class="fas fa-sign-out-alt"></i> Déconnexion
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Layout principal -->
<div class="sc-layout">
