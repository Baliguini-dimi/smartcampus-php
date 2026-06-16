<?php
// =============================================================================
// SmartCampus SaaS — includes/footer.php
// Fermeture du layout + chargement des scripts JS.
// Doit être inclus EN FIN de chaque page tenant.
// =============================================================================

$slug = getTenantSlug() ?? '';
?>

</main><!-- /.sc-main -->
</div><!-- /.sc-layout -->

<!-- ============================================================
     SCRIPTS JS
     ============================================================ -->

<!-- Bootstrap 5 Bundle (inclut Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery (pour AJAX dans courses.php) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// =============================================================================
// SmartCampus — JS Global
// =============================================================================

const SC = {
    tenantSlug : '<?php echo escape($slug); ?>',
    baseUrl    : '<?php echo APP_URL; ?>',
    tenantUrl  : function(page) {
        return this.baseUrl + '/' + this.tenantSlug + '/' + page;
    }
};

// -----------------------------------------------------------------------------
// 1. DARK MODE
// -----------------------------------------------------------------------------
(function() {
    const stored = localStorage.getItem('sc_dark_mode');
    const html   = document.documentElement;
    const btn    = document.getElementById('darkModeToggle');

    function setMode(dark) {
        html.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
        if (btn) btn.innerHTML = dark
            ? '<i class="fas fa-sun"></i>'
            : '<i class="fas fa-moon"></i>';
        localStorage.setItem('sc_dark_mode', dark ? '1' : '0');
    }

    // Appliquer le mode sauvegardé
    setMode(stored === '1');

    if (btn) {
        btn.addEventListener('click', () => {
            setMode(html.getAttribute('data-bs-theme') !== 'dark');
        });
    }
})();

// -----------------------------------------------------------------------------
// 2. SIDEBAR MOBILE (toggle)
// -----------------------------------------------------------------------------
(function() {
    const sidebar  = document.getElementById('scSidebar');
    const overlay  = document.getElementById('sidebarOverlay');
    const toggler  = document.querySelector('.navbar-toggler');

    function toggleSidebar() {
        sidebar?.classList.toggle('sc-sidebar--open');
        overlay?.classList.toggle('sc-sidebar-overlay--visible');
    }

    toggler?.addEventListener('click', toggleSidebar);
    overlay?.addEventListener('click', toggleSidebar);
})();

// -----------------------------------------------------------------------------
// 3. NOTIFICATIONS — Polling AJAX toutes les 30 secondes
// -----------------------------------------------------------------------------
(function() {
    const badge   = document.getElementById('msgBadge');
    const content = document.getElementById('msgDropdownContent');

    function loadNotifications() {
        fetch(SC.tenantUrl('ajax_notifications.php?action=unread'))
            .then(r => r.json())
            .then(data => {
                if (!data) return;

                // Mettre à jour le badge
                const count = data.unread_count ?? 0;
                if (badge) {
                    badge.textContent = count;
                    badge.style.display = count > 0 ? 'flex' : 'none';
                }

                // Mettre à jour le dropdown
                if (content && data.messages) {
                    if (data.messages.length === 0) {
                        content.innerHTML = '<div class="sc-dropdown__empty"><i class="fas fa-inbox"></i><p>Aucun message</p></div>';
                    } else {
                        content.innerHTML = data.messages.map(m => `
                            <a href="${SC.tenantUrl('messages.php?id=' + m.id)}"
                               class="sc-dropdown__msg ${m.is_read ? '' : 'sc-dropdown__msg--unread'}">
                                <div class="sc-dropdown__msg-from">${escHtml(m.sender_name)}</div>
                                <div class="sc-dropdown__msg-subject">${escHtml(m.subject)}</div>
                                <div class="sc-dropdown__msg-time">${escHtml(m.time_ago)}</div>
                            </a>
                        `).join('');
                    }
                }
            })
            .catch(() => {}); // Silencieux si offline
    }

    // Charger au démarrage + toutes les 30s
    loadNotifications();
    setInterval(loadNotifications, 30000);
})();

// -----------------------------------------------------------------------------
// 4. UTILITAIRES GLOBAUX
// -----------------------------------------------------------------------------

/** Échappe le HTML dans les templates JS (protection XSS côté client) */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** Affiche un toast Bootstrap (message temporaire) */
function showToast(message, type = 'success') {
    const colors = {
        success : 'bg-success',
        error   : 'bg-danger',
        warning : 'bg-warning',
        info    : 'bg-info'
    };
    const id = 'toast_' + Date.now();
    const html = `
        <div id="${id}" class="toast align-items-center text-white ${colors[type] ?? 'bg-secondary'} border-0"
             role="alert" aria-live="assertive">
            <div class="d-flex">
                <div class="toast-body">${escHtml(message)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto"
                        data-bs-dismiss="toast"></button>
            </div>
        </div>`;

    let container = document.getElementById('sc-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'sc-toast-container';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }
    container.insertAdjacentHTML('beforeend', html);
    const el = document.getElementById(id);
    new bootstrap.Toast(el, { delay: 4000 }).show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

/** Confirme une action destructive avant soumission de formulaire */
function confirmAction(message, formOrCallback) {
    if (!confirm(message)) return false;
    if (typeof formOrCallback === 'function') formOrCallback();
    else if (formOrCallback instanceof HTMLFormElement) formOrCallback.submit();
    return true;
}

/** Envoie une requête AJAX POST avec CSRF et retourne une Promise JSON */
function ajaxPost(url, data) {
    const formData = new FormData();
    // Récupérer le token CSRF depuis le DOM
    const csrfInput = document.querySelector('[name="csrf_token"]');
    if (csrfInput) formData.append('csrf_token', csrfInput.value);
    if (data instanceof FormData) {
        for (const [k, v] of data.entries()) formData.append(k, v);
    } else {
        for (const [k, v] of Object.entries(data)) formData.append(k, v);
    }
    return fetch(url, { method: 'POST', body: formData })
        .then(r => r.json());
}

// -----------------------------------------------------------------------------
// 5. AUTO-DISMISS DES ALERTES FLASH après 5s
// -----------------------------------------------------------------------------
document.querySelectorAll('.sc-flash-container .alert').forEach(alert => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        bsAlert?.close();
    }, 5000);
});
</script>

<?php if (isset($extraJs)): ?>
    <!-- Scripts spécifiques à cette page -->
    <?php echo $extraJs; ?>
<?php endif; ?>

</body>
</html>
