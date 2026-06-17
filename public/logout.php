<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/app/models/Database.php';
require_once dirname(__DIR__) . '/app/models/Tenant.php';
require_once dirname(__DIR__) . '/app/models/ActivityLog.php';

if (isLoggedIn()) {
    $tenantId = getCurrentTenantId();
    $userId   = getCurrentUserId();
    $log = new ActivityLog();
    $log->log((int)$tenantId, $userId, 'logout', 'Déconnexion');
}

logoutUser();
