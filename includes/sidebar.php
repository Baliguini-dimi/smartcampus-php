<?php
// =============================================================================
// SmartCampus SaaS — includes/sidebar.php
// Sidebar dynamique selon le rôle de l'utilisateur.
// Doit être inclus APRÈS header.php.
// =============================================================================

$currentUser = getCurrentUser();
$role        = $currentUser['role'] ?? '';
$slug        = getTenantSlug() ?? '';
$activeMenu  = $activeMenu ?? '';

// Helper pour générer un lien sidebar
function sidebarLink(string $page, string $icon, string $label, string $active, string $badge = ''): string {
    $slug    = getTenantSlug() ?? '';
    $url     = tenantUrl($page);
    $isActive = ($active === $page) ? 'active' : '';
    $badgeHtml = $badge ? "<span class=\"sc-sidebar__badge\">$badge</span>" : '';
    return "
    <li class=\"sc-sidebar__item\">
        <a href=\"$url\" class=\"sc-sidebar__link $isActive\">
            <i class=\"fas $icon sc-sidebar__icon\"></i>
            <span class=\"sc-sidebar__label\">$label</span>
            $badgeHtml
        </a>
    </li>";
}

// Compte messages non lus pour le badge
$unreadMessages = (int)($_SESSION['unread_messages'] ?? 0);
$msgBadge = $unreadMessages > 0 ? (string)$unreadMessages : '';
?>

<!-- ============================================================
     SIDEBAR
     ============================================================ -->
<aside class="sc-sidebar" id="scSidebar">

    <!-- En-tête sidebar avec info utilisateur -->
    <div class="sc-sidebar__user">
        <?php if (!empty($currentUser['profile_picture'])): ?>
            <img src="<?php echo escape(profilePictureUrl($currentUser['profile_picture'])); ?>"
                 alt="Avatar" class="sc-sidebar__avatar">
        <?php else: ?>
            <div class="sc-sidebar__initials">
                <?php echo strtoupper(substr($currentUser['first_name'] ?? '', 0, 1) . substr($currentUser['last_name'] ?? '', 0, 1)); ?>
            </div>
        <?php endif; ?>
        <div class="sc-sidebar__user-info">
            <div class="sc-sidebar__user-name">
                <?php echo escape(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')); ?>
            </div>
            <div class="sc-sidebar__user-role">
                <i class="fas <?php echo getRoleIcon($role); ?>"></i>
                <?php echo escape(getRoleLabel($role)); ?>
            </div>
        </div>
    </div>

    <nav class="sc-sidebar__nav">
        <ul class="sc-sidebar__list">

            <!-- === SECTION PRINCIPALE === -->
            <li class="sc-sidebar__section-title">Principal</li>

            <?php echo sidebarLink('dashboard.php', 'fa-tachometer-alt', 'Tableau de bord', $activeMenu); ?>

            <?php echo sidebarLink('messages.php', 'fa-envelope', 'Messages', $activeMenu, $msgBadge); ?>

            <?php echo sidebarLink('announcements.php', 'fa-bullhorn', 'Annonces', $activeMenu); ?>

            <!-- === SECTION ACADÉMIQUE === -->
            <li class="sc-sidebar__section-title">Académique</li>

            <?php echo sidebarLink('courses.php', 'fa-book-open', 'Cours', $activeMenu); ?>

            <!-- Notes : tous les rôles, vue différente -->
            <?php echo sidebarLink('grades.php', 'fa-chart-bar', $role === 'student' ? 'Mes notes' : 'Notes', $activeMenu); ?>

            <?php if ($role === 'student'): ?>
                <!-- Bulletin uniquement étudiant -->
                <?php echo sidebarLink('bulletin.php', 'fa-file-alt', 'Mon bulletin', $activeMenu); ?>
            <?php endif; ?>

            <!-- === SECTION GESTION (admin + professeur) === -->
            <?php if (in_array($role, ['admin', 'professor'], true)): ?>
                <li class="sc-sidebar__section-title">Gestion</li>

                <?php echo sidebarLink('students.php', 'fa-users', 'Étudiants', $activeMenu); ?>

                <?php if ($role === 'admin'): ?>
                    <?php echo sidebarLink('professors.php', 'fa-chalkboard-teacher', 'Professeurs', $activeMenu); ?>
                <?php endif; ?>
            <?php endif; ?>

            <!-- === SECTION IA === -->
            <li class="sc-sidebar__section-title">Intelligence IA</li>

            <?php echo sidebarLink('ai.php', 'fa-robot', 'Assistant IA', $activeMenu); ?>

            <!-- === SECTION COMPTE === -->
            <li class="sc-sidebar__section-title">Compte</li>

            <?php echo sidebarLink('profile.php', 'fa-user-cog', 'Mon profil', $activeMenu); ?>

            <?php if ($role === 'admin'): ?>
                <?php echo sidebarLink('search.php', 'fa-search', 'Recherche', $activeMenu); ?>
            <?php endif; ?>

        </ul>
    </nav>

    <!-- Pied de sidebar -->
    <div class="sc-sidebar__footer">
        <a href="<?php echo tenantUrl('logout.php'); ?>" class="sc-sidebar__logout">
            <i class="fas fa-sign-out-alt"></i>
            <span>Déconnexion</span>
        </a>
        <div class="sc-sidebar__version">SmartCampus v2.0</div>
    </div>

</aside>

<!-- Overlay mobile (ferme la sidebar au clic) -->
<div class="sc-sidebar-overlay" id="sidebarOverlay"></div>

<!-- Contenu principal -->
<main class="sc-main" id="scMain">
    <!-- Messages flash (alertes de succès/erreur) -->
    <div class="sc-flash-container">
        <?php echo displayFlashMessages(); ?>
    </div>
