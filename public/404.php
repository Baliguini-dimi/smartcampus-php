<?php
declare(strict_types=1);
http_response_code(404);
if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
$tenant = getCurrentTenant();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Page introuvable | <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo APP_URL; ?>/css/style.css" rel="stylesheet">
    <?php if ($tenant) echo tenantCssVars($tenant); ?>
</head>
<body style="background:var(--sc-bg);display:flex;align-items:center;justify-content:center;min-height:100vh;">
    <div class="text-center" style="max-width:420px;padding:32px;">
        <div style="font-size:80px;margin-bottom:16px;">🎓</div>
        <h1 style="font-size:64px;font-weight:800;color:var(--sc-primary);margin:0;font-family:'Space Grotesk',sans-serif;">404</h1>
        <h2 style="font-size:20px;font-weight:600;margin:8px 0 12px;">Page introuvable</h2>
        <p class="text-muted" style="font-size:14px;">La page que vous cherchez n'existe pas ou a été déplacée.</p>
        <div class="d-flex gap-2 justify-content-center mt-4">
            <a href="javascript:history.back()" class="sc-btn sc-btn--outline">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
            <?php if ($tenant): ?>
                <a href="<?php echo tenantUrl('dashboard.php'); ?>" class="sc-btn sc-btn--primary">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            <?php else: ?>
                <a href="<?php echo APP_URL; ?>/index.php" class="sc-btn sc-btn--primary">
                    <i class="fas fa-home"></i> Accueil
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
