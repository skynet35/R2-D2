<?php
/* =========================================================================
   CompoZ'IT - Marquage CMS : retrouver un composant a partir du code
   imprime sur son boitier.
   -------------------------------------------------------------------------
   La recherche est instantanee (sans rechargement) et interroge
   marking_search.php, qui s'appuie sur la table `marking_codes`.
   ========================================================================= */

require_once 'session_init.php';
require_once 'config.php';

/* Verifier si l'utilisateur est connecte */
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=not_logged_in');
    exit();
}

/* La table de marquage est installee par un import separe : tant qu'il n'a
   pas ete fait, la page doit l'expliquer plutot que renvoyer une erreur. */
$ready  = false;
$total  = 0;
$notice = '';

try {
    $pdo = getConnection();
    $pdo->query('SELECT code FROM marking_codes LIMIT 1');
    $ready = true;
    $total = (int) $pdo->query('SELECT COUNT(*) FROM marking_codes')->fetchColumn();
} catch (Throwable $e) {
    $ready = false;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){try{var t=localStorage.getItem('compozit-theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t||'light');}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="assets/theme.css?v=20261109b">
    <script src="assets/theme.js?v=20261109b" defer></script>
    <title>Marquage CMS - Gestion des Composants</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--bg-muted) 0%, var(--border-color) 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }
        .container { max-width: 1480px; margin: 0 auto; padding: 20px; }
        .app-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; border-radius: 20px; padding: 28px 32px 32px;
            margin-bottom: 24px; box-shadow: 0 20px 40px rgba(102,126,234,0.2);
            position: relative; overflow: hidden;
        }
        .app-header::before {
            content: ''; position: absolute; top: -80px; right: -80px;
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(255,255,255,0.15), transparent 70%);
            border-radius: 50%;
        }
        .header-top {
            display: flex; justify-content: space-between; align-items: flex-start;
            position: relative; z-index: 2; margin-bottom: 20px;
        }
        .header-title { display: flex; align-items: center; gap: 14px; }
        .header-icon {
            width: 52px; height: 52px; background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px); border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px; border: 1px solid rgba(255,255,255,0.25);
        }
        .header-title h1 { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; }
        .header-title p { font-size: 13px; opacity: 0.85; margin-top: 3px; }
        .user-chip {
            display: flex; align-items: center; gap: 12px;
            background: rgba(255,255,255,0.15); backdrop-filter: blur(10px);
            padding: 8px 16px; border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.2); font-size: 13px;
        }
        .logout-link {
            color: white; text-decoration: none; background: rgba(255,255,255,0.2);
            padding: 4px 10px; border-radius: 999px; font-size: 12px;
            font-weight: 500; transition: background 0.2s;
        }
        .logout-link:hover { background: rgba(255,255,255,0.3); }
        .nav-buttons {
            display: flex; gap: 10px; flex-wrap: wrap;
            margin-top: 6px; position: relative; z-index: 2;
        }
        .nav-buttons a {
            background: rgba(255,255,255,0.15); backdrop-filter: blur(10px);
            color: white; padding: 10px 18px; border-radius: 999px;
            text-decoration: none; font-weight: 500; font-size: 14px;
            transition: all 0.2s; border: 1px solid rgba(255,255,255,0.2);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .nav-buttons a:hover { background: rgba(255,255,255,0.28); transform: translateY(-1px); }
        .nav-buttons a.active { background: rgba(255,255,255,0.35); }
        .content {
            padding: 30px; background: var(--bg-card);
            border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.08);
        }
        .card-head {
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
            margin-bottom: 6px;
        }
        .card-head h2 { font-size: 20px; }
        .badge {
            background: var(--bg-muted); color: var(--text-secondary);
            padding: 5px 12px; border-radius: 999px; font-size: 12.5px; font-weight: 600;
        }
        .hint { font-size: 12.5px; color: var(--text-secondary); }
        .hint code, .mono {
            font-family: 'Consolas', 'Courier New', monospace;
            background: var(--bg-muted); padding: 1px 5px; border-radius: 4px;
        }
        .toolbar {
            display: flex; gap: 16px; align-items: flex-end;
            flex-wrap: wrap; margin: 20px 0 10px;
        }
        .field { display: flex; flex-direction: column; gap: 6px; flex: 1 1 320px; }
        .field.narrow { flex: 0 0 120px; }
        .field.actions-field { flex: 0 0 auto; flex-direction: row; gap: 10px; }
        .field label {
            font-size: 12.5px; font-weight: 600; color: var(--text-secondary);
            text-transform: uppercase; letter-spacing: 0.03em;
        }
        .field input[type="search"], .field select {
            padding: 12px 14px; border: 1px solid var(--border-color);
            border-radius: 8px; font-size: 15px; background: var(--bg-card);
            color: var(--text-primary); font-family: inherit;
        }
        .field input[type="search"] { font-size: 16px; letter-spacing: 0.04em; }
        .field input[type="search"]:focus, .field select:focus {
            outline: none; border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
        }
        .btn {
            padding: 12px 20px; border: none; border-radius: 8px; cursor: pointer;
            text-decoration: none; font-weight: 600; display: inline-flex;
            align-items: center; gap: 7px; font-size: 14px; transition: all 0.18s;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 6px 14px rgba(0,0,0,0.14); }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-primary { background: #6366f1; color: white; }
        .btn-ghost   { background: var(--bg-muted); color: var(--text-secondary); }
        .btn-sm      { padding: 6px 11px; font-size: 13px; border-radius: 6px; }
        .alert {
            padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;
            font-weight: 600; font-size: 14px; line-height: 1.6;
        }
        .alert-warn { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .alert-warn a { color: #856404; }
        .marking-table {
            width: 100%; border-collapse: collapse; margin-top: 18px;
            background: var(--bg-card); border-radius: 10px; overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        .marking-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 14px 10px; text-align: left; font-weight: 600;
            font-size: 13px; text-transform: uppercase; letter-spacing: 0.03em;
        }
        .marking-table td {
            padding: 11px 10px; border-bottom: 1px solid var(--border-color);
            font-size: 13.5px; vertical-align: top;
        }
        .marking-table tr:hover td { background: var(--bg-muted); }
        .code-cell {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 14px; font-weight: 700; letter-spacing: 0.06em;
            background: var(--bg-muted); color: #4c1d95;
            padding: 4px 10px; border-radius: 6px; white-space: nowrap;
        }
        .ref-cell { font-weight: 600; }
        .sub { color: var(--text-secondary); font-size: 12.5px; margin-top: 3px; }
        .package-cell { display: flex; align-items: center; gap: 8px; }
        .package-thumb {
            width: 34px; height: 34px; object-fit: contain; flex: 0 0 auto;
            background: #fff; border: 1px solid var(--border-color);
            border-radius: 5px; padding: 2px;
        }
        .row-actions { display: flex; gap: 6px; }
        .empty {
            text-align: center; padding: 46px 20px; color: var(--text-secondary);
            font-size: 15px;
        }
        .empty-icon { display: block; font-size: 34px; margin-bottom: 10px; }
        @media (max-width: 900px) {
            .container { padding: 12px; }
            .marking-table { font-size: 12px; display: block; overflow-x: auto; }
            .content { padding: 18px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="app-header">
            <div class="header-top">
                <div class="header-title">
                    <div class="header-icon">🔡</div>
                    <div>
                        <h1>Marquage CMS</h1>
                        <p>Retrouver un composant à partir du code imprimé sur son boîtier</p>
                    </div>
                </div>
                <div class="user-chip">
                    <span>👤 <?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Utilisateur'); ?></span>
                    <a href="logout.php" class="logout-link">🚪 Déconnexion</a>
                </div>
            </div>
            <div class="nav-buttons">
                <a href="components.php">📦 Composants</a>
                <a href="create_component.php">➕ Créer</a>
                <a href="marking.php" class="active">🔡 Marquage</a>
                <a href="projects.php">🚀 Projets</a>
                <a href="settings.php">⚙️ Paramètres</a>
            </div>
        </div>

        <div class="content">
            <?php if (!$ready): ?>
                <div class="alert alert-warn">
                    ⚠️ La base de marquage n'est pas installée sur cette base de données.
                    Installe-la depuis
                    <a href="import_marking_codes.php">Base de marquage CMS</a>
                    (Paramètres), puis recharge cette page.
                </div>
            <?php endif; ?>

            <div class="card-head">
                <h2>🔎 Identifier un composant</h2>
                <?php if ($ready): ?>
                    <span class="badge"><?php echo number_format($total, 0, ',', ' '); ?> correspondances indexées</span>
                <?php endif; ?>
                <span class="hint">Les codes usuels font 2 à 4 caractères (ex. <span class="mono">65V</span>, <span class="mono">J1</span>, <span class="mono">2A</span>).</span>
            </div>

            <div class="toolbar">
                <div class="field">
                    <label for="marking_query">Code de marquage</label>
                    <input type="search" id="marking_query" autocomplete="off" spellcheck="false"
                           placeholder="Code imprimé sur le boîtier, ou référence fabricant">
                </div>
                <div class="field narrow">
                    <label for="marking_limit">Résultats</label>
                    <select id="marking_limit">
                        <option value="50">50</option>
                        <option value="100" selected>100</option>
                        <option value="200">200</option>
                    </select>
                </div>
                <div class="field actions-field">
                    <button class="btn btn-primary" type="button" id="marking_search_btn">🔎 Rechercher</button>
                    <button class="btn btn-ghost" type="button" id="marking_reset_btn">Réinitialiser</button>
                </div>
            </div>

            <div class="hint" id="marking_status">
                <?php if ($ready): ?>
                    Saisissez un code : la recherche démarre dès le premier caractère.
                <?php else: ?>
                    Importez d'abord la base de marquage.
                <?php endif; ?>
            </div>

            <table class="marking-table">
                <thead>
                <tr>
                    <th>Code</th>
                    <th>Référence</th>
                    <th>Fabricant</th>
                    <th>Boîtier</th>
                    <th>Fonction</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody id="marking_results">
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <span class="empty-icon">🔎</span>
                            Saisissez le code lu sur le composant pour retrouver sa référence.
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    (function () {
        var query    = document.getElementById('marking_query');
        var body     = document.getElementById('marking_results');
        if (!query || !body) { return; }

        var status   = document.getElementById('marking_status');
        var limitBox = document.getElementById('marking_limit');
        var cache    = [];

        // Noms de fichiers de img/ : sert a illustrer le boitier quand la
        // bibliotheque contient une image homonyme (comme le formulaire composant).
        var library = [];
        var libraryLoaded = false;

        function escapeHTML(value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function cell(value) {
            return escapeHTML(value === null || value === undefined ? '' : value);
        }

        function loadLibrary() {
            if (libraryLoaded) { return; }
            libraryLoaded = true;

            fetch('get_images.php', { headers: { 'Accept': 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    library = (data && data.images) || [];
                    if (cache.length) { body.innerHTML = cache.map(renderRow).join(''); }
                })
                .catch(function () { library = []; });
        }

        // Image correspondant au boitier : nom de fichier identique d'abord,
        // sinon le premier nom qui le contient.
        function packageImage(value) {
            var term = String(value || '').trim().toLowerCase();
            if (!term) { return null; }

            var loose = null;
            for (var index = 0; index < library.length; index += 1) {
                var name = String(library[index].name || '').toLowerCase();
                if (name === term) { return library[index]; }
                if (!loose && name.indexOf(term) !== -1) { loose = library[index]; }
            }
            return loose;
        }

        function packageCell(value) {
            if (!value) { return '<span class="sub">—</span>'; }
            var image = packageImage(value);
            return '<div class="package-cell">'
                + (image ? '<img class="package-thumb" src="' + escapeHTML(image.path) + '" alt="" loading="lazy" title="' + escapeHTML(image.filename) + '">' : '')
                + '<span>' + cell(value) + '</span></div>';
        }

        function setStatus(text) {
            if (status) { status.textContent = text; }
        }

        function emptyRow(text, icon) {
            return '<tr><td colspan="6"><div class="empty">'
                + '<span class="empty-icon">' + icon + '</span>' + cell(text) + '</div></td></tr>';
        }

        function renderRow(item, index) {
            return '<tr>'
                + '<td><span class="code-cell">' + cell(item.code) + '</span></td>'
                + '<td><span class="ref-cell">' + cell(item.part_number || item.name) + '</span></td>'
                + '<td>' + (item.manufacturer ? cell(item.manufacturer) : '<span class="sub">—</span>') + '</td>'
                + '<td>' + packageCell(item.package) + '</td>'
                + '<td>' + (item.function ? cell(item.function) : '<span class="sub">—</span>')
                    + (item.description ? '<div class="sub">' + cell(item.description) + '</div>' : '') + '</td>'
                + '<td><div class="row-actions">'
                    + '<button class="btn btn-ghost btn-sm" type="button" data-marking-datasheet="' + index
                        + '" title="Chercher la fiche technique">📄</button>'
                    + '<button class="btn btn-ghost btn-sm" type="button" data-marking-create="' + index
                        + '" title="Créer un composant avec cette référence">➕</button>'
                + '</div></td></tr>';
        }

        function reset() {
            cache = [];
            body.innerHTML = emptyRow('Saisissez le code lu sur le composant pour retrouver sa référence.', '🔎');
            setStatus('Saisissez un code : la recherche démarre dès le premier caractère.');
        }

        function search() {
            var term = query.value.trim();
            if (term === '') { reset(); return; }

            var limit = limitBox ? limitBox.value : '100';
            setStatus('Recherche en cours…');

            fetch('marking_search.php?limit=' + encodeURIComponent(limit)
                    + '&search=' + encodeURIComponent(term),
                  { headers: { 'Accept': 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        cache = [];
                        setStatus((data && data.message) || 'Recherche impossible.');
                        body.innerHTML = emptyRow((data && data.message) || 'Aucune correspondance.', '🔍');
                        return;
                    }
                    cache = data.results || [];
                    setStatus(data.message);
                    body.innerHTML = cache.map(renderRow).join('');
                })
                .catch(function () {
                    setStatus('Erreur de communication avec le serveur.');
                });
        }

        /* Ouvre le formulaire de composant pre-rempli par la correspondance. */
        function createComponent(item) {
            try {
                window.sessionStorage.setItem('compozit.marking', JSON.stringify({
                    code: item.code,
                    part_number: item.part_number || item.name,
                    manufacturer: item.manufacturer || '',
                    package: item.package || '',
                    function: item.function || '',
                    description: item.description || ''
                }));
            } catch (error) {
                // Stockage indisponible : le formulaire s'ouvrira vierge.
            }
            window.location.href = 'create_component.php';
        }

        /* Cherche la fiche technique officielle de la reference et l'ouvre. */
        function openDatasheet(item, button) {
            var reference = item.part_number || item.name;
            if (!reference) { return; }

            var label = button.textContent;
            button.disabled = true;
            button.textContent = '…';

            fetch('datasheet_lookup.php?mpn=' + encodeURIComponent(reference)
                    + '&manufacturer=' + encodeURIComponent(item.manufacturer || ''),
                  { headers: { 'Accept': 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.ok && data.datasheet) {
                        window.open(data.datasheet, '_blank', 'noopener');
                        setStatus('Fiche technique de ' + reference + ' trouvée sur ' + data.source + '.');
                    } else {
                        setStatus('Aucune fiche technique trouvée pour « ' + reference + ' ».');
                    }
                })
                .catch(function () {
                    setStatus('Erreur de communication avec le serveur.');
                })
                .finally(function () {
                    button.disabled = false;
                    button.textContent = label;
                });
        }

        var debounceTimer = null;
        function debouncedSearch() {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(search, 250);
        }

        // La bibliotheque d'images arrive en parallele de la premiere saisie.
        loadLibrary();

        query.addEventListener('input', debouncedSearch);
        query.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                search();
            } else if (event.key === 'Escape') {
                query.value = '';
                reset();
            }
        });

        var button = document.getElementById('marking_search_btn');
        if (button) { button.addEventListener('click', search); }

        var resetButton = document.getElementById('marking_reset_btn');
        if (resetButton) {
            resetButton.addEventListener('click', function () {
                query.value = '';
                reset();
                query.focus();
            });
        }

        if (limitBox) { limitBox.addEventListener('change', search); }

        body.addEventListener('click', function (event) {
            var node = event.target.closest('[data-marking-create]');
            if (node) {
                var selected = cache[parseInt(node.getAttribute('data-marking-create'), 10)];
                if (selected) { createComponent(selected); }
                return;
            }
            var sheet = event.target.closest('[data-marking-datasheet]');
            if (sheet) {
                var part = cache[parseInt(sheet.getAttribute('data-marking-datasheet'), 10)];
                if (part) { openDatasheet(part, sheet); }
            }
        });
    })();
    </script>
</body>
</html>


