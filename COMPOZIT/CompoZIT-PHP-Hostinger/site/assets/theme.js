/* =========================================================================
   CompoZ'IT — Thème partagé et coque moderne du site PHP
   -------------------------------------------------------------------------
   Le site PHP est désormais piloté en mode sombre uniquement :
   - thème sombre forcé ;
   - coque commune (sidebar + topbar) pour les pages authentifiées ;
   - adaptations compactes de suppliers.php et api_settings.php.
   ========================================================================= */
(function () {
    'use strict';

    if (window.__czPhpModernized) {
        return;
    }
    window.__czPhpModernized = true;

    var STORAGE_KEY = 'compozit-theme';
    var SIDEBAR_KEY = 'compozit-sidebar';
    var root = document.documentElement;

    var navGroups = [
        {
            title: 'Inventaire',
            items: [
                { href: 'components.php', label: 'Composants', icon: '▣', section: 'components' },
                { href: 'locations.php', label: 'Emplacements', icon: '▤', section: 'locations' },
                { href: 'categories_management.php', label: 'Catégories', icon: '#', section: 'categories' },
                { href: 'marking.php', label: 'Marquage CMS', icon: '◌', section: 'marking' }
            ]
        },
        {
            title: 'Partenaires',
            items: [
                { href: 'manufacturers.php', label: 'Fabricants', icon: '◍', section: 'manufacturers' },
                { href: 'suppliers.php', label: 'Fournisseurs', icon: '⇄', section: 'suppliers' }
            ]
        },
        {
            title: 'Activité',
            items: [
                { href: 'projects.php', label: 'Projets', icon: '◫', section: 'projects' }
            ]
        },
        {
            title: 'Système',
            items: [
                { href: 'settings.php', label: 'Paramètres', icon: '⚙', section: 'settings' }
            ]
        }
    ];

    function currentTheme() {
        return 'dark';
    }

    function updateSwitches(theme) {
        var isDark = true;
        var switches = document.querySelectorAll('.theme-switch');
        for (var i = 0; i < switches.length; i++) {
            var sun = switches[i].querySelector('.theme-switch-sun');
            var moon = switches[i].querySelector('.theme-switch-moon');
            if (sun) { sun.classList.toggle('is-active', false); }
            if (moon) { moon.classList.toggle('is-active', true); }
            switches[i].setAttribute('aria-pressed', isDark ? 'true' : 'false');
            switches[i].setAttribute('aria-label', 'Mode sombre activé');
            switches[i].setAttribute('title', 'Mode sombre activé');
        }
    }

    function applyTheme(theme, persist) {
        var normalized = 'dark';
        root.setAttribute('data-theme', 'dark');
        if (persist) {
            try { localStorage.setItem(STORAGE_KEY, normalized); } catch (e) {}
        }
        updateSwitches(normalized);
    }

    function ensureTheme() {
        applyTheme('dark', true);
    }

    function toggleTheme() {
        applyTheme('dark', true);
    }

    function sidebarCollapsed() {
        try { return localStorage.getItem(SIDEBAR_KEY) === 'collapsed'; } catch (e) { return false; }
    }

    function applySidebarMode() {
        if (document.body) {
            document.body.classList.toggle('cz-side-collapsed', sidebarCollapsed());
        }
    }

    function updateSidebarToggle() {
        var btn = document.querySelector('.cz-side-toggle');
        if (!btn) { return; }
        var collapsed = !!(document.body && document.body.classList.contains('cz-side-collapsed'));
        btn.innerHTML = '<span class="cz-side-toggle-icon">' + (collapsed ? '»' : '«') + '</span>';
        btn.setAttribute('title', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
        btn.setAttribute('aria-label', collapsed ? 'Agrandir le menu' : 'Réduire le menu');
    }

    function toggleSidebar() {
        var collapsed = !(document.body && document.body.classList.contains('cz-side-collapsed'));
        try { localStorage.setItem(SIDEBAR_KEY, collapsed ? 'collapsed' : 'normal'); } catch (e) {}
        if (document.body) { document.body.classList.toggle('cz-side-collapsed', collapsed); }
        updateSidebarToggle();
    }

    function buildSwitch() {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'theme-switch';
        button.innerHTML = '<span class="theme-switch-seg theme-switch-sun">☀</span>' +
            '<span class="theme-switch-seg theme-switch-moon">◐</span>';
        button.addEventListener('click', toggleTheme);
        return button;
    }

    function mountThemeSwitch() {
        var existing = document.querySelectorAll('.theme-switch');
        for (var i = 0; i < existing.length; i++) {
            existing[i].remove();
        }
        return;
        if (document.querySelector('.theme-switch')) {
            updateSwitches(currentTheme());
            return;
        }
        var chip = document.querySelector('.user-chip');
        var logout = chip ? chip.querySelector('.logout-link') : null;
        if (chip) {
            if (logout) {
                chip.insertBefore(buildSwitch(), logout);
            } else {
                chip.appendChild(buildSwitch());
            }
            updateSwitches(currentTheme());
            return;
        }
        var anchor = document.querySelector('.header-top') ||
            document.querySelector('.nav-buttons') ||
            document.querySelector('header');
        if (anchor) {
            anchor.appendChild(buildSwitch());
        }
        updateSwitches(currentTheme());
    }

    function pagePath() {
        var path = location.pathname.split('/').pop() || 'index.php';
        return path.toLowerCase();
    }

    function sectionForPath(path) {
        if ([
            'index.php',
            'components.php',
            'component_sheet.php',
            'create_component.php',
            'edit_component.php',
            'add_component.php',
            'check_duplicate.php',
            'get_components.php',
            'upload_image.php',
            'update_quantity.php'
        ].indexOf(path) >= 0) {
            return 'components';
        }
        if ([
            'locations.php',
            'add_location.php',
            'add_location_batch.php',
            'edit_location.php',
            'delete_location.php'
        ].indexOf(path) >= 0) {
            return 'locations';
        }
        if ([
            'categories_management.php',
            'get_subcategories.php'
        ].indexOf(path) >= 0) {
            return 'categories';
        }
        if ([
            'marking.php',
            'marking_search.php',
            'import_marking_codes.php'
        ].indexOf(path) >= 0) {
            return 'marking';
        }
        if ([
            'manufacturers.php'
        ].indexOf(path) >= 0) {
            return 'manufacturers';
        }
        if ([
            'suppliers.php',
            'suppliers_management.php',
            'add_supplier.php',
            'edit_supplier.php',
            'delete_supplier.php'
        ].indexOf(path) >= 0) {
            return 'suppliers';
        }
        if ([
            'projects.php',
            'project_detail.php',
            'create_project_ajax.php',
            'add_component_to_project.php',
            'ajax_update_project_component_needed.php',
            'ajax_update_project_progress.php',
            'ajax_update_project_quantity.php',
            'upload_project_file.php',
            'upload_project_image.php',
            'delete_project_file.php',
            'download_project_file.php',
            'generate_project_pdf.php',
            'get_projects.php'
        ].indexOf(path) >= 0) {
            return 'projects';
        }
        if ([
            'package_images_manager.php',
            'packages_management.php',
            'get_images.php',
            'get_packages.php',
            'get_subcategory_images.php',
            'auto_assign_package_images.php',
            'update_image.php'
        ].indexOf(path) >= 0) {
            return 'images';
        }
        if ([
            'api_settings.php',
            'nexar_api.php',
            'nexar_search.php',
            'mouser_api.php',
            'mouser_search.php',
            'digikey_api.php',
            'digikey_search.php',
            'farnell_api.php',
            'farnell_search.php',
            'tme_api.php',
            'tme_search.php',
            'lcsc_api.php',
            'lcsc_search.php',
            'rs_api.php',
            'rs_search.php',
            'datasheets_api.php',
            'datasheet_lookup.php'
        ].indexOf(path) >= 0) {
            return 'api';
        }
        if ([
            'import_database.php',
            'export_database.php'
        ].indexOf(path) >= 0) {
            return 'import_export';
        }
        if ([
            'settings.php',
            'profile.php',
            'mobile_api.php'
        ].indexOf(path) >= 0) {
            return 'settings';
        }
        return '';
    }

    function bodyContainer() {
        var children = document.body ? document.body.children : [];
        for (var i = 0; i < children.length; i++) {
            if (children[i].classList && children[i].classList.contains('container')) {
                return children[i];
            }
        }
        return document.querySelector('.container');
    }

    function createElement(tag, className, text) {
        var el = document.createElement(tag);
        if (className) { el.className = className; }
        if (typeof text === 'string') { el.textContent = text; }
        return el;
    }

    function buildSidebar(activeSection) {
        var aside = createElement('aside', 'cz-sidebar');

        var topRow = createElement('div', 'cz-side-top');
        var brand = createElement('a', 'cz-brand');
        brand.href = 'components.php';
        brand.innerHTML = '<span class="cz-brand-badge">C</span>' +
            '<span class="cz-brand-copy"><strong>CompoZ\'IT</strong><span>Gestion de composants</span></span>';
        var toggle = createElement('button', 'cz-side-toggle');
        toggle.type = 'button';
        toggle.innerHTML = '<span class="cz-side-toggle-icon">«</span>';
        toggle.setAttribute('title', 'Réduire le menu');
        toggle.setAttribute('aria-label', 'Réduire le menu');
        toggle.addEventListener('click', toggleSidebar);
        topRow.appendChild(brand);
        topRow.appendChild(toggle);
        aside.appendChild(topRow);

        for (var g = 0; g < navGroups.length; g++) {
            var group = navGroups[g];
            var title = createElement('div', 'cz-side-group-title', group.title);
            aside.appendChild(title);
            var nav = createElement('nav', 'cz-side-group');
            for (var i = 0; i < group.items.length; i++) {
                var item = group.items[i];
                var link = createElement('a', 'cz-side-link');
                if (item.section === activeSection) {
                    link.classList.add('is-active');
                }
                link.href = item.href;
                link.setAttribute('title', item.label);
                link.innerHTML = '<span class="cz-side-link-icon">' + item.icon + '</span>' +
                    '<span class="cz-side-link-label">' + item.label + '</span>';
                nav.appendChild(link);
            }
            aside.appendChild(nav);
        }

        return aside;
    }

    function buildShell() {
        var path = pagePath();
        document.body.setAttribute('data-cz-page', path);

        var loginCard = document.querySelector('body > main.page > .container');
        if (loginCard) {
            document.body.classList.add('cz-site-login');
            return;
        }

        var container = bodyContainer();
        var header = container ? container.querySelector('.app-header') : null;
        if (!container || document.querySelector('.cz-shell')) {
            if (path === 'component_sheet.php') {
                document.body.classList.add('cz-standalone-dark');
            }
            return;
        }

        if (!header) {
            if (path === 'component_sheet.php') {
                document.body.classList.add('cz-standalone-dark');
            }
            return;
        }

        var activeSection = sectionForPath(path);
        document.body.classList.add('cz-site-modern');
        if (activeSection) {
            document.body.setAttribute('data-cz-section', activeSection);
        }

        var shell = createElement('div', 'cz-shell');
        var sidebar = buildSidebar(activeSection);
        var main = createElement('div', 'cz-main-shell');
        document.body.insertBefore(shell, container);
        shell.appendChild(sidebar);
        shell.appendChild(main);
        main.appendChild(container);
        container.classList.add('cz-page-container');
    }

    function textContentOf(el) {
        return el ? el.textContent.replace(/\s+/g, ' ').trim() : '';
    }

    function firstTopLevelLink(card) {
        var links = card.querySelectorAll('a[href]');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href') || '';
            if (/^https?:\/\//i.test(href)) {
                return links[i];
            }
        }
        return null;
    }

    function transformSuppliers() {
        if (pagePath() !== 'suppliers.php' || document.querySelector('.cz-sup-list')) {
            return;
        }
        var grid = document.querySelector('.suppliers-grid');
        if (!grid) {
            return;
        }

        var cards = grid.querySelectorAll('.supplier-card');
        if (!cards.length) {
            return;
        }

        var shell = createElement('section', 'cz-sup-shell');
        var search = createElement('div', 'cz-sup-search');
        var input = document.createElement('input');
        input.type = 'search';
        input.className = 'cz-sup-input';
        input.placeholder = 'Rechercher un fournisseur, un contact, un site, un email...';
        input.autocomplete = 'off';
        input.spellcheck = false;
        var count = createElement('div', 'cz-sup-count', '0 / 0');
        search.appendChild(input);
        search.appendChild(count);
        shell.appendChild(search);

        var list = createElement('div', 'cz-sup-list');
        shell.appendChild(list);

        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var row = createElement('article', 'cz-sup-row');
            var head = createElement('div', 'cz-sup-head');
            var logoBox = createElement('div', 'cz-sup-logo');
            var name = textContentOf(card.querySelector('.supplier-name')) || 'Fournisseur';
            var siteLink = firstTopLevelLink(card);
            var siteText = siteLink ? textContentOf(siteLink) : 'Aucun site renseigné';
            var siteHref = siteLink ? siteLink.getAttribute('href') : '';

            var logo = card.querySelector('.supplier-logo');
            if (logo && logo.tagName && logo.tagName.toLowerCase() === 'img') {
                var cloneLogo = logo.cloneNode(true);
                cloneLogo.removeAttribute('style');
                logoBox.appendChild(cloneLogo);
            } else {
                logoBox.textContent = name.charAt(0).toUpperCase();
            }

            var main = createElement('div', 'cz-sup-main');
            var title = createElement('div', 'cz-sup-name', name);
            var site = createElement(siteHref ? 'a' : 'div', 'cz-sup-site', siteText);
            if (siteHref) {
                site.href = siteHref;
                site.target = '_blank';
                site.rel = 'noopener noreferrer';
            }
            main.appendChild(title);
            main.appendChild(site);

            var toggle = createElement('button', 'cz-sup-toggle');
            toggle.type = 'button';
            toggle.innerHTML = '<span class="cz-sup-caret">▾</span><span class="cz-sup-toggle-label">Détails</span>';

            var details = createElement('div', 'cz-sup-details');
            details.hidden = true;
            for (var c = 0; c < card.children.length; c++) {
                var child = card.children[c];
                if (child.classList.contains('supplier-header')) {
                    continue;
                }
                details.appendChild(child.cloneNode(true));
            }

            toggle.addEventListener('click', (function (panel, button) {
                return function () {
                    var open = panel.hidden;
                    panel.hidden = !open;
                    button.querySelector('.cz-sup-caret').classList.toggle('is-open', open);
                    button.querySelector('.cz-sup-toggle-label').textContent = open ? 'Masquer' : 'Détails';
                };
            })(details, toggle));

            head.appendChild(logoBox);
            head.appendChild(main);
            head.appendChild(toggle);
            row.appendChild(head);
            row.appendChild(details);
            row.setAttribute('data-cz-search', (name + ' ' + textContentOf(card)).toLowerCase());
            list.appendChild(row);
        }

        grid.parentNode.replaceChild(shell, grid);

        var rows = list.querySelectorAll('.cz-sup-row');
        var filter = function () {
            var q = (input.value || '').trim().toLowerCase();
            var shown = 0;
            for (var r = 0; r < rows.length; r++) {
                var match = !q || (rows[r].getAttribute('data-cz-search') || '').indexOf(q) >= 0;
                rows[r].hidden = !match;
                if (match) { shown++; }
            }
            count.textContent = shown + ' / ' + rows.length;
        };
        input.addEventListener('input', filter);
        input.addEventListener('search', filter);
        filter();
    }

    function cleanApiLabel(text) {
        var cleaned = (text || '').replace(/\s+/g, ' ').trim();
        cleaned = cleaned.replace(/^[^A-Za-z0-9À-ÿ]+/, '');
        cleaned = cleaned.split(/(?:—|–|-)\s+/)[0].trim();
        return cleaned || 'API';
    }

    function apiState(label, states) {
        var key = (label || '').toLowerCase();
        if (Object.prototype.hasOwnProperty.call(states, key)) {
            return states[key];
        }
        for (var name in states) {
            if (!Object.prototype.hasOwnProperty.call(states, name)) {
                continue;
            }
            if (key.indexOf(name) >= 0 || name.indexOf(key) >= 0) {
                return states[name];
            }
        }
        return null;
    }

    function transformApiSettings() {
        if (pagePath() !== 'api_settings.php' || document.querySelector('.cz-api-item')) {
            return;
        }
        var forms = document.querySelectorAll('form.card');
        if (!forms.length) {
            return;
        }

        var chipState = {};
        var badges = document.querySelector('.status-badges');
        if (badges) {
            var chips = badges.querySelectorAll('.status-chip');
            for (var i = 0; i < chips.length; i++) {
                var chip = chips[i];
                chipState[textContentOf(chip).toLowerCase()] =
                    chip.querySelector('.status-dot.on') ? true :
                    chip.querySelector('.status-dot.off') ? false : null;
            }
            badges.style.display = 'none';
        }

        var params = new URLSearchParams(location.search);
        var justSaved = (params.get('success') || '').toLowerCase();

        for (var f = 0; f < forms.length; f++) {
            (function (form) {
                var sectionInput = form.querySelector('input[name="section"]');
                var section = sectionInput ? (sectionInput.value || '').toLowerCase() : '';
                var heading = form.querySelector('h2');
                var label = cleanApiLabel(textContentOf(heading) || section);
                var isOn = apiState(label, chipState);

                var item = createElement('div', 'cz-api-item');
                var head = createElement('div', 'cz-api-head');
                var dot = createElement('span', 'cz-api-dot');
                if (isOn === true) { dot.classList.add('is-on'); }
                else if (isOn === false) { dot.classList.add('is-off'); }

                var title = createElement('div', 'cz-api-label', label);
                var meta = createElement('div', 'cz-api-meta', isOn === true ? 'Configurée' : (isOn === false ? 'À configurer' : 'Statut inconnu'));
                var toggle = createElement('button', 'cz-api-toggle');
                toggle.type = 'button';
                toggle.innerHTML = '<span class="cz-api-caret">▾</span>';

                form.classList.add('cz-api-form');
                form.hidden = true;

                var setOpen = function (open) {
                    form.hidden = !open;
                    toggle.querySelector('.cz-api-caret').classList.toggle('is-open', open);
                };

                toggle.addEventListener('click', function () {
                    setOpen(form.hidden);
                });

                head.appendChild(dot);
                head.appendChild(title);
                head.appendChild(meta);
                head.appendChild(toggle);
                item.appendChild(head);
                form.parentNode.insertBefore(item, form);

                if (section && justSaved && section === justSaved) {
                    setOpen(true);
                }

                item.__czOpen = setOpen;
            })(forms[f]);
        }

        if ((location.hash || '').indexOf('#tuto-') === 0) {
            var target = document.getElementById(location.hash.slice(1));
            if (target && target.closest) {
                var owner = target.closest('form.cz-api-form');
                var trigger = owner ? owner.previousElementSibling : null;
                if (trigger && trigger.__czOpen) {
                    trigger.__czOpen(true);
                }
            }
        }
    }

    function markReady() {
        root.classList.add('cz-ready');
    }

    function mount() {
        try {
            ensureTheme();
            applySidebarMode();
            buildShell();
            updateSidebarToggle();
            mountThemeSwitch();
            transformSuppliers();
            transformApiSettings();
        } finally {
            markReady();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
