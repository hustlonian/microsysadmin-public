(function () {
    const base = document.querySelector('meta[name="base-url"]')?.content || '/microsysadmin';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const refreshInterval = Number(document.querySelector('meta[name="refresh-interval"]')?.content || 5000);
    let sandboxTerminal = null;
    let sandboxTerminalFit = null;
    let sandboxTerminalSession = '';
    let sandboxTerminalOffset = 0;
    let sandboxTerminalStarting = false;
    let sandboxTerminalPollTimer = null;
    let sandboxTerminalInputBuffer = '';
    let sandboxTerminalInputTimer = null;
    let sandboxTerminalResizeBound = false;

    function endpoint(path) {
        return base + '/' + path.replace(/^\/+/, '');
    }

    function toast(title, icon = 'success') {
        if (window.Swal) {
            Swal.fire({ toast: true, position: 'top-end', icon, title, timer: 2400, showConfirmButton: false });
        } else {
            console.log(title);
        }
    }

    async function api(url, options = {}) {
        const headers = options.headers || {};
        headers['X-CSRF-Token'] = csrf;
        if (!(options.body instanceof FormData)) {
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        const response = await fetch(url, { credentials: 'same-origin', ...options, headers });
        const json = await response.json();
        if (!json.ok) {
            throw new Error(json.error || 'Request failed');
        }
        return json.data;
    }

    function setOutput(selector, data) {
        const node = document.querySelector(selector);
        if (node) {
            const serialized = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
            const text = serialized == null ? '' : String(serialized);
            node.textContent = text;
            node.hidden = text.trim() === '';
        }
    }

    function get(obj, key) {
        return key.split('.').reduce((value, part) => value && value[part] !== undefined ? value[part] : '', obj);
    }

    function initTables() {
        if (window.jQuery && jQuery.fn.DataTable) {
            document.querySelectorAll('.data-table').forEach((table) => {
                if (!jQuery.fn.DataTable.isDataTable(table)) {
                    const isMysqlCrud = table.classList.contains('mysql-crud-table');
                    const hasActions = table.dataset.actions === '1';
                    jQuery(table).DataTable({
                        pageLength: 25,
                        order: [],
                        autoWidth: false,
                        responsive: !isMysqlCrud,
                        scrollX: isMysqlCrud,
                        columnDefs: isMysqlCrud && hasActions ? [
                            { targets: 0, orderable: false, searchable: false }
                        ] : [],
                        language: {
                            emptyTable: 'No rows found.',
                            info: 'Showing _START_-_END_ of _TOTAL_',
                            lengthMenu: '_MENU_ rows',
                            search: 'Search',
                            searchPlaceholder: 'Filter rows'
                        }
                    });
                }
            });
        }
    }

    function initDoctorLibrary() {
        const table = document.getElementById('doctorInventoryTable');
        if (!table) return;
        document.querySelectorAll('[data-doctor-library-filter]').forEach((filter) => {
            filter.addEventListener('change', () => {
                const column = Number(filter.dataset.doctorLibraryFilter);
                if (window.jQuery && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable(table)) {
                    jQuery(table).DataTable().column(column).search(filter.value ? `^${jQuery.fn.dataTable.util.escapeRegex(filter.value)}$` : '', true, false).draw();
                    return;
                }
                table.querySelectorAll('tbody tr').forEach((row) => {
                    row.hidden = filter.value !== '' && row.cells[column]?.textContent.trim() !== filter.value;
                });
            });
        });
    }

    function initPageRefresh() {
        document.querySelectorAll('[data-page-refresh]').forEach((button) => {
            button.addEventListener('click', () => {
                button.classList.add('is-refreshing');
                window.location.reload();
            });
        });
    }

    function showModal(modalNode) {
        if (window.bootstrap?.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalNode).show();
            return;
        }

        modalNode.style.display = 'block';
        modalNode.removeAttribute('aria-hidden');
        modalNode.setAttribute('aria-modal', 'true');
        modalNode.classList.add('show');
        document.body.classList.add('modal-open');

        let backdrop = document.querySelector('.modal-backdrop[data-fallback-modal]');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.dataset.fallbackModal = '1';
            backdrop.addEventListener('click', () => hideModal(modalNode));
            document.body.appendChild(backdrop);
        }
    }

    function hideModal(modalNode) {
        if (window.bootstrap?.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalNode).hide();
            return;
        }

        modalNode.style.display = 'none';
        modalNode.setAttribute('aria-hidden', 'true');
        modalNode.removeAttribute('aria-modal');
        modalNode.classList.remove('show');
        document.body.classList.remove('modal-open');
        document.querySelectorAll('.modal-backdrop[data-fallback-modal]').forEach((node) => node.remove());
    }

    let resourceChart;
    let dashboardRefreshInFlight = false;
    const fallbackHistory = [];
    async function refreshDashboard() {
        if (!document.querySelector('[data-dashboard]') || document.hidden || dashboardRefreshInFlight) {
            return;
        }
        dashboardRefreshInFlight = true;
        try {
            const data = await api(endpoint('api/metrics.php'));
            document.querySelectorAll('[data-metric]').forEach((node) => {
                const key = node.dataset.metric;
                let value = get(data, key);
                if (value === '' && key !== 'load' && key !== 'temperature') {
                    return;
                }
                if (key === 'ram.percent' || key === 'swap.percent' || key === 'disk.percent' || key === 'cpu') {
                    value = value + '%';
                }
                if (key === 'load') {
                    value = data.load.map((n) => Number(n).toFixed(2)).join(' ');
                }
                if (key === 'temperature') {
                    value = data.temperature ? data.temperature.celsius + ' C' : 'Unavailable';
                }
                node.textContent = value;
            });

            const tbody = document.querySelector('[data-process-rows]');
            if (tbody) {
                tbody.innerHTML = data.top_processes.map((p) => `<tr><td>${escapeHtml(p.pid)}</td><td>${escapeHtml(p.cpu)}%</td><td>${escapeHtml(p.memory)}%</td><td>${escapeHtml(p.owner)}</td><td><code>${escapeHtml(p.command)}</code></td></tr>`).join('');
            }

            drawResourceChart(data);
        } finally {
            dashboardRefreshInFlight = false;
        }
    }

    async function drawAnalyticsChart() {
        const canvas = document.getElementById('analyticsChart');
        if (!canvas) {
            return;
        }
        const rows = await api(endpoint('api/analytics.php'));
        const labels = rows.map((r) => r.created_at.substring(11, 19));
        const dataSets = [
            { label: 'CPU %', data: rows.map((r) => Number(r.cpu_percent)), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)' },
            { label: 'RAM %', data: rows.map((r) => Number(r.ram_percent)), borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.12)' },
            { label: 'Storage %', data: rows.map((r) => Number(r.disk_percent)), borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.12)' },
        ];
        if (!window.Chart) {
            drawFallbackLines(canvas, labels, dataSets);
            return;
        }
        new Chart(canvas, {
            type: 'line',
            data: { labels, datasets: dataSets },
            options: { responsive: true, tension: .25, scales: { y: { min: 0, max: 100 } } }
        });
    }

    function drawResourceChart(data) {
        const canvas = document.getElementById('resourceChart');
        if (!canvas) {
            return;
        }
        const point = {
            label: new Date().toLocaleTimeString(),
            cpu: Number(data.cpu),
            ram: Number(data.ram.percent),
            disk: Number(data.disk.percent)
        };

        if (!window.Chart) {
            fallbackHistory.push(point);
            if (fallbackHistory.length > 30) fallbackHistory.shift();
            drawFallbackLines(canvas, fallbackHistory.map((p) => p.label), [
                { label: 'CPU %', data: fallbackHistory.map((p) => p.cpu), borderColor: '#2563eb' },
                { label: 'RAM %', data: fallbackHistory.map((p) => p.ram), borderColor: '#16a34a' },
                { label: 'Storage %', data: fallbackHistory.map((p) => p.disk), borderColor: '#dc2626' }
            ]);
            return;
        }

        if (!resourceChart) {
            resourceChart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: [point.label],
                    datasets: [
                        { label: 'CPU %', data: [point.cpu], borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)' },
                        { label: 'RAM %', data: [point.ram], borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.12)' },
                        { label: 'Storage %', data: [point.disk], borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.12)' }
                    ]
                },
                options: { responsive: true, tension: .25, scales: { y: { min: 0, max: 100 } } }
            });
            return;
        }

        resourceChart.data.labels.push(point.label);
        resourceChart.data.datasets[0].data.push(point.cpu);
        resourceChart.data.datasets[1].data.push(point.ram);
        resourceChart.data.datasets[2].data.push(point.disk);
        if (resourceChart.data.labels.length > 30) {
            resourceChart.data.labels.shift();
            resourceChart.data.datasets.forEach((set) => set.data.shift());
        }
        resourceChart.update();
    }

    function drawFallbackLines(canvas, labels, datasets) {
        const rect = canvas.getBoundingClientRect();
        const scale = window.devicePixelRatio || 1;
        const width = Math.max(320, Math.floor(rect.width || canvas.parentElement.clientWidth || 640));
        const height = Math.max(160, Math.floor(Number(canvas.getAttribute('height')) || 180));
        canvas.width = width * scale;
        canvas.height = height * scale;
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';
        const ctx = canvas.getContext('2d');
        ctx.scale(scale, scale);
        ctx.clearRect(0, 0, width, height);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, width, height);
        ctx.strokeStyle = '#d7dee8';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = 20 + ((height - 42) / 4) * i;
            ctx.beginPath();
            ctx.moveTo(42, y);
            ctx.lineTo(width - 16, y);
            ctx.stroke();
            ctx.fillStyle = '#64748b';
            ctx.font = '12px sans-serif';
            ctx.fillText(String(100 - i * 25), 8, y + 4);
        }
        datasets.forEach((set, index) => {
            const values = set.data.length ? set.data : [0];
            ctx.strokeStyle = set.borderColor;
            ctx.lineWidth = 2;
            ctx.beginPath();
            values.forEach((value, i) => {
                const x = 42 + ((width - 64) / Math.max(1, values.length - 1)) * i;
                const y = 20 + (height - 42) * (1 - Math.max(0, Math.min(100, value)) / 100);
                if (i === 0) ctx.moveTo(x, y);
                else ctx.lineTo(x, y);
            });
            ctx.stroke();
            ctx.fillStyle = set.borderColor;
            ctx.fillRect(width - 128, 18 + index * 18, 10, 10);
            ctx.fillStyle = '#17212f';
            ctx.font = '12px sans-serif';
            ctx.fillText(set.label, width - 112, 27 + index * 18);
        });
        if (labels.length) {
            ctx.fillStyle = '#64748b';
            ctx.font = '12px sans-serif';
            ctx.fillText(labels[0], 42, height - 8);
            ctx.fillText(labels[labels.length - 1], Math.max(42, width - 90), height - 8);
        }
    }

    function initServiceActions() {
        document.querySelectorAll('.js-service-action').forEach((button) => {
            button.addEventListener('click', async () => {
                const action = button.dataset.action;
                const service = button.dataset.service;
                if (['start', 'stop', 'restart', 'reload'].includes(action)) {
                    const confirmed = await confirmServiceAction(service, action);
                    if (!confirmed) {
                        return;
                    }
                }

                const body = new URLSearchParams({ service: button.dataset.service, action: button.dataset.action });
                const output = document.querySelector(`[data-service-output="${button.dataset.service}"]`);
                const originalText = button.textContent;
                button.disabled = true;
                button.textContent = 'Working';
                output.textContent = 'Running approved command...';
                try {
                    const data = await api(endpoint('api/services.php'), { method: 'POST', body });
                    output.textContent = formatServiceResult(data);
                    if (data.states) {
                        updateServiceStates(data.states);
                    } else {
                        refreshServiceStates().catch(() => {});
                    }
                    toast(data.ok ? `${action} completed` : `${action} returned an error`, data.ok ? 'success' : 'warning');
                } catch (error) {
                    output.textContent = error.message;
                    toast(error.message, 'error');
                } finally {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            });
        });
    }

    async function confirmServiceAction(service, action) {
        const title = `${action.charAt(0).toUpperCase() + action.slice(1)} ${service}?`;
        const text = service === 'apache' && ['stop', 'restart', 'reload'].includes(action)
            ? 'This can briefly interrupt the web interface.'
            : service === 'mysql' && ['stop', 'restart'].includes(action)
                ? 'This can interrupt database-backed pages until MySQL is running again.'
                : 'This will run a root-approved system service command.';

        if (window.Swal) {
            const result = await Swal.fire({
                title,
                text,
                icon: action === 'stop' ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonText: `${action.charAt(0).toUpperCase() + action.slice(1)} service`
            });
            return result.isConfirmed;
        }

        return window.confirm(`${title}\n\n${text}`);
    }

    function formatServiceResult(data) {
        const lines = [];
        if (data.command) lines.push(`$ ${data.command}`);
        if (data.output) lines.push(data.output.trim());
        if (data.error) lines.push(data.error.trim());
        if (data.exit_code !== undefined) lines.push(`exit: ${data.exit_code}`);
        return lines.filter(Boolean).join('\n\n') || 'Command completed.';
    }

    async function refreshServiceStates() {
        const states = await api(endpoint('api/services.php'));
        updateServiceStates(states);
    }

    function updateServiceStates(states) {
        Object.entries(states).forEach(([service, state]) => {
            const badge = document.querySelector(`[data-service-state="${service}"]`);
            if (!badge) return;
            badge.textContent = state.label || 'Unknown';
            badge.classList.toggle('bg-success', !!state.running);
            badge.classList.toggle('bg-secondary', !state.running);
        });
    }

    function initApiForms() {
        document.querySelectorAll('.js-api-form').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (form.dataset.submitting === '1') return;
                const submitter = event.submitter;
                const data = new FormData(form);
                if (submitter && submitter.name) {
                    data.set(submitter.name, submitter.value);
                }
                const confirmTitle = submitter?.dataset.confirmTitle || form.dataset.confirmTitle;
                const confirmText = submitter?.dataset.confirmText || form.dataset.confirmText;
                if (confirmTitle || confirmText) {
                    const confirmed = await confirmFileAction(
                        confirmTitle || 'Confirm action?',
                        confirmText || 'Continue with this action?',
                        submitter?.dataset.confirmButton || form.dataset.confirmButton || 'Continue',
                        submitter?.dataset.confirmIcon || form.dataset.confirmIcon || 'question'
                    );
                    if (!confirmed) return;
                }

                form.dataset.submitting = '1';
                const restoreButton = submitter
                    ? setButtonWorking(submitter, submitter.dataset.submittingLabel || form.dataset.submittingLabel || 'Working')
                    : () => {};
                const output = form.dataset.output;
                if (output) setOutput(output, 'Running...');
                try {
                    const result = await api(form.dataset.endpoint, { method: 'POST', body: data });
                    if (output) {
                        setOutput(
                            output,
                            form.dataset.outputFormat === 'command'
                                ? formatServiceResult(result)
                                : result
                        );
                    }
                    if (form.dataset.outputFormat === 'command' && result?.ok === false) {
                        toast('Command failed', 'error');
                        return;
                    }
                    const progress = document.getElementById('backupProgress');
                    if (progress) {
                        progress.style.width = '100%';
                        progress.textContent = '100%';
                    }
                    const resultStatus = String(result?.status || '');
                    const resultIcon = ['COMPLETED_WITH_ERRORS', 'COMPLETED_WITH_LIMITATIONS'].includes(resultStatus)
                        ? 'warning'
                        : (resultStatus === 'FAILED' ? 'error' : 'success');
                    toast(result?.notice || 'Action completed', resultIcon);
                    if (form.dataset.reloadOnSuccess === '1') {
                        window.setTimeout(() => window.location.reload(), 600);
                    }
                } catch (error) {
                    if (output) setOutput(output, error.message);
                    toast(error.message, 'error');
                } finally {
                    delete form.dataset.submitting;
                    restoreButton();
                }
            });
        });
    }

    function initSitePages() {
        const modalNode = document.getElementById('sitePagesModal');
        if (!modalNode) return;

        const canEditPages = modalNode.dataset.canEditPages === '1' || modalNode.dataset.canWrite === '1';
        const canManagePages = modalNode.dataset.canManagePages === '1' || modalNode.dataset.canWrite === '1';
        const modalTitleNode = document.getElementById('sitePagesModalTitle');
        const siteNameNode = document.getElementById('sitePagesSiteName');
        const siteRootNode = document.getElementById('sitePagesRoot');
        const listNode = document.getElementById('sitePagesList');
        const createForm = document.getElementById('sitePageCreateForm');
        const editorForm = document.getElementById('sitePageEditorForm');
        const editorEmpty = document.getElementById('sitePagesEmpty');
        const editorPath = document.getElementById('sitePageEditorPath');
        const editorLabel = document.getElementById('sitePageEditorLabel');
        let currentSite = '';
        let currentPages = [];
        let currentSiteKind = 'static';
        let currentEditorLabel = 'File';
        let currentView = '';
        let modalIsShown = false;
        let pendingEditorInput = null;

        async function loadSitePages(site, preferredView = '', revealEditor = false) {
            currentSite = site;
            clearBladeEditor();
            setOutput('#sitePagesOutput', 'Loading...');
            if (createForm) createForm.querySelector('[name="site"]').value = site;
            if (editorForm) editorForm.querySelector('[name="site"]').value = site;
            const data = await api(endpoint('api/hosting.php?action=pages&site=' + encodeURIComponent(site)));
            currentPages = data.pages || [];
            configureSitePageMode(data.site || {});
            renderSitePages(data);
            setOutput('#sitePagesOutput', '');

            const target = preferredView
                ? currentPages.find((page) => page.view === preferredView)
                : null;
            if (target) {
                await openBladeEditor(target.view, { focus: revealEditor });
            } else {
                clearBladeEditor();
            }
        }

        function configureSitePageMode(site) {
            currentSiteKind = site.kind || 'static';
            currentEditorLabel = site.editor_label || (currentSiteKind === 'laravel' ? 'Blade' : 'File');
            const uriLabel = document.getElementById('sitePageUriLabel');
            const uriInput = document.getElementById('sitePageUri');
            const viewGroup = document.getElementById('sitePageViewGroup');
            const viewLabel = document.getElementById('sitePageViewLabel');
            const viewInput = document.getElementById('sitePageView');

            if (uriLabel) uriLabel.textContent = currentSiteKind === 'laravel' ? 'Route' : 'Page URL';
            if (uriInput) uriInput.placeholder = currentSiteKind === 'laravel' ? '/about' : '/about.html';
            if (viewLabel) viewLabel.textContent = currentSiteKind === 'laravel' ? 'Blade view' : 'Page file';
            if (viewInput) {
                viewInput.required = currentSiteKind === 'laravel';
                viewInput.placeholder = currentSiteKind === 'laravel' ? 'pages.about' : 'about.html';
                if (currentSiteKind !== 'laravel') viewInput.value = '';
            }
            if (viewGroup) viewGroup.classList.toggle('d-none', currentSiteKind !== 'laravel');
            if (editorEmpty) editorEmpty.textContent = `Select a ${currentEditorLabel.toLowerCase()} page.`;
            if (editorLabel) editorLabel.textContent = currentEditorLabel;
        }

        function renderSitePages(data) {
            if (!listNode) return;
            const pages = data.pages || [];
            if (!pages.length) {
                const label = escapeHtml((data.site?.editor_label || currentEditorLabel).toLowerCase());
                listNode.innerHTML = `<div class="text-muted p-2">No editable ${label} pages found.</div>`;
                return;
            }

            listNode.innerHTML = pages.map((page) => {
                const pageKind = page.kind || currentSiteKind;
                const pageLabel = page.editor_label || (pageKind === 'laravel' ? 'Blade' : 'File');
                const routeName = page.name ? `<span class="badge bg-light text-dark">${escapeHtml(page.name)}</span>` : '';
                const controller = page.controller
                    ? `<span class="small text-muted">${escapeHtml(page.controller_action || '')} · ${escapeHtml(page.route_type || '')}</span>`
                    : `<span class="small text-muted">${escapeHtml(page.route_type || '')}</span>`;
                const bladeBadge = page.blade_exists
                    ? `<span class="badge bg-success">${escapeHtml(pageLabel)}</span>`
                    : `<span class="badge bg-warning">Missing ${escapeHtml(pageLabel)}</span>`;
                const canDeletePage = canManagePages && page.deletable !== false;
                const deleteButton = canDeletePage
                    ? `<button class="btn btn-xs btn-outline-danger js-site-page-delete" type="button" data-kind="${escapeHtml(pageKind)}" data-uri="${escapeHtml(page.uri)}" data-view="${escapeHtml(page.view)}" data-blade-exists="${page.blade_exists ? '1' : '0'}"><i class="fas fa-trash-can"></i> Delete</button>`
                    : '';

                return [
                    `<div class="site-page-row" data-view="${escapeHtml(page.view)}">`,
                    `<button class="site-page-main site-page-select js-site-page-edit" type="button" data-view="${escapeHtml(page.view)}">`,
                    `<span><strong>${escapeHtml(page.uri)}</strong> ${routeName}</span>`,
                    `<code>${escapeHtml(page.view)}</code> ${bladeBadge}`,
                    controller,
                    '</button>',
                    '<div class="site-page-actions">',
                    page.url ? `<a class="btn btn-xs btn-outline-primary" href="${escapeHtml(page.url)}" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> View</a>` : '',
                    `<button class="btn btn-xs btn-outline-secondary js-site-page-edit" type="button" data-view="${escapeHtml(page.view)}"><i class="fas fa-pen-to-square"></i> Edit ${escapeHtml(pageLabel)}</button>`,
                    deleteButton,
                    '</div>',
                    '</div>'
                ].join('');
            }).join('');
        }

        function markActiveSitePage(view) {
            listNode?.querySelectorAll('.site-page-row').forEach((row) => {
                const active = row.dataset.view === view;
                row.classList.toggle('is-active', active);
                row.querySelectorAll('.js-site-page-edit').forEach((button) => {
                    button.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
            });
        }

        function performEditorReveal(contentInput) {
            if (!editorForm || !contentInput || editorForm.classList.contains('d-none')) return;
            pendingEditorInput = null;
            const modalBody = modalNode.querySelector('.modal-body');
            if (modalBody) {
                const bodyRect = modalBody.getBoundingClientRect();
                const editorRect = editorForm.getBoundingClientRect();
                const top = modalBody.scrollTop + editorRect.top - bodyRect.top - 8;
                modalBody.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
            }
            try {
                contentInput.focus({ preventScroll: true });
            } catch (error) {
                contentInput.focus();
            }
        }

        function revealBladeEditor(contentInput) {
            if (!editorForm || !contentInput) return;
            pendingEditorInput = contentInput;
            if (!modalIsShown) return;
            requestAnimationFrame(() => performEditorReveal(contentInput));
        }

        async function openBladeEditor(view, options = {}) {
            if (!editorForm) return;
            view = String(view || '').trim();
            if (!view) return;
            const contentInput = editorForm.querySelector('[name="content"]');
            if (view === currentView && !editorForm.classList.contains('d-none')) {
                markActiveSitePage(view);
                if (options.focus) revealBladeEditor(contentInput);
                return;
            }

            setOutput('#sitePagesOutput', 'Loading Blade...');
            const data = await api(endpoint('api/hosting.php?action=read-page&site=' + encodeURIComponent(currentSite) + '&view=' + encodeURIComponent(view)));
            editorForm.classList.remove('d-none');
            if (editorEmpty) editorEmpty.classList.add('d-none');
            currentView = data.view || view;
            editorForm.querySelector('[name="view"]').value = currentView;
            const saveButton = editorForm.querySelector('button[type="submit"]');
            const canWriteFile = canEditPages && data.writable !== false;
            contentInput.value = data.content || '';
            contentInput.readOnly = !canWriteFile;
            if (saveButton) {
                saveButton.disabled = !canWriteFile;
            }
            if (editorPath) editorPath.textContent = data.path || data.view || view;
            if (editorLabel) editorLabel.textContent = data.editor_label || currentEditorLabel;
            markActiveSitePage(currentView);
            if (!canEditPages) {
                setOutput('#sitePagesOutput', `You do not have permission to edit ${data.editor_label || currentEditorLabel} files.`);
            } else if (data.writable === false) {
                setOutput('#sitePagesOutput', `${data.editor_label || currentEditorLabel} file is not writable by Apache/PHP: ${data.path || data.view || view}`);
            } else {
                setOutput('#sitePagesOutput', data.exists ? '' : `${data.editor_label || currentEditorLabel} file does not exist yet. Saving will create it.`);
            }
            if (options.focus) revealBladeEditor(contentInput);
        }

        function clearBladeEditor() {
            currentView = '';
            if (editorForm) {
                editorForm.classList.add('d-none');
                editorForm.querySelector('[name="view"]').value = '';
                const contentInput = editorForm.querySelector('[name="content"]');
                contentInput.value = '';
                contentInput.readOnly = !canEditPages;
                const saveButton = editorForm.querySelector('button[type="submit"]');
                if (saveButton) {
                    saveButton.disabled = !canEditPages;
                }
            }
            if (editorEmpty) editorEmpty.classList.remove('d-none');
            if (editorPath) editorPath.textContent = '';
        }

        async function confirmDeleteSitePage(page) {
            const title = `Delete ${page.uri}?`;
            const text = page.kind === 'laravel'
                ? 'This removes the route from routes/web.php. The Blade file can also be moved to trash.'
                : 'This moves the page file to trash.';
            if (window.Swal) {
                const result = await Swal.fire({
                    title,
                    html: `${escapeHtml(text)}${page.kind === 'laravel' && page.blade_exists ? '<div class="form-check text-start mt-3"><input class="form-check-input" type="checkbox" id="deleteBladeToo"><label class="form-check-label" for="deleteBladeToo">Move Blade file to trash too</label></div>' : ''}`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: page.kind === 'laravel' ? 'Delete route' : 'Delete page',
                    preConfirm: () => !!document.getElementById('deleteBladeToo')?.checked
                });
                return { confirmed: result.isConfirmed, deleteBlade: !!result.value };
            }

            return { confirmed: window.confirm(`${title}\n\n${text}`), deleteBlade: false };
        }

        document.addEventListener('click', async (event) => {
            const pagesButton = event.target.closest('.js-site-pages');
            if (pagesButton) {
                const site = pagesButton.dataset.site || '';
                const openCreate = pagesButton.dataset.openCreate === '1';
                currentSite = site;
                if (createForm) createForm.classList.toggle('d-none', !openCreate);
                if (modalTitleNode) modalTitleNode.textContent = openCreate ? 'Create Site Page' : 'Edit Site Page';
                if (siteNameNode) siteNameNode.textContent = pagesButton.dataset.siteName || 'Site';
                if (siteRootNode) siteRootNode.textContent = site;
                showModal(modalNode);
                if (!window.bootstrap?.Modal) modalIsShown = true;
                try {
                    await loadSitePages(site);
                    if (openCreate) {
                        const createInput = createForm?.querySelector('[name="uri"]');
                        createInput?.focus();
                        createInput?.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    }
                } catch (error) {
                    setOutput('#sitePagesOutput', error.message);
                    toast(error.message, 'error');
                }
                return;
            }

            const editButton = event.target.closest('.js-site-page-edit');
            if (editButton && modalNode.contains(editButton)) {
                const restore = setButtonWorking(editButton, 'Opening');
                try {
                    await openBladeEditor(editButton.dataset.view || '', { focus: true });
                } catch (error) {
                    setOutput('#sitePagesOutput', error.message);
                    toast(error.message, 'error');
                } finally {
                    restore();
                }
                return;
            }

            const deleteButton = event.target.closest('.js-site-page-delete');
            if (deleteButton && modalNode.contains(deleteButton)) {
                const page = {
                    kind: deleteButton.dataset.kind || currentSiteKind,
                    uri: deleteButton.dataset.uri || '',
                    view: deleteButton.dataset.view || '',
                    blade_exists: deleteButton.dataset.bladeExists === '1'
                };
                const confirmed = await confirmDeleteSitePage(page);
                if (!confirmed.confirmed) return;

                const body = new URLSearchParams({
                    action: 'delete-page',
                    site: currentSite,
                    uri: page.uri,
                    view: page.view,
                    delete_blade: confirmed.deleteBlade ? '1' : '0'
                });
                const restore = setButtonWorking(deleteButton, 'Deleting');
                try {
                    const data = await api(endpoint('api/hosting.php'), { method: 'POST', body });
                    renderSitePages(data);
                    clearBladeEditor();
                    setOutput('#sitePagesOutput', data);
                    toast(page.kind === 'laravel' ? 'Page route deleted' : 'Page deleted');
                } catch (error) {
                    setOutput('#sitePagesOutput', error.message);
                    toast(error.message, 'error');
                } finally {
                    restore();
                }
            }
        });

        modalNode.addEventListener('shown.bs.modal', () => {
            modalIsShown = true;
            if (pendingEditorInput) performEditorReveal(pendingEditorInput);
        });

        modalNode.addEventListener('hide.bs.modal', () => {
            modalIsShown = false;
            pendingEditorInput = null;
        });

        createForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submitter = event.submitter;
            const restore = submitter ? setButtonWorking(submitter, 'Creating') : () => {};
            try {
                const body = new FormData(createForm);
                body.set('site', currentSite);
                const data = await api(endpoint('api/hosting.php'), { method: 'POST', body });
                createForm.reset();
                createForm.querySelector('[name="site"]').value = currentSite;
                renderSitePages(data);
                setOutput('#sitePagesOutput', data);
                toast('Page created');
                await loadSitePages(currentSite, body.get('view') || '', true);
            } catch (error) {
                setOutput('#sitePagesOutput', error.message);
                toast(error.message, 'error');
            } finally {
                restore();
            }
        });

        editorForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!canEditPages || editorForm.querySelector('[name="content"]').readOnly) {
                toast('This file is not editable from the browser.', 'error');
                return;
            }
            const submitter = event.submitter;
            const restore = submitter ? setButtonWorking(submitter, 'Saving') : () => {};
            const view = editorForm.querySelector('[name="view"]').value;
            try {
                const body = new FormData(editorForm);
                body.set('site', currentSite);
                const data = await api(endpoint('api/hosting.php'), { method: 'POST', body });
                setOutput('#sitePagesOutput', data);
                toast('Blade saved');
                await loadSitePages(currentSite, view, true);
            } catch (error) {
                setOutput('#sitePagesOutput', error.message);
                toast(error.message, 'error');
            } finally {
                restore();
            }
        });
    }

    function initSiteArtisan() {
        const modalNode = document.getElementById('siteArtisanModal');
        if (!modalNode) return;

        const siteNameNode = document.getElementById('siteArtisanSiteName');
        const siteRootNode = document.getElementById('siteArtisanRoot');
        const migrateButton = document.getElementById('siteArtisanMigrate');
        const commandForm = document.getElementById('siteArtisanForm');
        const commandInput = document.getElementById('siteArtisanCommand');
        const outputNode = document.getElementById('siteArtisanOutput');
        let currentSite = '';
        let currentSiteName = '';
        let running = false;

        function selectSite(button) {
            currentSite = button.dataset.site || '';
            currentSiteName = button.dataset.siteName || fileBasename(currentSite);
            if (siteNameNode) siteNameNode.textContent = currentSiteName;
            if (siteRootNode) siteRootNode.textContent = currentSite;
            if (outputNode) {
                outputNode.hidden = true;
                outputNode.textContent = '';
            }
        }

        async function confirmCommand(command, allowAny) {
            const destructive = /^(?:db:wipe|migrate:(?:fresh|refresh|reset|rollback)|key:generate|env:|down\b)/i.test(command.trim());
            const title = command.trim() === 'migrate' ? 'Run database migrations?' : 'Run Artisan command?';
            const text = command.trim() === 'migrate'
                ? `Run pending migrations for ${currentSiteName}?`
                : `Run "php artisan ${command}" for ${currentSiteName}?`;

            return confirmFileAction(
                title,
                text,
                command.trim() === 'migrate' ? 'Run migrations' : 'Run command',
                destructive || !allowAny ? 'warning' : 'question'
            );
        }

        async function runCommand(command, button, allowAny) {
            command = String(command || '').trim();
            if (running || currentSite === '' || command === '') return;
            if (!await confirmCommand(command, allowAny)) return;

            running = true;
            const controls = [migrateButton, commandForm?.querySelector('button[type="submit"]')].filter(Boolean);
            const previousStates = controls.map((control) => control.disabled);
            controls.forEach((control) => {
                control.disabled = true;
            });
            const restoreWorking = button ? setButtonWorking(button, 'Running') : () => {};
            setOutput('#siteArtisanOutput', 'Running...');

            const body = new URLSearchParams({
                type: 'artisan',
                scope: 'site',
                project: currentSite,
                command
            });
            if (allowAny) {
                body.set('allow_any', '1');
                body.set('confirmed', '1');
            }

            try {
                const result = await api(endpoint('api/artisan.php'), { method: 'POST', body });
                setOutput('#siteArtisanOutput', formatServiceResult(result));
                toast(
                    result.ok ? 'Artisan command completed' : 'Artisan command failed',
                    result.ok ? 'success' : 'error'
                );
            } catch (error) {
                setOutput('#siteArtisanOutput', error.message);
                toast(error.message, 'error');
            } finally {
                restoreWorking();
                controls.forEach((control, index) => {
                    control.disabled = previousStates[index];
                });
                running = false;
            }
        }

        document.addEventListener('click', (event) => {
            const artisanButton = event.target.closest('.js-site-artisan');
            if (artisanButton) {
                selectSite(artisanButton);
                showModal(modalNode);
                window.setTimeout(() => commandInput?.focus(), 150);
                return;
            }

            const siteMigrateButton = event.target.closest('.js-site-migrate');
            if (siteMigrateButton) {
                selectSite(siteMigrateButton);
                showModal(modalNode);
                runCommand('migrate', migrateButton, false);
            }
        });

        migrateButton?.addEventListener('click', () => {
            runCommand('migrate', migrateButton, false);
        });

        commandForm?.addEventListener('submit', (event) => {
            event.preventDefault();
            runCommand(commandInput?.value || '', event.submitter, true);
        });
    }

    function initFiles() {
        const manager = document.querySelector('.file-manager');
        if (!manager) return;
        const selected = document.getElementById('selectedPath');
        const selectedLabel = document.getElementById('selectedPathLabel');
        const preview = document.getElementById('filePreview');

        function selectFileRow(row, loadPreview = true) {
            document.querySelectorAll('.js-file-row').forEach((candidate) => candidate.classList.remove('selected'));
            row.classList.add('selected');
            selected.value = row.dataset.path;
            selected.dataset.type = row.dataset.type;
            selected.dataset.permissions = row.dataset.permissions || '';
            if (selectedLabel) {
                selectedLabel.textContent = row.dataset.path;
            }
            if (loadPreview) {
                previewPath(row.dataset.path).catch((error) => {
                    preview.textContent = error.message;
                });
            }
        }

        async function previewPath(path) {
            preview.textContent = 'Loading preview...';
            const data = await api(endpoint('api/file.php?action=preview&path=' + encodeURIComponent(path)));
            if (data.type === 'image') {
                preview.innerHTML = [
                    previewToolbar(path, false),
                    `<img alt="Preview" src="${escapeHtml(data.url)}">`
                ].join('');
                return;
            }
            if (data.type === 'text') {
                preview.innerHTML = [
                    previewToolbar(path, true),
                    `<pre class="sys-output">${escapeHtml(data.content)}</pre>`
                ].join('');
                return;
            }
            preview.innerHTML = previewToolbar(path, false) + `<p class="mb-0">${escapeHtml(data.content)}</p>`;
        }

        manager.addEventListener('click', async (event) => {
            const actionButton = event.target.closest('.js-file-action');
            if (actionButton && manager.contains(actionButton)) {
                event.preventDefault();
                event.stopPropagation();
                const row = actionButton.closest('.js-file-row');
                if (row) {
                    selectFileRow(row, false);
                }
                await runFileAction(actionButton);
                return;
            }

            if (event.target.closest('a, button, input, select, textarea, label')) {
                return;
            }

            const row = event.target.closest('.js-file-row');
            if (row && manager.contains(row)) {
                if (row.dataset.type === 'folder') {
                    window.location.href = row.dataset.openUrl || fileOpenUrl(row.dataset.path);
                    return;
                }
                selectFileRow(row);
            }
        });

        function revealInitialSelection() {
            const initialPath = manager.dataset.selectedPath || '';
            if (!initialPath) {
                return;
            }

            const table = manager.querySelector('.data-table');
            if (window.jQuery && jQuery.fn.DataTable && table && jQuery.fn.DataTable.isDataTable(table)) {
                jQuery(table).DataTable().search(fileBasename(initialPath)).draw();
            }

            const row = Array.from(manager.querySelectorAll('.js-file-row'))
                .find((candidate) => candidate.dataset.path === initialPath);
            if (!row) {
                return;
            }

            selectFileRow(row);
            row.scrollIntoView({ block: 'center' });
        }

        async function runFileAction(button) {
            const action = button.dataset.action === 'permissions' ? 'chmod' : button.dataset.action;
            const currentPath = manager.dataset.currentPath;
            const selectedPath = button.dataset.path || selected.value;
            const selectedType = button.dataset.type || selected.dataset.type || '';

            if (action === 'edit') {
                if (!selectedPath) {
                    toast('Select a file first', 'warning');
                    return;
                }
                if (selectedType === 'folder') {
                    toast('Folders cannot be edited', 'warning');
                    return;
                }
                await editFile(button, selectedPath);
                return;
            }

            const body = new URLSearchParams({ action });
            if (action === 'create-folder') {
                const name = await promptText('Folder name');
                if (!name) return;
                body.set('path', currentPath);
                body.set('name', name);
            } else if (action === 'create-file') {
                const name = await promptText('File name');
                if (!name) return;
                const content = await promptTextarea('Initial content', '', 'Create file');
                if (content === null) return;
                body.set('path', currentPath);
                body.set('name', name);
                body.set('content', content);
            } else {
                if (!selectedPath) {
                    toast('Select a file or folder first', 'warning');
                    return;
                }
                body.set('path', selectedPath);
            }

            if (action === 'rename') {
                const name = await promptText('New name', fileBasename(selectedPath));
                if (!name) return;
                body.set('name', name);
            }
            if (['copy', 'move', 'unzip'].includes(action)) {
                const target = await promptText('Target directory', currentPath);
                if (!target) return;
                body.set('target', target);
            }
            if (action === 'chmod') {
                const currentMode = button.dataset.permissions || selected.dataset.permissions || '';
                const mode = await promptText('Permissions mode', currentMode);
                if (!mode) return;
                const normalizedMode = mode.trim();
                if (!/^0?[0-7]{3,4}$/.test(normalizedMode)) {
                    toast('Use 3 or 4 octal digits, like 644 or 0755', 'warning');
                    return;
                }
                body.set('mode', normalizedMode);
            }
            if (action === 'delete') {
                const confirmed = await confirmFileAction('Move to trash?', `Move ${fileBasename(selectedPath)} to trash?`, 'Move to trash', 'warning');
                if (!confirmed) return;
            }

            const restore = setButtonWorking(button);
            try {
                await api(endpoint('api/file.php'), { method: 'POST', body });
                toast('File action completed');
                window.location.reload();
            } catch (error) {
                toast(error.message, 'error');
            } finally {
                restore();
            }
        }

        async function editFile(button, path) {
            let restore = setButtonWorking(button, 'Loading');
            try {
                const file = await api(endpoint('api/file.php?action=read&path=' + encodeURIComponent(path)));
                restore();
                restore = () => {};
                const content = await promptTextarea(`Edit ${file.name}`, file.content, 'Save file');
                if (content === null) return;

                const body = new URLSearchParams({ action: 'save-file', path, content });
                restore = setButtonWorking(button, 'Saving');
                await api(endpoint('api/file.php'), { method: 'POST', body });
                toast('File saved');
                window.location.reload();
            } catch (error) {
                toast(error.message, 'error');
            } finally {
                restore();
            }
        }

        document.querySelectorAll('.js-upload-form').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                try {
                    await api(endpoint('api/file.php'), { method: 'POST', body: new FormData(form) });
                    toast('Upload completed');
                    window.location.reload();
                } catch (error) {
                    toast(error.message, 'error');
                }
            });
        });

        revealInitialSelection();
    }

    function initGit() {
        document.querySelectorAll('.js-git-action').forEach((button) => {
            button.addEventListener('click', async () => {
                const body = new URLSearchParams({ path: button.dataset.path, action: button.dataset.action });
                if (button.dataset.action === 'commit') {
                    const message = await promptText('Commit message');
                    if (!message) return;
                    body.set('message', message);
                }
                setOutput('#gitOutput', 'Running...');
                try {
                    const data = await api(endpoint('api/git.php'), { method: 'POST', body });
                    setOutput('#gitOutput', (data.output || '') + (data.error || ''));
                    toast('Git action completed');
                } catch (error) {
                    setOutput('#gitOutput', error.message);
                    toast(error.message, 'error');
                }
            });
        });
    }

    function initCronScripts() {
        document.querySelectorAll('.js-cron-action').forEach((button) => {
            button.addEventListener('click', async () => {
                const body = new URLSearchParams({ action: button.dataset.action, id: button.dataset.id || '', script: button.dataset.script || '' });
                setOutput('#cronOutput', 'Running...');
                try {
                    const data = await api(endpoint('api/cron.php'), { method: 'POST', body });
                    setOutput('#cronOutput', data);
                    toast('Cron action completed');
                } catch (error) {
                    setOutput('#cronOutput', error.message);
                    toast(error.message, 'error');
                }
            });
        });
        document.querySelectorAll('.js-script-run').forEach((button) => {
            button.addEventListener('click', async () => {
                const body = new URLSearchParams({ script: button.dataset.script });
                setOutput('#scriptOutput', 'Running...');
                try {
                    const data = await api(endpoint('api/scripts.php'), { method: 'POST', body });
                    setOutput('#scriptOutput', (data.output || '') + (data.error || ''));
                    toast('Script completed');
                } catch (error) {
                    setOutput('#scriptOutput', error.message);
                    toast(error.message, 'error');
                }
            });
        });
    }

    function initMysqlCrud() {
        const root = document.querySelector('[data-mysql-crud]');
        if (!root) return;

        function parseJsonAttr(value, fallback = {}) {
            try {
                return JSON.parse(value || JSON.stringify(fallback));
            } catch (error) {
                console.error('Invalid MySQL row payload', error);
                toast('Could not read row data. Refresh and try again.', 'error');
                return fallback;
            }
        }

        function syncNullToggle(toggle) {
            const input = document.getElementById(toggle.dataset.targetId || '');
            if (!input) return;
            const field = input.closest('.mysql-field');
            input.disabled = toggle.checked;
            field?.classList.toggle('is-null', toggle.checked);
            if (toggle.checked) {
                input.value = '';
            }
        }

        function mysqlFieldValue(input, value) {
            if (value === null || value === undefined) {
                return '';
            }
            if (input.dataset.mysqlKind === 'json' && typeof value === 'string' && value.trim() !== '') {
                try {
                    return JSON.stringify(JSON.parse(value), null, 2);
                } catch (error) {
                    return value;
                }
            }
            return String(value);
        }

        document.querySelectorAll('.js-mysql-null-toggle').forEach((toggle) => {
            syncNullToggle(toggle);
            toggle.addEventListener('change', () => syncNullToggle(toggle));
        });

        root.querySelectorAll('[data-mysql-selector] select').forEach((select) => {
            select.addEventListener('change', () => {
                if (select.matches('[data-mysql-database-select]')) {
                    const tableSelect = root.querySelector('[data-mysql-table-select]');
                    if (tableSelect) {
                        tableSelect.name = '';
                    }
                }
                select.form?.submit();
            });
        });

        root.querySelectorAll('[data-bs-dismiss="modal"]').forEach((button) => {
            button.addEventListener('click', () => {
                const modalNode = button.closest('.modal');
                if (modalNode) {
                    hideModal(modalNode);
                }
            });
        });

        document.querySelectorAll('.js-mysql-row-form').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const submitter = event.submitter;
                const restore = submitter ? setButtonWorking(submitter, 'Saving') : () => {};
                const output = form.dataset.output;
                if (output) setOutput(output, 'Saving...');
                try {
                    const result = await api(endpoint('api/mysql.php'), { method: 'POST', body: new FormData(form) });
                    if (output) setOutput(output, result);
                    toast('MySQL record saved');
                    window.location.reload();
                } catch (error) {
                    if (output) setOutput(output, error.message);
                    toast(error.message, 'error');
                } finally {
                    restore();
                }
            });
        });

        root.addEventListener('click', async (event) => {
            const createButton = event.target.closest('.js-mysql-create');
            if (createButton && root.contains(createButton)) {
                if (!window.bootstrap?.Modal) {
                    event.preventDefault();
                    const modalNode = document.getElementById('mysqlCreateModal');
                    if (modalNode) {
                        showModal(modalNode);
                    }
                }
                return;
            }

            const editButton = event.target.closest('.js-mysql-edit');
            if (editButton && root.contains(editButton)) {
                const modalNode = document.getElementById('mysqlEditModal');
                if (!modalNode) {
                    toast('Edit form is not available for this table.', 'error');
                    return;
                }
                const form = modalNode.querySelector('form');
                const row = parseJsonAttr(editButton.dataset.row, {});
                form.querySelector('[name="key"]').value = editButton.dataset.key || '{}';

                form.querySelectorAll('[data-column-input]').forEach((input) => {
                    const column = input.dataset.columnInput;
                    const value = row[column];
                    input.value = mysqlFieldValue(input, value);
                    const nullToggle = Array.from(form.querySelectorAll('[data-column-null]')).find((toggle) => toggle.dataset.columnNull === column);
                    if (nullToggle) {
                        nullToggle.checked = value === null;
                        syncNullToggle(nullToggle);
                    }
                });

                showModal(modalNode);
                return;
            }

            const deleteButton = event.target.closest('.js-mysql-delete');
            if (deleteButton && root.contains(deleteButton)) {
                const confirmed = await confirmFileAction('Delete MySQL record?', 'This permanently deletes the selected database record.', 'Delete record', 'warning');
                if (!confirmed) return;

                const body = new URLSearchParams({
                    action: 'delete-row',
                    database: root.dataset.database || '',
                    table: root.dataset.table || '',
                    key: deleteButton.dataset.key || '{}'
                });
                setOutput('#mysqlOutput', 'Deleting...');
                const restore = setButtonWorking(deleteButton, 'Deleting');
                try {
                    const result = await api(endpoint('api/mysql.php'), { method: 'POST', body });
                    setOutput('#mysqlOutput', result);
                    toast('MySQL record deleted');
                    window.location.reload();
                } catch (error) {
                    setOutput('#mysqlOutput', error.message);
                    toast(error.message, 'error');
                } finally {
                    restore();
                }
            }
        });
    }

    function initSandbox() {
        if (!document.querySelector('.sandbox-tabs')) {
            return;
        }

        const phpEditor = document.getElementById('sandboxPhpCode');
        if (phpEditor) {
            initPhpEditor(phpEditor);
        }
        // Code Runner uses bounded scripts; never auto-start a host shell.

        document.querySelectorAll('.js-sandbox-run').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const language = form.querySelector('[name="language"]')?.value || form.dataset.language || 'php';
                const output = document.getElementById(language === 'php' ? 'sandboxPhpOutput' : 'sandboxBashOutput');
                const submitter = event.submitter || form.querySelector('[type="submit"]');
                const confirmed = form.dataset.skipConfirm === '1' || await confirmRealCodeAction(form);
                if (!confirmed) {
                    return;
                }

                const restore = submitter ? setButtonWorking(submitter, 'Running') : () => {};
                if (language === 'php') {
                    enforcePhpOpeningLine(form.querySelector('[name="code"]'));
                    renderPhpOutput(output, { output: '<p>Running...</p>', error: '', ok: true });
                } else {
                    output.textContent = 'Running...';
                }
                try {
                    const body = new FormData(form);
                    body.set('confirm_real', '1');
                    const data = await api(endpoint('api/sandbox.php'), { method: 'POST', body });
                    if (language === 'php') {
                        renderPhpOutput(output, data);
                    } else {
                        output.textContent = formatSandboxResult(data);
                    }
                    toast(data.ok ? 'Code run completed' : 'Code run returned an error', data.ok ? 'success' : 'warning');
                } catch (error) {
                    if (language === 'php') {
                        renderPhpOutput(output, { output: '', error: error.message, ok: false });
                    } else {
                        output.textContent = error.message;
                    }
                    toast(error.message, 'error');
                } finally {
                    restore();
                    if (language === 'php') {
                        focusPhpEditorAtTypingPoint(form.querySelector('[name="code"]'));
                    }
                }
            });
        });

        document.querySelectorAll('.js-sandbox-web').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const submitter = event.submitter || form.querySelector('[type="submit"]');
                const confirmed = await confirmRealCodeAction(form);
                if (!confirmed) {
                    return;
                }

                const restore = submitter ? setButtonWorking(submitter, 'Saving') : () => {};
                setOutput('#sandboxConsole', 'Saving browser files...');
                try {
                    const body = new FormData(form);
                    body.set('confirm_real', '1');
                    const data = await api(endpoint('api/sandbox.php'), { method: 'POST', body });
                    setOutput('#sandboxConsole', formatSandboxWebResult(data));
                    loadSandboxPreview(data.preview_url || form.dataset.previewUrl, data.version);
                    toast('Browser files saved');
                } catch (error) {
                    setOutput('#sandboxConsole', error.message);
                    toast(error.message, 'error');
                } finally {
                    restore();
                }
            });
        });

        document.querySelectorAll('.js-sandbox-load-preview').forEach((button) => {
            button.addEventListener('click', async () => {
                const confirmed = await confirmRealCodeAction(button);
                if (!confirmed) {
                    return;
                }
                loadSandboxPreview(button.dataset.previewUrl);
                setOutput('#sandboxConsole', `Preview loaded: ${button.dataset.previewUrl}`);
            });
        });

        document.querySelectorAll('.js-sandbox-reset').forEach((button) => {
            button.addEventListener('click', () => {
                document.querySelectorAll(button.dataset.target || '').forEach((node) => {
                    const example = node.dataset.exampleTarget ? document.getElementById(node.dataset.exampleTarget) : null;
                    node.value = example ? example.textContent : node.defaultValue ?? '';
                    if (node.id === 'sandboxPhpCode') {
                        enforcePhpOpeningLine(node, 'editable-start');
                        node.focus();
                    }
                });
                if (button.dataset.target?.includes('sandboxHtml')) {
                    clearSandboxPreview();
                }
            });
        });
    }

    function initSandboxTerminalControls() {
        document.querySelector('.js-sandbox-terminal-start')?.addEventListener('click', () => {
            startSandboxShellSession({ force: true, notify: true }).catch((error) => toast(error.message, 'error'));
        });

        document.querySelector('.js-sandbox-terminal-stop')?.addEventListener('click', () => {
            stopSandboxTerminal().catch((error) => toast(error.message, 'error'));
        });

        document.querySelector('.js-sandbox-terminal-fullscreen')?.addEventListener('click', () => {
            toggleSandboxTerminalFullscreen();
        });

        document.querySelector('.js-sandbox-terminal-clear')?.addEventListener('click', () => {
            if (sandboxTerminal) {
                sandboxTerminal.clear();
            }
            const fallback = document.getElementById('sandboxBashOutput');
            if (fallback) {
                fallback.textContent = '';
            }
        });

        document.getElementById('sandbox-bash-tab')?.addEventListener('shown.bs.tab', () => {
            startSandboxShellSession().catch((error) => toast(error.message, 'error'));
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && isSandboxTerminalFullscreen()) {
                setSandboxTerminalFullscreen(false);
            }
        });

        window.addEventListener('beforeunload', () => {
            if (!sandboxTerminalSession || !navigator.sendBeacon) {
                return;
            }

            const body = new URLSearchParams({
                _csrf: csrf,
                action: 'terminal-stop',
                session: sandboxTerminalSession
            });
            navigator.sendBeacon(endpoint('api/sandbox.php'), body);
        });
    }

    async function startSandboxShellSession(options = {}) {
        if (sandboxTerminalStarting) {
            return;
        }

        const terminal = ensureSandboxTerminal();
        const fallback = document.getElementById('sandboxBashOutput');
        if (!terminal) {
            if (fallback) {
                fallback.hidden = false;
                fallback.textContent = 'Terminal assets did not load. Refresh the page and try again.';
            }
            toast('Terminal could not be loaded', 'error');
            return;
        }

        if (sandboxTerminalSession && !options.force) {
            fitSandboxTerminal();
            terminal.focus();
            return;
        }

        if (sandboxTerminalSession) {
            await requestSandboxTerminalStop(sandboxTerminalSession);
            clearSandboxTerminalSessionState();
        }

        sandboxTerminalStarting = true;
        terminal.clear();
        terminal.focus();
        setSandboxTerminalStatus('Starting');
        const startButton = document.querySelector('.js-sandbox-terminal-start');
        const restore = startButton ? setButtonWorking(startButton, 'Starting') : () => {};
        let failed = false;

        try {
            const body = new URLSearchParams({
                action: 'terminal-start',
                language: 'bash',
                code: '',
                confirm_real: '1',
                cols: String(terminal.cols || 100),
                rows: String(terminal.rows || 30)
            });

            const data = await api(endpoint('api/sandbox.php'), { method: 'POST', body });
            sandboxTerminalSession = data.id || '';
            sandboxTerminalOffset = Number(data.offset || 0);
            updateBashWorkingPath(data.working_directory);
            setSandboxTerminalStatus('Running');
            terminal.focus();
            pollSandboxTerminal();
            if (sandboxTerminalInputBuffer) {
                flushSandboxTerminalInput().catch((inputError) => toast(inputError.message, 'error'));
            }
            if (options.notify) {
                toast('Shell started');
            }
        } catch (error) {
            failed = true;
            setSandboxTerminalStatus('Error');
            writeSandboxTerminal(error.message + '\r\n');
            toast(error.message, 'error');
        } finally {
            sandboxTerminalStarting = false;
            restore();
            if (!failed) {
                setSandboxTerminalStatus(sandboxTerminalSession ? 'Running' : 'Idle');
            }
        }
    }

    function ensureSandboxTerminal() {
        const container = document.getElementById('sandboxBashTerminal');
        const fallback = document.getElementById('sandboxBashOutput');
        if (!container) {
            return null;
        }

        if (!window.Terminal) {
            container.hidden = true;
            if (fallback) {
                fallback.hidden = false;
            }
            return null;
        }

        if (sandboxTerminal) {
            return sandboxTerminal;
        }

        if (fallback) {
            fallback.hidden = true;
        }
        container.hidden = false;
        sandboxTerminal = new window.Terminal({
            cursorBlink: true,
            cursorStyle: 'block',
            fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
            fontSize: 17,
            scrollback: 5000,
            theme: {
                background: '#0f172a',
                foreground: '#dbeafe',
                cursor: '#f8fafc',
                selectionBackground: '#334155'
            }
        });

        if (window.FitAddon?.FitAddon) {
            sandboxTerminalFit = new window.FitAddon.FitAddon();
            sandboxTerminal.loadAddon(sandboxTerminalFit);
        }

        sandboxTerminal.open(container);
        sandboxTerminal.onData((data) => queueSandboxTerminalInput(data));
        fitSandboxTerminal();
        setTimeout(fitSandboxTerminal, 50);

        if (!sandboxTerminalResizeBound) {
            sandboxTerminalResizeBound = true;
            window.addEventListener('resize', () => fitSandboxTerminal());
        }

        return sandboxTerminal;
    }

    function toggleSandboxTerminalFullscreen() {
        setSandboxTerminalFullscreen(!isSandboxTerminalFullscreen());
    }

    function setSandboxTerminalFullscreen(enabled) {
        const card = document.getElementById('sandboxTerminalCard');
        if (!card) {
            return;
        }

        card.classList.toggle('is-fullscreen', enabled);
        document.body.classList.toggle('sandbox-terminal-fullscreen', enabled);
        const button = document.querySelector('.js-sandbox-terminal-fullscreen');
        const icon = button?.querySelector('i');
        if (button) {
            button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            button.setAttribute('title', enabled ? 'Exit fullscreen terminal' : 'Toggle fullscreen terminal');
            button.setAttribute('aria-label', enabled ? 'Exit fullscreen terminal' : 'Toggle fullscreen terminal');
        }
        if (icon) {
            icon.classList.toggle('fa-expand', !enabled);
            icon.classList.toggle('fa-compress', enabled);
        }

        ensureSandboxTerminal();
        requestAnimationFrame(() => {
            fitSandboxTerminal();
            sandboxTerminal?.focus();
        });
    }

    function isSandboxTerminalFullscreen() {
        return document.getElementById('sandboxTerminalCard')?.classList.contains('is-fullscreen') || false;
    }

    function fitSandboxTerminal() {
        try {
            sandboxTerminalFit?.fit();
        } catch (error) {
            // The fit addon can throw while the tab is hidden.
        }
    }

    function pollSandboxTerminal() {
        clearTimeout(sandboxTerminalPollTimer);
        if (!sandboxTerminalSession) {
            return;
        }

        const session = sandboxTerminalSession;
        const url = endpoint(`api/sandbox.php?action=terminal-output&session=${encodeURIComponent(session)}&offset=${encodeURIComponent(String(sandboxTerminalOffset))}`);
        api(url).then((data) => {
            if (session !== sandboxTerminalSession) {
                return;
            }

            const output = decodeBase64(data.output_b64 || '');
            if (output) {
                writeSandboxTerminal(output);
            }

            sandboxTerminalOffset = Number(data.offset || sandboxTerminalOffset || 0);
            updateBashWorkingPath(data.working_directory);

            if (data.running) {
                setSandboxTerminalStatus('Running');
                sandboxTerminalPollTimer = setTimeout(pollSandboxTerminal, 150);
                return;
            }

            sandboxTerminalSession = '';
            sandboxTerminalPollTimer = null;
            if (data.state === 'stopped') {
                setSandboxTerminalStatus('Stopped');
                toast('Terminal stopped', 'warning');
                return;
            }

            setSandboxTerminalStatus(data.exit_code === 0 ? 'Finished' : `Exit ${data.exit_code ?? ''}`.trim());
            toast(data.exit_code === 0 ? 'Terminal finished' : 'Terminal exited with an error', data.exit_code === 0 ? 'success' : 'warning');
        }).catch((error) => {
            setSandboxTerminalStatus('Error');
            writeSandboxTerminal(error.message + '\r\n');
            toast(error.message, 'error');
        });
    }

    function queueSandboxTerminalInput(data) {
        if (!sandboxTerminalSession) {
            sandboxTerminalInputBuffer += data;
            if (!sandboxTerminalStarting) {
                startSandboxShellSession().catch((error) => toast(error.message, 'error'));
            }
            return;
        }

        sandboxTerminalInputBuffer += data;
        if (sandboxTerminalInputTimer) {
            return;
        }

        sandboxTerminalInputTimer = setTimeout(() => {
            sandboxTerminalInputTimer = null;
            flushSandboxTerminalInput().catch((error) => toast(error.message, 'error'));
        }, 12);
    }

    async function flushSandboxTerminalInput() {
        if (!sandboxTerminalInputBuffer) {
            return;
        }

        if (!sandboxTerminalSession) {
            if (!sandboxTerminalStarting) {
                startSandboxShellSession().catch((error) => toast(error.message, 'error'));
            }
            return;
        }

        const session = sandboxTerminalSession;
        let input = sandboxTerminalInputBuffer;
        sandboxTerminalInputBuffer = '';

        while (input.length > 0 && session === sandboxTerminalSession) {
            const chunk = input.slice(0, 4000);
            input = input.slice(4000);
            const body = new URLSearchParams({
                action: 'terminal-input',
                session,
                input: chunk
            });
            await api(endpoint('api/sandbox.php'), { method: 'POST', body });
        }
    }

    async function stopSandboxTerminal() {
        if (!sandboxTerminalSession) {
            return;
        }

        await requestSandboxTerminalStop(sandboxTerminalSession);
        setSandboxTerminalStatus('Stopping');
        pollSandboxTerminal();
    }

    async function requestSandboxTerminalStop(session) {
        const body = new URLSearchParams({
            action: 'terminal-stop',
            session
        });
        await api(endpoint('api/sandbox.php'), { method: 'POST', body });
    }

    function clearSandboxTerminalSessionState() {
        clearTimeout(sandboxTerminalPollTimer);
        sandboxTerminalSession = '';
        sandboxTerminalOffset = 0;
        sandboxTerminalPollTimer = null;
        sandboxTerminalInputBuffer = '';
        setSandboxTerminalStatus('Idle');
    }

    function setSandboxTerminalStatus(status) {
        const label = document.getElementById('sandboxTerminalStatus');
        if (label) {
            label.textContent = status || 'Idle';
        }

        const startButton = document.querySelector('.js-sandbox-terminal-start');
        if (startButton) {
            startButton.disabled = sandboxTerminalStarting;
        }

        const stopButton = document.querySelector('.js-sandbox-terminal-stop');
        if (stopButton) {
            stopButton.disabled = sandboxTerminalStarting || !sandboxTerminalSession || status === 'Stopping';
        }
    }

    function writeSandboxTerminal(output) {
        if (sandboxTerminal) {
            sandboxTerminal.write(output);
            return;
        }

        const fallback = document.getElementById('sandboxBashOutput');
        if (fallback) {
            fallback.hidden = false;
            fallback.textContent += output;
            fallback.scrollTop = fallback.scrollHeight;
        }
    }

    function decodeBase64(value) {
        if (!value) {
            return '';
        }

        const binary = window.atob(value);
        const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
        return new TextDecoder().decode(bytes);
    }

    function initPhpEditor(editor) {
        enforcePhpOpeningLine(editor, 'editable-start');
        requestAnimationFrame(() => {
            editor.focus();
            movePhpCaret(editor, editor.value.length > phpOpeningPrefix().length ? 'end' : 'editable-start');
        });

        editor.addEventListener('input', () => enforcePhpOpeningLine(editor));
        editor.addEventListener('paste', () => setTimeout(() => enforcePhpOpeningLine(editor), 0));
        editor.addEventListener('click', () => setTimeout(() => keepPhpCaretEditable(editor), 0));
        editor.addEventListener('keyup', () => keepPhpCaretEditable(editor));
    }

    function focusPhpEditorAtTypingPoint(editor) {
        if (!editor) {
            return;
        }

        enforcePhpOpeningLine(editor, 'end');
        editor.focus();
    }

    function enforcePhpOpeningLine(editor, placement = 'preserve') {
        if (!editor) {
            return;
        }

        const original = editor.value;
        const start = editor.selectionStart ?? original.length;
        const normalized = normalizePhpEditorValue(original);
        if (normalized !== original) {
            editor.value = normalized;
            if (placement === 'preserve') {
                const delta = normalized.length - original.length;
                editor.setSelectionRange(
                    Math.max(phpOpeningPrefix().length, Math.min(normalized.length, start + delta)),
                    Math.max(phpOpeningPrefix().length, Math.min(normalized.length, start + delta))
                );
                return;
            }
        }

        movePhpCaret(editor, placement);
    }

    function normalizePhpEditorValue(value) {
        const prefix = phpOpeningPrefix();
        const tag = '<?php';
        const normalized = String(value || '').replace(/\r\n?/g, '\n');
        if (normalized === '') {
            return prefix;
        }
        if (normalized.startsWith(prefix)) {
            return normalized;
        }
        if (normalized.startsWith(tag)) {
            return prefix + normalized.slice(tag.length).replace(/^\n/, '');
        }

        const lines = normalized.split('\n');
        const firstLine = lines.shift() || '';
        const rest = lines.join('\n');
        const code = firstLine.trim() === tag ? rest : normalized.replace(/^\s*<\?php\s*/, '');

        return prefix + code.replace(/^\n/, '');
    }

    function keepPhpCaretEditable(editor) {
        if (!editor || (editor.selectionStart ?? 0) >= phpOpeningPrefix().length) {
            return;
        }

        movePhpCaret(editor, 'editable-start');
    }

    function movePhpCaret(editor, placement) {
        const prefixLength = phpOpeningPrefix().length;
        if (placement === 'end') {
            editor.setSelectionRange(editor.value.length, editor.value.length);
            return;
        }
        if (placement === 'editable-start' || (editor.selectionStart ?? 0) < prefixLength) {
            editor.setSelectionRange(prefixLength, prefixLength);
        }
    }

    function phpOpeningPrefix() {
        return '<?php\n';
    }

    function formatSandboxResult(data) {
        const lines = [
            `language: ${data.language}`,
            `file: ${data.saved_file || ''}`,
            `cwd: ${data.working_directory || ''}`,
            `workspace: ${data.isolated ? 'isolated, temporary' : 'unavailable'}`,
            `exit: ${data.exit_code}`,
            `elapsed: ${data.elapsed_ms}ms`
        ];
        if (data.warning) lines.push(`warning: ${data.warning}`);
        if (data.timed_out) lines.push('timed out: yes');
        if (data.output) lines.push(`\nSTDOUT\n${data.output.trimEnd()}`);
        if (data.error) lines.push(`\nSTDERR\n${data.error.trimEnd()}`);
        return lines.join('\n') || 'Code run completed.';
    }

    function updateBashWorkingPath(path) {
        const node = document.getElementById('sandboxBashCwd');
        if (node && path) {
            node.textContent = path;
        }
    }

    function renderPhpOutput(frame, data) {
        if (!frame) {
            return;
        }

        const output = data.output ? data.output.trimEnd() : '';
        const error = data.error ? data.error.trimEnd() : '';
        const fallback = !output && !error && !data.ok ? `Process exited with code ${data.exit_code}` : '';
        const errorHtml = error ? `<pre style="white-space: pre-wrap; color: #b42318;">${escapeHtml(error)}</pre>` : '';
        const fallbackHtml = fallback ? `<pre style="white-space: pre-wrap;">${escapeHtml(fallback)}</pre>` : '';

        frame.removeAttribute('src');
        const status = `<pre>${escapeHtml(`exit: ${data.exit_code ?? ''} | elapsed: ${data.elapsed_ms ?? 0}ms | workspace: isolated, temporary`)}</pre>`;
        frame.srcdoc = `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'">${status}${output}${errorHtml}${fallbackHtml}`;
    }

    function formatSandboxWebResult(data) {
        const lines = [
            'browser files saved',
            'browser preview: sandboxed',
            `preview: ${data.preview_url || ''}`
        ];
        if (data.warning) lines.push(`warning: ${data.warning}`);
        Object.entries(data.files || {}).forEach(([name, path]) => {
            lines.push(`${name}: ${path}`);
        });
        return lines.join('\n');
    }

    async function confirmRealCodeAction(node) {
        return confirmFileAction(
            node.dataset.realTitle || 'Run code against real files?',
            node.dataset.realWarning || 'This action can modify files that the web server user can access.',
            node.dataset.confirmLabel || 'Continue',
            'warning'
        );
    }

    function loadSandboxPreview(url, version = Date.now()) {
        const frame = document.getElementById('sandboxFrame');
        if (!frame || !url) {
            return;
        }

        const separator = url.includes('?') ? '&' : '?';
        frame.removeAttribute('srcdoc');
        frame.src = `${url}${separator}v=${encodeURIComponent(version || Date.now())}`;
    }

    function clearSandboxPreview() {
        const frame = document.getElementById('sandboxFrame');
        if (frame) {
            frame.removeAttribute('src');
            frame.srcdoc = '';
        }
        setOutput('#sandboxConsole', '');
    }

    async function promptText(title, value = '') {
        if (window.Swal) {
            const result = await Swal.fire({ title, input: 'text', inputValue: value, showCancelButton: true });
            return result.isConfirmed ? result.value : '';
        }
        return window.prompt(title, value) || '';
    }

    async function promptTextarea(title, value = '', confirmButtonText = 'Save') {
        if (window.Swal) {
            const result = await Swal.fire({
                title,
                input: 'textarea',
                inputValue: value,
                inputAttributes: { spellcheck: 'false' },
                width: 'min(960px, calc(100vw - 2rem))',
                showCancelButton: true,
                confirmButtonText,
                customClass: { input: 'sys-file-editor' }
            });
            return result.isConfirmed ? (result.value ?? '') : null;
        }

        const result = window.prompt(title, value);
        return result === null ? null : result;
    }

    async function confirmFileAction(title, text, confirmButtonText = 'Continue', icon = 'question') {
        if (window.Swal) {
            const result = await Swal.fire({ title, text, icon, showCancelButton: true, confirmButtonText });
            return result.isConfirmed;
        }

        return window.confirm(`${title}\n\n${text}`);
    }

    function setButtonWorking(button, label = 'Working') {
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${label}`;
        return () => {
            button.disabled = false;
            button.innerHTML = original;
        };
    }

    function fileBasename(path) {
        return String(path || '').split('/').filter(Boolean).pop() || path;
    }

    function fileOpenUrl(path) {
        const url = new URL(window.location.href);
        url.searchParams.set('path', path);
        return url.toString();
    }

    function previewToolbar(path, editable) {
        const encodedPath = encodeURIComponent(path);
        const editButton = editable
            ? `<button class="btn btn-xs btn-outline-secondary js-file-action" type="button" data-action="edit" data-path="${escapeHtml(path)}" data-type="file"><i class="fas fa-pen-to-square"></i> Edit</button>`
            : '';

        return [
            '<div class="file-preview-toolbar">',
            editButton,
            `<a class="btn btn-xs btn-outline-secondary" href="${endpoint('api/file.php?action=download&path=' + encodedPath)}"><i class="fas fa-download"></i> Download</a>`,
            '</div>'
        ].join('');
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-refresh-label]').forEach((node) => node.textContent = Math.round(refreshInterval / 1000) + 's');
        initPageRefresh();
        initTables();
        initDoctorLibrary();
        initServiceActions();
        initApiForms();
        initSitePages();
        initSiteArtisan();
        initFiles();
        initGit();
        initCronScripts();
        initMysqlCrud();
        initSandbox();
        const resourceCanvas = document.getElementById('resourceChart');
        if (resourceCanvas?.dataset.initialCpu) {
            drawResourceChart({
                cpu: Number(resourceCanvas.dataset.initialCpu),
                ram: { percent: Number(resourceCanvas.dataset.initialRam) },
                disk: { percent: Number(resourceCanvas.dataset.initialDisk) }
            });
        }
        drawAnalyticsChart();
        if (document.querySelector('[data-dashboard]')) {
            setInterval(() => refreshDashboard().catch(() => {}), refreshInterval);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    refreshDashboard().catch(() => {});
                }
            });
        }
    });
})();
