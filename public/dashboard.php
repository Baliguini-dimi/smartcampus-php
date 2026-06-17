<?php
// =============================================================================
// SmartCampus SaaS — public/dashboard.php
// Tableau de bord adaptatif selon le rôle (admin / professeur / étudiant)
// =============================================================================
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/app/models/Database.php';
require_once dirname(__DIR__) . '/app/models/Tenant.php';
require_once dirname(__DIR__) . '/app/models/User.php';
require_once dirname(__DIR__) . '/app/models/Student.php';
require_once dirname(__DIR__) . '/app/models/Grade.php';
require_once dirname(__DIR__) . '/app/models/Course.php';
require_once dirname(__DIR__) . '/app/models/Announcement.php';
require_once dirname(__DIR__) . '/app/models/Message.php';
require_once dirname(__DIR__) . '/app/models/Professor.php';

requireLogin();

$tenantId    = (int)getCurrentTenantId();
$currentUser = getCurrentUser();
$role        = $currentUser['role'];

$gradeModel  = new Grade();
$courseModel = new Course();
$annoModel   = new Announcement();
$msgModel    = new Message();
$userModel   = new User();

// ---- Données communes ----
$announcements  = $annoModel->getRecent($tenantId, 3);
$unreadMessages = $msgModel->countUnread($currentUser['id'], $tenantId);
$_SESSION['unread_messages'] = $unreadMessages;

// ---- Données selon le rôle ----
if ($role === 'admin') {
    $roleCounts  = $userModel->countByRole($tenantId);
    $stats       = $gradeModel->getStatistics($tenantId);
    $distribution= $gradeModel->getGradeDistribution($tenantId);
    $avgByCourse = $gradeModel->getAverageByCourse($tenantId, 8);
    $totalCourses= $courseModel->count($tenantId);

} elseif ($role === 'professor') {
    $profModel   = new Professor();
    $professor   = $profModel->findByUserId($currentUser['id'], $tenantId);
    $myCourses   = $professor
        ? $courseModel->getAll($tenantId, 0, 0, '')
        : [];
    // Filtrer les cours du prof
    $myCourses   = array_filter($myCourses, fn($c) => $c['professor_id'] == ($professor['id'] ?? 0));
    $stats       = $gradeModel->getStatistics($tenantId);
    $distribution= $gradeModel->getGradeDistribution($tenantId);
    $avgByCourse = $gradeModel->getAverageByCourse($tenantId, 8);

} elseif ($role === 'student') {
    $studentModel= new Student();
    $student     = $studentModel->findByUserId($currentUser['id'], $tenantId);
    $myStats     = $student ? $studentModel->getStats($student['id'], $tenantId) : null;
    $myGrades    = $myStats['grades']  ?? [];
    $myAvg       = $myStats['average'] ?? null;
}

$pageTitle  = 'Tableau de bord';
$activeMenu = 'dashboard.php';
?>
<?php require_once dirname(__DIR__) . '/includes/header.php'; ?>
<?php require_once dirname(__DIR__) . '/includes/sidebar.php'; ?>

<div class="sc-page-header">
    <h1 class="sc-page-title">
        <i class="fas fa-tachometer-alt"></i>
        Tableau de bord
    </h1>
    <div class="text-muted" style="font-size:13px;">
        <i class="fas fa-calendar me-1"></i>
        <?php echo date('l d F Y'); ?>
    </div>
</div>

<!-- Bannière de bienvenue -->
<div class="sc-card mb-4" style="background: linear-gradient(135deg, var(--sc-primary) 0%, var(--sc-primary-light) 100%); border: none; color: #fff;">
    <div class="sc-card__body d-flex align-items-center gap-4">
        <div>
            <?php if (!empty($currentUser['profile_picture'])): ?>
                <img src="<?php echo escape(profilePictureUrl($currentUser['profile_picture'])); ?>"
                     alt="Avatar" style="width:56px;height:56px;border-radius:14px;object-fit:cover;border:2px solid rgba(255,255,255,.3);">
            <?php else: ?>
                <div style="width:56px;height:56px;border-radius:14px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#fff;border:2px solid rgba(255,255,255,.2);">
                    <?php echo strtoupper(substr($currentUser['first_name'], 0, 1) . substr($currentUser['last_name'], 0, 1)); ?>
                </div>
            <?php endif; ?>
        </div>
        <div>
            <div style="font-size:18px;font-weight:700;font-family:'Space Grotesk',sans-serif;">
                Bonjour, <?php echo escape($currentUser['first_name']); ?> 👋
            </div>
            <div style="font-size:13px;opacity:.75;margin-top:3px;">
                <i class="fas <?php echo getRoleIcon($role); ?> me-1"></i>
                <?php echo escape(getRoleLabel($role)); ?> —
                <?php echo escape(getCurrentTenant()['name'] ?? ''); ?>
            </div>
        </div>
        <?php if ($unreadMessages > 0): ?>
            <div class="ms-auto">
                <a href="<?php echo tenantUrl('messages.php'); ?>"
                   style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:10px;padding:8px 14px;font-size:13px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-envelope"></i>
                    <?php echo $unreadMessages; ?> message<?php echo $unreadMessages > 1 ? 's' : ''; ?> non lu<?php echo $unreadMessages > 1 ? 's' : ''; ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($role === 'admin'): ?>
<!-- ================================================================
     VUE ADMIN
     ================================================================ -->
<!-- Cartes statistiques -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--blue">
                <i class="fas fa-users"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo $roleCounts['student']; ?></div>
                <div class="sc-stat-card__label">Étudiants</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--purple">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo $roleCounts['professor']; ?></div>
                <div class="sc-stat-card__label">Professeurs</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--green">
                <i class="fas fa-book-open"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo $totalCourses; ?></div>
                <div class="sc-stat-card__label">Cours</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--orange">
                <i class="fas fa-chart-line"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo $stats['average'] ?? '—'; ?></div>
                <div class="sc-stat-card__label">Moyenne générale /20</div>
            </div>
        </div>
    </div>
</div>

<!-- Graphiques -->
<div class="row g-4 mb-4">
    <div class="col-md-5">
        <div class="sc-card h-100">
            <div class="sc-card__header">
                <span><i class="fas fa-chart-pie"></i> Répartition des notes</span>
            </div>
            <div class="sc-card__body" style="display:flex;align-items:center;justify-content:center;min-height:220px;">
                <canvas id="gradeDistChart" style="max-height:220px;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="sc-card h-100">
            <div class="sc-card__header">
                <span><i class="fas fa-chart-bar"></i> Moyenne par cours</span>
            </div>
            <div class="sc-card__body" style="min-height:220px;">
                <canvas id="courseAvgChart" style="max-height:220px;"></canvas>
            </div>
        </div>
    </div>
</div>

<?php elseif ($role === 'professor'): ?>
<!-- ================================================================
     VUE PROFESSEUR
     ================================================================ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--blue">
                <i class="fas fa-book-open"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo count($myCourses); ?></div>
                <div class="sc-stat-card__label">Mes cours</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--green">
                <i class="fas fa-chart-line"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo $stats['average'] ?? '—'; ?></div>
                <div class="sc-stat-card__label">Moyenne générale</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--orange">
                <i class="fas fa-trophy"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo $stats['maximum'] ?? '—'; ?></div>
                <div class="sc-stat-card__label">Note maximale</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--purple">
                <i class="fas fa-users"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo $stats['total'] ?? '—'; ?></div>
                <div class="sc-stat-card__label">Notes saisies</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-5">
        <div class="sc-card h-100">
            <div class="sc-card__header"><span><i class="fas fa-chart-pie"></i> Répartition des notes</span></div>
            <div class="sc-card__body" style="display:flex;align-items:center;justify-content:center;min-height:220px;">
                <canvas id="gradeDistChart" style="max-height:220px;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="sc-card h-100">
            <div class="sc-card__header"><span><i class="fas fa-book-open"></i> Mes cours</span></div>
            <div class="sc-card__body p-0">
                <table class="sc-table">
                    <thead><tr><th>Cours</th><th>Code</th><th>Quiz</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($myCourses, 0, 6) as $c): ?>
                        <tr>
                            <td><?php echo escape($c['title']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo escape($c['code']); ?></span></td>
                            <td><?php echo $c['quiz_count']; ?> quiz</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($myCourses)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">Aucun cours assigné</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php elseif ($role === 'student'): ?>
<!-- ================================================================
     VUE ÉTUDIANT
     ================================================================ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--blue">
                <i class="fas fa-book-open"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo $myStats['total_courses'] ?? 0; ?></div>
                <div class="sc-stat-card__label">Cours</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--green">
                <i class="fas fa-chart-line"></i>
            </div>
            <div>
                <div class="sc-stat-card__value">
                    <?php echo $myAvg !== null ? number_format($myAvg, 1) : '—'; ?>
                </div>
                <div class="sc-stat-card__label">Ma moyenne /20</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--orange">
                <i class="fas fa-check-circle"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo $myStats['passed'] ?? 0; ?></div>
                <div class="sc-stat-card__label">Cours validés</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sc-stat-card">
            <div class="sc-stat-card__icon sc-stat-card__icon--<?php echo ($myStats['failed'] ?? 0) > 0 ? 'red' : 'green'; ?>">
                <i class="fas fa-times-circle"></i>
            </div>
            <div>
                <div class="sc-stat-card__value"><?php echo $myStats['failed'] ?? 0; ?></div>
                <div class="sc-stat-card__label">Non validés</div>
            </div>
        </div>
    </div>
</div>

<!-- Tableau de notes de l'étudiant -->
<div class="sc-card mb-4">
    <div class="sc-card__header">
        <span><i class="fas fa-chart-bar"></i> Mes notes</span>
        <a href="<?php echo tenantUrl('grades.php'); ?>" class="sc-btn sc-btn--outline sc-btn--sm">Voir tout</a>
    </div>
    <div class="sc-card__body p-0">
        <table class="sc-table">
            <thead>
                <tr><th>Cours</th><th>Devoir</th><th>Examen</th><th>Final</th><th>Moyenne</th><th>Mention</th></tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($myGrades, 0, 5) as $g): ?>
                <tr>
                    <td><span class="fw-semibold"><?php echo escape($g['course_title']); ?></span></td>
                    <td><?php echo escape(formatGrade($g['assignment_score'])); ?></td>
                    <td><?php echo escape(formatGrade($g['midterm_score'])); ?></td>
                    <td><?php echo escape(formatGrade($g['final_score'])); ?></td>
                    <td>
                        <?php if ($g['score'] !== null): ?>
                            <span class="badge bg-<?php echo getGradeColor($g['score']); ?>">
                                <?php echo number_format((float)$g['score'], 1); ?>/20
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fw-bold text-<?php echo getGradeColor($g['score']); ?>">
                            <?php echo getLetterGrade($g['score']); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($myGrades)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Aucune note disponible</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ================================================================
     ANNONCES (tous les rôles)
     ================================================================ -->
<div class="sc-card">
    <div class="sc-card__header">
        <span><i class="fas fa-bullhorn"></i> Dernières annonces</span>
        <a href="<?php echo tenantUrl('announcements.php'); ?>" class="sc-btn sc-btn--outline sc-btn--sm">Toutes les annonces</a>
    </div>
    <div class="sc-card__body">
        <?php if (empty($announcements)): ?>
            <div class="sc-empty">
                <div class="sc-empty__icon"><i class="fas fa-bullhorn"></i></div>
                <div class="sc-empty__title">Aucune annonce</div>
                <div class="sc-empty__desc">Les annonces de votre établissement apparaîtront ici.</div>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
            <?php foreach ($announcements as $ann): ?>
                <?php
                $priorityColors = ['0' => 'secondary', '1' => 'warning', '2' => 'danger'];
                $priorityLabels = ['0' => 'Info', '1' => 'Important', '2' => 'Urgent'];
                $pc = $priorityColors[$ann['priority']] ?? 'secondary';
                $pl = $priorityLabels[$ann['priority']] ?? 'Info';
                ?>
                <div style="padding:14px;border-radius:10px;border:1px solid var(--sc-border);background:var(--sc-surface-2);">
                    <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                        <div class="fw-semibold" style="font-size:14px;"><?php echo escape($ann['title']); ?></div>
                        <span class="badge bg-<?php echo $pc; ?> text-nowrap"><?php echo $pl; ?></span>
                    </div>
                    <div style="font-size:13px;color:var(--sc-text-muted);margin-bottom:6px;">
                        <?php echo escape(truncate($ann['content'], 140)); ?>
                    </div>
                    <div style="font-size:11px;color:var(--sc-text-light);">
                        <i class="fas fa-user me-1"></i><?php echo escape($ann['author_name']); ?>
                        &nbsp;·&nbsp;
                        <i class="fas fa-clock me-1"></i><?php echo timeAgo($ann['created_at']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// ---- Données graphiques JSON pour Chart.js ----
$chartDist = isset($distribution) ? json_encode(array_values($distribution)) : '[]';
$chartLabels = json_encode(isset($avgByCourse) ? array_column($avgByCourse, 'code') : []);
$chartAvgs   = json_encode(isset($avgByCourse) ? array_map(fn($r) => $r['average'], $avgByCourse) : []);

$extraJs = <<<JS
<script>
// Graphique répartition des notes (camembert)
const distCanvas = document.getElementById('gradeDistChart');
if (distCanvas) {
    new Chart(distCanvas, {
        type: 'doughnut',
        data: {
            labels: ['A (≥16)', 'B (14-16)', 'C (12-14)', 'D (10-12)', 'F (<10)'],
            datasets: [{
                data: $chartDist,
                backgroundColor: ['#22c55e','#3b82f6','#f59e0b','#f97316','#ef4444'],
                borderWidth: 2,
                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--sc-surface').trim()
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
            cutout: '60%'
        }
    });
}

// Graphique moyenne par cours (barres)
const avgCanvas = document.getElementById('courseAvgChart');
if (avgCanvas) {
    new Chart(avgCanvas, {
        type: 'bar',
        data: {
            labels: $chartLabels,
            datasets: [{
                label: 'Moyenne /20',
                data: $chartAvgs,
                backgroundColor: 'rgba(11,43,79,0.75)',
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, max: 20, grid: { color: 'rgba(0,0,0,0.05)' } },
                x: { grid: { display: false } }
            }
        }
    });
    avgCanvas.parentElement.style.height = '220px';
}
</script>
JS;
?>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
