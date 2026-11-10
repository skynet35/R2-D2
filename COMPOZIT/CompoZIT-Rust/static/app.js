/* CompoZ'IT — interactions légères : thème, filtres, stock, formulaire composant. */
(function () {
    'use strict';

    var STORAGE_KEY = 'compozit.theme';

    // ------------------------------------------------------------------ Outils

    function requestJSON(url, options) {
        return fetch(url, options).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    function postJSON(url, payload) {
        return requestJSON(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    }

    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var args = arguments, self = this;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { fn.apply(self, args); }, delay);
        };
    }

    // ------------------------------------------------------------------- Thème

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        var button = document.getElementById('theme-toggle');
        if (button) {
            button.textContent = theme === 'dark' ? '☀️' : '🌙';
            button.setAttribute('title', theme === 'dark' ? 'Passer en thème clair' : 'Passer en thème sombre');
        }
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    applyTheme(currentTheme());

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('#theme-toggle');
        if (!toggle) {
            return;
        }
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(STORAGE_KEY, next); } catch (e) { /* stockage indisponible */ }
        applyTheme(next);
    });

    // Les listes déroulantes de filtre relancent la recherche immédiatement.
    document.querySelectorAll('[data-auto-submit]').forEach(function (select) {
        select.addEventListener('change', function () {
            // Une catégorie principale change : on oublie la sous-catégorie,
            // qui n'a de sens que dans son arbre.
            var cascade = select.getAttribute('data-cascade-reset');
            if (cascade) {
                var target = document.getElementById(cascade);
                if (target) {
                    target.value = '';
                }
            }
            var form = select.closest('form');
            if (form) {
                form.submit();
            }
        });
    });

    // Champ de recherche : envoi automatique après une courte pause.
    var searchInput = document.querySelector('[data-live-search]');
    if (searchInput) {
        var timer = null;
        searchInput.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                searchInput.closest('form').submit();
            }, 450);
        });
    }

    // -------------------------------------------------- Stock (− / +) sans rechargement

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.qty-btn');
        if (!button || button.disabled) {
            return;
        }
        var wrapper = button.closest('.qty');
        var value = wrapper.querySelector('.qty-value');
        var change = parseInt(button.getAttribute('data-change'), 10);

        button.disabled = true;
        postJSON('/api/components/quantity', {
            component_id: parseInt(wrapper.getAttribute('data-component-id'), 10),
            change: change
        }).then(function (data) {
            value.textContent = data.new_quantity;
            value.classList.toggle('is-ok', data.new_quantity > 0);
            value.classList.toggle('is-zero', data.new_quantity <= 0);
            wrapper.querySelector('[data-change="-1"]').disabled = data.new_quantity <= 0;

            // Le bouton de suppression n'est possible qu'à quantité nulle.
            var row = wrapper.closest('tr');
            var removeButton = row ? row.querySelector('.actions .danger') : null;
            if (removeButton) {
                removeButton.disabled = data.new_quantity > 0;
                removeButton.setAttribute('title', data.new_quantity > 0
                    ? 'Mettez la quantité à 0 avant de supprimer'
                    : 'Supprimer');
            }
        }).catch(function (error) {
            window.alert('Mise à jour impossible : ' + error.message);
        }).finally(function () {
            button.disabled = false;
        });
    });

    // -------------------------------------------------------- Confirmation de suppression

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-confirm]');
        if (form && !window.confirm(form.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    // ------------------------------------------------------------------ Lightbox

    var lightbox = document.getElementById('lightbox');
    if (lightbox) {
        var lightboxImage = document.getElementById('lightbox-image');
        var lightboxCaption = document.getElementById('lightbox-caption');

        function closeLightbox() {
            lightbox.hidden = true;
            lightboxImage.removeAttribute('src');
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('.thumb-btn');
            if (trigger) {
                lightboxImage.src = trigger.getAttribute('data-image');
                lightboxCaption.textContent = trigger.getAttribute('data-image-title') || '';
                lightbox.hidden = false;
                return;
            }
            if (event.target.closest('.lightbox-close') || event.target === lightbox) {
                closeLightbox();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !lightbox.hidden) {
                closeLightbox();
            }
        });
    }

    // ============================================================== Emplacements

    function postForm(url, values) {
        // Un `FormData` est transmis tel quel (envoi de fichier), sinon les
        // valeurs sont encodées en `application/x-www-form-urlencoded`.
        if (values instanceof FormData) {
            return requestJSON(url, { method: 'POST', body: values });
        }
        var body = new URLSearchParams();
        Object.keys(values).forEach(function (key) {
            body.append(key, values[key]);
        });
        return requestJSON(url, { method: 'POST', body: body });
    }

    // --- Liste : vues, ouverture d'un conteneur, actions sur les compartiments.

    (function initLocations() {
        var root = document.querySelector('[data-locations]');
        if (!root) {
            return;
        }

        var grid = root.querySelector('.loc-grid');
        var table = root.querySelector('[data-loc-panel="table"]');
        var viewButtons = root.querySelectorAll('[data-loc-view]');
        var details = root.querySelectorAll('[data-loc-detail]');
        var view = 'casiers';
        var openLetter = root.getAttribute('data-open') || null;

        function render() {
            Array.prototype.forEach.call(viewButtons, function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-loc-view') === view);
            });
            if (grid) {
                grid.hidden = view !== 'casiers' || openLetter !== null;
            }
            if (table) {
                table.hidden = view !== 'table';
            }
            Array.prototype.forEach.call(details, function (detail) {
                detail.hidden = detail.getAttribute('data-loc-detail') !== openLetter;
            });
        }

        function openContainer(letter) {
            openLetter = letter;
            view = 'casiers';
            render();
            var detail = root.querySelector('[data-loc-detail="' + letter + '"]');
            if (detail) {
                detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function closeContainer() {
            openLetter = null;
            render();
            if (grid) {
                grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // --- Modale d'action sur un compartiment.

        var actionModal = document.querySelector('[data-loc-action]');
        var actionCode = actionModal ? actionModal.querySelector('[data-loc-action-code]') : null;
        var actionStatus = actionModal ? actionModal.querySelector('[data-loc-action-status]') : null;
        var actionEdit = actionModal ? actionModal.querySelector('[data-loc-action-edit]') : null;
        var actionDelete = actionModal ? actionModal.querySelector('[data-loc-action-delete]') : null;
        var actionId = null;
        var actionLabel = '';

        function openActions(id, label, occupied) {
            if (!actionModal) {
                return;
            }
            actionId = id;
            actionLabel = label;
            if (actionCode) {
                actionCode.textContent = label;
            }
            if (actionEdit) {
                actionEdit.setAttribute('href', '/locations/' + id + '/edit');
            }
            if (actionDelete) {
                actionDelete.hidden = occupied;
            }
            if (actionStatus) {
                actionStatus.className = 'loc-modal-status';
                actionStatus.textContent = occupied
                    ? '🔒 Ce compartiment contient des composants : suppression verrouillée.'
                    : 'Compartiment vide : modification et suppression disponibles.';
            }
            actionModal.hidden = false;
        }

        function closeActions() {
            if (actionModal) {
                actionModal.hidden = true;
            }
            actionId = null;
        }

        function deleteLocation(id, label) {
            if (!id || !window.confirm('Supprimer l\'emplacement ' + label + ' ?')) {
                return;
            }
            postJSON('/locations/delete', { location_id: parseInt(id, 10) })
                .then(function (data) {
                    if (!data.ok) {
                        window.alert('❌ ' + (data.error || 'Suppression impossible.'));
                        return;
                    }
                    window.location.reload();
                })
                .catch(function (error) {
                    window.alert('❌ Erreur réseau : ' + error.message);
                });
        }

        function deleteContainer(letter, label) {
            if (!window.confirm('⚠️ Supprimer définitivement « ' + label + ' » ?\n\n'
                + 'Tous ses emplacements vides seront effacés.\nCette action est irréversible.')) {
                return;
            }
            postForm('/locations/casier-delete', { casier: letter })
                .then(function (data) {
                    if (!data.ok) {
                        window.alert('❌ ' + (data.error || 'Suppression impossible.'));
                        return;
                    }
                    window.location.reload();
                })
                .catch(function (error) {
                    window.alert('❌ Erreur réseau : ' + error.message);
                });
        }

        if (actionModal) {
            actionModal.addEventListener('click', function (event) {
                if (event.target === actionModal || event.target.closest('[data-loc-action-close]')) {
                    closeActions();
                }
            });
        }
        if (actionDelete) {
            actionDelete.addEventListener('click', function () {
                deleteLocation(actionId, actionLabel);
            });
        }

        // --- Modale « type & logo » d'un conteneur.

        var typeModal = document.querySelector('[data-loc-type-modal]');
        var typeName = typeModal ? typeModal.querySelector('[data-loc-type-name]') : null;
        var typeStatus = typeModal ? typeModal.querySelector('[data-loc-type-status]') : null;
        var typeLogoLink = typeModal ? typeModal.querySelector('[data-loc-type-logo]') : null;
        var typeChoices = typeModal ? typeModal.querySelectorAll('[data-loc-type-choice]') : [];
        var container = null;

        function paintTypeChoices() {
            Array.prototype.forEach.call(typeChoices, function (choice) {
                choice.classList.toggle(
                    'is-active',
                    container !== null && choice.getAttribute('data-loc-type-choice') === container.type
                );
            });
        }

        function updateContainer(letter, icon, label) {
            var card = root.querySelector('[data-loc-card="' + letter + '"]');
            if (card) {
                var badge = card.querySelector('.loc-badge');
                if (badge && !badge.querySelector('img')) {
                    badge.textContent = icon;
                }
                var title = card.querySelector('.loc-card-title strong');
                if (title) {
                    title.textContent = label + ' ' + letter;
                }
            }
            var detail = root.querySelector('[data-loc-detail="' + letter + '"]');
            if (detail) {
                var detailBadge = detail.querySelector('.loc-badge');
                if (detailBadge && !detailBadge.querySelector('img')) {
                    detailBadge.textContent = icon;
                }
                var heading = detail.querySelector('h2');
                if (heading) {
                    heading.textContent = label + ' ' + letter;
                }
            }
        }

        function applyType(choice) {
            if (!container) {
                return;
            }
            var key = choice.getAttribute('data-loc-type-choice');
            choice.disabled = true;

            postForm('/locations/casier-type', { casier: container.letter, storage_type: key })
                .then(function (data) {
                    choice.disabled = false;
                    if (!data.ok) {
                        typeStatus.className = 'loc-modal-status is-error';
                        typeStatus.textContent = '❌ ' + (data.error || 'Changement impossible.');
                        return;
                    }
                    container.type = key;
                    Array.prototype.forEach.call(root.querySelectorAll('[data-loc-type]'), function (button) {
                        if (button.getAttribute('data-loc-type') === container.letter) {
                            button.setAttribute('data-loc-type-current', key);
                        }
                    });
                    updateContainer(container.letter, data.icon, data.label);
                    paintTypeChoices();
                    typeStatus.className = 'loc-modal-status is-ok';
                    typeStatus.textContent = '✅ Type mis à jour : ' + data.label + '.';
                })
                .catch(function (error) {
                    choice.disabled = false;
                    typeStatus.className = 'loc-modal-status is-error';
                    typeStatus.textContent = '❌ Erreur réseau : ' + error.message;
                });
        }

        if (typeModal) {
            typeModal.addEventListener('click', function (event) {
                if (event.target === typeModal || event.target.closest('[data-loc-type-close]')) {
                    typeModal.hidden = true;
                    return;
                }
                var choice = event.target.closest('[data-loc-type-choice]');
                if (choice) {
                    applyType(choice);
                }
            });
        }

        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-loc-view]');
            if (button) {
                view = button.getAttribute('data-loc-view');
                if (view !== 'casiers') {
                    openLetter = null;
                }
                render();
                return;
            }

            if (event.target.closest('[data-loc-close]')) {
                closeContainer();
                return;
            }

            button = event.target.closest('[data-loc-open]');
            if (button) {
                openContainer(button.getAttribute('data-loc-open'));
                return;
            }

            button = event.target.closest('[data-loc-cell]');
            if (button) {
                openActions(
                    button.getAttribute('data-loc-cell'),
                    button.getAttribute('data-loc-label'),
                    button.getAttribute('data-loc-occupied') === 'true'
                );
                return;
            }

            button = event.target.closest('[data-loc-type]');
            if (button) {
                container = {
                    letter: button.getAttribute('data-loc-type'),
                    type: button.getAttribute('data-loc-type-current')
                };
                if (typeModal) {
                    typeName.textContent = container.letter;
                    if (typeLogoLink) {
                        typeLogoLink.setAttribute('href', '/locations/' + button.getAttribute('data-loc-type-first') + '/edit');
                    }
                    typeStatus.className = 'loc-modal-status';
                    typeStatus.textContent = '';
                    paintTypeChoices();
                    typeModal.hidden = false;
                }
                return;
            }

            button = event.target.closest('[data-loc-delete-container]');
            if (button) {
                deleteContainer(
                    button.getAttribute('data-loc-delete-container'),
                    button.getAttribute('data-loc-container-label')
                );
                return;
            }

            button = event.target.closest('[data-loc-delete]');
            if (button) {
                deleteLocation(button.getAttribute('data-loc-delete'), button.getAttribute('data-loc-label'));
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }
            if (actionModal && !actionModal.hidden) {
                closeActions();
            }
            if (typeModal && !typeModal.hidden) {
                typeModal.hidden = true;
            }
        });

        render();
    })();

    // --- Formulaire d'emplacement : aperçu du code et choix du logo.

    (function initLocationForm() {
        var form = document.getElementById('location-form');
        if (!form) {
            return;
        }

        var casierInput = document.getElementById('casier');
        var tiroirInput = document.getElementById('tiroir');
        var compartimentInput = document.getElementById('compartiment');
        var codeOutput = document.getElementById('location-code');
        var previewCode = document.querySelector('[data-loc-preview-code]');
        var previewIcon = document.querySelector('[data-loc-preview-icon]');
        var typeSelect = document.getElementById('storage_type');

        function syncCode() {
            var code = (casierInput ? casierInput.value.trim() : '')
                + (tiroirInput ? tiroirInput.value.trim() : '')
                + '-' + (compartimentInput ? compartimentInput.value.trim() : '');
            if (codeOutput) {
                codeOutput.textContent = code;
            }
            if (previewCode) {
                previewCode.textContent = code;
            }
        }

        [casierInput, tiroirInput, compartimentInput].forEach(function (input) {
            if (input) {
                input.addEventListener('input', syncCode);
            }
        });
        syncCode();

        function syncIcon() {
            if (!typeSelect || !previewIcon) {
                return;
            }
            var option = typeSelect.options[typeSelect.selectedIndex];
            previewIcon.textContent = option ? option.textContent.trim().split(' ')[0] : '🗄️';
        }
        if (typeSelect) {
            typeSelect.addEventListener('change', syncIcon);
            syncIcon();
        }

        var logoAction = document.getElementById('logo_action');
        var existingGroup = document.getElementById('logo_existing_group');
        var uploadGroup = document.getElementById('logo_upload_group');
        var logoImages = document.getElementById('logo-images');
        var logoFilter = document.getElementById('logo-filter');
        var existingLogo = document.getElementById('existing_logo');
        var deleteLogo = document.getElementById('delete_logo');
        var logoFile = document.getElementById('logo');
        var logoPreview = document.getElementById('logo-preview');

        function setLogoPreview(source) {
            if (!logoPreview) {
                return;
            }
            logoPreview.innerHTML = source
                ? '<img src="' + escapeHTML(source) + '" alt="Logo du conteneur">'
                : '<div class="image-placeholder">Aucun logo</div>';
        }

        function syncLogoGroups() {
            var value = logoAction ? logoAction.value : '';
            if (existingGroup) {
                existingGroup.hidden = value !== 'existing';
            }
            if (uploadGroup) {
                uploadGroup.hidden = value !== 'upload';
            }
            if (deleteLogo) {
                deleteLogo.value = value === 'none' ? '1' : '';
            }
            if (existingLogo && value !== 'existing') {
                existingLogo.value = '';
            }
            if (logoFile && value !== 'upload') {
                logoFile.value = '';
            }
            if (value === 'none') {
                setLogoPreview('');
            }
        }

        if (logoAction) {
            logoAction.addEventListener('change', syncLogoGroups);
            syncLogoGroups();
        }

        if (logoImages) {
            logoImages.addEventListener('click', function (event) {
                var button = event.target.closest('button[data-logo-image]');
                if (!button) {
                    return;
                }
                var name = button.getAttribute('data-logo-image');
                if (existingLogo) {
                    existingLogo.value = name;
                }
                if (deleteLogo) {
                    deleteLogo.value = '';
                }
                Array.prototype.forEach.call(logoImages.querySelectorAll('button'), function (node) {
                    node.classList.toggle('is-selected', node === button);
                });
                setLogoPreview('/img/' + name);
            });
        }

        if (logoFilter && logoImages) {
            logoFilter.addEventListener('input', function () {
                var term = logoFilter.value.trim().toLowerCase();
                Array.prototype.forEach.call(logoImages.querySelectorAll('button'), function (button) {
                    var name = (button.getAttribute('data-logo-image') || '').toLowerCase();
                    button.hidden = term.length > 0 && name.indexOf(term) === -1;
                });
            });
        }

        if (logoFile) {
            logoFile.addEventListener('change', function () {
                var file = logoFile.files && logoFile.files[0];
                if (!file) {
                    return;
                }
                if (existingLogo) {
                    existingLogo.value = '';
                }
                setLogoPreview(URL.createObjectURL(file));
            });
        }
    })();

    // --- Création par grappe : aperçu temps réel de la grille générée.

    (function initBatchForm() {
        var form = document.getElementById('location-batch-form');
        if (!form) {
            return;
        }

        var preview = document.getElementById('batch-preview');
        var summary = document.getElementById('batch-summary');
        var totalLabel = document.getElementById('batch-total');

        function number(id, fallback, maximum) {
            var input = document.getElementById(id);
            var value = input ? parseInt(input.value, 10) : NaN;
            if (isNaN(value)) {
                value = fallback;
            }
            return Math.min(Math.max(value, 1), maximum);
        }

        function render() {
            var casierInput = document.getElementById('casier');
            var casier = casierInput && casierInput.value.trim() ? casierInput.value.trim() : 'X';
            var first = number('premier_tiroir', 10, 99);
            var horizontal = number('tiroirs_horizontal', 6, 20);
            var vertical = number('tiroirs_vertical', 3, 10);
            var compartments = number('compartiments_par_tiroir', 4, 10);
            var count = horizontal * vertical * compartments;

            if (summary) {
                summary.innerHTML =
                    '<span class="batch-summary-item">🗄️ ' + escapeHTML(casier) + '</span>' +
                    '<span class="batch-summary-item">↔️ ' + horizontal + ' tiroirs / ligne</span>' +
                    '<span class="batch-summary-item">↕️ ' + vertical + ' ligne(s)</span>' +
                    '<span class="batch-summary-item">🧩 ' + compartments + ' compartiments / tiroir</span>' +
                    '<span class="batch-summary-item">📦 ' + count + ' emplacements</span>';
            }
            if (totalLabel) {
                totalLabel.textContent = count + ' emplacement(s) au total';
            }
            if (!preview) {
                return;
            }

            var html = '<div class="batch-grid" style="grid-template-columns: repeat(' + horizontal + ', 140px)">';
            var ligne;
            var colonne;
            var compartiment;
            for (ligne = 0; ligne < vertical; ligne += 1) {
                for (colonne = 0; colonne < horizontal; colonne += 1) {
                    var drawer = first + ligne * 10 + colonne;
                    html += '<div class="batch-drawer">' +
                        '<div class="batch-drawer-head">' +
                        '<span class="batch-drawer-title">' + escapeHTML(casier) + drawer + '</span>' +
                        '<span class="batch-drawer-meta">Tiroir ' + (ligne + 1) + '.' + (colonne + 1) + '</span>' +
                        '</div><div class="batch-slots">';
                    for (compartiment = 1; compartiment <= compartments; compartiment += 1) {
                        html += '<span class="batch-slot">' + escapeHTML(casier) + drawer + '-' + compartiment + '</span>';
                    }
                    html += '</div></div>';
                }
            }
            preview.innerHTML = html + '</div>';
        }

        ['casier', 'premier_tiroir', 'tiroirs_horizontal', 'tiroirs_vertical', 'compartiments_par_tiroir']
            .forEach(function (id) {
                var input = document.getElementById(id);
                if (input) {
                    input.addEventListener('input', render);
                }
            });

        render();
    })();

    // ============================================================== Catégories

    (function initCategories() {
        var card = document.querySelector('[data-categories]');
        if (!card) {
            return;
        }

        var list = document.getElementById('categories-list');
        var feedback = document.getElementById('cat-feedback');
        var search = document.getElementById('cat-search');
        var searchInfo = document.getElementById('cat-search-info');
        var opened = {};

        var subcatIds = {};
        var dataScript = document.getElementById('subcat-ids');
        if (dataScript) {
            try {
                subcatIds = JSON.parse(dataScript.textContent || '{}');
            } catch (error) {
                subcatIds = {};
            }
        }

        // --- Retour utilisateur -------------------------------------------------

        function notify(kind, text) {
            if (!feedback) {
                return;
            }
            feedback.hidden = false;
            feedback.className = 'alert ' + (kind === 'ok' ? 'ok' : 'error');
            var icon = feedback.querySelector('[data-fb-icon]');
            var body = feedback.querySelector('[data-fb-text]');
            if (icon) {
                icon.textContent = kind === 'ok' ? '✅' : '⚠️';
            }
            if (body) {
                body.textContent = text;
            }
        }

        // --- Identifiant prévisionnel d'une sous-catégorie ----------------------

        function nextSubId(parent) {
            var base = parent * 100;
            var existing = subcatIds[String(parent)] || [];
            var used = [];
            existing.forEach(function (id) {
                if (id > base && id <= base + 99) {
                    used.push(id - base);
                }
            });
            used.sort(function (a, b) { return a - b; });
            var next = 1;
            for (var i = 0; i < used.length; i += 1) {
                if (used[i] === next) {
                    next += 1;
                } else if (used[i] > next) {
                    break;
                }
            }
            return next <= 99 ? base + next : base + 100;
        }

        var parentSelect = document.getElementById('parent_category');
        var preview = document.getElementById('sub-id-preview');

        function refreshPreview() {
            if (!preview || !parentSelect) {
                return;
            }
            var parent = parseInt(parentSelect.value, 10);
            preview.textContent = parent > 0 ? String(nextSubId(parent)) : '—';
        }

        if (parentSelect) {
            parentSelect.addEventListener('change', refreshPreview);
        }

        // --- Recherche ----------------------------------------------------------

        function filter() {
            var term = search ? search.value.trim().toLowerCase() : '';
            var cards = list ? list.querySelectorAll('[data-cat-card]') : [];
            var visibleCards = 0;
            var visibleSubs = 0;

            Array.prototype.forEach.call(cards, function (item) {
                var id = item.getAttribute('data-cat-card');
                var name = (item.getAttribute('data-cat-name') || '');
                var cardMatch = !term || name.indexOf(term) >= 0;
                var subMatches = 0;

                Array.prototype.forEach.call(item.querySelectorAll('[data-cat-sub]'), function (sub) {
                    var subName = (sub.getAttribute('data-cat-sub-name') || '');
                    var match = !term || cardMatch || subName.indexOf(term) >= 0;
                    sub.classList.toggle('is-hidden', !match);
                    if (match) {
                        subMatches += 1;
                    }
                });

                var body = item.querySelector('[data-cat-body]');
                var visible = cardMatch || subMatches > 0;
                item.classList.toggle('is-hidden', !visible);

                if (visible) {
                    visibleCards += 1;
                    visibleSubs += subMatches;
                    if (term) {
                        opened[id] = true;
                    }
                }
                if (body) {
                    body.hidden = !opened[id];
                }
                item.classList.toggle('is-open', !!opened[id]);
            });

            if (searchInfo) {
                searchInfo.textContent = term
                    ? visibleCards + ' catégorie(s) · ' + visibleSubs + ' sous-catégorie(s)'
                    : '';
            }
        }

        if (search) {
            search.addEventListener('input', debounce(filter, 120));
        }

        // --- Rafraîchissement sans rechargement ---------------------------------

        function refresh() {
            return requestJSON('/api/categories').then(function (data) {
                if (list) {
                    list.innerHTML = data.html;
                }
                if (data.stats) {
                    ['categories', 'subcategories', 'components'].forEach(function (key) {
                        var target = card.querySelector('[data-cat-stat="' + key + '"]');
                        if (target && typeof data.stats[key] !== 'undefined') {
                            target.textContent = data.stats[key];
                        }
                    });
                }
                if (data.subcat_ids) {
                    subcatIds = data.subcat_ids;
                }
                if (data.categories && parentSelect) {
                    var selected = parentSelect.value;
                    var options = '<option value="">— Choisir —</option>';
                    data.categories.forEach(function (item) {
                        options += '<option value="' + item.id + '">' + escapeHTML(item.name) + '</option>';
                    });
                    parentSelect.innerHTML = options;
                    parentSelect.value = selected;
                }
                refreshPreview();
                filter();
            }).catch(function (error) {
                notify('error', 'Rafraîchissement impossible : ' + error.message);
            });
        }

        function action(url, values, successText) {
            return postForm(url, values).then(function (data) {
                if (!data.ok) {
                    notify('error', data.error || "L'opération n'a pas pu aboutir.");
                    return false;
                }
                notify('ok', successText);
                refresh();
                return true;
            }).catch(function (error) {
                notify('error', 'Erreur réseau : ' + error.message);
                return false;
            });
        }

        // --- Panneaux d'ajout ---------------------------------------------------

        var panels = card.querySelectorAll('[data-cat-panel-form]');
        var currentPanel = null;

        function showPanel(kind) {
            currentPanel = kind;
            Array.prototype.forEach.call(panels, function (panel) {
                panel.hidden = panel.getAttribute('data-cat-panel-form') !== kind;
            });
            var focus = document.getElementById(kind === 'category' ? 'category_name' : 'subcategory_name');
            if (focus) {
                focus.focus();
            }
        }

        function hidePanel() {
            currentPanel = null;
            Array.prototype.forEach.call(panels, function (panel) {
                panel.hidden = true;
            });
        }

        Array.prototype.forEach.call(card.querySelectorAll('[data-cat-panel]'), function (button) {
            button.addEventListener('click', function () {
                var kind = button.getAttribute('data-cat-panel');
                if (currentPanel === kind) {
                    hidePanel();
                } else {
                    showPanel(kind);
                }
            });
        });

        Array.prototype.forEach.call(card.querySelectorAll('[data-cat-panel-cancel]'), function (button) {
            button.addEventListener('click', hidePanel);
        });

        var categoryForm = document.getElementById('cat-form-category');
        if (categoryForm) {
            categoryForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var input = document.getElementById('category_name');
                var name = input ? input.value.trim() : '';
                if (!name) {
                    return;
                }
                action('/categories/add', { name: name }, 'Catégorie ajoutée : ' + name)
                    .then(function (done) {
                        if (done && input) {
                            input.value = '';
                            hidePanel();
                        }
                    });
            });
        }

        var subcategoryForm = document.getElementById('cat-form-subcategory');
        if (subcategoryForm) {
            subcategoryForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var input = document.getElementById('subcategory_name');
                var name = input ? input.value.trim() : '';
                if (!name || !parentSelect || !parentSelect.value) {
                    notify('error', 'Choisissez une catégorie parente et saisissez un nom.');
                    return;
                }
                action(
                    '/categories/add-sub',
                    { parent_category: parentSelect.value, subcategory_name: name },
                    'Sous-catégorie ajoutée : ' + name
                ).then(function (done) {
                    if (done && input) {
                        input.value = '';
                        hidePanel();
                    }
                });
            });
        }

        // --- Dépliage, renommage, suppression -----------------------------------

        function toggleCard(id) {
            opened[id] = !opened[id];
            var body = list ? list.querySelector('[data-cat-body="' + id + '"]') : null;
            var holder = list ? list.querySelector('[data-cat-card="' + id + '"]') : null;
            if (body) {
                body.hidden = !opened[id];
            }
            if (holder) {
                holder.classList.toggle('is-open', !!opened[id]);
            }
        }

        var renameModal = document.getElementById('cat-rename-modal');
        var renameForm = document.getElementById('cat-rename-form');
        var renameInput = document.getElementById('cat-rename-input');
        var renameTitle = renameModal ? renameModal.querySelector('[data-rename-title]') : null;
        var renameStatus = renameModal ? renameModal.querySelector('[data-rename-status]') : null;
        var renameTarget = null;

        function openRename(kind, id, label) {
            if (!renameModal) {
                return;
            }
            renameTarget = { kind: kind, id: id };
            if (renameTitle) {
                renameTitle.textContent = kind === 'head' ? 'Renommer la catégorie' : 'Renommer la sous-catégorie';
            }
            if (renameInput) {
                renameInput.value = label || '';
            }
            if (renameStatus) {
                renameStatus.className = 'loc-modal-status';
                renameStatus.textContent = '';
            }
            renameModal.hidden = false;
            if (renameInput) {
                renameInput.focus();
                renameInput.select();
            }
        }

        function closeRename() {
            if (renameModal) {
                renameModal.hidden = true;
            }
            renameTarget = null;
        }

        if (renameForm) {
            renameForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!renameTarget || !renameInput) {
                    return;
                }
                var value = renameInput.value.trim();
                if (!value) {
                    return;
                }
                var isHead = renameTarget.kind === 'head';
                var payload = isHead
                    ? { category_id: renameTarget.id, new_name: value }
                    : { subcategory_id: renameTarget.id, new_name: value };
                if (renameStatus) {
                    renameStatus.className = 'loc-modal-status';
                    renameStatus.textContent = 'Enregistrement…';
                }
                postForm(isHead ? '/categories/rename' : '/categories/rename-sub', payload)
                    .then(function (data) {
                        if (!data.ok) {
                            if (renameStatus) {
                                renameStatus.className = 'loc-modal-status is-error';
                                renameStatus.textContent = data.error || 'Échec du renommage.';
                            }
                            return;
                        }
                        closeRename();
                        notify('ok', 'Renommage effectué : ' + value);
                        refresh();
                    })
                    .catch(function (error) {
                        if (renameStatus) {
                            renameStatus.className = 'loc-modal-status is-error';
                            renameStatus.textContent = 'Erreur réseau : ' + error.message;
                        }
                    });
            });
        }

        var deleteModal = document.getElementById('cat-delete-modal');
        var deleteTitle = deleteModal ? deleteModal.querySelector('[data-delete-title]') : null;
        var deleteText = deleteModal ? deleteModal.querySelector('[data-delete-text]') : null;
        var deleteStatus = deleteModal ? deleteModal.querySelector('[data-delete-status]') : null;
        var deleteButton = deleteModal ? deleteModal.querySelector('[data-delete-confirm]') : null;
        var deleteTarget = null;

        function openDelete(kind, id, label) {
            if (!deleteModal) {
                return;
            }
            deleteTarget = { kind: kind, id: id };
            if (deleteTitle) {
                deleteTitle.textContent = kind === 'head' ? 'Supprimer la catégorie' : 'Supprimer la sous-catégorie';
            }
            if (deleteText) {
                deleteText.textContent = 'Confirmer la suppression de « ' + (label || '') + ' » ? Cette action est définitive.';
            }
            if (deleteStatus) {
                deleteStatus.className = 'loc-modal-status';
                deleteStatus.textContent = kind === 'head'
                    ? 'La catégorie doit être vide (aucune sous-catégorie).'
                    : 'La sous-catégorie ne doit être utilisée par aucun composant.';
            }
            deleteModal.hidden = false;
        }

        function closeDelete() {
            if (deleteModal) {
                deleteModal.hidden = true;
            }
            deleteTarget = null;
        }

        if (deleteButton) {
            deleteButton.addEventListener('click', function () {
                if (!deleteTarget) {
                    return;
                }
                var isHead = deleteTarget.kind === 'head';
                var payload = isHead
                    ? { category_id: deleteTarget.id }
                    : { subcategory_id: deleteTarget.id };
                if (deleteStatus) {
                    deleteStatus.className = 'loc-modal-status';
                    deleteStatus.textContent = 'Suppression…';
                }
                postForm(isHead ? '/categories/delete' : '/categories/delete-sub', payload)
                    .then(function (data) {
                        if (!data.ok) {
                            if (deleteStatus) {
                                deleteStatus.className = 'loc-modal-status is-error';
                                deleteStatus.textContent = data.error || 'Suppression impossible.';
                            }
                            return;
                        }
                        closeDelete();
                        notify('ok', 'Suppression effectuée.');
                        refresh();
                    })
                    .catch(function (error) {
                        if (deleteStatus) {
                            deleteStatus.className = 'loc-modal-status is-error';
                            deleteStatus.textContent = 'Erreur réseau : ' + error.message;
                        }
                    });
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-rename-close]'), function (button) {
            button.addEventListener('click', closeRename);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-delete-close]'), function (button) {
            button.addEventListener('click', closeDelete);
        });

        card.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            var button = target.closest('[data-cat-toggle]');
            if (button) {
                toggleCard(button.getAttribute('data-cat-toggle'));
                return;
            }
            button = target.closest('[data-cat-rename]');
            if (button) {
                openRename('head', button.getAttribute('data-cat-rename'), button.getAttribute('data-cat-label'));
                return;
            }
            button = target.closest('[data-sub-rename]');
            if (button) {
                openRename('sub', button.getAttribute('data-sub-rename'), button.getAttribute('data-sub-label'));
                return;
            }
            button = target.closest('[data-cat-delete]');
            if (button) {
                openDelete('head', button.getAttribute('data-cat-delete'), button.getAttribute('data-cat-label'));
                return;
            }
            button = target.closest('[data-sub-delete]');
            if (button) {
                openDelete('sub', button.getAttribute('data-sub-delete'), button.getAttribute('data-sub-label'));
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeRename();
                closeDelete();
            }
        });

        refreshPreview();
    })();

    // ============================================================== Fabricants

    (function initManufacturers() {
        var card = document.querySelector('[data-manufacturers]');
        if (!card) {
            return;
        }

        var list = document.getElementById('manufacturers-list');
        var feedback = document.getElementById('mfr-feedback');
        var search = document.getElementById('mfr-search');
        var searchInfo = document.getElementById('mfr-search-info');
        var panel = document.getElementById('mfr-form');
        var panelButton = card.querySelector('[data-mfr-panel]');

        function notify(kind, text) {
            if (!feedback) {
                return;
            }
            feedback.hidden = false;
            feedback.className = 'alert ' + (kind === 'ok' ? 'ok' : 'error');
            var icon = feedback.querySelector('[data-fb-icon]');
            var body = feedback.querySelector('[data-fb-text]');
            if (icon) {
                icon.textContent = kind === 'ok' ? '✅' : '⚠️';
            }
            if (body) {
                body.textContent = text;
            }
        }

        function filter() {
            var term = search ? search.value.trim().toLowerCase() : '';
            var rows = list ? list.querySelectorAll('[data-mfr-row]') : [];
            var visible = 0;
            Array.prototype.forEach.call(rows, function (row) {
                var name = row.getAttribute('data-mfr-name') || '';
                var match = !term || name.indexOf(term) >= 0;
                row.hidden = !match;
                if (match) {
                    visible += 1;
                }
            });
            if (searchInfo) {
                searchInfo.textContent = term ? visible + ' fabricant(s)' : '';
            }
        }

        if (search) {
            search.addEventListener('input', debounce(filter, 120));
        }

        function refresh() {
            return requestJSON('/api/manufacturers/list').then(function (data) {
                if (list) {
                    list.innerHTML = data.html;
                }
                if (data.stats) {
                    ['manufacturers', 'linked', 'unattached'].forEach(function (key) {
                        var target = card.querySelector('[data-mfr-stat="' + key + '"]');
                        if (target && typeof data.stats[key] !== 'undefined') {
                            target.textContent = data.stats[key];
                        }
                    });
                }
                filter();
            }).catch(function (error) {
                notify('error', 'Rafraîchissement impossible : ' + error.message);
            });
        }

        function action(url, values, successText) {
            return postForm(url, values).then(function (data) {
                if (!data.ok) {
                    notify('error', data.error || "L'opération n'a pas pu aboutir.");
                    return false;
                }
                notify('ok', successText);
                refresh();
                return true;
            }).catch(function (error) {
                notify('error', 'Erreur réseau : ' + error.message);
                return false;
            });
        }

        function togglePanel(force) {
            if (!panel) {
                return;
            }
            var show = typeof force === 'boolean' ? force : panel.hidden;
            panel.hidden = !show;
            if (show) {
                var input = document.getElementById('manufacturer_name');
                if (input) {
                    input.focus();
                }
            }
        }

        if (panelButton) {
            panelButton.addEventListener('click', function () {
                togglePanel();
            });
        }
        Array.prototype.forEach.call(card.querySelectorAll('[data-mfr-panel-cancel]'), function (button) {
            button.addEventListener('click', function () {
                togglePanel(false);
            });
        });

        if (panel) {
            panel.addEventListener('submit', function (event) {
                event.preventDefault();
                var input = document.getElementById('manufacturer_name');
                var name = input ? input.value.trim() : '';
                if (!name) {
                    return;
                }
                action('/manufacturers/add', { name: name }, 'Fabricant ajouté : ' + name)
                    .then(function (done) {
                        if (done && input) {
                            input.value = '';
                            togglePanel(false);
                        }
                    });
            });
        }

        // --- Renommage ---------------------------------------------------------

        var renameModal = document.getElementById('mfr-rename-modal');
        var renameForm = document.getElementById('mfr-rename-form');
        var renameInput = document.getElementById('mfr-rename-input');
        var renameStatus = renameModal ? renameModal.querySelector('[data-rename-status]') : null;
        var renameId = 0;

        function openRename(id, label) {
            if (!renameModal) {
                return;
            }
            renameId = id;
            if (renameInput) {
                renameInput.value = label || '';
            }
            if (renameStatus) {
                renameStatus.className = 'loc-modal-status';
                renameStatus.textContent = '';
            }
            renameModal.hidden = false;
            if (renameInput) {
                renameInput.focus();
                renameInput.select();
            }
        }

        function closeRename() {
            if (renameModal) {
                renameModal.hidden = true;
            }
            renameId = 0;
        }

        if (renameForm) {
            renameForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!renameId || !renameInput) {
                    return;
                }
                var value = renameInput.value.trim();
                if (!value) {
                    return;
                }
                if (renameStatus) {
                    renameStatus.className = 'loc-modal-status';
                    renameStatus.textContent = 'Enregistrement…';
                }
                postForm('/manufacturers/rename', { manufacturer_id: renameId, new_name: value })
                    .then(function (data) {
                        if (!data.ok) {
                            if (renameStatus) {
                                renameStatus.className = 'loc-modal-status is-error';
                                renameStatus.textContent = data.error || 'Échec du renommage.';
                            }
                            return;
                        }
                        closeRename();
                        notify('ok', 'Fabricant renommé : ' + value);
                        refresh();
                    })
                    .catch(function (error) {
                        if (renameStatus) {
                            renameStatus.className = 'loc-modal-status is-error';
                            renameStatus.textContent = 'Erreur réseau : ' + error.message;
                        }
                    });
            });
        }

        // --- Suppression -------------------------------------------------------

        var deleteModal = document.getElementById('mfr-delete-modal');
        var deleteText = deleteModal ? deleteModal.querySelector('[data-delete-text]') : null;
        var deleteStatus = deleteModal ? deleteModal.querySelector('[data-delete-status]') : null;
        var deleteButton = deleteModal ? deleteModal.querySelector('[data-delete-confirm]') : null;
        var deleteId = 0;

        function openDelete(id, label, count) {
            if (!deleteModal) {
                return;
            }
            deleteId = id;
            if (deleteText) {
                deleteText.textContent = 'Confirmer la suppression de « ' + (label || '') + ' » ?';
            }
            if (deleteStatus) {
                deleteStatus.className = 'loc-modal-status';
                deleteStatus.textContent = count > 0
                    ? count + ' composant(s) seront détachés (fabricant vidé).'
                    : 'Aucun composant n\'est rattaché à ce fabricant.';
            }
            deleteModal.hidden = false;
        }

        function closeDelete() {
            if (deleteModal) {
                deleteModal.hidden = true;
            }
            deleteId = 0;
        }

        if (deleteButton) {
            deleteButton.addEventListener('click', function () {
                if (!deleteId) {
                    return;
                }
                if (deleteStatus) {
                    deleteStatus.className = 'loc-modal-status';
                    deleteStatus.textContent = 'Suppression…';
                }
                postForm('/manufacturers/delete', { manufacturer_id: deleteId })
                    .then(function (data) {
                        if (!data.ok) {
                            if (deleteStatus) {
                                deleteStatus.className = 'loc-modal-status is-error';
                                deleteStatus.textContent = data.error || 'Suppression impossible.';
                            }
                            return;
                        }
                        closeDelete();
                        notify('ok', 'Fabricant supprimé.');
                        refresh();
                    })
                    .catch(function (error) {
                        if (deleteStatus) {
                            deleteStatus.className = 'loc-modal-status is-error';
                            deleteStatus.textContent = 'Erreur réseau : ' + error.message;
                        }
                    });
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-rename-close]'), function (button) {
            button.addEventListener('click', closeRename);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-delete-close]'), function (button) {
            button.addEventListener('click', closeDelete);
        });

        card.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            var button = target.closest('[data-mfr-rename]');
            if (button) {
                openRename(
                    parseInt(button.getAttribute('data-mfr-rename'), 10),
                    button.getAttribute('data-mfr-label')
                );
                return;
            }
            button = target.closest('[data-mfr-delete]');
            if (button) {
                openDelete(
                    parseInt(button.getAttribute('data-mfr-delete'), 10),
                    button.getAttribute('data-mfr-label'),
                    parseInt(button.getAttribute('data-mfr-count'), 10) || 0
                );
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeRename();
                closeDelete();
            }
        });

        filter();
    })();

    // ============================================================== Fournisseurs

    (function initSuppliers() {
        var card = document.querySelector('[data-suppliers]');
        if (!card) {
            return;
        }

        var list = document.getElementById('suppliers-list');
        var feedback = document.getElementById('sup-feedback');
        var search = document.getElementById('sup-search');
        var searchInfo = document.getElementById('sup-search-info');
        var panel = document.getElementById('sup-form');
        var panelButton = card.querySelector('[data-sup-panel]');

        var suppliers = {};
        var dataScript = document.getElementById('suppliers-data');
        if (dataScript) {
            try {
                JSON.parse(dataScript.textContent || '[]').forEach(function (item) {
                    suppliers[String(item.id)] = item;
                });
            } catch (error) {
                suppliers = {};
            }
        }

        function notify(kind, text) {
            if (!feedback) {
                return;
            }
            feedback.hidden = false;
            feedback.className = 'alert ' + (kind === 'ok' ? 'ok' : 'error');
            var icon = feedback.querySelector('[data-fb-icon]');
            var body = feedback.querySelector('[data-fb-text]');
            if (icon) {
                icon.textContent = kind === 'ok' ? '✅' : '⚠️';
            }
            if (body) {
                body.textContent = text;
            }
        }

        function filter() {
            var term = search ? search.value.trim().toLowerCase() : '';
            var rows = list ? list.querySelectorAll('[data-sup-row]') : [];
            var visible = 0;
            Array.prototype.forEach.call(rows, function (row) {
                var haystack = row.getAttribute('data-sup-name') || '';
                var match = !term || haystack.indexOf(term) >= 0;
                row.hidden = !match;
                if (match) {
                    visible += 1;
                }
            });
            if (searchInfo) {
                searchInfo.textContent = term ? visible + ' fournisseur(s)' : '';
            }
        }

        if (search) {
            search.addEventListener('input', debounce(filter, 120));
        }

        function refresh() {
            return requestJSON('/api/suppliers/list').then(function (data) {
                if (list) {
                    list.innerHTML = data.html;
                }
                if (data.stats) {
                    ['suppliers', 'linked', 'unattached'].forEach(function (key) {
                        var target = card.querySelector('[data-sup-stat="' + key + '"]');
                        if (target && typeof data.stats[key] !== 'undefined') {
                            target.textContent = data.stats[key];
                        }
                    });
                }
                if (data.suppliers) {
                    suppliers = {};
                    data.suppliers.forEach(function (item) {
                        suppliers[String(item.id)] = item;
                    });
                }
                filter();
            }).catch(function (error) {
                notify('error', 'Rafraîchissement impossible : ' + error.message);
            });
        }

        function action(url, values, successText) {
            return postForm(url, values).then(function (data) {
                if (!data.ok) {
                    notify('error', data.error || "L'opération n'a pas pu aboutir.");
                    return false;
                }
                notify('ok', successText);
                refresh();
                return true;
            }).catch(function (error) {
                notify('error', 'Erreur réseau : ' + error.message);
                return false;
            });
        }

        function togglePanel(force) {
            if (!panel) {
                return;
            }
            var show = typeof force === 'boolean' ? force : panel.hidden;
            panel.hidden = !show;
            if (show) {
                var input = document.getElementById('sup_name');
                if (input) {
                    input.focus();
                }
            }
        }

        if (panelButton) {
            panelButton.addEventListener('click', function () {
                togglePanel();
            });
        }
        Array.prototype.forEach.call(card.querySelectorAll('[data-sup-panel-cancel]'), function (button) {
            button.addEventListener('click', function () {
                togglePanel(false);
            });
        });

        function readForm(prefix) {
            var values = {};
            ['name', 'contact_person', 'website', 'email', 'phone', 'country', 'address', 'notes']
                .forEach(function (key) {
                    var input = document.getElementById(prefix + key);
                    values[key] = input ? input.value.trim() : '';
                });
            return values;
        }

        if (panel) {
            panel.addEventListener('submit', function (event) {
                event.preventDefault();
                var values = readForm('sup_');
                if (!values.name) {
                    notify('error', 'Le nom du fournisseur est obligatoire.');
                    return;
                }
                action('/suppliers/add', new FormData(panel), 'Fournisseur ajouté : ' + values.name)
                    .then(function (done) {
                        if (done) {
                            togglePanel(false);
                            if (panel.reset) {
                                panel.reset();
                            }
                        }
                    });
            });
        }

        // --- Modification ------------------------------------------------------

        var editModal = document.getElementById('sup-edit-modal');
        var editForm = document.getElementById('sup-edit-form');
        var editStatus = editModal ? editModal.querySelector('[data-edit-status]') : null;
        var editTitle = editModal ? editModal.querySelector('[data-edit-title]') : null;
        var editId = 0;

        var FIELD_IDS = {
            name: 'edit_name',
            contact_person: 'edit_contact',
            website: 'edit_website',
            email: 'edit_email',
            phone: 'edit_phone',
            country: 'edit_country',
            address: 'edit_address',
            notes: 'edit_notes'
        };

        // --- Logo : aperçu avant enregistrement (URL distante ou fichier local).
        var logoPreview = editModal ? editModal.querySelector('[data-logo-preview]') : null;
        var logoUrlInput = document.getElementById('edit_logo_url');
        var logoFileInput = document.getElementById('edit_logo_file');
        var logoClearInput = document.getElementById('edit_logo_clear');

        function showLogoPreview(src) {
            if (!logoPreview) {
                return;
            }
            logoPreview.innerHTML = src ? '<img src="' + escapeHTML(src) + '" alt="">' : '🏢';
        }

        if (logoFileInput) {
            logoFileInput.addEventListener('change', function () {
                var file = logoFileInput.files && logoFileInput.files[0];
                if (!file) {
                    return;
                }
                if (logoClearInput) {
                    logoClearInput.checked = false;
                }
                showLogoPreview(URL.createObjectURL(file));
            });
        }

        if (logoUrlInput) {
            logoUrlInput.addEventListener('input', function () {
                if (logoClearInput) {
                    logoClearInput.checked = false;
                }
                showLogoPreview(logoUrlInput.value.trim());
            });
        }

        function openEdit(id) {
            var item = suppliers[String(id)];
            if (!editModal || !item) {
                return;
            }
            editId = id;
            Object.keys(FIELD_IDS).forEach(function (key) {
                var input = document.getElementById(FIELD_IDS[key]);
                if (input) {
                    input.value = item[key] || '';
                }
            });
            var hiddenId = document.getElementById('edit_supplier_id');
            if (hiddenId) {
                hiddenId.value = id;
            }
            if (logoUrlInput) {
                logoUrlInput.value = item.logo_url || '';
            }
            if (logoClearInput) {
                logoClearInput.checked = false;
            }
            if (logoFileInput) {
                logoFileInput.value = '';
            }
            showLogoPreview(item.logo || '');
            if (editTitle) {
                editTitle.textContent = 'Modifier ' + item.name;
            }
            if (editStatus) {
                editStatus.className = 'loc-modal-status';
                editStatus.textContent = item.components > 0
                    ? item.components + ' composant(s) rattaché(s) à ce fournisseur.'
                    : '';
            }
            editModal.hidden = false;
            var first = document.getElementById('edit_name');
            if (first) {
                first.focus();
            }
        }

        function closeEdit() {
            if (editModal) {
                editModal.hidden = true;
            }
            editId = 0;
        }

        if (editForm) {
            editForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!editId) {
                    return;
                }
                var values = readForm('edit_');
                if (!values.name) {
                    if (editStatus) {
                        editStatus.className = 'loc-modal-status is-error';
                        editStatus.textContent = 'Le nom du fournisseur est obligatoire.';
                    }
                    return;
                }
                values.supplier_id = editId;
                if (editStatus) {
                    editStatus.className = 'loc-modal-status';
                    editStatus.textContent = 'Enregistrement…';
                }
                postForm('/suppliers/update', new FormData(editForm))
                    .then(function (data) {
                        if (!data.ok) {
                            if (editStatus) {
                                editStatus.className = 'loc-modal-status is-error';
                                editStatus.textContent = data.error || 'Échec de la modification.';
                            }
                            return;
                        }
                        closeEdit();
                        notify('ok', 'Fournisseur mis à jour : ' + values.name);
                        refresh();
                    })
                    .catch(function (error) {
                        if (editStatus) {
                            editStatus.className = 'loc-modal-status is-error';
                            editStatus.textContent = 'Erreur réseau : ' + error.message;
                        }
                    });
            });
        }

        // --- Suppression -------------------------------------------------------

        var deleteModal = document.getElementById('sup-delete-modal');
        var deleteText = deleteModal ? deleteModal.querySelector('[data-delete-text]') : null;
        var deleteStatus = deleteModal ? deleteModal.querySelector('[data-delete-status]') : null;
        var deleteButton = deleteModal ? deleteModal.querySelector('[data-delete-confirm]') : null;
        var deleteId = 0;

        function openDelete(id, label, count) {
            if (!deleteModal) {
                return;
            }
            deleteId = id;
            if (deleteText) {
                deleteText.textContent = 'Confirmer la suppression de « ' + (label || '') + ' » ?';
            }
            if (deleteStatus) {
                deleteStatus.className = 'loc-modal-status';
                deleteStatus.textContent = count > 0
                    ? count + ' composant(s) perdront la référence à ce fournisseur.'
                    : 'Aucun composant n\'est rattaché à ce fournisseur.';
            }
            deleteModal.hidden = false;
        }

        function closeDelete() {
            if (deleteModal) {
                deleteModal.hidden = true;
            }
            deleteId = 0;
        }

        if (deleteButton) {
            deleteButton.addEventListener('click', function () {
                if (!deleteId) {
                    return;
                }
                if (deleteStatus) {
                    deleteStatus.className = 'loc-modal-status';
                    deleteStatus.textContent = 'Suppression…';
                }
                postForm('/suppliers/delete', { supplier_id: deleteId })
                    .then(function (data) {
                        if (!data.ok) {
                            if (deleteStatus) {
                                deleteStatus.className = 'loc-modal-status is-error';
                                deleteStatus.textContent = data.error || 'Suppression impossible.';
                            }
                            return;
                        }
                        closeDelete();
                        notify('ok', 'Fournisseur supprimé.');
                        refresh();
                    })
                    .catch(function (error) {
                        if (deleteStatus) {
                            deleteStatus.className = 'loc-modal-status is-error';
                            deleteStatus.textContent = 'Erreur réseau : ' + error.message;
                        }
                    });
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-edit-close]'), function (button) {
            button.addEventListener('click', closeEdit);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-delete-close]'), function (button) {
            button.addEventListener('click', closeDelete);
        });

        card.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            var button = target.closest('[data-sup-edit]');
            if (button) {
                openEdit(parseInt(button.getAttribute('data-sup-edit'), 10));
                return;
            }
            button = target.closest('[data-sup-delete]');
            if (button) {
                openDelete(
                    parseInt(button.getAttribute('data-sup-delete'), 10),
                    button.getAttribute('data-sup-label'),
                    parseInt(button.getAttribute('data-sup-count'), 10) || 0
                );
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeEdit();
                closeDelete();
            }
        });

        filter();
    })();

    // ============================================================== Projets

    // --- Liste : création, modification, suppression, recherche côté client.

    (function initProjects() {
        var root = document.querySelector('[data-projects]');
        if (!root) {
            return;
        }

        var feedback = document.getElementById('prj-feedback');
        var list = document.getElementById('projects-list');
        var search = document.getElementById('prj-search');
        var searchInfo = document.getElementById('prj-search-info');
        var rows = root.querySelectorAll('[data-prj-row]');
        var timer = null;

        function notify(kind, text) {
            if (!feedback) {
                return;
            }
            var icon = feedback.querySelector('[data-fb-icon]');
            var body = feedback.querySelector('[data-fb-text]');
            if (icon) { icon.textContent = kind === 'error' ? '⚠️' : '✅'; }
            if (body) { body.textContent = text; }
            feedback.hidden = false;
            feedback.classList.toggle('error', kind === 'error');
            feedback.classList.toggle('ok', kind !== 'error');
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { feedback.hidden = true; }, 4000);
        }

        function filter() {
            rows = root.querySelectorAll('[data-prj-row]');
            var term = (search && search.value ? search.value : '').trim().toLowerCase();
            var shown = 0;
            Array.prototype.forEach.call(rows, function (row) {
                var haystack = row.getAttribute('data-prj-name') || '';
                var match = !term || haystack.indexOf(term) !== -1;
                row.hidden = !match;
                if (match) { shown += 1; }
            });
            if (searchInfo) {
                searchInfo.textContent = term ? shown + ' projet(s) sur ' + rows.length : '';
            }
        }

        function applyStats(stats) {
            if (!stats) { return; }
            Object.keys(stats).forEach(function (key) {
                var node = root.querySelector('[data-prj-stat="' + key + '"]');
                if (node) { node.textContent = stats[key]; }
            });
        }

        function refresh() {
            return requestJSON('/api/projects').then(function (data) {
                if (!data || !data.ok) {
                    throw new Error((data && data.error) || 'Rafraîchissement impossible');
                }
                if (list) { list.innerHTML = data.html; }
                applyStats(data.stats);
                filter();
            });
        }

        // --- Panneau de création.

        var panel = root.querySelector('[data-prj-panel-form]');
        var openButton = root.querySelector('[data-prj-panel="add"]');

        function togglePanel(open) {
            if (!panel) { return; }
            panel.hidden = !open;
            if (open) {
                var first = panel.querySelector('input, textarea, select');
                if (first) { first.focus(); }
            }
        }

        if (openButton) {
            openButton.addEventListener('click', function () { togglePanel(panel.hidden); });
        }
        Array.prototype.forEach.call(root.querySelectorAll('[data-prj-panel-cancel]'), function (button) {
            button.addEventListener('click', function () { togglePanel(false); });
        });

        var createForm = document.getElementById('prj-form');
        if (createForm) {
            createForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var submit = createForm.querySelector('button[type="submit"]');
                if (submit) { submit.disabled = true; }
                postForm('/projects/create', {
                    name: document.getElementById('project_name').value,
                    status: document.getElementById('project_status').value,
                    description: document.getElementById('project_description').value
                }).then(function (data) {
                    if (!data || !data.ok) {
                        notify('error', (data && data.error) || 'Création impossible.');
                        return null;
                    }
                    createForm.reset();
                    togglePanel(false);
                    notify('ok', 'Projet créé.');
                    return refresh();
                }).catch(function (error) {
                    notify('error', 'Création impossible : ' + error.message);
                }).finally(function () {
                    if (submit) { submit.disabled = false; }
                });
            });
        }

        // --- Modale de modification.

        var editModal = document.getElementById('prj-edit-modal');
        var editForm = document.getElementById('prj-edit-form');
        var editStatus = editModal ? editModal.querySelector('[data-edit-status]') : null;

        function setEditStatus(text, isError) {
            if (!editStatus) { return; }
            editStatus.textContent = text;
            editStatus.className = 'loc-modal-status' + (isError ? ' is-error' : ' is-ok');
        }

        function closeEdit() { if (editModal) { editModal.hidden = true; } }

        function openEdit(button) {
            if (!editModal || !editForm) { return; }
            document.getElementById('edit_project_id').value = button.getAttribute('data-prj-edit');
            document.getElementById('edit_prj_name').value = button.getAttribute('data-prj-pname') || '';
            document.getElementById('edit_prj_description').value = button.getAttribute('data-prj-description') || '';
            document.getElementById('edit_prj_status').value = button.getAttribute('data-prj-status') || 'En cours';
            setEditStatus('');
            editModal.hidden = false;
        }

        if (editForm) {
            editForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var submit = editForm.querySelector('button[type="submit"]');
                if (submit) { submit.disabled = true; }
                postForm('/projects/update', {
                    project_id: document.getElementById('edit_project_id').value,
                    name: document.getElementById('edit_prj_name').value,
                    status: document.getElementById('edit_prj_status').value,
                    description: document.getElementById('edit_prj_description').value
                }).then(function (data) {
                    if (!data || !data.ok) {
                        setEditStatus((data && data.error) || 'Modification impossible.', true);
                        return null;
                    }
                    closeEdit();
                    notify('ok', 'Projet mis à jour.');
                    return refresh();
                }).catch(function (error) {
                    setEditStatus('Modification impossible : ' + error.message, true);
                }).finally(function () {
                    if (submit) { submit.disabled = false; }
                });
            });
        }

        // --- Modale de suppression.

        var deleteModal = document.getElementById('prj-delete-modal');
        var deleteText = deleteModal ? deleteModal.querySelector('[data-delete-text]') : null;
        var deleteStatus = deleteModal ? deleteModal.querySelector('[data-delete-status]') : null;
        var pendingDelete = null;

        function closeDelete() {
            if (deleteModal) { deleteModal.hidden = true; }
            pendingDelete = null;
        }

        function openDelete(button) {
            if (!deleteModal) { return; }
            pendingDelete = button.getAttribute('data-prj-delete');
            var label = button.getAttribute('data-prj-label') || '';
            var count = button.getAttribute('data-prj-count') || '0';
            if (deleteText) {
                deleteText.textContent = 'Supprimer « ' + label + ' » ? Les ' + count +
                    ' composant(s) lié(s), les travaux et les documents du projet seront également supprimés.';
            }
            if (deleteStatus) { deleteStatus.textContent = ''; }
            deleteModal.hidden = false;
        }

        var deleteConfirm = deleteModal ? deleteModal.querySelector('[data-delete-confirm]') : null;
        if (deleteConfirm) {
            deleteConfirm.addEventListener('click', function () {
                if (!pendingDelete) { return; }
                deleteConfirm.disabled = true;
                postForm('/projects/delete', { project_id: pendingDelete }).then(function (data) {
                    if (!data || !data.ok) {
                        if (deleteStatus) {
                            deleteStatus.textContent = (data && data.error) || 'Suppression impossible.';
                            deleteStatus.className = 'loc-modal-status is-error';
                        }
                        return null;
                    }
                    closeDelete();
                    notify('ok', 'Projet supprimé.');
                    return refresh();
                }).catch(function (error) {
                    if (deleteStatus) {
                        deleteStatus.textContent = 'Suppression impossible : ' + error.message;
                        deleteStatus.className = 'loc-modal-status is-error';
                    }
                }).finally(function () {
                    deleteConfirm.disabled = false;
                });
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-edit-close]'), function (button) {
            button.addEventListener('click', closeEdit);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-delete-close]'), function (button) {
            button.addEventListener('click', closeDelete);
        });

        root.addEventListener('click', function (event) {
            var edit = event.target.closest('[data-prj-edit]');
            if (edit) { openEdit(edit); return; }
            var remove = event.target.closest('[data-prj-delete]');
            if (remove) { openDelete(remove); }
        });

        if (search) {
            search.addEventListener('input', debounce(filter, 120));
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeEdit(); closeDelete(); }
        });

        filter();
    })();

    // --- Fiche détaillée : onglets, composants, travaux, documents.

    (function initProjectDetail() {
        var root = document.querySelector('[data-project]');
        if (!root) {
            return;
        }

        var projectId = root.getAttribute('data-project-id');
        var body = document.getElementById('detail-body');
        var feedback = document.getElementById('detail-feedback');
        var tabs = root.querySelectorAll('[data-tab]');
        var activeTab = 'overview';
        var timer = null;

        function notify(kind, text) {
            if (!feedback) {
                return;
            }
            var icon = feedback.querySelector('[data-fb-icon]');
            var label = feedback.querySelector('[data-fb-text]');
            if (icon) { icon.textContent = kind === 'error' ? '⚠️' : '✅'; }
            if (label) { label.textContent = text; }
            feedback.hidden = false;
            feedback.classList.toggle('error', kind === 'error');
            feedback.classList.toggle('ok', kind !== 'error');
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { feedback.hidden = true; }, 4000);
        }

        function setTab(name) {
            activeTab = name;
            Array.prototype.forEach.call(tabs, function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-tab') === name);
            });
            Array.prototype.forEach.call(root.querySelectorAll('[data-pane]'), function (pane) {
                pane.hidden = pane.getAttribute('data-pane') !== name;
            });
        }

        function setText(selector, value) {
            Array.prototype.forEach.call(root.querySelectorAll(selector), function (node) {
                node.textContent = value;
            });
        }

        /// Met à jour les compteurs et l'avancement sans reconstruire le corps.
        function applyNumbers(data) {
            if (data.stats) {
                var stats = data.stats;
                setText('[data-stat="progress"]', stats.progress + ' %');
                var bar = root.querySelector('[data-progress-bar]');
                if (bar) { bar.style.width = stats.progress + '%'; }
                setText('[data-stat="components_progress"]', stats.components_progress);
                setText('[data-stat="items_progress"]', stats.items_progress);
                setText('[data-stat="components"]', stats.components);
                setText('[data-stat="picked"]', stats.total_used + '/' + stats.total_needed);
                setText('[data-stat="items"]', stats.items);
                setText('[data-stat="items_done"]', stats.items_completed + '/' + stats.items_quantity);
                setText('[data-stat="items_cost"]', stats.items_cost);
                setText('[data-stat="components_cost"]', stats.components_cost);
                setText('[data-stat="total_cost"]', stats.total_cost);
                setText('[data-stat="hours"]', stats.hours);
                setText('[data-stat="hours_total"]', stats.hours_total);
                setText('[data-stat="files"]', stats.files);
                ['components', 'items', 'files'].forEach(function (key) {
                    var node = root.querySelector('[data-tab-count="' + key + '"]');
                    if (node) { node.textContent = stats[key]; }
                });
            }
            if (data.project) {
                var title = root.querySelector('.card-head h2');
                if (title) { title.textContent = data.project.name; }
                Array.prototype.forEach.call(root.querySelectorAll('[data-detail-status]'), function (pill) {
                    pill.textContent = data.project.status;
                    pill.className = 'status-pill ' + data.project.status_key;
                });
            }
        }

        /// Réinstalle ce qui dépend du corps fraîchement remplacé.
        function bindBody() {
            attachAutocomplete(
                document.getElementById('prj_component_search'),
                document.getElementById('prj_component_suggestions'),
                '/api/components?search=',
                function (item, input) {
                    input.value = item.name || '';
                    var hidden = document.getElementById('prj_component_id');
                    if (hidden) { hidden.value = item.id || ''; }
                },
                1
            );

            var searchInput = document.getElementById('prj_component_search');
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    var hidden = document.getElementById('prj_component_id');
                    if (hidden) { hidden.value = ''; }
                });
            }

            var imageForm = document.getElementById('prj-image-form');
            var imageInput = document.getElementById('prj_image');
            if (imageForm && imageInput) {
                var imageName = imageForm.querySelector('[data-image-name]');
                var pick = imageForm.querySelector('[data-image-pick]');
                if (pick) {
                    pick.addEventListener('click', function () { imageInput.click(); });
                }
                imageInput.addEventListener('change', function () {
                    if (imageName) {
                        imageName.textContent = imageInput.files && imageInput.files.length
                            ? imageInput.files[0].name
                            : 'Aucun fichier sélectionné (10 Mo max).';
                    }
                });
                imageForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    if (!imageInput.files || !imageInput.files.length) {
                        notify('error', 'Sélectionnez une image.');
                        return;
                    }
                    sendMultipart('/projects/' + projectId + '/image', imageForm, 'Image de couverture mise à jour.');
                });
            }
        }

        function apply(data) {
            if (body && data.html) {
                body.innerHTML = data.html;
            }
            applyNumbers(data);
            bindBody();
            setTab(activeTab);
        }

        function refresh() {
            return requestJSON('/api/projects/' + projectId).then(function (data) {
                if (!data || !data.ok) {
                    throw new Error((data && data.error) || 'Rafraîchissement impossible');
                }
                apply(data);
            });
        }

        function post(url, values) {
            return postForm(url, values).then(function (data) {
                if (!data || !data.ok) {
                    notify('error', (data && data.error) || 'Action impossible.');
                    return null;
                }
                return data;
            }).catch(function (error) {
                notify('error', 'Action impossible : ' + error.message);
                return null;
            });
        }

        function sendMultipart(url, form, successText) {
            return fetch(url, { method: 'POST', body: new FormData(form) })
                .then(function (response) {
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }
                    return response.json();
                })
                .then(function (payload) {
                    if (!payload || !payload.ok) {
                        notify('error', (payload && payload.error) || 'Envoi impossible.');
                        return null;
                    }
                    apply(payload);
                    if (successText) { notify('ok', successText); }
                    return payload;
                })
                .catch(function (error) {
                    notify('error', 'Envoi impossible : ' + error.message);
                    return null;
                })
                .finally(function () {
                    if (form.reset) { form.reset(); }
                });
        }

        // --- Tuiles « nécessaire » / « prélevé » d'une ligne composant.
        //     Une même référence apparaît dans l'onglet Composants et dans la
        //     vue d'ensemble : les deux doivent rester synchronisées.

        function updateComponentRow(pcId, field, data) {
            var component = (data.components || []).filter(function (item) {
                return String(item.pc_id) === String(pcId);
            })[0];
            if (!component) { return; }

            Array.prototype.forEach.call(root.querySelectorAll('[data-pc-row="' + pcId + '"]'), function (row) {
                ['needed', 'used'].forEach(function (name) {
                    var wrapper = row.querySelector('.pstep[data-field="' + name + '"]');
                    if (!wrapper) { return; }
                    var value = component[name];
                    var valueNode = wrapper.querySelector('[data-pc-value]');
                    if (valueNode) { valueNode.textContent = value; }
                    var minus = wrapper.querySelector('[data-change="-1"]');
                    if (minus) { minus.disabled = value <= 0; }
                    var plus = wrapper.querySelector('[data-change="1"]');
                    if (plus) {
                        plus.disabled = name === 'used' && value >= component.needed;
                    }
                });

                var count = row.querySelector('[data-pc-count]');
                if (count) { count.textContent = component.used + ' / ' + component.needed; }
                var bar = row.querySelector('[data-pc-bar]');
                if (bar) { bar.style.width = component.progress + '%'; }
                var percent = row.querySelector('[data-pc-percent]');
                if (percent) { percent.textContent = component.progress + ' %'; }
            });
        }

        function updateItemRow(itemId, data) {
            var item = (data.items || []).filter(function (entry) {
                return String(entry.id) === String(itemId);
            })[0];
            if (!item) { return; }

            Array.prototype.forEach.call(root.querySelectorAll('[data-item-row="' + itemId + '"]'), function (row) {
                var count = row.querySelector('[data-item-count]');
                if (count) {
                    count.textContent = item.completed_label + ' / ' + item.quantity_label + ' ' + item.unit;
                }
                var bar = row.querySelector('[data-item-bar]');
                if (bar) { bar.style.width = item.progress + '%'; }
                var percent = row.querySelector('[data-item-percent]');
                if (percent) { percent.textContent = item.status + ' · ' + item.progress + ' %'; }

                var minus = row.querySelector('[data-item-change="-1"]');
                if (minus) { minus.disabled = !item.can_minus; }
                var plus = row.querySelector('[data-item-change="1"]');
                if (plus) { plus.disabled = !item.can_plus; }
            });
        }

        // --- Modification d'une ligne (travail / matériel / service).
        //     La modale vit hors de `#detail-body`, elle survit aux
        //     rafraîchissements du corps de la fiche.

        var itemModal = document.getElementById('item-edit-modal');

        function setField(id, value) {
            var node = document.getElementById(id);
            if (node) { node.value = value; }
        }

        function closeItemEdit() {
            if (itemModal) { itemModal.hidden = true; }
        }

        function openItemEdit(button) {
            if (!itemModal) { return; }
            var row = button.closest('[data-item-row]');
            if (!row) { return; }
            setField('item_edit_id', row.getAttribute('data-item-row'));
            setField('item_edit_kind', row.getAttribute('data-item-kind') || 'travail');
            setField('item_edit_name', row.getAttribute('data-item-name') || '');
            setField('item_edit_description', row.getAttribute('data-item-description') || '');
            setField('item_edit_quantity', row.getAttribute('data-item-quantity') || '0');
            setField('item_edit_unit', row.getAttribute('data-item-unit') || 'unité');
            setField('item_edit_unit_price', row.getAttribute('data-item-unit-price') || '0');
            itemModal.hidden = false;
            var nameInput = document.getElementById('item_edit_name');
            if (nameInput) { nameInput.focus(); }
        }

        // --- Navigation par onglets.

        root.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-tab]');
            if (tab) {
                setTab(tab.getAttribute('data-tab'));
                return;
            }

            if (event.target.closest('[data-item-edit-close]')) {
                closeItemEdit();
                return;
            }

            var editItem = event.target.closest('[data-item-edit]');
            if (editItem) {
                openItemEdit(editItem);
                return;
            }

            var step = event.target.closest('.pstep-btn');
            if (step && !step.hasAttribute('data-item-step')) {
                var wrapper = step.closest('.pstep');
                var pcId = wrapper.getAttribute('data-pc');
                var valueNode = wrapper.querySelector('[data-pc-value]');
                var current = parseInt(valueNode.textContent, 10) || 0;
                var next = Math.max(0, current + parseInt(step.getAttribute('data-change'), 10));
                if (next === current) { return; }
                post('/projects/' + projectId + '/components/update', {
                    pc_id: pcId,
                    field: wrapper.getAttribute('data-field'),
                    value: next
                }).then(function (data) {
                    if (!data) { return; }
                    applyNumbers(data);
                    updateComponentRow(pcId, wrapper.getAttribute('data-field'), data);
                });
                return;
            }

            var itemStep = event.target.closest('[data-item-step]');
            if (itemStep) {
                var itemId = itemStep.getAttribute('data-item-step');
                post('/projects/' + projectId + '/items/update', {
                    item_id: itemId,
                    change: itemStep.getAttribute('data-item-change')
                }).then(function (data) {
                    if (!data) { return; }
                    applyNumbers(data);
                    updateItemRow(itemId, data);
                });
                return;
            }

            var removeComponent = event.target.closest('[data-pc-delete]');
            if (removeComponent) {
                var label = removeComponent.getAttribute('data-pc-label') || '';
                if (!window.confirm('Retirer « ' + label + ' » de ce projet ?')) { return; }
                post('/projects/' + projectId + '/components/delete', {
                    pc_id: removeComponent.getAttribute('data-pc-delete')
                }).then(function (data) {
                    if (!data) { return; }
                    apply(data);
                    notify('ok', 'Composant retiré du projet.');
                });
                return;
            }

            var removeItem = event.target.closest('[data-item-delete]');
            if (removeItem) {
                var itemLabel = removeItem.getAttribute('data-item-label') || '';
                if (!window.confirm('Supprimer « ' + itemLabel + ' » ?')) { return; }
                post('/projects/' + projectId + '/items/delete', {
                    item_id: removeItem.getAttribute('data-item-delete')
                }).then(function (data) {
                    if (!data) { return; }
                    apply(data);
                    notify('ok', 'Ligne supprimée.');
                });
                return;
            }

            var removeFile = event.target.closest('[data-file-delete]');
            if (removeFile) {
                var fileLabel = removeFile.getAttribute('data-file-label') || '';
                if (!window.confirm('Supprimer « ' + fileLabel + ' » ?')) { return; }
                post('/projects/' + projectId + '/files/delete', {
                    file_id: removeFile.getAttribute('data-file-delete')
                }).then(function (data) {
                    if (!data) { return; }
                    apply(data);
                    notify('ok', 'Document supprimé.');
                });
            }
        });

        // --- Formulaires de la fiche.

        root.addEventListener('submit', function (event) {
            var form = event.target;

            if (form.id === 'prj-component-form') {
                event.preventDefault();
                var componentId = (document.getElementById('prj_component_id') || {}).value;
                if (!componentId) {
                    notify('error', 'Sélectionnez un composant dans la liste.');
                    return;
                }
                post('/projects/' + projectId + '/components/add', {
                    component_id: componentId,
                    quantity_needed: document.getElementById('prj_component_qty').value
                }).then(function (data) {
                    if (!data) { return; }
                    apply(data);
                    notify('ok', 'Composant lié au projet.');
                });
                return;
            }

            if (form.id === 'prj-item-form') {
                event.preventDefault();
                post('/projects/' + projectId + '/items/add', {
                    kind: document.getElementById('item_kind').value,
                    name: document.getElementById('item_name').value,
                    description: document.getElementById('item_description').value,
                    quantity: document.getElementById('item_quantity').value,
                    unit: document.getElementById('item_unit').value,
                    unit_price: document.getElementById('item_unit_price').value
                }).then(function (data) {
                    if (!data) { return; }
                    apply(data);
                    notify('ok', 'Ligne ajoutée.');
                });
                return;
            }

            if (form.id === 'prj-item-edit-form') {
                event.preventDefault();
                post('/projects/' + projectId + '/items/edit', {
                    item_id: document.getElementById('item_edit_id').value,
                    kind: document.getElementById('item_edit_kind').value,
                    name: document.getElementById('item_edit_name').value,
                    description: document.getElementById('item_edit_description').value,
                    quantity: document.getElementById('item_edit_quantity').value,
                    unit: document.getElementById('item_edit_unit').value,
                    unit_price: document.getElementById('item_edit_unit_price').value
                }).then(function (data) {
                    if (!data) { return; }
                    closeItemEdit();
                    apply(data);
                    notify('ok', 'Ligne modifiée.');
                });
                return;
            }

            if (form.id === 'prj-file-form') {
                event.preventDefault();
                var fileInput = document.getElementById('prj_file');
                if (!fileInput || !fileInput.files || !fileInput.files.length) {
                    notify('error', 'Sélectionnez un fichier.');
                    return;
                }
                sendMultipart('/projects/' + projectId + '/files/upload', form, 'Document déposé.');
            }
        });

        setTab('overview');
        bindBody();

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeItemEdit(); }
        });
    })();

    // ============================================================== API fournisseurs
    // Réglages : un formulaire par fournisseur, pastilles mises à jour sans
    // rechargement.

    (function initApiSettings() {
        var root = document.getElementById('api-chips');
        var message = document.getElementById('api-message');
        var readyLabel = document.getElementById('api-ready');
        var forms = document.querySelectorAll('[data-api-section]');
        if (!forms.length) {
            return;
        }

        function showMessage(kind, text) {
            if (!message) {
                return;
            }
            message.className = 'alert ' + kind;
            message.textContent = text;
            message.hidden = false;
        }

        function setDot(node, on) {
            if (!node) {
                return;
            }
            node.classList.toggle('on', on);
            node.classList.toggle('off', !on);
        }

        function refresh(provider) {
            var key = provider.key;
            var on = !!provider.configured;

            var form = document.querySelector('[data-api-section="' + key + '"]');
            if (form) {
                setDot(form.querySelector('[data-api-dot]'), on);
                var badge = form.querySelector('[data-api-badge]');
                if (badge) {
                    badge.textContent = on ? 'Configurée' : 'À configurer';
                    badge.className = 'badge ' + (on ? 'ok' : 'warn');
                }
            }

            var chip = root ? root.querySelector('[data-api-chip="' + key + '"]') : null;
            if (chip) {
                setDot(chip.querySelector('[data-api-dot]'), on);
                chip.classList.toggle('is-on', on);
                chip.classList.toggle('is-off', !on);
                chip.title = on ? 'Clés renseignées' : 'À configurer';
            }

            if (readyLabel && typeof provider.ready === 'number') {
                readyLabel.textContent = provider.ready;
            }
        }

        // Afficher / masquer un champ secret.
        Array.prototype.forEach.call(document.querySelectorAll('[data-secret-toggle]'), function (button) {
            button.addEventListener('click', function () {
                var input = button.parentNode.querySelector('input');
                if (!input) {
                    return;
                }
                input.type = input.type === 'password' ? 'text' : 'password';
            });
        });

        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var values = { section: form.getAttribute('data-api-section') };
                Array.prototype.forEach.call(form.querySelectorAll('input[name]'), function (input) {
                    values[input.name] = input.value;
                });
                if (message) { message.hidden = true; }

                postForm('/api-settings/save', values)
                    .then(function (data) {
                        if (!data || !data.ok) {
                            showMessage('error', (data && data.error) || 'Enregistrement impossible.');
                            return;
                        }
                        var provider = data.provider || {};
                        provider.ready = data.ready;
                        refresh(provider);
                        showMessage('ok', 'Réglages de « ' + provider.label + ' » enregistrés.');
                    })
                    .catch(function () {
                        showMessage('error', 'Erreur de communication avec le serveur.');
                    });
            });
        });

        // --- Aide « comment obtenir la clé » : pastille à côté du nom.
        var helpModal = document.getElementById('api-help-modal');
        var helpTitle = helpModal ? helpModal.querySelector('[data-api-help-title]') : null;
        var helpMark = helpModal ? helpModal.querySelector('[data-api-help-mark]') : null;
        var helpBody = helpModal ? helpModal.querySelector('[data-api-help-body]') : null;

        function closeHelp() {
            if (helpModal) {
                helpModal.hidden = true;
            }
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-api-help]'), function (button) {
            button.addEventListener('click', function () {
                var key = button.getAttribute('data-api-help');
                var source = document.getElementById('api-help-' + key);
                if (!helpModal || !helpBody || !source) {
                    return;
                }
                var label = button.getAttribute('data-api-help-label') || key;
                if (helpTitle) {
                    helpTitle.textContent = 'Obtenir une clé — ' + label;
                }
                if (helpMark) {
                    helpMark.textContent = button.querySelector('.api-help-mark')
                        ? button.querySelector('.api-help-mark').textContent
                        : '';
                }
                helpBody.innerHTML = source.innerHTML;
                helpModal.hidden = false;
            });
        });

        if (helpModal) {
            helpModal.addEventListener('click', function (event) {
                if (event.target === helpModal || event.target.closest('[data-api-help-close]')) {
                    closeHelp();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !helpModal.hidden) {
                    closeHelp();
                }
            });
        }
    })();

    // ================================================================ Sauvegardes
    // Réglage de la sauvegarde automatique et gestion des fichiers produits :
    // tout se met à jour sur place, sans rechargement.

    (function initBackup() {
        var form = document.querySelector('[data-backup-form]');
        if (!form) {
            return;
        }

        var message = document.getElementById('backup-message');
        var rows = document.getElementById('backup-rows');
        var empty = document.getElementById('bk-empty');

        function showMessage(kind, text) {
            if (!message) {
                return;
            }
            message.className = 'alert ' + kind;
            message.textContent = text;
            message.hidden = false;
        }

        function cell(tag, className, text) {
            var node = document.createElement(tag);
            if (className) {
                node.className = className;
            }
            node.textContent = text;
            return node;
        }

        function renderFiles(files) {
            if (!rows) {
                return;
            }
            rows.innerHTML = '';
            (files || []).forEach(function (file) {
                var row = document.createElement('tr');
                row.appendChild(cell('td', 'mono', file.name));
                row.appendChild(cell('td', 'nowrap', file.created));
                row.appendChild(cell('td', 'numeric', file.size_label));

                var actions = document.createElement('div');
                actions.className = 'actions';

                var link = document.createElement('a');
                link.className = 'btn btn-ghost btn-sm';
                link.href = '/backup/download/' + encodeURIComponent(file.name);
                link.title = 'Télécharger';
                link.textContent = '⤓';
                actions.appendChild(link);

                var remove = document.createElement('button');
                remove.className = 'btn btn-ghost btn-sm';
                remove.type = 'button';
                remove.title = 'Supprimer';
                remove.textContent = '✕';
                remove.setAttribute('data-backup-delete', file.name);
                actions.appendChild(remove);

                var wrapper = document.createElement('td');
                wrapper.className = 'nowrap';
                wrapper.appendChild(actions);
                row.appendChild(wrapper);

                rows.appendChild(row);
            });

            if (empty) {
                empty.hidden = (files || []).length > 0;
            }
        }

        function applyStatus(status) {
            if (!status) {
                return;
            }
            var settings = status.settings || {};

            var badge = document.getElementById('bk-badge');
            if (badge) {
                badge.textContent = settings.enabled ? 'Activée' : 'Désactivée';
                badge.className = 'badge ' + (settings.enabled ? 'ok' : 'warn');
            }

            var last = document.getElementById('bk-last');
            if (last) {
                last.textContent = status.last_run;
            }
            var next = document.getElementById('bk-next');
            if (next) {
                next.textContent = status.next_run;
            }
            var count = document.getElementById('bk-count');
            if (count) {
                count.textContent = status.count;
            }
            var total = document.getElementById('bk-total');
            if (total) {
                total.textContent = status.total_label;
            }

            renderFiles(status.files);
        }

        function values() {
            return {
                enabled: document.getElementById('bk-enabled').checked ? 'on' : '',
                interval_days: document.getElementById('bk-interval').value,
                hour: document.getElementById('bk-hour').value,
                keep: document.getElementById('bk-keep').value
            };
        }

        // Une suppression est confirmée avant d'être envoyée.
        if (rows) {
            rows.addEventListener('click', function (event) {
                var button = event.target.closest('[data-backup-delete]');
                if (!button) {
                    return;
                }
                var name = button.getAttribute('data-backup-delete');
                if (!window.confirm('Supprimer la sauvegarde « ' + name + ' » ?')) {
                    return;
                }
                postForm('/backup/delete', { name: name })
                    .then(handle)
                    .catch(function () {
                        showMessage('error', 'Erreur de communication avec le serveur.');
                    });
            });
        }

        function handle(data) {
            if (!data || !data.ok) {
                showMessage('error', (data && data.error) || 'Opération impossible.');
                return;
            }
            applyStatus(data.status);
            showMessage('ok', data.message);
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (message) {
                message.hidden = true;
            }
            postForm('/backup/save', values())
                .then(handle)
                .catch(function () {
                    showMessage('error', 'Erreur de communication avec le serveur.');
                });
        });

        var runButton = document.querySelector('[data-backup-run]');
        if (runButton) {
            runButton.addEventListener('click', function () {
                runButton.disabled = true;
                if (message) {
                    message.hidden = true;
                }
                postForm('/backup/run', {})
                    .then(handle)
                    .catch(function () {
                        showMessage('error', 'Erreur de communication avec le serveur.');
                    })
                    .then(function () {
                        runButton.disabled = false;
                    });
            });
        }
    })();

    // ============================================================ Autocomplétion partagée
    // Définie avant tout retour anticipé : la fiche projet s'en sert aussi pour
    // choisir un composant du stock global.

    // --- Autocomplétion générique (le menu s'affiche SOUS le champ de saisie).

    function attachAutocomplete(input, container, url, render, minimum) {
        if (!input || !container) {
            return;
        }
        var items = [];
        var active = -1;

        function close() {
            container.innerHTML = '';
            items = [];
            active = -1;
        }

        function paint() {
            Array.prototype.forEach.call(container.querySelectorAll('.suggestion'), function (node, index) {
                node.classList.toggle('is-active', index === active);
            });
        }

        function choose(index) {
            var item = items[index];
            if (!item) {
                return;
            }
            render(item, input);
            close();
        }

        function open(data) {
            items = data || [];
            active = items.length ? 0 : -1;
            if (!items.length) {
                close();
                return;
            }
            container.innerHTML = items.map(function (item, index) {
                return '<div class="suggestion' + (index === 0 ? ' is-active' : '') + '" data-index="' + index + '">' +
                    window.__compozitSuggestion(item) + '</div>';
            }).join('');
        }

        input.addEventListener('input', debounce(function () {
            var term = input.value.trim();
            if (term.length < minimum) {
                close();
                return;
            }
            requestJSON(url + encodeURIComponent(term)).then(open).catch(close);
        }, 220));

        input.addEventListener('keydown', function (event) {
            if (!items.length) {
                return;
            }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                active = (active + 1) % items.length;
                paint();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                active = (active - 1 + items.length) % items.length;
                paint();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                choose(active);
            } else if (event.key === 'Escape') {
                close();
            }
        });

        container.addEventListener('mousedown', function (event) {
            var node = event.target.closest('.suggestion');
            if (node) {
                event.preventDefault();
                choose(parseInt(node.getAttribute('data-index'), 10));
            }
        });

        input.addEventListener('blur', function () {
            window.setTimeout(close, 150);
        });
    }

    window.__compozitSuggestion = function (item) {
        var meta = [];
        if (item.manufacturer) { meta.push(item.manufacturer); }
        if (item.package) { meta.push(item.package); }
        // Les références du stock portent un champ `quantity` (éventuellement
        // nul) : les autres autocomplétions (boîtiers…) n'en ont pas.
        var stock = Object.prototype.hasOwnProperty.call(item, 'quantity')
            ? (typeof item.quantity === 'number' ? item.quantity : 0)
            : null;
        return (item.code ? '<span class="s-code">' + escapeHTML(item.code) + '</span>' : '') +
            '<div class="s-main">' +
                '<span class="s-name">' + escapeHTML(item.name) + '</span>' +
                (meta.length ? '<span class="s-meta">' + escapeHTML(meta.join(' · ')) + '</span>' : '') +
            '</div>' +
            (stock === null
                ? ''
                : '<span class="s-stock' + (stock > 0 ? '' : ' is-out') + '">' +
                    escapeHTML(stock) + ' en stock</span>');
    };

    function escapeHTML(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ============================================================== Formulaire composant
    // Ce qui suit ne concerne que la création / modification d'un composant.
    // L'ensemble est isolé dans sa propre fonction : son retour anticipé, quand la
    // page n'affiche pas ce formulaire, ne doit plus interrompre les blocs montés
    // après lui (bibliothèque d'images, etc.).

    (function initComponentForm() {
    var form = document.getElementById('component-form');
    if (!form) {
        return;
    }

    // --- Nom : suggestions + avertissement de doublon.

    var nameInput = document.getElementById('name');
    var duplicateBox = document.getElementById('name-duplicate-warning');
    var manufacturerSelect = document.getElementById('manufacturer');

    function checkDuplicates() {
        if (!nameInput || !duplicateBox) {
            return;
        }
        var name = nameInput.value.trim();
        if (name.length < 2) {
            duplicateBox.hidden = true;
            duplicateBox.innerHTML = '';
            return;
        }
        var manufacturer = manufacturerSelect && manufacturerSelect.value !== '__new__'
            ? manufacturerSelect.value
            : '';

        requestJSON('/api/components/duplicates?name=' + encodeURIComponent(name) +
            '&manufacturer=' + encodeURIComponent(manufacturer))
            .then(function (data) {
                var rows = (data && data.duplicates) || [];
                if (!rows.length) {
                    duplicateBox.hidden = true;
                    duplicateBox.innerHTML = '';
                    return;
                }
                duplicateBox.innerHTML = '⚠️ ' + rows.length +
                    ' référence' + (rows.length > 1 ? 's' : '') + ' portent déjà ce nom : ' +
                    rows.map(function (row) {
                        return '<strong>' + escapeHTML(row.name) + '</strong>' +
                            (row.manufacturer ? ' (' + escapeHTML(row.manufacturer) + ')' : '') +
                            (row.location ? ' — ' + escapeHTML(row.location) : '') +
                            (row.same_manufacturer ? ' <em>même fabricant</em>' : '');
                    }).join(' · ');
                duplicateBox.hidden = false;
            })
            .catch(function () {
                duplicateBox.hidden = true;
            });
    }

    attachAutocomplete(
        nameInput,
        document.getElementById('name-suggestions'),
        '/api/components?search=',
        function (item, input) {
            input.value = item.name || '';
            if (manufacturerSelect && item.manufacturer) {
                var match = Array.prototype.find.call(manufacturerSelect.options, function (option) {
                    return option.value === item.manufacturer;
                });
                if (match) {
                    manufacturerSelect.value = item.manufacturer;
                    manufacturerSelect.dispatchEvent(new Event('change'));
                }
            }
            checkDuplicates();
        },
        2
    );

    if (nameInput) {
        nameInput.addEventListener('input', debounce(checkDuplicates, 350));
        nameInput.addEventListener('change', checkDuplicates);
    }
    if (manufacturerSelect) {
        manufacturerSelect.addEventListener('change', checkDuplicates);
    }

    // --- Boîtier : suggestions depuis la table `packages`.

    attachAutocomplete(
        document.getElementById('package'),
        document.getElementById('package-suggestions'),
        '/api/packages?search=',
        function (item, input) {
            input.value = item.name || '';
            // Choix dans la liste : le boîtier amorce aussitôt l'image, et
            // fournit au passage son nombre de broches et son type de montage.
            applyPackageToImage();
            applyPackageTraits(item);
        },
        1
    );

    // --- Code de marquage : remplit la fiche d'après le code lu sur le boîtier.

    var markingInput = document.getElementById('marking_code');

    /** Renseigne nom, fabricant, boîtier et fiche technique depuis un code CMS. */
    function applyMarking(item) {
        if (!item) {
            return;
        }
        var reference = item.part_number || item.name || '';
        if (markingInput && item.code) {
            markingInput.value = item.code;
        }
        if (nameInput && reference) {
            nameInput.value = reference;
        }
        selectManufacturer(item.manufacturer);
        var packageInput = document.getElementById('package');
        if (packageInput && item.package) {
            packageInput.value = item.package;
            applyPackageToImage();
        }
        if (reference) {
            applyDatasheet('', reference, '');
        }
        // Le code de marquage ne donne ni broches ni catégorie : elles sont
        // déduites du boîtier et de la fonction annoncée par la base CMS.
        applyDetection({
            name: reference,
            package: item.package,
            function: item.function,
            description: item.description
        });
        checkDuplicates();
    }

    attachAutocomplete(
        markingInput,
        document.getElementById('marking-suggestions'),
        '/api/marking?limit=12&search=',
        function (item) {
            applyMarking(item);
        },
        1
    );

    // Reprise d'une recherche lancée depuis la page « Marquage CMS ».
    (function consumeMarkingPrefill() {
        var raw;
        try {
            raw = window.sessionStorage.getItem('compozit.marking');
            window.sessionStorage.removeItem('compozit.marking');
        } catch (error) {
            return;
        }
        if (!raw) {
            return;
        }
        try {
            applyMarking(JSON.parse(raw));
        } catch (error) {
            // Contenu illisible : le formulaire reste vierge.
        }
    })();

    // --- Fabricant / fournisseur créés à la volée.

    function bindNewToggle(selectId, hintId) {
        var select = document.getElementById(selectId);
        var hint = document.getElementById(hintId);
        if (!select || !hint) {
            return;
        }
        function sync() {
            var isNew = select.value === '__new__';
            hint.hidden = !isNew;
            var input = hint.querySelector('input');
            if (isNew && input) {
                input.focus();
            }
        }
        select.addEventListener('change', function () {
            sync();
            if (selectId === 'manufacturer') {
                checkDuplicates();
            }
        });
        sync();
    }

    bindNewToggle('manufacturer', 'new_manufacturer_hint');
    bindNewToggle('supplier_id', 'new_supplier_hint');

    // --- Catégorie → sous-catégories chargées à la demande.

    var headSelect = document.getElementById('category_head');
    var subSelect = document.getElementById('category');

    /**
     * Recharge les sous-catégories d'une catégorie. Le remplissage étant
     * asynchrone, la sous-catégorie à sélectionner est transmise pour être
     * appliquée à l'arrivée de la liste (et non juste après le `change`).
     * Les éléments sont recherchés à l'appel : la reprise d'un code de
     * marquage se produit avant l'affectation des variables ci-dessus.
     */
    function loadSubcategories(head, wanted) {
        var field = document.getElementById('category');
        var endpoint = field ? field.getAttribute('data-endpoint') : null;
        if (!field || !endpoint) {
            return Promise.resolve();
        }
        field.innerHTML = '<option value="">— Non classée —</option>';
        if (!head) {
            renderDynamicParams('');
            return Promise.resolve();
        }
        return requestJSON(endpoint + '?parent_id=' + encodeURIComponent(head))
            .then(function (options) {
                options.forEach(function (option) {
                    var node = document.createElement('option');
                    node.value = option.id;
                    node.textContent = option.name;
                    field.appendChild(node);
                });
                if (wanted !== undefined && wanted !== null && wanted !== '') {
                    Array.prototype.forEach.call(field.options, function (option) {
                        if (String(option.value) === String(wanted)) {
                            field.value = option.value;
                        }
                    });
                }
                renderDynamicParams(field.value);
            })
            .catch(function () {
                renderDynamicParams(field.value);
            });
    }

    // --- Paramètres techniques : champs construits d'après la sous-catégorie.
    // Le référentiel et les valeurs du composant sont fournis par la page.

    var paramDefinitions = window.compozitParamDefinitions || {};
    var paramValues = window.compozitParamValues || {};

    function escapeParam(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/[&<>"']/g, function (char) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
            });
    }

    function paramCompareLabel(mode) {
        if (mode === '>=') { return 'min'; }
        if (mode === '<=') { return 'max'; }
        return '=';
    }

    /** Mémorise les valeurs saisies pour les réafficher au retour en arrière. */
    function snapshotDynamicParams() {
        var fields = document.querySelectorAll('#dynamic_params_container [name^="param_"]');
        Array.prototype.forEach.call(fields, function (field) {
            paramValues[field.name.replace('param_', '')] = field.value;
        });
    }

    function renderDynamicParams(subcategoryId) {
        var group = document.getElementById('dynamic_params_group');
        var container = document.getElementById('dynamic_params_container');
        if (!group || !container) {
            return;
        }
        snapshotDynamicParams();

        var definitions = paramDefinitions[String(subcategoryId)] || [];
        if (!definitions.length) {
            group.hidden = true;
            container.innerHTML = '';
            return;
        }

        container.innerHTML = definitions.map(function (definition) {
            var key = definition.param_key;
            var meta = paramCompareLabel(definition.compare_mode)
                + (definition.unit ? ' · ' + definition.unit : '');
            var value = escapeParam(paramValues[key] || '');
            var isNumber = definition.value_type === 'number';
            var attributes = isNumber ? ' type="number" step="any" inputmode="decimal"' : ' type="text"';
            return '<div class="field dynamic-param-field">'
                + '<label class="dynamic-param-label" for="param_' + escapeParam(key) + '"><span>'
                + escapeParam(definition.label) + '</span><span class="dynamic-param-meta">'
                + escapeParam(meta) + '</span></label>'
                + '<input' + attributes + ' id="param_' + escapeParam(key)
                + '" name="param_' + escapeParam(key) + '" value="' + value + '">'
                + '</div>';
        }).join('');

        group.hidden = false;
    }

    if (headSelect && subSelect) {
        headSelect.addEventListener('change', function () {
            loadSubcategories(headSelect.value);
        });
    }

    if (subSelect) {
        subSelect.addEventListener('change', function () {
            renderDynamicParams(subSelect.value);
        });
        renderDynamicParams(subSelect.value);
    }

    // --- Détection automatique : broches, montage, catégorie, sous-catégorie.
    // Tout ce qui a été recueilli (nom, boîtier, fonction, description) sert à
    // compléter la fiche. Rien n'est inventé : un champ dont la valeur reste
    // introuvable n'est pas modifié et demeure vide.

    /// Clé de comparaison des boîtiers (« DO-214AC (SMA) » → « do214acsma »).
    function normalizePackage(value) {
        return String(value === null || value === undefined ? '' : value)
            .toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    /**
     * Motif de recherche d'un mot-clé. Les bornes sont écrites à la main :
     * celle de JavaScript ignore les lettres accentuées et « humidité » ne
     * serait jamais trouvé. Les références comportant un chiffre n'ont pas de
     * frontière à droite, pour que « 74hc » trouve « 74HC595 ».
     */
    function keywordPattern(word) {
        var escaped = word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        var start = '(^|[^0-9a-zà-ÿ])';
        return /[0-9]/.test(word)
            ? new RegExp(start + escaped)
            : new RegExp(start + escaped + '($|[^0-9a-zà-ÿ])');
    }

    /**
     * Vocabulaire des fiches (« NPN transistor », « Linear voltage regulator
     * IC », « Schottky diode »…) ramené sur l'arborescence des catégories.
     * L'ordre compte : la première règle satisfaite l'emporte, les familles
     * les plus précises sont donc placées en tête.
     */
    var CATEGORY_RULES = [
        // Règles prioritaires : sans elles, les règles génériques qui suivent
        // les capteraient (« photodiode » par « diode », « LED display » par
        // « LED », « laser diode » par « diode »…).
        { head: 15, sub: 1508, words: ['photodiode', 'phototransistor'] },
        { head: 14, sub: 1404, words: ['led display', '7-segment', 'seven segment', 'dot matrix'] },
        { head: 7, sub: 708, words: ['ic socket', 'dip socket', 'support ci'] },

        // Opto / LED
        { head: 8, sub: 806, words: ['optocoupler', 'optoisolator', 'optocoupleur', 'photocoupler', 'photo-coupler'] },
        { head: 8, sub: 801, words: ['photointerrupter', 'photo interrupter', 'photo-interrupter', 'slotted optical', 'barrière photo'] },
        { head: 8, sub: 802, words: ['laser diode', 'laser module'] },
        { head: 8, sub: 807, words: ['infrared led', 'ir led', 'infrared emitter', 'ir emitter'] },
        { head: 8, sub: 803, words: ['light emitting diode', 'light-emitting diode', 'led'] },

        // Transistors
        { head: 12, sub: 1204, words: ['npn'] },
        { head: 12, sub: 1205, words: ['pnp'] },
        { head: 12, sub: 1208, words: ['n-channel', 'n channel', 'nmos'] },
        { head: 12, sub: 1209, words: ['p-channel', 'p channel', 'pmos'] },
        { head: 12, sub: 1202, words: ['jfet'] },
        { head: 12, sub: 1201, words: ['igbt'] },
        { head: 12, sub: 1207, words: ['thyristor', 'scr'] },
        { head: 12, sub: 1206, words: ['triac'] },
        { head: 12, sub: 1299, words: ['mosfet', 'mesfet', 'transistor'] },

        // Circuits intégrés
        { head: 5, sub: 514, words: ['dc-dc', 'dc/dc', 'buck converter', 'boost converter', 'switching regulator', 'buck-boost', 'flyback'] },
        { head: 5, sub: 505, words: ['operational amplifier', 'op-amp', 'opamp', 'op amp'] },
        { head: 5, sub: 504, words: ['comparator', 'comparateur'] },
        { head: 5, sub: 509, words: ['voltage regulator', 'régulateur de tension', 'ldo', 'linear regulator'] },
        { head: 5, sub: 508, words: ['voltage reference', 'référence de tension'] },
        { head: 5, sub: 503, words: ['microcontroller', 'microcontrôleur', 'mcu', 'atmega', 'attiny', 'stm32', 'pic16', 'pic18'] },
        { head: 5, sub: 516, words: ['eeprom', 'flash memory', 'sram', 'dram', 'nvram', 'memory ic', 'ferroelectric memory'] },
        { head: 5, sub: 507, words: ['timer', '555'] },
        { head: 5, sub: 510, words: ['a/d converter', 'd/a converter', 'analog-to-digital', 'digital-to-analog', 'digital potentiometer'] },
        { head: 5, sub: 511, words: ['multiplexer', 'demultiplexer', 'analog switch'] },
        { head: 5, sub: 512, words: ['gate driver', 'mosfet driver', 'led driver', 'driver ic'] },
        { head: 5, sub: 515, words: ['audio amplifier', 'video amplifier', 'audio codec'] },
        { head: 5, sub: 517, words: ['logic gate', 'shift register', 'flip-flop', 'decade counter', 'nand', 'nor gate', 'and gate', 'or gate', 'logic ic'] },
        { head: 5, sub: 502, words: ['74hc', '74hct', '74ls', '74als', '74ahc', '74lvc', 'sn74'] },
        { head: 5, sub: 501, words: ['cd4', 'hef4', 'mc14'] },
        { head: 5, sub: 599, words: ['integrated circuit', 'ic'] },

        // Diodes
        { head: 4, sub: 406, words: ['bridge rectifier', 'pont de diode', 'pont redresseur'] },
        { head: 4, sub: 402, words: ['schottky'] },
        { head: 4, sub: 404, words: ['zener'] },
        { head: 4, sub: 401, words: ['rectifier', 'redresseur'] },
        { head: 4, sub: 403, words: ['small signal diode', 'switching diode', 'signal diode'] },
        { head: 4, sub: 499, words: ['diode'] },

        // Oscillateurs
        { head: 18, sub: 1801, words: ['crystal', 'quartz'] },
        { head: 18, sub: 1802, words: ['resonator', 'résonateur'] },
        { head: 18, sub: 1899, words: ['oscillator', 'oscillateur'] },

        // Résistances
        { head: 13, sub: 1312, words: ['potentiometer', 'potentiomètre', 'trimmer', 'variable resistor'] },
        { head: 13, sub: 1310, words: ['resistor network', 'resistor array', 'network resistor'] },
        { head: 13, sub: 1309, words: ['photoresistor', 'light dependent resistor', 'ldr'] },
        { head: 13, sub: 1315, words: ['precision resistor', 'high precision resistor'] },
        { head: 13, sub: 1399, words: ['resistor', 'resistance', 'résistance', 'shunt'] },

        // Condensateurs (après les oscillateurs : « ceramic resonator »)
        { head: 2, sub: 202, words: ['electrolytic', 'électrolytique'] },
        { head: 2, sub: 204, words: ['tantalum', 'tantale'] },
        { head: 2, sub: 203, words: ['polyester', 'polypropylene', 'film capacitor'] },
        { head: 2, sub: 201, words: ['ceramic', 'céramique', 'mlcc'] },
        { head: 2, sub: 205, words: ['variable capacitor', 'trimmer capacitor'] },
        { head: 2, sub: 299, words: ['capacitor', 'condensateur', 'cap'] },

        // Protections
        { head: 9, sub: 905, words: ['fuse holder', 'support fusible', 'porte-fusible'] },
        { head: 9, sub: 901, words: ['fuse', 'fusible'] },
        { head: 9, sub: 902, words: ['varistor', 'varistance', 'metal oxide varistor'] },
        { head: 9, sub: 903, words: ['ntc', 'ctn', 'thermistor', 'thermistance'] },
        { head: 9, sub: 904, words: ['ptc', 'ctp'] },

        // Inductances
        { head: 6, sub: 601, words: ['ferrite', 'bead'] },
        { head: 6, sub: 602, words: ['line filter', 'emi filter', 'common mode choke'] },
        { head: 6, sub: 603, words: ['inductor', 'inductance', 'choke', 'coil', 'bobine'] },

        // Interrupteurs
        { head: 10, sub: 1007, words: ['dip switch'] },
        { head: 10, sub: 1004, words: ['rotary encoder', 'encodeur rotatif'] },
        { head: 10, sub: 1002, words: ['push button', 'pushbutton', 'tact switch', 'momentary', 'bouton poussoir', 'poussoir'] },
        { head: 10, sub: 1001, words: ['keypad', 'clavier'] },
        { head: 10, sub: 1099, words: ['switch', 'interrupteur'] },

        // Connecteurs
        { head: 3, sub: 304, words: ['d-sub', 'db9', 'db25'] },
        { head: 3, sub: 302, words: ['coaxial connector', 'bnc connector'] },
        { head: 3, sub: 305, words: ['rf connector', 'sma connector'] },
        { head: 3, sub: 303, words: ['barrel jack', 'dc jack', 'power jack'] },
        { head: 3, sub: 308, words: ['usb connector', 'rj45'] },
        { head: 3, sub: 301, words: ['jack', 'xlr'] },
        { head: 3, sub: 306, words: ['pin header', 'socket strip', 'bornier', 'pcb connector'] },
        { head: 3, sub: 399, words: ['connector', 'connecteur', 'plug', 'header'] },

        // Capteurs
        { head: 15, sub: 1505, words: ['hall effect'] },
        { head: 15, sub: 1501, words: ['humidity sensor', 'humidité', 'dht11', 'dht22'] },
        { head: 15, sub: 1503, words: ['pressure sensor', 'barometric'] },
        { head: 15, sub: 1506, words: ['gas sensor', 'mq-'] },
        { head: 15, sub: 1507, words: ['accelerometer', 'accéléromètre', 'gyroscope'] },
        { head: 15, sub: 1509, words: ['proximity sensor', 'ultrasonic sensor'] },
        { head: 15, sub: 1502, words: ['temperature sensor', 'thermocouple', 'thermometer'] },
        { head: 15, sub: 1504, words: ['magnetic sensor', 'magnetometer'] },
        { head: 15, sub: 1599, words: ['sensor', 'capteur'] },

        // Écrans
        { head: 14, sub: 1401, words: ['lcd'] },
        { head: 14, sub: 1403, words: ['tft'] },
        { head: 14, sub: 1402, words: ['vfd'] },
        { head: 14, sub: 1499, words: ['display', 'écran', 'oled'] },

        // Modules
        { head: 16, sub: 1606, words: ['arduino uno'] },
        { head: 16, sub: 1607, words: ['arduino mega'] },
        { head: 16, sub: 1605, words: ['arduino'] },
        { head: 16, sub: 1608, words: ['raspberry'] },
        { head: 16, sub: 1609, words: ['esp32', 'esp8266', 'nodemcu', 'node mcu'] },
        { head: 16, sub: 1601, words: ['ethernet module', 'w5500', 'enc28j60'] },
        { head: 16, sub: 1602, words: ['wifi module', 'bluetooth module', 'hc-05', 'hc-06'] },
        { head: 16, sub: 1604, words: ['motor driver', 'l298', 'l293'] },
        { head: 16, sub: 1603, words: ['motor module', 'stepper module'] },
        { head: 16, sub: 1699, words: ['module', 'shield', 'breakout', 'development board'] },

        // Câbles
        { head: 1, sub: 107, words: ['dupont', 'jumper wire'] },
        { head: 1, sub: 102, words: ['coaxial cable', 'rg58', 'rg59'] },
        { head: 1, sub: 106, words: ['fibre optique', 'fiber optic'] },
        { head: 1, sub: 105, words: ['usb cable', 'data cable', 'signal cable'] },
        { head: 1, sub: 199, words: ['cable', 'câble', 'wire'] },

        // Mécanique
        { head: 7, sub: 709, words: ['heatsink', 'heat sink', 'dissipateur', 'radiateur'] },
        { head: 7, sub: 705, words: ['relay', 'relais'] },
        { head: 7, sub: 704, words: ['motor', 'moteur', 'servo'] },
        { head: 7, sub: 711, words: ['multimeter', 'multimètre'] },

        // Outils et consommables
        { head: 17, sub: 1701, words: ['soldering iron', 'fer à souder', 'tool', 'outil'] },
        { head: 17, sub: 1702, words: ['accessory', 'accessoire'] },
        { head: 17, sub: 1703, words: ['kit'] },
        { head: 17, sub: 1798, words: ['solder wire', 'étain'] }
    ];

    var CATEGORY_MATCHERS = CATEGORY_RULES.map(function (rule) {
        return { head: rule.head, sub: rule.sub, patterns: rule.words.map(keywordPattern) };
    });

    /// Sous-catégorie d'une résistance d'après le code de son boîtier CMS.
    var CMS_RESISTORS = { '0603': 1305, '0805': 1306, '1206': 1307, '1210': 1307 };

    function classifyByKeywords(text) {
        for (var index = 0; index < CATEGORY_MATCHERS.length; index += 1) {
            var rule = CATEGORY_MATCHERS[index];
            for (var word = 0; word < rule.patterns.length; word += 1) {
                if (rule.patterns[word].test(text)) {
                    return { head: rule.head, sub: rule.sub };
                }
            }
        }
        return null;
    }

    /** Nombre de broches : complété seulement si le champ est encore vide. */
    function fillPins(count) {
        var field = document.getElementById('pins');
        if (field && count && !String(field.value).trim()) {
            field.value = count;
        }
    }

    /** Type de montage du boîtier → champ « Montage ». */
    function fillMounting(mounting) {
        var field = document.getElementById('smd');
        if (field && mounting === 'Surface-mount') { field.value = 'Yes'; }
        if (field && mounting === 'Through-hole') { field.value = 'No'; }
    }

    /// Reprend broches et montage d'une fiche boîtier déjà obtenue.
    function applyPackageTraits(hit) {
        if (!hit) { return; }
        fillPins(hit.pin_count);
        fillMounting(hit.mounting_type);
    }

    /**
     * Interroge la fiche du boîtier : c'est elle qui porte le nombre de
     * broches et le type de montage, souvent absents des API fournisseurs.
     * Le nom exact est préféré, à défaut le premier nom qui correspond.
     */
    function lookupPackage(text, done) {
        var term = String(text === null || text === undefined ? '' : text).trim();
        if (!term) { done(null); return; }
        requestJSON('/api/packages?search=' + encodeURIComponent(term))
            .then(function (hits) {
                var wanted = normalizePackage(term);
                var exact = null;
                var loose = null;
                (hits || []).forEach(function (hit) {
                    var key = normalizePackage(hit.name);
                    if (!exact && key === wanted) { exact = hit; }
                    if (!loose && (key.indexOf(wanted) !== -1 || wanted.indexOf(key) !== -1)) { loose = hit; }
                });
                done(exact || loose);
            })
            .catch(function () { done(null); });
    }

    /** Catégorie et sous-catégorie déduites du texte de la fiche. */
    function applyCategory(info) {
        var headField = document.getElementById('category_head');
        var subField = document.getElementById('category');
        if (!headField || !subField) {
            return;
        }
        // La classification déjà choisie par l'utilisateur n'est pas écrasée.
        if (String(headField.value).trim()) {
            return;
        }

        var text = [info.name, info.function, info.description, info.package]
            .filter(Boolean).join(' ').toLowerCase();
        var match = classifyByKeywords(text);
        if (!match) {
            return;
        }

        var sub = match.sub;
        if (match.head === 13) {
            var key = normalizePackage(info.package);
            Object.keys(CMS_RESISTORS).forEach(function (code) {
                if (key.indexOf(code) !== -1) { sub = CMS_RESISTORS[code]; }
            });
        }

        headField.value = String(match.head);
        loadSubcategories(headField.value, sub);
    }

    /**
     * Complète broches, montage, catégorie et sous-catégorie à partir de tout
     * ce qui a été recueilli. L'API fournit broches et montage quand elle les
     * connaît, le boîtier prend le relais sinon ; la catégorie provient du
     * vocabulaire de la fiche.
     */
    function applyDetection(info) {
        var known = info.smd === 'Yes' || info.smd === 'No' ? info.smd : null;

        if (info.pins) {
            var pinsField = document.getElementById('pins');
            if (pinsField) { pinsField.value = info.pins; }
        }
        if (known) {
            var smdField = document.getElementById('smd');
            if (smdField) { smdField.value = known; }
        }

        lookupPackage(info.package, function (hit) {
            if (!hit) { return; }
            fillPins(hit.pin_count);
            if (!known) { fillMounting(hit.mounting_type); }
        });

        applyCategory(info);
    }

    // --- Image : bascule des groupes, bibliothèque et aperçu.

    var imageType = document.getElementById('image_type');
    var groups = {
        existing: document.getElementById('existing_images_group'),
        upload: document.getElementById('image_upload_group'),
        url: document.getElementById('image_url_group')
    };
    var libraryLoaded = false;
    var filterTouched = false;   // l'utilisateur a saisi son propre filtre
    var imagePicked = false;     // l'utilisateur a choisi son image à la main
    // Image réellement enregistrée à l'ouverture : elle distingue la création
    // (aucune image) de la modification (image existante à ne pas écraser).
    var initialImage = (function () {
        var field = document.getElementById('selected_existing_image');
        return field ? field.value.trim() : '';
    })();

    function syncImageGroups() {
        var value = imageType ? imageType.value : '';
        Object.keys(groups).forEach(function (key) {
            if (groups[key]) {
                groups[key].hidden = key !== value;
            }
        });
        if (value === 'existing') {
            loadLibrary();
        }
    }

    if (imageType) {
        imageType.addEventListener('change', function () {
            // Une autre source choisie explicitement prime sur la proposition du
            // boîtier : celle-ci ne doit plus écraser la sélection ensuite.
            if (imageType.value !== 'existing') {
                imagePicked = true;
            }
            // Retour à « Conserver l'image actuelle » : l'aperçu doit de nouveau
            // montrer l'image réellement enregistrée.
            if (imageType.value === '') {
                setPreview(initialImage ? '/' + initialImage : '');
            }
            syncImageGroups();
        });
        syncImageGroups();
    }

    var libraryContainer = document.getElementById('existing-images-container');
    var libraryFilter = document.getElementById('image-filter');
    var selectedImage = document.getElementById('selected_existing_image');
    var allImages = [];

    function renderLibrary() {
        if (!libraryContainer) {
            return;
        }
        var term = (libraryFilter ? libraryFilter.value : '').trim().toLowerCase();
        var visible = allImages.filter(function (image) {
            return !term || image.name.toLowerCase().indexOf(term) !== -1;
        });

        if (!visible.length) {
            libraryContainer.innerHTML = '<div class="no-result">Aucune image ne correspond.</div>';
            return;
        }

        var current = selectedImage ? selectedImage.value : '';
        libraryContainer.innerHTML = visible.map(function (image) {
            return '<button type="button" data-path="' + escapeHTML(image.path) + '"' +
                (image.path === current ? ' class="is-selected"' : '') +
                ' title="' + escapeHTML(image.filename) + '">' +
                '<img src="/' + escapeHTML(image.path) + '" alt="" loading="lazy"></button>';
        }).join('');
    }

    function loadLibrary() {
        if (libraryLoaded || !libraryContainer) {
            return;
        }
        libraryLoaded = true;
        requestJSON('/api/images')
            .then(function (data) {
                allImages = (data && data.images) || [];
                renderLibrary();
                syncImageToPackage();
            })
            .catch(function () {
                libraryContainer.innerHTML = '<div class="no-result">Bibliothèque indisponible.</div>';
            });
    }

    if (libraryFilter) {
        libraryFilter.addEventListener('input', function () {
            filterTouched = true;
            renderLibrary();
        });
    }

    if (libraryContainer) {
        libraryContainer.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-path]');
            if (!button) {
                return;
            }
            var path = button.getAttribute('data-path');
            imagePicked = true;
            if (selectedImage) {
                selectedImage.value = path;
            }
            libraryContainer.querySelectorAll('button').forEach(function (node) {
                node.classList.toggle('is-selected', node === button);
            });
            setPreview('/' + path);
        });
    }

    function setPreview(source) {
        var box = document.getElementById('image-preview');
        if (!box) {
            return;
        }
        if (!source) {
            box.innerHTML = '<div class="image-placeholder">Aucune image</div>';
            return;
        }
        box.innerHTML = '<img src="' + escapeHTML(source) + '" alt="Aperçu">';
    }

    // --- Boîtier → bibliothèque : le boîtier amorce la recherche d'image.
    // Exemple : taper « RADIAL » filtre la bibliothèque sur « radial » et
    // propose RADIAL.jpg par défaut, une autre vignette restant sélectionnable.

    var packageInput = document.getElementById('package');

    /// Meilleure image correspondant au boîtier : nom de fichier identique
    /// d'abord, sinon le premier nom qui le contient.
    function packageImage() {
        var term = packageInput ? packageInput.value.trim().toLowerCase() : '';
        if (!term) {
            return null;
        }
        var loose = null;
        for (var index = 0; index < allImages.length; index += 1) {
            var name = String(allImages[index].name || '').toLowerCase();
            if (name === term) {
                return allImages[index];
            }
            if (!loose && name.indexOf(term) !== -1) {
                loose = allImages[index];
            }
        }
        return loose;
    }

    function syncImageToPackage() {
        if (!packageInput || !libraryContainer) {
            return;
        }
        if (!libraryLoaded) {
            // Noms de fichiers pas encore connus : on lance la lecture du
            // dossier, la synchronisation reprendra à son retour.
            loadLibrary();
            return;
        }

        var term = packageInput.value.trim();
        if (!filterTouched && libraryFilter) {
            libraryFilter.value = term;
        }

        var match = packageImage();

        // À la création, le boîtier bascule d'office sur la bibliothèque dès
        // qu'une image homonyme existe : sans cela la proposition resterait
        // sans effet à l'enregistrement.
        if (match && !imagePicked && !initialImage && imageType && imageType.value === '') {
            imageType.value = 'existing';
            syncImageGroups();
        }

        if (!imagePicked && imageType && imageType.value === 'existing') {
            if (selectedImage) {
                selectedImage.value = match ? match.path : '';
            }
            setPreview(match ? '/' + match.path : '');
        }

        renderLibrary();
    }

    // Le boîtier reprend la main sur la bibliothèque : un filtre saisi à la
    // main n'est qu'un affinage temporaire, que le prochain boîtier remplace.
    function applyPackageToImage() {
        filterTouched = false;
        syncImageToPackage();
    }

    if (packageInput) {
        packageInput.addEventListener('input', applyPackageToImage);
    }

    // Un boîtier déjà renseigné (retour de formulaire) amorce la proposition.
    if (packageInput && packageInput.value.trim() && !initialImage) {
        syncImageToPackage();
    }

    var imageFile = document.getElementById('image');
    if (imageFile) {
        imageFile.addEventListener('change', function () {
            var file = imageFile.files && imageFile.files[0];
            if (file) {
                setPreview(URL.createObjectURL(file));
            }
        });
    }

    var imageUrl = document.getElementById('image_url');
    if (imageUrl) {
        imageUrl.addEventListener('change', function () {
            var value = imageUrl.value.trim();
            if (value) {
                setPreview(value);
            }
        });
    }

    // --- Import depuis une API fournisseur : recherche, fusion, pré-remplissage.

    var apiSource = document.getElementById('api_source');
    var apiQuery = document.getElementById('nexar_query');
    var apiButton = document.getElementById('nexar_search_btn');
    var apiStatus = document.getElementById('nexar_status');
    var apiResultsBox = document.getElementById('nexar_results');
    var apiResultsCache = [];

    function renderApiResult(part, index) {
        var meta = [];
        if (part.package) { meta.push('Boîtier : ' + part.package); }
        if (part.pins) { meta.push(part.pins + ' broches'); }
        meta.push(part.smd === 'Yes' ? 'SMD' : 'Traversant');

        var offerHtml = '<span class="api-desc">Aucune offre disponible</span>';
        var offer = part.best_offer;
        if (offer) {
            var tier = (offer.quantity && offer.quantity > 1) ? ' dès ' + parseInt(offer.quantity, 10) + ' pcs' : '';
            var tax = offer.tax === 'incl' ? ' TTC' : ' HT';
            offerHtml = '<span class="badge info">' + Number(offer.price).toFixed(4) + ' ' +
                escapeHTML(offer.currency) + tax + escapeHTML(tier) + '</span> ' +
                '<span class="api-desc">' + escapeHTML(offer.supplier_name) +
                (offer.supplier_ref ? ' · réf. ' + escapeHTML(offer.supplier_ref) : '') +
                (offer.inventory ? ' · stock ' + parseInt(offer.inventory, 10) : '') +
                '</span>';
        }

        var thumb = part.image_url
            ? '<img class="api-thumb" src="' + escapeHTML(part.image_url) + '" alt="" loading="lazy">'
            : '<div class="api-thumb"></div>';

        var badges = (part.sources || []).map(function (source) {
            return '<span class="api-source-badge">' + escapeHTML(source) + '</span>';
        }).join('');

        return '<div class="api-result">' + thumb +
            '<div class="api-result-body">' +
            '<div class="api-result-title">' + escapeHTML(part.mpn) +
            (part.manufacturer ? ' — ' + escapeHTML(part.manufacturer) : '') + badges + '</div>' +
            '<div class="api-result-meta">' + escapeHTML(meta.join(' · ')) + '</div>' +
            '<div class="api-desc">' + escapeHTML(part.description) + '</div>' +
            '<div class="api-result-meta">' + offerHtml + '</div>' +
            '</div>' +
            '<div class="api-result-action">' +
            '<button type="button" class="btn" data-api-apply="' + index + '">⬇ Pré-remplir</button>' +
            '</div></div>';
    }

    function searchApi() {
        if (!apiQuery || !apiStatus || !apiResultsBox) {
            return;
        }
        var query = apiQuery.value.trim();
        if (query.length < 2) {
            apiStatus.textContent = 'Saisissez au moins 2 caractères.';
            return;
        }

        var source = apiSource ? apiSource.value : 'all';
        if (apiButton) { apiButton.disabled = true; }
        apiStatus.textContent = 'Recherche en cours…';
        apiResultsBox.innerHTML = '';
        apiResultsCache = [];

        requestJSON('/api/suppliers/search?source=' + encodeURIComponent(source) +
            '&q=' + encodeURIComponent(query))
            .then(function (data) {
                if (!data || !data.ok) {
                    apiStatus.textContent = (data && data.message) || 'Recherche impossible.';
                    return;
                }
                apiResultsCache = data.results || [];
                apiStatus.textContent = data.message;
                apiResultsBox.innerHTML = apiResultsCache.map(renderApiResult).join('');
            })
            .catch(function () {
                apiStatus.textContent = 'Erreur de communication avec le serveur.';
            })
            .finally(function () {
                if (apiButton) { apiButton.disabled = false; }
            });
    }

    function apiLabelKey(value) {
        return String(value === null || value === undefined ? '' : value)
            .toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    function selectManufacturer(name) {
        if (!name || !manufacturerSelect) {
            return;
        }
        var target = apiLabelKey(name);
        var match = Array.prototype.find.call(manufacturerSelect.options, function (option) {
            return option.value && option.value !== '__new__' && apiLabelKey(option.value) === target;
        });
        if (match) {
            manufacturerSelect.value = match.value;
            manufacturerSelect.dispatchEvent(new Event('change'));
            return;
        }
        // Fabricant inconnu : on bascule sur « Nouveau fabricant ».
        manufacturerSelect.value = '__new__';
        manufacturerSelect.dispatchEvent(new Event('change'));
        var field = document.getElementById('new_manufacturer');
        if (field) { field.value = name.trim(); }
    }

    function selectSupplier(name) {
        var select = document.getElementById('supplier_id');
        if (!name || !select) {
            return;
        }
        var target = apiLabelKey(name);
        var match = Array.prototype.find.call(select.options, function (option) {
            if (!option.value || option.value === '__new__') {
                return false;
            }
            var label = apiLabelKey(option.textContent);
            return label === target ||
                (label !== '' && (label.indexOf(target) !== -1 || target.indexOf(label) !== -1));
        });
        if (match) { select.value = match.value; }
    }

    /**
     * Renseigne la fiche technique : celle fournie par l'API si elle existe,
     * sinon un lien de recherche alldatasheet, remplacé par le PDF officiel
     * dès qu'une autre source le fournit.
     */
    function applyDatasheet(datasheet, mpn, source) {
        var input = document.getElementById('datasheet');
        var hint = document.getElementById('datasheet_hint');
        if (!input) {
            return;
        }

        if (datasheet) {
            input.value = datasheet;
            input.dataset.fallbackFor = '';
            if (hint) { hint.textContent = ''; hint.hidden = true; }
            return;
        }

        if (!mpn) {
            input.value = '';
            input.dataset.fallbackFor = '';
            if (hint) { hint.textContent = ''; hint.hidden = true; }
            return;
        }

        var key = mpn + '|' + (source || '');
        input.dataset.fallbackFor = key;
        input.value = 'https://www.alldatasheet.com/view.jsp?Searchword=' + encodeURIComponent(mpn);
        if (hint) {
            hint.textContent = '🔎 Aucune datasheet fournie par l\'API : lien de recherche proposé pour « ' +
                mpn + ' ». Recherche d\'un PDF officiel en cours…';
            hint.hidden = false;
        }

        requestJSON('/api/suppliers/datasheet?mpn=' + encodeURIComponent(mpn) +
            '&exclude=' + encodeURIComponent(source || ''))
            .then(function (data) {
                if (input.dataset.fallbackFor !== key || !hint) {
                    return;
                }
                if (data && data.ok && data.datasheet) {
                    input.value = data.datasheet;
                    hint.textContent = '📄 Fiche technique récupérée depuis ' + data.source + '. Modifiable.';
                } else {
                    hint.textContent = '🔎 Aucune datasheet trouvée automatiquement : ' +
                        'lien de recherche proposé pour « ' + mpn + ' ». Modifiable.';
                }
            })
            .catch(function () { /* mention initiale conservée */ });
    }

    function applyApiResult(index) {
        var part = apiResultsCache[index];
        if (!part) {
            return;
        }

        if (part.mpn && nameInput) { nameInput.value = part.mpn; }
        selectManufacturer(part.manufacturer);

        var packageInput = document.getElementById('package');
        if (part.package && packageInput) { packageInput.value = part.package; }

        // Broches, montage et catégorie : donnés par l'API quand elle les
        // connaît, sinon déduits du boîtier et du texte de la fiche.
        applyDetection({
            name: part.mpn,
            package: part.package,
            pins: part.pins,
            smd: part.smd,
            description: part.description
        });

        applyDatasheet(part.datasheet, part.mpn, part.source);

        var comment = document.getElementById('comment');
        if (part.description && comment) { comment.value = part.description; }

        if (part.best_offer) {
            var offer = part.best_offer;
            var price = document.getElementById('price');
            if (offer.price && price) { price.value = Number(offer.price).toFixed(2); }
            var reference = document.getElementById('supplier_reference');
            if (offer.supplier_ref && reference) { reference.value = offer.supplier_ref; }
            selectSupplier(offer.supplier_name);
        }

        var imageTypeField = document.getElementById('image_type');
        if (part.image_url && imageTypeField) {
            imageTypeField.value = 'url';
            imageTypeField.dispatchEvent(new Event('change'));
            var imageUrlField = document.getElementById('image_url');
            if (imageUrlField) {
                imageUrlField.value = part.image_url;
                setPreview(part.image_url);
            }
        }

        checkDuplicates();
        if (nameInput) { nameInput.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }

    if (apiButton) {
        apiButton.addEventListener('click', searchApi);
    }
    if (apiQuery) {
        apiQuery.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchApi();
            }
        });
    }
    if (apiResultsBox) {
        apiResultsBox.addEventListener('click', function (event) {
            var button = event.target.closest('[data-api-apply]');
            if (button) {
                applyApiResult(parseInt(button.getAttribute('data-api-apply'), 10));
            }
        });
    }
    })();

    // =================================================================== Images

    (function initImages() {
        var card = document.querySelector('[data-images]');
        if (!card) {
            return;
        }

        var body = document.getElementById('images-body');
        var feedback = document.getElementById('img-feedback');
        var search = document.getElementById('img-search');
        var filter = document.getElementById('img-filter');
        var searchInfo = document.getElementById('img-search-info');
        var panel = document.getElementById('img-form');
        var panelButton = card.querySelector('[data-img-panel="add"]');
        var panelCancel = card.querySelector('[data-img-panel-cancel]');
        var refreshButton = card.querySelector('[data-img-refresh]');

        var renameModal = document.getElementById('img-rename-modal');
        var renameForm = document.getElementById('img-rename-form');
        var renameSource = document.getElementById('img_rename_source');
        var renameName = document.getElementById('img_rename_name');
        var renameTitle = renameModal ? renameModal.querySelector('[data-img-rename-title]') : null;
        var renameStatus = renameModal ? renameModal.querySelector('[data-img-rename-status]') : null;

        var deleteModal = document.getElementById('img-delete-modal');
        var deleteSource = document.getElementById('img_delete_source');
        var deleteTitle = deleteModal ? deleteModal.querySelector('[data-img-delete-title]') : null;
        var deleteText = deleteModal ? deleteModal.querySelector('[data-img-delete-text]') : null;
        var deleteRefs = deleteModal ? deleteModal.querySelector('[data-img-delete-refs]') : null;
        var deleteDetachLine = deleteModal ? deleteModal.querySelector('[data-img-detach-line]') : null;
        var deleteDetach = document.getElementById('img_delete_detach');
        var deleteConfirm = deleteModal ? deleteModal.querySelector('[data-img-delete-confirm]') : null;
        var deleteStatus = deleteModal ? deleteModal.querySelector('[data-img-delete-status]') : null;

        function notify(kind, text) {
            if (!feedback) {
                return;
            }
            feedback.hidden = false;
            feedback.className = 'alert ' + (kind === 'ok' ? 'ok' : 'error');
            var icon = feedback.querySelector('[data-fb-icon]');
            var label = feedback.querySelector('[data-fb-text]');
            if (icon) { icon.textContent = kind === 'ok' ? '✅' : '⚠️'; }
            if (label) { label.textContent = text; }
        }

        function setStatus(node, kind, text) {
            if (!node) {
                return;
            }
            node.className = 'loc-modal-status' + (kind ? ' is-' + kind : '');
            node.textContent = text || '';
        }

        // --- Filtres : recherche par nom et par état.

        function filterTiles() {
            var term = search ? search.value.trim().toLowerCase() : '';
            var mode = filter ? filter.value : 'all';
            var tiles = body ? body.querySelectorAll('[data-img-row]') : [];
            var visible = 0;
            Array.prototype.forEach.call(tiles, function (tile) {
                var state = tile.getAttribute('data-img-state') || '';
                var haystack = tile.getAttribute('data-img-search') || '';
                var match = (!term || haystack.indexOf(term) >= 0) && (mode === 'all' || state === mode);
                tile.hidden = !match;
                if (match) {
                    visible += 1;
                }
            });
            if (searchInfo) {
                searchInfo.textContent = tiles.length ? visible + ' / ' + tiles.length + ' image(s)' : '';
            }
        }

        if (search) { search.addEventListener('input', debounce(filterTiles, 120)); }
        if (filter) { filter.addEventListener('change', filterTiles); }

        // --- Rafraîchissement de la bibliothèque sans recharger la page.

        function applyStats(stats) {
            if (!stats) {
                return;
            }
            ['files', 'size_label', 'broken', 'unused', 'missing'].forEach(function (key) {
                var target = document.querySelector('[data-img-stat="' + key + '"]');
                if (target && typeof stats[key] !== 'undefined') {
                    target.textContent = stats[key];
                }
            });
        }

        function refresh() {
            return requestJSON('/api/images/library').then(function (data) {
                if (!data.ok) {
                    notify('error', data.error || 'Rafraîchissement impossible.');
                    return;
                }
                if (body) { body.innerHTML = data.html; }
                applyStats(data.stats);
                filterTiles();
            }).catch(function (error) {
                notify('error', 'Rafraîchissement impossible : ' + error.message);
            });
        }

        if (refreshButton) {
            refreshButton.addEventListener('click', function () { refresh(); });
        }

        // --- Ajout d'images.

        function togglePanel(force) {
            if (!panel) {
                return;
            }
            var open = typeof force === 'boolean' ? force : panel.hidden;
            panel.hidden = !open;
            if (open) {
                var input = panel.querySelector('input[type="file"]');
                if (input) { input.focus(); }
            }
        }

        if (panelButton) {
            panelButton.addEventListener('click', function () { togglePanel(); });
        }
        if (panelCancel) {
            panelCancel.addEventListener('click', function () { togglePanel(false); });
        }

        if (panel) {
            panel.addEventListener('submit', function (event) {
                event.preventDefault();
                var fileInput = panel.querySelector('input[type="file"]');
                var nameField = document.getElementById('img-name');
                var files = fileInput && fileInput.files
                    ? Array.prototype.slice.call(fileInput.files)
                    : [];
                if (!files.length) {
                    notify('error', 'Choisissez au moins un fichier image.');
                    return;
                }

                var wanted = nameField ? nameField.value.trim() : '';
                var submit = panel.querySelector('button[type="submit"]');
                var added = 0;
                var failures = [];
                var chain = Promise.resolve();

                if (submit) { submit.disabled = true; }

                files.forEach(function (file) {
                    chain = chain.then(function () {
                        var form = new FormData();
                        form.append('image', file);
                        // Le nom saisi ne s'applique qu'à un dépôt unique :
                        // plusieurs fichiers ne peuvent pas porter le même nom.
                        if (wanted && files.length === 1) {
                            form.append('name', wanted);
                        }
                        return postForm('/images/upload', form).then(function (data) {
                            if (data.ok) {
                                added += 1;
                            } else {
                                failures.push(file.name + ' : ' + (data.error || 'refusé'));
                            }
                        });
                    });
                });

                chain.then(function () {
                    if (submit) { submit.disabled = false; }
                    if (failures.length) {
                        notify('error', failures.join(' · '));
                    } else {
                        notify('ok', added + ' image(s) ajoutée(s) à la bibliothèque.');
                    }
                    if (added) {
                        if (fileInput) { fileInput.value = ''; }
                        if (nameField) { nameField.value = ''; }
                        togglePanel(false);
                        refresh();
                    }
                });
            });
        }

        // --- Renommage.

        function openRename(fileName, stem) {
            if (!renameModal) {
                return;
            }
            if (renameSource) { renameSource.value = fileName; }
            if (renameName) { renameName.value = stem || ''; }
            if (renameTitle) { renameTitle.textContent = 'Renommer « ' + fileName + ' »'; }
            setStatus(renameStatus, '', '');
            renameModal.hidden = false;
            if (renameName) {
                renameName.focus();
                renameName.select();
            }
        }

        function closeRename() {
            if (renameModal) { renameModal.hidden = true; }
        }

        if (renameForm) {
            renameForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var fileName = renameSource ? renameSource.value : '';
                var name = renameName ? renameName.value.trim() : '';
                if (!fileName || !name) {
                    setStatus(renameStatus, 'error', 'Le nouveau nom est obligatoire.');
                    return;
                }

                var submit = renameForm.querySelector('button[type="submit"]');
                if (submit) { submit.disabled = true; }
                setStatus(renameStatus, '', 'Renommage en cours…');

                postForm('/images/rename', { filename: fileName, name: name }).then(function (data) {
                    if (submit) { submit.disabled = false; }
                    if (!data.ok) {
                        setStatus(renameStatus, 'error', data.error || 'Renommage impossible.');
                        return;
                    }
                    closeRename();
                    notify('ok', '« ' + fileName + ' » renommée en « ' + data.filename + ' »' +
                        (data.references ? ' — ' + data.references + ' fiche(s) mise(s) à jour.' : '.'));
                    refresh();
                }).catch(function (error) {
                    if (submit) { submit.disabled = false; }
                    setStatus(renameStatus, 'error', 'Erreur réseau : ' + error.message);
                });
            });
        }

        // --- Suppression.

        function openDelete(fileName) {
            if (!deleteModal) {
                return;
            }
            if (deleteSource) { deleteSource.value = fileName; }
            if (deleteTitle) { deleteTitle.textContent = 'Supprimer « ' + fileName + ' »'; }
            if (deleteText) {
                deleteText.textContent = "Le fichier sera retiré du dossier img/. Cette action est définitive.";
            }
            if (deleteRefs) {
                deleteRefs.hidden = true;
                deleteRefs.innerHTML = '';
            }
            if (deleteDetachLine) { deleteDetachLine.hidden = true; }
            if (deleteDetach) { deleteDetach.checked = false; }
            setStatus(deleteStatus, '', '');
            deleteModal.hidden = false;
            loadReferences(fileName);
        }

        function closeDelete() {
            if (deleteModal) { deleteModal.hidden = true; }
        }

        // Détail des fiches qui utilisent l'image : affiché avant confirmation.
        function loadReferences(fileName) {
            requestJSON('/api/images/references?filename=' + encodeURIComponent(fileName))
                .then(function (data) {
                    if (!data.ok || !data.total) {
                        return;
                    }
                    if (deleteText) {
                        deleteText.textContent = 'Cette image est encore utilisée. Le fichier sera ' +
                            'retiré du dossier img/ et les fiches concernées seront détachées.';
                    }
                    if (deleteRefs) {
                        deleteRefs.hidden = false;
                        deleteRefs.innerHTML = '<strong>Utilisée par</strong><ul>' +
                            data.references.map(function (item) {
                                return '<li><span class="mono">' + escapeHTML(item.table + '.' + item.column) +
                                    '</span> — ' + item.count + ' fiche(s)</li>';
                            }).join('') + '</ul>';
                    }
                    if (deleteDetachLine) { deleteDetachLine.hidden = false; }
                    if (deleteDetach) { deleteDetach.checked = true; }
                })
                .catch(function () {
                    // Le détail est indicatif : le serveur reste seul juge.
                });
        }

        if (deleteConfirm) {
            deleteConfirm.addEventListener('click', function () {
                var fileName = deleteSource ? deleteSource.value : '';
                if (!fileName) {
                    return;
                }
                var values = { filename: fileName };
                if (deleteDetach && deleteDetach.checked) {
                    values.detach = '1';
                }

                deleteConfirm.disabled = true;
                setStatus(deleteStatus, '', 'Suppression en cours…');

                postForm('/images/delete', values).then(function (data) {
                    deleteConfirm.disabled = false;
                    if (!data.ok) {
                        setStatus(deleteStatus, 'error', data.error || 'Suppression impossible.');
                        if (data.total) { loadReferences(fileName); }
                        return;
                    }
                    closeDelete();
                    notify('ok', '« ' + fileName + ' » supprimée' +
                        (data.detached ? ' — ' + data.detached + ' fiche(s) détachée(s).' : '.'));
                    refresh();
                }).catch(function (error) {
                    deleteConfirm.disabled = false;
                    setStatus(deleteStatus, 'error', 'Erreur réseau : ' + error.message);
                });
            });
        }

        // Les vignettes sont remplacées à chaque rafraîchissement : les actions
        // sont captées sur le conteneur.
        if (body) {
            body.addEventListener('click', function (event) {
                var renameButton = event.target.closest('[data-img-rename]');
                if (renameButton) {
                    openRename(
                        renameButton.getAttribute('data-img-rename'),
                        renameButton.getAttribute('data-img-name')
                    );
                    return;
                }
                var deleteButton = event.target.closest('[data-img-delete]');
                if (deleteButton) {
                    openDelete(deleteButton.getAttribute('data-img-delete'));
                }
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll('[data-img-rename-close]'), function (button) {
            button.addEventListener('click', closeRename);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-img-delete-close]'), function (button) {
            button.addEventListener('click', closeDelete);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeRename();
                closeDelete();
            }
        });

        filterTiles();
    })();

    // ============================================================== Page « Marquage CMS »
    // Recherche par code imprimé sur le boîtier, sans rechargement de page.

    (function initMarking() {
        var query = document.getElementById('marking_query');
        var body = document.getElementById('marking_results');
        if (!query || !body) {
            return;
        }

        var status = document.getElementById('marking_status');
        var limitSelect = document.getElementById('marking_limit');
        var cache = [];

        // Noms de fichiers de `img/` : sert à illustrer le boîtier quand la
        // bibliothèque contient une image homonyme (cf. formulaire composant).
        var library = [];
        var libraryLoaded = false;

        function cell(value) {
            return escapeHTML(value === null || value === undefined ? '' : value);
        }

        function loadLibrary() {
            if (libraryLoaded) {
                return;
            }
            libraryLoaded = true;
            requestJSON('/api/images')
                .then(function (data) {
                    library = (data && data.images) || [];
                    if (cache.length) {
                        body.innerHTML = cache.map(renderRow).join('');
                    }
                })
                .catch(function () {
                    library = [];
                });
        }

        /// Image correspondant au boîtier : nom de fichier identique d'abord,
        /// sinon le premier nom qui le contient.
        function packageImage(value) {
            var term = String(value || '').trim().toLowerCase();
            if (!term) {
                return null;
            }
            var loose = null;
            for (var index = 0; index < library.length; index += 1) {
                var name = String(library[index].name || '').toLowerCase();
                if (name === term) {
                    return library[index];
                }
                if (!loose && name.indexOf(term) !== -1) {
                    loose = library[index];
                }
            }
            return loose;
        }

        function packageCell(value) {
            if (!value) {
                return '<span class="cell-sub">—</span>';
            }
            var image = packageImage(value);
            return '<div class="package-cell">' +
                (image ? '<img class="package-thumb" src="/' + escapeHTML(image.path) +
                    '" alt="" loading="lazy" title="' + escapeHTML(image.filename) + '">' : '') +
                '<span>' + cell(value) + '</span>' +
                '</div>';
        }

        function setStatus(text) {
            if (status) {
                status.textContent = text;
            }
        }

        function emptyRow(text, icon) {
            return '<tr><td colspan="6"><div class="empty">' +
                '<span class="empty-icon">' + icon + '</span>' + cell(text) + '</div></td></tr>';
        }

        function renderRow(item, index) {
            return '<tr>' +
                '<td class="nowrap"><span class="badge info mono">' + cell(item.code) + '</span></td>' +
                '<td><span class="cell-name">' + cell(item.part_number || item.name) + '</span></td>' +
                '<td>' + (item.manufacturer ? cell(item.manufacturer) : '<span class="cell-sub">—</span>') + '</td>' +
                '<td class="nowrap">' + packageCell(item.package) + '</td>' +
                '<td>' + (item.function ? cell(item.function) : '<span class="cell-sub">—</span>') +
                    (item.description ? '<div class="cell-sub">' + cell(item.description) + '</div>' : '') + '</td>' +
                // Le flex est porté par un <div> interne : appliqué au <td>, il
                // ferait perdre à la cellule sa mise en tableau (elle ne
                // s'étirerait plus à la hauteur de la ligne).
                '<td class="nowrap"><div class="actions">' +
                    '<button class="btn btn-ghost btn-sm" type="button" data-marking-datasheet="' + index +
                        '" title="Chercher la fiche technique">📄</button>' +
                    '<button class="btn btn-ghost btn-sm" type="button" data-marking-create="' + index +
                        '" title="Créer un composant avec cette référence">➕</button>' +
                '</div></td></tr>';
        }

        function reset() {
            cache = [];
            body.innerHTML = emptyRow(
                'Saisissez le code lu sur le composant pour retrouver sa référence.', '🔎');
            setStatus('Saisissez un code : la recherche démarre dès le premier caractère.');
        }

        function search() {
            var term = query.value.trim();
            if (!term) {
                reset();
                return;
            }
            var limit = limitSelect ? limitSelect.value : '100';
            setStatus('Recherche en cours…');

            requestJSON('/api/marking?limit=' + encodeURIComponent(limit) +
                '&search=' + encodeURIComponent(term))
                .then(function (data) {
                    if (!data || !data.ok) {
                        cache = [];
                        setStatus((data && data.message) || 'Recherche impossible.');
                        body.innerHTML = emptyRow('Aucune correspondance.', '🔍');
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

        /** Ouvre le formulaire de composant pré-rempli par la correspondance. */
        function createComponent(item) {
            try {
                window.sessionStorage.setItem('compozit.marking', JSON.stringify({
                    code: item.code,
                    part_number: item.part_number || item.name,
                    manufacturer: item.manufacturer || '',
                    package: item.package || ''
                }));
            } catch (error) {
                // Stockage indisponible : le formulaire s'ouvrira vierge.
            }
            window.location.href = '/components/new';
        }

        /** Cherche la fiche technique officielle de la référence et l'ouvre. */
        function openDatasheet(item, button) {
            var reference = item.part_number || item.name;
            if (!reference) {
                return;
            }
            var label = button.textContent;
            button.disabled = true;
            button.textContent = '…';

            requestJSON('/api/suppliers/datasheet?mpn=' + encodeURIComponent(reference))
                .then(function (data) {
                    if (data && data.ok && data.datasheet) {
                        var link = document.createElement('a');
                        link.href = data.datasheet;
                        link.target = '_blank';
                        link.rel = 'noreferrer';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
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

        // La bibliothèque d'images arrive en parallèle de la première saisie.
        loadLibrary();

        query.addEventListener('input', debounce(search, 250));
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
        if (button) {
            button.addEventListener('click', search);
        }
        var resetButton = document.getElementById('marking_reset_btn');
        if (resetButton) {
            resetButton.addEventListener('click', function () {
                query.value = '';
                reset();
                query.focus();
            });
        }
        if (limitSelect) {
            limitSelect.addEventListener('change', search);
        }

        body.addEventListener('click', function (event) {
            var node = event.target.closest('[data-marking-create]');
            if (node) {
                var selected = cache[parseInt(node.getAttribute('data-marking-create'), 10)];
                if (selected) {
                    createComponent(selected);
                }
                return;
            }
            var sheet = event.target.closest('[data-marking-datasheet]');
            if (sheet) {
                var part = cache[parseInt(sheet.getAttribute('data-marking-datasheet'), 10)];
                if (part) {
                    openDatasheet(part, sheet);
                }
            }
        });
    })();

    // ------------------------------------------ Recherche des caractéristiques
    // Scan de toute la base : appels AJAX successifs, barre de progression et
    // compte rendu final, sans rechargement de page.
    (function () {
        var button = document.getElementById('enrich-all-btn');
        if (!button) {
            return;
        }
        var progress = document.getElementById('enrich-all-progress');
        var fill = document.getElementById('enrich-all-fill');
        var text = document.getElementById('enrich-all-text');
        var result = document.getElementById('enrich-all-result');

        function call(reset) {
            var body = new URLSearchParams();
            if (reset) {
                body.set('reset', '1');
            }
            return fetch('/api/enrich-all', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            });
        }

        function step(reset) {
            call(reset).then(function (data) {
                if (!data || !data.ok) {
                    button.disabled = false;
                    text.textContent = 'Erreur : ' + ((data && data.error) ? data.error : 'inconnue');
                    return;
                }

                var total = data.total || 0;
                var processed = data.processed || 0;
                var percent = total > 0 ? Math.floor(processed * 100 / total) : 100;
                fill.style.width = percent + '%';
                text.textContent = processed + ' / ' + total + ' composants analysés (' + percent + '%)'
                    + ' — ' + (data.paramsAdded || 0) + ' paramètre(s) ajouté(s)';

                if (data.done) {
                    button.disabled = false;
                    button.textContent = '🔎 Relancer sur toute la base';
                    result.hidden = false;
                    result.textContent = '✅ Analyse terminée : ' + (data.enriched || 0)
                        + ' composant(s) enrichi(s), ' + (data.paramsAdded || 0)
                        + ' paramètre(s) ajouté(s).';
                    return;
                }

                step(false);
            }).catch(function (error) {
                button.disabled = false;
                text.textContent = 'Erreur réseau : ' + error.message;
            });
        }

        button.addEventListener('click', function () {
            if (button.disabled) {
                return;
            }
            if (!window.confirm('Rechercher les caractéristiques manquantes sur toute la base, sans écraser les valeurs existantes ?')) {
                return;
            }
            button.disabled = true;
            progress.hidden = false;
            result.hidden = true;
            fill.style.width = '0%';
            text.textContent = 'Initialisation…';
            step(true);
        });
    })();
})();
