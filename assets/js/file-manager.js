jQuery(function ($) {
    const $root = $('[data-afm]');
    if (!$root.length) return;

    const portalMode = $root.is('[data-apfm]');

    const $tree = $root.find('[data-afm-tree]');
    const $grid = $root.find('[data-afm-grid]');
    const $breadcrumbs = $root.find('[data-afm-breadcrumbs]');
    const $drawer = $root.find('[data-afm-drawer]');
    const $drawerTitle = $root.find('[data-afm-drawer-title]');
    const $preview = $root.find('[data-afm-preview]');
    const $meta = $root.find('[data-afm-meta]');
    const $drawerActions = $root.find('[data-afm-drawer-actions]');
    const $modal = $root.find('[data-afm-modal]');
    const $modalBody = $root.find('[data-afm-modal-body]');
    const $modalTitle = $root.find('.afm__modalTitle');
    const $modalPrimary = $root.find('[data-afm-action="modal-primary"]');
    const $productDocs = $root.find('[data-afm-product-docs]');
    const $productSelect = $root.find('[data-afm-product-select]');
    const $productDocsManage = $root.find('[data-afm-product-docs-manage]');
    const $productDocsNotice = $root.find('[data-afm-product-docs-notice]');
    const $productDocUpload = $('<input type="file" accept="*/*" data-afm-doc-upload style="display:none;">');
    $root.append($productDocUpload);
    const $search = $root.find('[data-afm-search]');
    const $dropzone = $root.find('[data-afm-dropzone]');
    const $content = $root.find('.afm__content');
    const $fileInput = $root.find('[data-afm-file-input]');
    const $uploadBtn = $root.find('[data-afm-action="upload"]');
    const $linkBtn = $root.find('[data-afm-action="new-link"]');
    const $frame = $root.find('.afm__frame');
    const $resizer = $root.find('[data-afm-resizer]');

    const productDocsFolderId = Number(AnchorFM.productDocsFolderId || 0);
    const state = {
        tab: 'files',
        tree: [],
        currentFolderId: 0,
        currentCapability: 'view',
        currentList: { folders: [], files: [] },
        selectedFileId: 0,
        selectedEntity: null,
        rolesDraft: [],
        usersDraft: [],
        modalMode: '',
        modalPayload: null,
        menuContext: null,
        search: '',
        products: [],
        productDocsDraft: [],
        expandedNodes: new Set(),
        parentById: {},
        childrenByParent: {},
        sortKey: 'name',
        sortDir: 'asc',
        expandedRows: {},
        selectedRows: new Set(),
    };

    const SIDEBAR_MIN = 220;
    const SIDEBAR_MAX = 420;
    let isResizing = false;

    function api(action, data) {
        return $.post(AnchorFM.ajax, Object.assign({ action, nonce: AnchorFM.nonce }, data || {}));
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function fmtSize(bytes) {
        const n = Number(bytes || 0);
        if (!n) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        let v = n;
        while (v >= 1024 && i < units.length - 1) {
            v /= 1024;
            i++;
        }
        return (Math.round(v * 10) / 10) + ' ' + units[i];
    }

    function setSidebarWidth(width) {
        if (!$root.length) return;
        $root.get(0).style.setProperty('--afm-sidebar-width', `${width}px`);
    }

    function clamp(num, min, max) {
        return Math.min(max, Math.max(min, num));
    }

    function capRank(cap) {
        switch (cap) {
            case 'manage': return 3;
            case 'upload': return 2;
            case 'view': return 1;
            default: return 0;
        }
    }

    function renderTreeNode(node) {
        const isOwner = node.ownerUserId && AnchorFM.user && node.ownerUserId === AnchorFM.user.id;
        const labelText = esc(node.name);
        const label = labelText + (node.isPrivate ? ' <span class="afm__tag">Private</span>' : '');
        const hasChildren = node.children && node.children.length;
        const isExpanded = hasChildren && state.expandedNodes.has(node.id);
        const childrenHtml = hasChildren
            ? `<div class="afm__treeChildren" data-afm-tree-children="${node.id}">${node.children.map(renderTreeNode).join('')}</div>`
            : '';

        return `
            <div class="afm__treeNode ${hasChildren ? (isExpanded ? 'is-expanded' : 'is-collapsed') : 'is-leaf'}" data-afm-folder-node="${node.id}">
                <div class="afm__treeRow">
                    ${hasChildren ? `<button type="button" class="afm__treeToggle" data-afm-tree-toggle="${node.id}" aria-label="Toggle folder" aria-expanded="${isExpanded ? 'true' : 'false'}">
                        <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </button>` : `<span class="afm__treeToggle is-placeholder" aria-hidden="true"></span>`}
                    <button type="button" class="afm__treeBtn" data-afm-open-folder="${node.id}" title="${labelText}">
                        <span class="dashicons dashicons-category afm__treeIcon" aria-hidden="true"></span>
                        <span class="afm__treeLabel">${label}</span>
                    </button>
                    ${(AnchorFM.isAdmin && !node.isProductDocs) ? `<button type="button" class="afm__kebab afm__kebab--tree" data-afm-folder-menu="${node.id}" aria-label="Folder actions">
                        <span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
                    </button>` : ''}
                </div>
                ${childrenHtml}
            </div>
        `;
    }

    function renderTree(tree) {
        const html = tree && tree.length ? tree.map(renderTreeNode).join('') : `<div class="afm__empty">${esc(AnchorFM.i18n.noFolders)}</div>`;
        $tree.html(html);
        highlightTreeSelection();
    }

    function rebuildTreeIndex() {
        state.parentById = {};
        state.childrenByParent = {};
        const walk = (nodes, parentId) => {
            (nodes || []).forEach(node => {
                state.parentById[node.id] = parentId;
                if (!state.childrenByParent[parentId]) state.childrenByParent[parentId] = [];
                state.childrenByParent[parentId].push(node.id);
                if (node.children && node.children.length) {
                    walk(node.children, node.id);
                }
            });
        };
        walk(state.tree || [], 0);
        const nextExpanded = new Set();
        state.expandedNodes.forEach(id => {
            if (Object.prototype.hasOwnProperty.call(state.parentById, id)) {
                nextExpanded.add(id);
            }
        });
        state.expandedNodes = nextExpanded;
    }

    function collapseBranch(nodeId) {
        state.expandedNodes.delete(nodeId);
        const kids = state.childrenByParent[nodeId] || [];
        kids.forEach(child => collapseBranch(child));
    }

    function openNode(nodeId) {
        const parentId = state.parentById[nodeId] || 0;
        const siblings = state.childrenByParent[parentId] || [];
        siblings.forEach(sibling => {
            if (sibling !== nodeId) collapseBranch(sibling);
        });
        state.expandedNodes.add(nodeId);
    }

    function openBranch(folderId) {
        const path = [];
        let current = Number(folderId);
        while (current && Object.prototype.hasOwnProperty.call(state.parentById, current)) {
            path.unshift(current);
            current = state.parentById[current];
        }
        let parentId = 0;
        path.forEach(id => {
            const siblings = state.childrenByParent[parentId] || [];
            siblings.forEach(sibling => {
                if (sibling !== id) collapseBranch(sibling);
            });
            state.expandedNodes.add(id);
            parentId = id;
        });
    }

    function toggleNode(nodeId) {
        const id = Number(nodeId);
        const kids = state.childrenByParent[id] || [];
        if (!kids.length) return;
        if (state.expandedNodes.has(id)) {
            collapseBranch(id);
        } else {
            openNode(id);
        }
        renderTree(state.tree);
    }

    function highlightTreeSelection() {
        $tree.find('[data-afm-open-folder]').removeClass('is-active');
        $tree.find(`[data-afm-open-folder="${state.currentFolderId}"]`).addClass('is-active');
    }

    function renderBreadcrumbs(crumbs) {
        let html = `<button type="button" class="afm__crumb" data-afm-crumb="0">Home</button>`;
        (crumbs || []).forEach(c => {
            html += `<span class="afm__crumbSep">/</span>`;
            html += `<button type="button" class="afm__crumb" data-afm-crumb="${c.id}">${esc(c.name)}</button>`;
        });
        $breadcrumbs.html(html);
    }

    function iconForMime(mime) {
        if (!mime) return 'media-default';
        if (mime.startsWith('image/')) return 'format-image';
        if (mime === 'application/pdf') return 'pdf';
        if (mime.startsWith('audio/')) return 'format-audio';
        if (mime.startsWith('video/')) return 'format-video';
        if (mime.startsWith('text/')) return 'media-text';
        return 'media-document';
    }

    function matchesSearch(name) {
        const q = (state.search || '').trim().toLowerCase();
        if (!q) return true;
        return String(name || '').toLowerCase().includes(q);
    }

    function kindLabel(kind, mime) {
        if (kind === 'folder') return 'Folder';
        if (kind === 'link') return 'Link';
        if (kind === 'video') return 'Video';
        return (mime || 'File');
    }

    function rowKey(kind, id) { return kind + ':' + id; }

    function rowIcon(item) {
        if (item.kind === 'folder') return 'category';
        if (item.kind === 'link') return 'admin-links';
        if (item.kind === 'video') return 'video-alt3';
        return iconForMime(item.mime);
    }

    function currentRows(list) {
        const rows = [];
        (list.folders || []).forEach(f => rows.push({ kind: 'folder', id: f.id, name: f.name, isPrivate: f.isPrivate }));
        (list.videos || []).forEach(v => rows.push({ kind: 'video', id: v.id, name: v.title, vimeoId: v.vimeoId, createdAt: v.createdAt }));
        (list.links || []).forEach(l => rows.push({ kind: 'link', id: l.id, name: l.title, url: l.url, createdAt: l.createdAt }));
        (list.files || []).forEach(f => rows.push({ kind: 'file', id: f.id, name: f.name, mime: f.mime, size: f.size, createdAt: f.createdAt }));
        return rows;
    }

    function sortRows(rows) {
        const dir = state.sortDir === 'desc' ? -1 : 1;
        const folderRank = r => (r.kind === 'folder' ? 0 : 1);
        return rows.slice().sort((a, b) => {
            if (folderRank(a) !== folderRank(b)) return folderRank(a) - folderRank(b);
            let av, bv;
            switch (state.sortKey) {
                case 'size': av = a.size || 0; bv = b.size || 0; break;
                case 'kind': av = kindLabel(a.kind, a.mime); bv = kindLabel(b.kind, b.mime); break;
                case 'modified': av = a.createdAt || ''; bv = b.createdAt || ''; break;
                default: av = (a.name || '').toLowerCase(); bv = (b.name || '').toLowerCase();
            }
            if (av < bv) return -1 * dir;
            if (av > bv) return 1 * dir;
            return 0;
        });
    }

    function rowHtml(item, depth) {
        const pad = 12 + (depth || 0) * 20;
        const selected = state.selectedRows.has(rowKey(item.kind, item.id)) ? ' is-selected' : '';
        const disclosure = item.kind === 'folder'
            ? `<button type="button" class="afm__rowDisclosure" data-afm-row-expand="${item.id}" aria-label="Expand"><span class="dashicons dashicons-arrow-right-alt2"></span></button>`
            : `<span class="afm__rowDisclosure afm__rowDisclosure--empty"></span>`;
        const sizeText = item.kind === 'file' ? esc(fmtSize(item.size)) : '—';
        const modified = item.createdAt ? esc(String(item.createdAt).slice(0, 10)) : '—';
        return `
            <div class="afm__row afm__row--${item.kind}${selected}"
                 data-afm-row="${item.kind}:${item.id}"
                 data-afm-row-kind="${item.kind}" data-afm-row-id="${item.id}"
                 style="--afm-row-pad:${pad}px" tabindex="-1">
                <div class="afm__rowCell afm__rowName">
                    ${disclosure}
                    <span class="afm__rowIcon dashicons dashicons-${rowIcon(item)}"></span>
                    <span class="afm__rowLabel" data-afm-row-label>${esc(item.name)}</span>
                </div>
                <div class="afm__rowCell afm__rowKind">${esc(kindLabel(item.kind, item.mime))}</div>
                <div class="afm__rowCell afm__rowSize">${sizeText}</div>
                <div class="afm__rowCell afm__rowModified">${modified}</div>
                <div class="afm__rowCell afm__rowActions">
                    <button type="button" class="afm__kebab" data-afm-row-menu="${item.kind}:${item.id}"><span class="dashicons dashicons-ellipsis"></span></button>
                </div>
            </div>`;
    }

    function headerHtml() {
        const arrow = k => state.sortKey === k ? (state.sortDir === 'asc' ? ' ▲' : ' ▼') : '';
        return `
            <div class="afm__listHead">
                <button type="button" class="afm__rowCell afm__rowName afm__sortBtn" data-afm-sort="name">Name${arrow('name')}</button>
                <button type="button" class="afm__rowCell afm__rowKind afm__sortBtn" data-afm-sort="kind">Kind${arrow('kind')}</button>
                <button type="button" class="afm__rowCell afm__rowSize afm__sortBtn" data-afm-sort="size">Size${arrow('size')}</button>
                <button type="button" class="afm__rowCell afm__rowModified afm__sortBtn" data-afm-sort="modified">Modified${arrow('modified')}</button>
                <div class="afm__rowCell afm__rowActions"></div>
            </div>`;
    }

    function renderList(list, capability) {
        state.currentList = list || { folders: [], files: [], links: [], videos: [] };
        state.currentCapability = capability || state.currentCapability;

        // Capability gating for the toolbar controls (restored from the old card grid).
        const canManage = capRank(state.currentCapability) >= 3;
        const canUpload = canManage && state.currentFolderId > 0;
        const canAddLink = canManage && state.currentFolderId > 0;
        $root.toggleClass('afm--canCreateFolder', canManage);
        $root.toggleClass('afm--canUpload', canUpload);
        if ($uploadBtn.length) { $uploadBtn.prop('disabled', !canUpload); }
        if ($linkBtn.length) { $linkBtn.prop('disabled', !canAddLink); }

        const rows = sortRows(currentRows(state.currentList));
        if (!rows.length) {
            $grid.html(headerHtml() + `<div class="afm__empty">${esc(AnchorFM.i18n.noFiles)}</div>`);
            return;
        }
        let html = headerHtml() + '<div class="afm__list" tabindex="0">';
        rows.forEach(item => {
            html += rowHtml(item, 0);
            if (item.kind === 'folder' && state.expandedRows[item.id]) {
                state.expandedRows[item.id].forEach(child => { html += rowHtml(child, 1); });
            }
        });
        html += '</div>';
        $grid.html(html);
        Object.keys(state.expandedRows).forEach(fid => {
            $grid.find(`[data-afm-row-expand="${fid}"]`).addClass('is-open');
        });
    }

    function openDrawer() {
        $drawer.addClass('is-open');
        $root.addClass('afm--drawerOpen');
    }

    function closeDrawer() {
        state.selectedFileId = 0;
        $drawer.removeClass('is-open');
        $root.removeClass('afm--drawerOpen');
        $drawerTitle.text('Select a file');
        $preview.html('');
        $meta.html('');
        $drawerActions.html('');
    }

    function openModal() {
        $modal.prop('hidden', false);
        $root.addClass('afm--modalOpen');
    }

    function closeModal() {
        $modal.prop('hidden', true);
        $root.removeClass('afm--modalOpen');
        state.selectedEntity = null;
        state.rolesDraft = [];
        state.modalMode = '';
        state.modalPayload = null;
        $modalBody.html('');
        $modal.find('.afm__modalPanel').removeClass('afm__modalPanel--viewer');
        $modal.find('.afm__modalFooter [data-afm-action="modal-primary"]').show();
        $modal.find('.afm__modalFooter [data-afm-action="close-modal"]').text('Cancel');
        $modal.find('.afm__viewerFooter').empty();
        if (typeof stopVideoTracking === 'function') stopVideoTracking();
    }

    function setModalPrimary(label, mode, payload) {
        $modalPrimary.text(label || 'Save');
        state.modalMode = mode || '';
        state.modalPayload = payload || null;
    }

    function openTextModal(opts) {
        const title = opts && opts.title ? String(opts.title) : 'Edit';
        const label = opts && opts.primaryLabel ? String(opts.primaryLabel) : 'Save';
        const placeholder = opts && opts.placeholder ? String(opts.placeholder) : '';
        const value = opts && typeof opts.value !== 'undefined' ? String(opts.value) : '';
        const mode = opts && opts.mode ? String(opts.mode) : 'text';
        const payload = opts && opts.payload ? opts.payload : null;

        $modalTitle.text(title);
        $modalBody.html(`
            <div class="afm__fieldRow">
                <label class="afm__label">${esc(title)}</label>
                <input type="text" class="afm__input" data-afm-modal-input placeholder="${esc(placeholder)}" value="${esc(value)}">
                <div class="afm__help">${esc(opts && opts.help ? opts.help : '')}</div>
            </div>
        `);
        setModalPrimary(label, mode, payload);
        openModal();
        window.setTimeout(() => $modalBody.find('[data-afm-modal-input]').trigger('focus'), 0);
    }

    function openConfirmModal(opts) {
        const title = opts && opts.title ? String(opts.title) : 'Confirm';
        const label = opts && opts.primaryLabel ? String(opts.primaryLabel) : 'Confirm';
        const mode = opts && opts.mode ? String(opts.mode) : 'confirm';
        const payload = opts && opts.payload ? opts.payload : null;
        const message = opts && opts.message ? String(opts.message) : '';

        $modalTitle.text(title);
        $modalBody.html(`<div class="afm__help">${esc(message)}</div>`);
        setModalPrimary(label, mode, payload);
        openModal();
    }

    function openLinkModal(opts) {
        const title = opts && opts.title ? String(opts.title) : 'Link';
        const label = opts && opts.primaryLabel ? String(opts.primaryLabel) : 'Save';
        const mode = opts && opts.mode ? String(opts.mode) : 'link';
        const payload = opts && opts.payload ? opts.payload : null;
        const linkTitle = opts && opts.linkTitle ? String(opts.linkTitle) : '';
        const linkUrl = opts && opts.linkUrl ? String(opts.linkUrl) : '';

        $modalTitle.text(title);
        $modalBody.html(`
            <div class="afm__fieldRow">
                <label class="afm__label">Title</label>
                <input type="text" class="afm__input" data-afm-link-title placeholder="Link title" value="${esc(linkTitle)}">
            </div>
            <div class="afm__fieldRow">
                <label class="afm__label">URL</label>
                <input type="url" class="afm__input" data-afm-link-url placeholder="https://example.com" value="${esc(linkUrl)}">
                <div class="afm__help">Use a full URL starting with https://</div>
            </div>
        `);
        setModalPrimary(label, mode, payload);
        openModal();
        window.setTimeout(() => $modalBody.find('[data-afm-link-title]').trigger('focus'), 0);
    }

    function loadFolder(folderId) {
        state.selectedRows.clear();
        state.currentFolderId = Number(folderId);
        openBranch(state.currentFolderId);
        renderTree(state.tree);
        closeDrawer();
        $root.addClass('afm--busy');
        $grid.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        $root.find('[data-afm-panel]').removeClass('is-active');
        $root.find('[data-afm-panel="files"]').addClass('is-active');
        api('anchor_fm_list', { folder_id: state.currentFolderId }).done(res => {
            if (!res || !res.success) return;
            renderBreadcrumbs(res.data.breadcrumbs);
            state.lastBreadcrumbs = res.data.breadcrumbs;
            renderList({ folders: res.data.folders, links: res.data.links, files: res.data.files, videos: res.data.videos }, res.data.capability);
            $root.trigger('anchorfm:folderLoaded', {
                folderId: state.currentFolderId,
                capability: res.data.capability,
                isProductDocs: res.data.isProductDocs
            });
            if (!state.deepLinkChecked) {
                state.deepLinkChecked = true;
                handleDeepLink();
            }
        }).always(() => {
            $root.removeClass('afm--busy');
        });
    }

    function reloadCurrentFolder() {
        loadFolder(state.currentFolderId);
    }

    function bootstrap() {
        $root.addClass('afm--busy');
        $tree.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        api('anchor_fm_bootstrap', {}).done(res => {
            if (!res || !res.success) return;
            state.tree = res.data.tree || [];
            rebuildTreeIndex();
            renderTree(state.tree);
            loadFolder(res.data.defaultFolderId);
            $root.trigger('anchorfm:bootstrapped', {
                tree: state.tree,
                defaultFolderId: res.data.defaultFolderId,
                productDocsFolderId: AnchorFM.productDocsFolderId || 0
            });
        }).always(() => {
            $root.removeClass('afm--busy');
        });
    }

    function ensureUploadProgress() {
        let $p = $root.find('[data-afm-upload-progress]');
        if (!$p.length) {
            $p = $(`<div class="afm__uploadProgress" data-afm-upload-progress>
                <div class="afm__uploadBar"><div class="afm__uploadBarFill"></div></div>
                <span class="afm__uploadPct">0%</span></div>`).appendTo($root);
        }
        $p.find('.afm__uploadBarFill').css('width', '0%');
        $p.find('.afm__uploadPct').text('0%');
        return $p;
    }

    function uploadFiles(files) {
        if (!files || !files.length) return;
        const canUpload = capRank(state.currentCapability) >= 2;
        if (!canUpload) return;

        const data = new FormData();
        data.append('action', 'anchor_fm_upload');
        data.append('nonce', AnchorFM.nonce);
        data.append('folder_id', String(state.currentFolderId));
        Array.from(files).forEach(f => data.append('files[]', f, f.name));

        $root.addClass('afm--busy');
        ensureUploadProgress();
        $.ajax({
            url: AnchorFM.ajax,
            method: 'POST',
            data,
            processData: false,
            contentType: false,
            xhr: function () {
                const xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function (evt) {
                    if (evt.lengthComputable) {
                        const pct = Math.round((evt.loaded / evt.total) * 100);
                        const $p = $root.find('[data-afm-upload-progress]');
                        $p.find('.afm__uploadBarFill').css('width', pct + '%');
                        $p.find('.afm__uploadPct').text(pct + '%');
                    }
                }, false);
                return xhr;
            },
        }).always(() => $root.removeClass('afm--busy'))
            .done(() => {
                loadFolder(state.currentFolderId);
                $root.find('[data-afm-upload-progress]').remove();
            })
            .fail(() => {
                $root.find('[data-afm-upload-progress]').remove();
            });
    }

    function loadFilePreview(fileId) {
        state.selectedFileId = Number(fileId);
        openDrawer();

        $drawerTitle.text('Loading…');
            $preview.html('<div class="afm__skeleton afm__skeleton--preview"></div>');
            $meta.html('<div class="afm__skeleton afm__skeleton--meta"></div>');
            $drawerActions.html('');

        api('anchor_fm_preview', { file_id: state.selectedFileId }).done(res => {
            if (!res || !res.success) return;
            const file = res.data.file;
            const prev = res.data.preview;
            const cap = res.data.capability;

            $drawerTitle.text(file.name);

            let previewHtml = '';
            if (prev.type === 'image') {
                previewHtml = `<img class="afm__imgPreview" src="${esc(prev.inlineUrl)}" alt="${esc(file.name)}">`;
            } else if (prev.type === 'pdf') {
                previewHtml = `<iframe class="afm__pdfPreview" src="${esc(prev.inlineUrl)}" title="${esc(file.name)}"></iframe>`;
            } else if (prev.type === 'text') {
                previewHtml = `<pre class="afm__textPreview">${esc(prev.textExcerpt || '')}</pre>`;
            } else {
                previewHtml = `<div class="afm__noPreview">
                    <span class="dashicons dashicons-${iconForMime(file.mime)}" aria-hidden="true"></span>
                    <div>No preview available</div>
                </div>`;
            }
            $preview.html(previewHtml);

            $meta.html(`
                <div class="afm__metaRow"><div class="afm__metaKey">Type</div><div class="afm__metaVal">${esc(file.mime)}</div></div>
                <div class="afm__metaRow"><div class="afm__metaKey">Size</div><div class="afm__metaVal">${fmtSize(file.size)}</div></div>
                <div class="afm__metaRow"><div class="afm__metaKey">Uploaded</div><div class="afm__metaVal">${esc(file.createdAt)}</div></div>
            `);

            const canManage = capRank(cap) >= 3;
            $drawerActions.html(`
                <a class="afm__btn afm__btn--primary" href="${esc(prev.downloadUrl)}">
                    <span class="dashicons dashicons-download" aria-hidden="true"></span>
                    ${esc(AnchorFM.i18n.download)}
                </a>
                ${canManage ? `<button type="button" class="afm__btn afm__btn--secondary" data-afm-action="permissions-file" data-afm-file="${file.id}">
                    <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                    ${esc(AnchorFM.i18n.permissions)}
                </button>` : ''}
                ${canManage ? `<button type="button" class="afm__btn afm__btn--danger" data-afm-action="delete-file" data-afm-file="${file.id}">
                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                    ${esc(AnchorFM.i18n.delete)}
                </button>` : ''}
            `);
        });
    }

    function openViewer(kind, id) {
        if (kind === 'file') return openFileViewer(id);
        if (kind === 'video') { if (typeof openVideoViewer === 'function') return openVideoViewer(id); }
    }

    function metaRow(k, v) {
        return `<div class="afm__metaRow"><div class="afm__metaKey">${esc(k)}</div><div class="afm__metaVal">${esc(v)}</div></div>`;
    }

    function openFileViewer(fileId) {
        api('anchor_fm_preview', { file_id: fileId }).then(res => {
            if (!res || !res.success) {
                if (typeof showAccessDenied === 'function') showAccessDenied('file', fileId, '');
                return;
            }
            const d = res.data, file = d.file, prev = d.preview;
            let body = '<div class="afm__viewer">';
            if (prev.type === 'image') {
                body += `<div class="afm__viewerStage"><img class="afm__viewerImg" src="${esc(prev.inlineUrl)}" alt="${esc(file.name)}"></div>`;
            } else if (prev.type === 'pdf') {
                body += `<div class="afm__viewerStage"><iframe class="afm__viewerPdf" src="${esc(prev.inlineUrl)}"></iframe></div>`;
            } else if (prev.type === 'text') {
                body += `<pre class="afm__viewerText">${esc(prev.textExcerpt || '')}</pre>`;
            } else {
                body += `<div class="afm__viewerNone"><span class="dashicons dashicons-${iconForMime(file.mime)}"></span><div>No preview available</div></div>`;
            }
            body += '<div class="afm__viewerMeta">' +
                metaRow('Type', file.mime) +
                metaRow('Size', fmtSize(file.size)) +
                metaRow('Added', String(file.createdAt || '').slice(0, 10)) +
                '</div></div>';
            const footer = prev.downloadUrl
                ? `<a class="afm__btn afm__btn--primary" href="${esc(prev.downloadUrl)}"><span class="dashicons dashicons-download"></span> Download</a>`
                : '';
            openViewerModal(esc(file.name), body, footer);
        });
    }

    function openViewerModal(titleHtml, bodyHtml, footerHtml) {
        $modal.find('.afm__modalTitle').html(titleHtml);
        $modalBody.html(bodyHtml);
        $modal.find('.afm__modalPanel').addClass('afm__modalPanel--viewer');
        const $footer = $modal.find('.afm__modalFooter');
        $footer.find('[data-afm-action="modal-primary"]').hide();
        $footer.find('[data-afm-action="close-modal"]').text('Close');
        let $vf = $footer.find('.afm__viewerFooter');
        if (!$vf.length) { $vf = $('<div class="afm__viewerFooter"></div>').prependTo($footer); }
        $vf.html(footerHtml || '');
        $modal.prop('hidden', false);
        $root.addClass('afm--modalOpen');
        state.modalMode = 'viewer';
    }

    let activePlayer = null;

    function openVideoViewer(videoId) {
        const v = findRow('video', videoId);
        if (!v || !v.vimeoId) {
            if (typeof showAccessDenied === 'function') showAccessDenied('video', videoId, '');
            return;
        }
        const playerId = 'afmVPlayer_' + videoId;
        let body = `<div class="afm__vplayer"><div id="${playerId}" class="afm__vplayerFrame" data-afm-video-frame></div></div>`;
        if (AnchorFM.isAdmin) {
            body += `<div class="afm__vhistory" data-afm-video-history><div class="afm__sectionTitle">Watch history</div><div class="afm__vhistoryBody">Loading…</div></div>`;
        }
        openViewerModal(esc(v.name), body, '');
        mountVimeoPlayer(playerId, v.vimeoId, videoId);
        if (AnchorFM.isAdmin) loadVideoHistory(videoId);
    }

    function mountVimeoPlayer(elId, vimeoId, videoId) {
        if (!window.Vimeo || !window.Vimeo.Player) return;
        activePlayer = new window.Vimeo.Player(elId, { id: Number(vimeoId), responsive: true });
        startVideoTracking(activePlayer, videoId);
    }

    let trackState = null;

    function startVideoTracking(player, videoId) {
        trackState = { videoId: videoId, lastTime: 0, accum: 0, duration: 0, newSession: true };
        player.getDuration().then(d => { trackState.duration = Math.floor(d || 0); }).catch(() => {});

        player.on('timeupdate', function (data) {
            if (!trackState) return;
            const t = Math.floor(data.seconds || 0);
            const delta = t - trackState.lastTime;
            if (delta > 0 && delta <= 2) trackState.accum += delta;
            trackState.lastTime = t;
            if (trackState.accum >= 10) flushProgress(false);
        });
        player.on('pause', function () { flushProgress(false); });
        player.on('ended', function () { flushProgress(false); });
    }

    function flushProgress(force) {
        if (!trackState) return;
        if (!force && trackState.accum <= 0) return;
        const payload = {
            video_id: trackState.videoId,
            point: trackState.lastTime,
            delta: trackState.accum,
            duration: trackState.duration,
            new_session: trackState.newSession ? 1 : 0,
        };
        trackState.accum = 0;
        trackState.newSession = false;
        api('anchor_fm_vimeo_progress', payload);
    }

    function stopVideoTracking() {
        flushProgress(true);
        if (activePlayer && activePlayer.unload) { try { activePlayer.unload(); } catch (e) {} }
        activePlayer = null;
        trackState = null;
    }

    function fmtMMSS(total) {
        total = Math.max(0, Number(total) || 0);
        const m = Math.floor(total / 60), s = total % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function loadVideoHistory(videoId) {
        api('anchor_fm_vimeo_history', { video_id: videoId }).then(res => {
            const $body = $modalBody.find('.afm__vhistoryBody');
            if (!res || !res.success) { $body.text('Unable to load history.'); return; }
            const rows = res.data.history || [];
            if (!rows.length) { $body.html('<div class="afm__empty">No views yet.</div>'); return; }
            let html = '<div class="afm__vhistoryTable">';
            rows.forEach(r => {
                html += `<div class="afm__vhistoryRow">
                    <span class="afm__vhName">${esc(r.name)}</span>
                    <span class="afm__vhPct">${esc(r.percent)}%</span>
                    <span class="afm__vhTime">${esc(fmtMMSS(r.totalSeconds))}</span>
                    <span class="afm__vhDate">${esc(String(r.lastViewedAt || '').slice(0,10))}</span>
                </div>`;
            });
            html += '</div>';
            $body.html(html);
        });
    }

    function openPermissions(entityType, entityId) {
        state.selectedEntity = { entityType, entityId: Number(entityId) };
        $modalBody.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        $modalTitle.text(AnchorFM.i18n.permissions || 'Permissions');
        setModalPrimary('Save', 'save-permissions', null);
        openModal();

        api('anchor_fm_get_permissions', { entity_type: entityType, entity_id: Number(entityId) }).done(res => {
            if (!res || !res.success) {
                const msg = res && res.data && res.data.message ? String(res.data.message) : 'Unable to load permissions.';
                $modalBody.html(`<div class="afm__empty">${esc(msg)}</div>`);
                setModalPrimary('Close', 'noop-close', null);
                return;
            }
            state.rolesDraft = Array.isArray(res.data.roles) ? res.data.roles.slice() : [];
            state.usersDraft = Array.isArray(res.data.users) ? res.data.users.slice() : [];
            renderPermissionsEditor();
        });
    }

    function renderPermissionsEditor() {
        const roles = Array.isArray(AnchorFM.roles) ? AnchorFM.roles : [];
        const selected = new Set((state.rolesDraft || []).map(r => String(r)));
        const users = Array.isArray(state.usersDraft) ? state.usersDraft : [];

        const html = `
            <div class="afm__permWrap">
                <div class="afm__help">Select which roles or users can view this ${esc(state.selectedEntity ? state.selectedEntity.entityType : 'item')}. Admins always have access.</div>
                <div class="afm__permList">
                    ${roles.length ? roles.map(r => `
                        <label class="afm__permRow">
                            <input type="checkbox" class="afm__checkbox" data-afm-role="${esc(r.key)}" ${selected.has(r.key) ? 'checked' : ''}>
                            <span class="afm__permLabel">${esc(r.label)}</span>
                            <span class="afm__mono">${esc(r.key)}</span>
                        </label>
                    `).join('') : `<div class="afm__empty afm__empty--tight">No roles available.</div>`}
                </div>

                <div class="afm__fieldRow">
                    <label class="afm__label">Add user access</label>
                    <div class="afm__fieldInline">
                        <input type="text" class="afm__input" placeholder="Search users" data-afm-user-search>
                        <button type="button" class="afm__btn afm__btn--secondary" data-afm-action="add-user-perm">Add</button>
                    </div>
                    <div class="afm__suggest" data-afm-suggest hidden></div>
                </div>

                <div class="afm__permList">
                    ${users.length ? users.map((u, idx) => `
                        <div class="afm__permRow">
                            <span class="afm__permLabel">${esc(u.name || u.id)}</span>
                            <span class="afm__mono">${esc(u.id)}</span>
                            <button type="button" class="afm__iconBtn" data-afm-action="remove-user-perm" data-afm-user-idx="${idx}" aria-label="Remove">
                                <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                            </button>
                        </div>
                    `).join('') : `<div class="afm__empty afm__empty--tight">No individual users added.</div>`}
                </div>
            </div>
        `;
        $modalBody.html(html);
    }

    function savePermissions() {
        if (!state.selectedEntity) return;
        const roles = [];
        $modalBody.find('[data-afm-role]').each(function () {
            const $cb = $(this);
            if ($cb.is(':checked')) roles.push(String($cb.data('afm-role')));
        });
        api('anchor_fm_set_permissions', {
            entity_type: state.selectedEntity.entityType,
            entity_id: state.selectedEntity.entityId,
            roles,
        }).done(res => {
            if (!res || !res.success) return;
            closeModal();
            bootstrap();
        });
    }

    function handleModalPrimary() {
        if (state.modalMode === 'save-permissions') {
            savePermissions();
            return;
        }
        if (state.modalMode === 'create-folder') {
            const name = String($modalBody.find('[data-afm-modal-input]').val() || '').trim();
            if (!name) return;
            api('anchor_fm_create_folder', { parent_id: state.currentFolderId, name }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                bootstrap();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'create-link') {
            const title = String($modalBody.find('[data-afm-link-title]').val() || '').trim();
            const url = String($modalBody.find('[data-afm-link-url]').val() || '').trim();
            if (!title || !url) return;
            api('anchor_fm_create_link', { folder_id: state.currentFolderId, title, url }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'rename-folder') {
            const name = String($modalBody.find('[data-afm-modal-input]').val() || '').trim();
            const folderId = state.modalPayload && state.modalPayload.folderId ? Number(state.modalPayload.folderId) : 0;
            if (!folderId || !name) return;
            api('anchor_fm_rename_folder', { folder_id: folderId, name }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                bootstrap();
            });
            return;
        }
        if (state.modalMode === 'edit-link') {
            const title = String($modalBody.find('[data-afm-link-title]').val() || '').trim();
            const url = String($modalBody.find('[data-afm-link-url]').val() || '').trim();
            const linkId = state.modalPayload && state.modalPayload.linkId ? Number(state.modalPayload.linkId) : 0;
            if (!linkId || !title || !url) return;
            api('anchor_fm_update_link', { link_id: linkId, title, url }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'delete-folder') {
            const folderId = state.modalPayload && state.modalPayload.folderId ? Number(state.modalPayload.folderId) : 0;
            if (!folderId) return;
            api('anchor_fm_delete_folder', { folder_id: folderId }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                bootstrap();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'delete-link') {
            const linkId = state.modalPayload && state.modalPayload.linkId ? Number(state.modalPayload.linkId) : 0;
            if (!linkId) return;
            api('anchor_fm_delete_link', { link_id: linkId }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'delete-file') {
            const fileId = state.modalPayload && state.modalPayload.fileId ? Number(state.modalPayload.fileId) : 0;
            if (!fileId) return;
            api('anchor_fm_delete_file', { file_id: fileId }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                closeDrawer();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'delete-video') {
            const videoId = state.modalPayload && state.modalPayload.videoId ? Number(state.modalPayload.videoId) : 0;
            if (!videoId) return;
            api('anchor_fm_vimeo_delete', { video_id: videoId }).done(res => {
                if (!res || !res.success) return;
                closeModal();
                loadFolder(state.currentFolderId);
            });
            return;
        }
        if (state.modalMode === 'new-video') {
            const title = $modalBody.find('[data-afm-video-title]').val();
            const src = $modalBody.find('[data-afm-video-src]').val();
            api('anchor_fm_vimeo_add', { folder_id: state.currentFolderId, title: title, vimeo: src }).then(res => {
                if (!res || !res.success) {
                    $modalBody.find('[data-afm-video-notice]').prop('hidden', false).text((res && res.data && res.data.message) || 'Could not add video');
                    return;
                }
                closeModal();
                reloadCurrentFolder();
            });
            return;
        }
        if (state.modalMode === 'noop-close') {
            closeModal();
            return;
        }
    }

    // Small popup menu for folder/file actions
    const $menu = $('<div class="afm__menu" data-afm-menu hidden></div>');
    $root.append($menu);

    function closeMenu() {
        $menu.prop('hidden', true).html('');
        state.menuContext = null;
    }

    function openMenu(anchorEl, items, context) {
        state.menuContext = context || null;
        const rect = anchorEl.getBoundingClientRect();
        const rootRect = $root[0].getBoundingClientRect();

        const html = (items || []).map(it => `
            <button type="button" class="afm__menuItem ${it.danger ? 'is-danger' : ''} ${it.disabled ? 'is-disabled' : ''}" data-afm-menu-action="${esc(it.action)}" ${it.disabled ? 'disabled' : ''}>
                <span class="dashicons dashicons-${esc(it.icon || 'admin-generic')}" aria-hidden="true"></span>
                <span>${esc(it.label)}</span>
            </button>
        `).join('');

        $menu.html(html).prop('hidden', false);

        // Position relative to root
        const top = rect.bottom - rootRect.top + 6;
        const left = rect.right - rootRect.left - 220;
        $menu.css({ top: Math.max(8, top) + 'px', left: Math.max(8, left) + 'px' });
    }

    function findFolderName(folderId) {
        const stack = (state.tree || []).slice();
        while (stack.length) {
            const n = stack.shift();
            if (Number(n.id) === Number(folderId)) return n.name;
            if (n.children && n.children.length) stack.push.apply(stack, n.children);
        }
        return '';
    }

    function findLinkById(linkId) {
        const links = (state.currentList && state.currentList.links) ? state.currentList.links : [];
        return links.find(l => Number(l.id) === Number(linkId)) || null;
    }

    $menu.on('click', '[data-afm-menu-action]', function () {
        const action = String($(this).data('afm-menu-action'));
        const ctx = state.menuContext || {};
        if ($(this).hasClass('is-disabled')) {
            closeMenu();
            return;
        }
        closeMenu();

        // Row (Finder list) context menu actions. Only the row menu sets ctx.kind.
        if (ctx.kind) {
            const k = ctx.kind;
            const vid = Number(ctx.id);
            if (action === 'open-folder') { loadFolder(vid); return; }
            if (action === 'open-file') { if (typeof openViewer === 'function') openViewer('file', vid); return; }
            if (action === 'open-video') { if (typeof openViewer === 'function') openViewer('video', vid); return; }
            if (action === 'open-link') { const l = findRow('link', vid); if (l && l.url) window.open(l.url, '_blank', 'noopener'); return; }
            if (action === 'show-in-folder') {
                const target = (state.searchFolderById && state.searchFolderById[k + ':' + vid]);
                const dest = (typeof target === 'number') ? target : state.currentFolderId;
                $search.val(''); state.search = '';
                loadFolder(dest);
                flashRow(k, vid);
                return;
            }
            if (action === 'copy-share-link') { if (typeof copyShareLink === 'function') copyShareLink(k, vid); return; }
            if (action === 'rename') { if (typeof startInlineRename === 'function') startInlineRename(k, vid); return; }
            if (action === 'permissions') { openPermissions(k, vid); return; }
            if (action === 'edit-link') {
                const link = findRow('link', vid);
                openLinkModal({
                    title: 'Edit link',
                    primaryLabel: 'Save',
                    mode: 'edit-link',
                    payload: { linkId: vid },
                    linkTitle: link ? (link.name || '') : '',
                    linkUrl: link ? (link.url || '') : '',
                });
                return;
            }
            if (action === 'delete') {
                if (k === 'folder') {
                    openConfirmModal({ title: 'Delete folder', primaryLabel: 'Delete', mode: 'delete-folder', payload: { folderId: vid }, message: 'This will permanently delete the folder and all its contents.' });
                } else if (k === 'file') {
                    openConfirmModal({ title: 'Delete file', primaryLabel: 'Delete', mode: 'delete-file', payload: { fileId: vid }, message: 'This will permanently delete the file.' });
                } else if (k === 'link') {
                    openConfirmModal({ title: 'Delete link', primaryLabel: 'Delete', mode: 'delete-link', payload: { linkId: vid }, message: 'This will remove the link from this folder.' });
                } else if (k === 'video') {
                    openConfirmModal({ title: 'Delete video', primaryLabel: 'Delete', mode: 'delete-video', payload: { videoId: vid }, message: 'This will permanently delete the video.' });
                }
                return;
            }
            return;
        }

        if (action === 'rename-folder') {
            const folderId = Number(ctx.folderId || 0);
            if (!folderId) return;
            openTextModal({
                title: 'Rename folder',
                primaryLabel: 'Save',
                placeholder: 'Folder name',
                value: findFolderName(folderId),
                mode: 'rename-folder',
                payload: { folderId },
            });
            return;
        }

        if (action === 'permissions-folder') {
            const folderId = Number(ctx.folderId || 0);
            if (!folderId) return;
            openPermissions('folder', folderId);
            return;
        }

        if (action === 'download-folder') {
            const folderId = Number(ctx.folderId || 0);
            if (!folderId) return;
            window.location = `${AnchorFM.ajax}?action=anchor_fm_download_folder&folder_id=${folderId}&nonce=${AnchorFM.nonce}`;
            return;
        }

        if (action === 'delete-folder') {
            const folderId = Number(ctx.folderId || 0);
            if (!folderId) return;
            openConfirmModal({
                title: 'Delete folder',
                primaryLabel: 'Delete',
                mode: 'delete-folder',
                payload: { folderId },
                message: 'This will permanently delete the folder and all its contents.',
            });
            return;
        }

        if (action === 'ungroup-folder') {
            const folderId = Number(ctx.folderId || 0);
            if (!folderId) return;
            api('anchor_fm_move_folder', { folder_id: folderId, target_folder_id: 0 }).done(res => {
                if (res && res.success) {
                    bootstrap();
                    loadFolder(folderId);
                }
            });
            return;
        }

        if (action === 'edit-link') {
            const linkId = Number(ctx.linkId || 0);
            if (!linkId) return;
            const link = findLinkById(linkId);
            if (!link) return;
            openLinkModal({
                title: 'Edit link',
                primaryLabel: 'Save',
                mode: 'edit-link',
                payload: { linkId },
                linkTitle: link.title || '',
                linkUrl: link.url || '',
            });
            return;
        }

        if (action === 'delete-link') {
            const linkId = Number(ctx.linkId || 0);
            if (!linkId) return;
            openConfirmModal({
                title: 'Delete link',
                primaryLabel: 'Delete',
                mode: 'delete-link',
                payload: { linkId },
                message: 'This will remove the link from this folder.',
            });
            return;
        }

        if (action === 'open-file') {
            const fileId = Number(ctx.fileId || 0);
            if (!fileId) return;
            openViewer('file', fileId);
            return;
        }

        if (action === 'permissions-file') {
            const fileId = Number(ctx.fileId || 0);
            if (!fileId) return;
            openPermissions('file', fileId);
            return;
        }

        if (action === 'delete-file') {
            const fileId = Number(ctx.fileId || 0);
            if (!fileId) return;
            openConfirmModal({
                title: 'Delete file',
                primaryLabel: 'Delete',
                mode: 'delete-file',
                payload: { fileId },
                message: 'This will permanently delete the file.',
            });
            return;
        }
    });

    function buildRowMenu(kind, id) {
        const items = [];
        if (kind === 'folder') items.push({ action: 'open-folder', icon: 'category', label: 'Open' });
        if (kind === 'file') items.push({ action: 'open-file', icon: 'visibility', label: 'Open' });
        if (kind === 'video') items.push({ action: 'open-video', icon: 'video-alt3', label: 'Play' });
        if (kind === 'link') items.push({ action: 'open-link', icon: 'admin-links', label: 'Open' });
        items.push({ action: 'show-in-folder', icon: 'category', label: 'Show in enclosing folder' });
        if (kind === 'file' || kind === 'video') items.push({ action: 'copy-share-link', icon: 'admin-links', label: 'Copy share link' });
        if (AnchorFM.isAdmin) {
            if (kind !== 'link') items.push({ action: 'rename', icon: 'edit', label: 'Rename' });
            if (kind === 'link') items.push({ action: 'edit-link', icon: 'edit', label: 'Edit' });
            if (kind === 'folder' || kind === 'file') items.push({ action: 'permissions', icon: 'shield', label: 'Permissions' });
            items.push({ action: 'delete', icon: 'trash', label: 'Delete', danger: true });
        }
        return items;
    }

    function openRowMenu(anchorEl, kind, id) {
        openMenu(anchorEl, buildRowMenu(kind, id), { kind: kind, id: Number(id) });
    }

    $root.on('click', '[data-afm-row-menu]', function (e) {
        e.stopPropagation();
        const parts = String($(this).data('afm-row-menu')).split(':');
        openRowMenu(this, parts[0], parts[1]);
    });
    $root.on('contextmenu', '[data-afm-row]', function (e) {
        e.preventDefault();
        openRowMenu(this, $(this).data('afm-row-kind'), $(this).data('afm-row-id'));
    });

    function flashRow(kind, id) {
        setTimeout(() => {
            const $r = $grid.find(`[data-afm-row="${kind}:${id}"]`);
            if (!$r.length) return;
            $r[0].scrollIntoView({ block: 'center' });
            $r.addClass('is-flash');
            setTimeout(() => $r.removeClass('is-flash'), 1400);
        }, 400);
    }

    // Events
    const filesTabActive = () => (!portalMode || $root.hasClass('apfm--tab-files'));
    const ignoreIfNotFilesTab = (evt) => {
        if (!filesTabActive()) {
            if (evt) evt.preventDefault();
            return true;
        }
        return false;
    };

    const getPageX = (evt) => {
        const oe = evt && evt.originalEvent;
        if (oe && oe.touches && oe.touches.length) return oe.touches[0].pageX;
        return evt.pageX;
    };

    const onResize = (evt) => {
        if (!isResizing) return;
        const pageX = getPageX(evt);
        const frameOffset = $frame.offset();
        if (!frameOffset || pageX === undefined) return;
        const nextWidth = clamp(pageX - frameOffset.left, SIDEBAR_MIN, SIDEBAR_MAX);
        setSidebarWidth(nextWidth);
        if (evt && evt.preventDefault) evt.preventDefault();
    };

    const stopResize = () => {
        if (!isResizing) return;
        isResizing = false;
        $root.removeClass('afm--resizing');
        $(document).off('.afmResize');
    };

    if ($resizer.length && $frame.length) {
        $resizer.on('mousedown', function (e) {
            if (e.which && e.which !== 1) return;
            e.preventDefault();
            isResizing = true;
            $root.addClass('afm--resizing');
            $(document).on('mousemove.afmResize', onResize);
            $(document).on('mouseup.afmResize', stopResize);
            onResize(e);
        });

        $resizer.on('touchstart', function (e) {
            e.preventDefault();
            isResizing = true;
            $root.addClass('afm--resizing');
            $(document).on('touchmove.afmResize', onResize);
            $(document).on('touchend.afmResize touchcancel.afmResize', stopResize);
            onResize(e);
        });
    }

    $root.on('click', '[data-afm-tree-toggle]', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (ignoreIfNotFilesTab(e)) return;
        toggleNode($(this).data('afm-tree-toggle'));
    });

    $root.on('click', '[data-afm-open-folder]', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        loadFolder($(this).data('afm-open-folder'));
    });

    $root.on('click', '[data-afm-folder-card]', function (e) {
        const id = $(this).data('afm-folder-card');
        if ($(e.target).closest('[data-afm-folder-menu]').length) return;
        if (ignoreIfNotFilesTab(e)) return;
        loadFolder(id);
    });

    $root.on('click', '[data-afm-file-card]', function (e) {
        const id = $(this).data('afm-file-card');
        if ($(e.target).closest('[data-afm-file-menu]').length) return;
        openViewer('file', id);
    });

    $root.on('click', '[data-afm-link-card]', function (e) {
        const id = $(this).data('afm-link-card');
        if ($(e.target).closest('[data-afm-link-menu]').length) return;
        const link = findLinkById(id);
        if (link && link.url) {
            window.open(link.url, '_blank', 'noopener');
        }
    });

    $root.on('click', '[data-afm-action="close-drawer"]', closeDrawer);
    $root.on('click', '[data-afm-action="close-modal"]', closeModal);
    $root.on('click', '[data-afm-action="modal-primary"]', handleModalPrimary);

    $(document).on('click', function (e) {
        if ($menu.prop('hidden')) return;
        if ($(e.target).closest('[data-afm-menu]').length) return;
        if ($(e.target).closest('[data-afm-folder-menu],[data-afm-file-menu],[data-afm-link-menu]').length) return;
        closeMenu();
    });

    $root.on('click', '[data-afm-action="upload"]', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        $fileInput.trigger('click');
    });
    $fileInput.on('change', function (e) {
        if (ignoreIfNotFilesTab(e)) {
            $(this).val('');
            return;
        }
        uploadFiles(this.files);
        $(this).val('');
    });

    let dragDepth = 0;
    $content.on('dragenter', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (ignoreIfNotFilesTab(e)) return;
        if (capRank(state.currentCapability) < 2) return;
        dragDepth++;
        $root.addClass('afm--drag');
    });
    $content.on('dragover', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (ignoreIfNotFilesTab(e)) return;
        if (capRank(state.currentCapability) < 2) return;
        $root.addClass('afm--drag');
    });
    $content.on('dragleave', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (ignoreIfNotFilesTab(e)) return;
        if (capRank(state.currentCapability) < 2) return;
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) $root.removeClass('afm--drag');
    });
    $content.on('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (ignoreIfNotFilesTab(e)) return;
        dragDepth = 0;
        $root.removeClass('afm--drag');
        if (capRank(state.currentCapability) < 2) return;
        if (e.originalEvent && e.originalEvent.dataTransfer) {
            uploadFiles(e.originalEvent.dataTransfer.files);
        }
    });

    let searchTimer = null;
    $search.on('input', function () {
        const term = String($(this).val() || '').trim();
        state.search = term;
        clearTimeout(searchTimer);
        if (term.length < 2) {
            renderList(state.currentList, state.currentCapability);
            renderBreadcrumbs(state.lastBreadcrumbs || []);
            return;
        }
        searchTimer = setTimeout(() => runGlobalSearch(term), 250);
    });

    function runGlobalSearch(term) {
        api('anchor_fm_search', { term: term }).then(res => {
            if (!res || !res.success) return;
            renderSearchResults(res.data.results || [], res.data.truncated, term);
        });
    }

    function renderSearchResults(results, truncated, term) {
        $breadcrumbs.html(`<span class="afm__crumb is-static">Search: “${esc(term)}”</span>`);
        state.searchFolderById = {};
        state.searchRowsByKey = {};
        if (!results.length) {
            $grid.html(`<div class="afm__empty">No matches for “${esc(term)}”.</div>`);
            return;
        }
        let html = headerHtml() + '<div class="afm__list afm__list--search" tabindex="0">';
        results.forEach(r => {
            const item = { kind: r.kind, id: r.id, name: r.name, mime: r.mime, size: r.size, url: r.url, vimeoId: r.vimeoId, createdAt: '' };
            // append the enclosing-folder path under the row
            const base = rowHtml(item, 0);
            html += base.replace('</div>\n            </div>', `</div><div class="afm__rowPath">${esc(r.path || 'Home')}</div>\n            </div>`);
            state.searchFolderById[r.kind + ':' + r.id] = r.folderId;
            // Cache the row so findRow() can resolve items opened from search
            // (e.g. play a video, open a link) without a browse listing.
            state.searchRowsByKey[r.kind + ':' + r.id] = item;
        });
        if (truncated) html += `<div class="afm__empty">Showing the first results — refine your search to narrow further.</div>`;
        html += '</div>';
        $grid.html(html);
    }

    $root.on('click', '[data-afm-action="new-folder"]', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        closeMenu();
        openTextModal({
            title: 'New folder',
            primaryLabel: 'Create',
            placeholder: 'Folder name',
            value: '',
            mode: 'create-folder',
            help: 'Folders inherit view permissions from parent folders.',
        });
    });

    $root.on('click', '[data-afm-action="new-link"]', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        if (state.currentFolderId <= 0) return;
        closeMenu();
        openLinkModal({
            title: 'New link',
            primaryLabel: 'Add link',
            mode: 'create-link',
        });
    });

    $root.on('click', '[data-afm-action="new-video"]', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        if (state.currentFolderId <= 0) return;
        closeMenu();
        openVideoModal();
    });

    function openVideoModal() {
        const body = `
            <div class="afm__formRow"><label class="afm__label">Title</label>
                <input type="text" class="afm__input" data-afm-video-title placeholder="Video title"></div>
            <div class="afm__formRow"><label class="afm__label">Vimeo URL or ID</label>
                <input type="text" class="afm__input" data-afm-video-src placeholder="https://vimeo.com/123456789"></div>
            <div class="afm__notice" data-afm-video-notice hidden></div>`;
        $modalTitle.text('New video');
        $modalBody.html(body);
        $modalPrimary.show();
        setModalPrimary('Add', 'new-video', null);
        openModal();
        window.setTimeout(() => $modalBody.find('[data-afm-video-title]').trigger('focus'), 0);
    }

    $root.on('click', '[data-afm-folder-menu]', function () {
        if (!AnchorFM.isAdmin) return;
        const folderId = Number($(this).data('afm-folder-menu'));
        if (productDocsFolderId && folderId === productDocsFolderId) return;
        closeMenu();
        const parentId = state.parentById ? (state.parentById[folderId] || 0) : 0;
        const items = [
            { action: 'rename-folder', label: 'Rename', icon: 'edit' },
            { action: 'permissions-folder', label: 'Permissions', icon: 'lock' },
            { action: 'download-folder', label: 'Download', icon: 'download' },
        ];
        items.push({ action: 'ungroup-folder', label: 'Move to top', icon: 'admin-site', disabled: parentId === 0 });
        items.push({ action: 'delete-folder', label: 'Delete', icon: 'trash', danger: true });
        openMenu(this, items, { folderId, parentId });
    });

    $root.on('click', '[data-afm-action="delete-file"]', function () {
        const fileId = Number($(this).data('afm-file'));
        openConfirmModal({
            title: 'Delete file',
            primaryLabel: 'Delete',
            mode: 'delete-file',
            payload: { fileId },
            message: 'This will permanently delete the file.',
        });
    });

    $root.on('click', '[data-afm-file-menu]', function () {
        if (!AnchorFM.isAdmin) return;
        const fileId = Number($(this).data('afm-file-menu'));
        closeMenu();
        openMenu(this, [
            { action: 'open-file', label: 'Open', icon: 'visibility' },
            { action: 'permissions-file', label: 'Permissions', icon: 'lock' },
            { action: 'delete-file', label: 'Delete', icon: 'trash', danger: true },
        ], { fileId });
    });

    $root.on('click', '[data-afm-link-menu]', function (e) {
        if (!AnchorFM.isAdmin) return;
        e.stopPropagation();
        const linkId = Number($(this).data('afm-link-menu'));
        closeMenu();
        openMenu(this, [
            { action: 'edit-link', label: 'Edit', icon: 'edit' },
            { action: 'delete-link', label: 'Delete', icon: 'trash', danger: true },
        ], { linkId });
    });

    $root.on('click', '[data-afm-action="permissions-file"]', function () {
        const fileId = Number($(this).data('afm-file'));
        openPermissions('file', fileId);
    });

    $root.on('click', '[data-afm-sort]', function () {
        const key = $(this).data('afm-sort');
        if (state.sortKey === key) {
            state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            state.sortKey = key; state.sortDir = 'asc';
        }
        renderList(state.currentList, state.currentCapability);
    });

    $root.on('click', '[data-afm-crumb]', function () {
        loadFolder(Number($(this).data('afm-crumb')));
    });

    $root.on('click', '[data-afm-row-expand]', function (e) {
        e.stopPropagation();
        // Expand-in-place operates on the browse listing only; in global-search
        // view there is no current-folder context to inject children into, and
        // re-rendering would discard the search results.
        if (state.search && state.search.length >= 2) return;
        const fid = Number($(this).data('afm-row-expand'));
        if (state.expandedRows[fid]) {
            delete state.expandedRows[fid];
            renderList(state.currentList, state.currentCapability);
            return;
        }
        api('anchor_fm_list', { folder_id: fid }).then(res => {
            if (!res || !res.success) return;
            state.expandedRows[fid] = currentRows(res.data);
            renderList(state.currentList, state.currentCapability);
        });
    });

    $root.on('click', '[data-afm-row]', function (e) {
        if ($(e.target).closest('[data-afm-row-expand],[data-afm-row-menu]').length) return;
        selectRow($(this), e);
    });

    $root.on('dblclick', '[data-afm-row]', function (e) {
        if ($(e.target).closest('[data-afm-row-expand],[data-afm-row-menu]').length) return;
        const kind = $(this).data('afm-row-kind');
        const id = Number($(this).data('afm-row-id'));
        if (kind === 'folder') { loadFolder(id); }
        else if (kind === 'file') { if (typeof openViewer === 'function') openViewer('file', id); }
        else if (kind === 'video') { if (typeof openViewer === 'function') openViewer('video', id); }
        else if (kind === 'link') { const l = findRow('link', id); if (l && l.url) window.open(l.url, '_blank', 'noopener'); }
    });

    function selectRow($row, e) {
        const key = $row.data('afm-row');
        if (e && (e.metaKey || e.ctrlKey)) {
            if (state.selectedRows.has(key)) state.selectedRows.delete(key); else state.selectedRows.add(key);
        } else if (e && e.shiftKey && state.lastSelectedKey) {
            selectRange(state.lastSelectedKey, key);
        } else {
            state.selectedRows.clear(); state.selectedRows.add(key);
        }
        state.lastSelectedKey = key;
        refreshSelectionUI();
    }

    function selectRange(fromKey, toKey) {
        const keys = $grid.find('.afm__row').map(function () { return $(this).data('afm-row'); }).get();
        const a = keys.indexOf(fromKey), b = keys.indexOf(toKey);
        if (a < 0 || b < 0) { state.selectedRows.add(toKey); return; }
        const lo = Math.min(a, b), hi = Math.max(a, b);
        for (let i = lo; i <= hi; i++) state.selectedRows.add(keys[i]);
    }

    function refreshSelectionUI() {
        $grid.find('.afm__row').each(function () {
            $(this).toggleClass('is-selected', state.selectedRows.has($(this).data('afm-row')));
        });
        renderBulkBar();
    }

    function renderBulkBar() {
        const n = state.selectedRows.size;
        let $bar = $root.find('[data-afm-bulkbar]');
        if (n < 2) { $bar.remove(); return; }
        if (!$bar.length) { $bar = $(`<div class="afm__bulkBar" data-afm-bulkbar></div>`).appendTo($root); }
        const adminBtns = AnchorFM.isAdmin
            ? `<button type="button" class="afm__btn afm__btn--danger" data-afm-bulk="delete">Delete</button>`
            : '';
        $bar.html(`<span class="afm__bulkCount">${n} selected</span>
            <button type="button" class="afm__btn afm__btn--secondary" data-afm-bulk="download">Download</button>
            ${adminBtns}
            <button type="button" class="afm__btn afm__btn--ghost" data-afm-bulk="clear">Clear</button>`);
    }

    $root.on('click', '[data-afm-bulk]', function () {
        const op = $(this).data('afm-bulk');
        const keys = Array.from(state.selectedRows);
        if (op === 'clear') { state.selectedRows.clear(); refreshSelectionUI(); return; }
        if (op === 'download') {
            keys.forEach(k => {
                const parts = k.split(':'), kind = parts[0], id = Number(parts[1]);
                if (kind === 'file') openFileDownload(id);
                else if (kind === 'folder') downloadFolder(id);
            });
            return;
        }
        if (op === 'delete' && AnchorFM.isAdmin) {
            if (!window.confirm(`Delete ${keys.length} item(s)? This cannot be undone.`)) return;
            Promise.all(keys.map(k => {
                const parts = k.split(':'), kind = parts[0], id = Number(parts[1]);
                if (kind === 'file') return api('anchor_fm_delete_file', { file_id: id });
                if (kind === 'folder') return api('anchor_fm_delete_folder', { folder_id: id });
                if (kind === 'video') return api('anchor_fm_vimeo_delete', { video_id: id });
                if (kind === 'link') return api('anchor_fm_delete_link', { link_id: id });
                return Promise.resolve();
            })).then(() => { state.selectedRows.clear(); reloadCurrentFolder(); });
        }
    });

    function openFileDownload(fileId) {
        api('anchor_fm_preview', { file_id: fileId }).then(res => {
            if (res && res.success && res.data.preview && res.data.preview.downloadUrl) {
                window.location.href = res.data.preview.downloadUrl;
            }
        });
    }

    function downloadFolder(folderId) {
        window.location = `${AnchorFM.ajax}?action=anchor_fm_download_folder&folder_id=${folderId}&nonce=${AnchorFM.nonce}`;
    }

    function findRow(kind, id) {
        const local = currentRows(state.currentList).concat(
            Object.values(state.expandedRows).flat()
        ).find(r => r.kind === kind && r.id === Number(id));
        if (local) return local;
        // Fall back to a row surfaced via global search.
        return (state.searchRowsByKey && state.searchRowsByKey[kind + ':' + id]) || null;
    }

    $root.on('keydown', function (e) {
        if ($(e.target).is('input, textarea, [contenteditable]')) return;
        const $rows = $grid.find('.afm__row');
        if (!$rows.length) return;
        let idx = $rows.index($grid.find('.afm__row.is-active'));
        if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min($rows.length - 1, idx + 1); focusRowAt($rows, idx); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(0, idx - 1); focusRowAt($rows, idx); }
        else if (e.key === 'ArrowRight') { const $r = $rows.eq(Math.max(0, idx)); if ($r.data('afm-row-kind') === 'folder') $r.find('[data-afm-row-expand]').trigger('click'); }
        else if (e.key === 'ArrowLeft') { const $r = $rows.eq(Math.max(0, idx)); const fid = Number($r.data('afm-row-id')); if (state.expandedRows[fid]) { delete state.expandedRows[fid]; renderList(state.currentList, state.currentCapability); } }
        else if (e.key === 'Enter') { const $r = $rows.eq(Math.max(0, idx)); openRowDefault($r); }
        else if (e.key === ' ') { e.preventDefault(); const $r = $rows.eq(Math.max(0, idx)); previewRow($r); }
        else if (e.key === 'Escape') { if (typeof closeMenu === 'function') closeMenu(); if (!$modal.prop('hidden')) closeModal(); }
    });

    function focusRowAt($rows, idx) {
        $rows.removeClass('is-active');
        const $r = $rows.eq(idx).addClass('is-active');
        if ($r[0]) $r[0].scrollIntoView({ block: 'nearest' });
    }
    function openRowDefault($r) {
        const kind = $r.data('afm-row-kind'), id = Number($r.data('afm-row-id'));
        if (kind === 'folder') loadFolder(id);
        else if (kind === 'file' || kind === 'video') openViewer(kind, id);
        else if (kind === 'link') { const l = findRow('link', id); if (l && l.url) window.open(l.url, '_blank', 'noopener'); }
    }
    function previewRow($r) {
        const kind = $r.data('afm-row-kind'), id = Number($r.data('afm-row-id'));
        if (kind === 'file' || kind === 'video') openViewer(kind, id);
    }

    function startInlineRename(kind, id) {
        if (!AnchorFM.isAdmin) return;
        const $row = $grid.find(`[data-afm-row="${kind}:${id}"]`);
        const $label = $row.find('[data-afm-row-label]');
        if (!$label.length || $row.find('input.afm__renameInput').length) return;
        const current = $label.text();
        const $input = $(`<input type="text" class="afm__renameInput">`).val(current);
        $label.hide().after($input);
        $input.trigger('focus').trigger('select');

        function commit() {
            const name = String($input.val() || '').trim();
            $input.prop('disabled', true);
            if (!name || name === current) { cancel(); return; }
            const action = kind === 'folder' ? 'anchor_fm_rename_folder'
                : kind === 'video' ? 'anchor_fm_vimeo_update'
                : kind === 'file' ? 'anchor_fm_rename_file' : null;
            if (!action) { cancel(); return; }
            const data = {};
            if (kind === 'folder') { data.folder_id = id; data.name = name; }
            if (kind === 'video') { data.video_id = id; data.title = name; }
            if (kind === 'file') { data.file_id = id; data.name = name; }
            api(action, data).then(res => {
                if (res && res.success) reloadCurrentFolder(); else cancel();
            });
        }
        function cancel() { $input.remove(); $label.show(); }
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
            else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        });
        $input.on('blur', commit);
    }

    $root.on('keydown', function (e) {
        if (e.key === 'F2' && AnchorFM.isAdmin) {
            const $r = $grid.find('.afm__row.is-active');
            if ($r.length) startInlineRename($r.data('afm-row-kind'), Number($r.data('afm-row-id')));
        }
    });
    $root.on('dblclick', '[data-afm-row-label]', function (e) {
        if (!AnchorFM.isAdmin) return;
        e.stopPropagation();
        const $r = $(this).closest('[data-afm-row]');
        startInlineRename($r.data('afm-row-kind'), Number($r.data('afm-row-id')));
    });

    function shareUrlFor(kind, id) {
        const base = window.location.origin + window.location.pathname;
        return base + '#afm-' + kind + '-' + id;
    }
    function copyShareLink(kind, id) {
        const url = shareUrlFor(kind, id);
        const done = () => toast('Link copied');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done, () => fallbackCopy(url, done));
        } else { fallbackCopy(url, done); }
    }
    function fallbackCopy(text, cb) {
        const $t = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
        $t[0].select(); try { document.execCommand('copy'); } catch (e) {}
        $t.remove(); if (cb) cb();
    }
    function toast(msg) {
        const $t = $(`<div class="afm__toast">${esc(msg)}</div>`).appendTo($root);
        setTimeout(() => $t.addClass('is-show'), 10);
        setTimeout(() => { $t.removeClass('is-show'); setTimeout(() => $t.remove(), 300); }, 1800);
    }
    function handleDeepLink() {
        const m = (window.location.hash || '').match(/^#afm-(file|video|folder|link)-(\d+)$/);
        if (!m) return;
        const kind = m[1], id = Number(m[2]);
        if (kind === 'folder') { loadFolder(id); return; }
        if (kind === 'file' || kind === 'video') openViewer(kind, id);
    }

    function showAccessDenied(entityType, entityId, label) {
        const body = `<div class="afm__denied">
            <span class="dashicons dashicons-lock"></span>
            <div class="afm__deniedTitle">You don't have access to this item</div>
            <p class="afm__deniedText">If you think you should, you can request access.</p>
            <button type="button" class="afm__btn afm__btn--primary" data-afm-request-access
                    data-entity-type="${esc(entityType)}" data-entity-id="${esc(entityId)}" data-label="${esc(label || '')}">
                Request access</button>
            <div class="afm__notice" data-afm-request-notice hidden></div>
        </div>`;
        openViewerModal('Access required', body, '');
    }

    $root.on('click', '[data-afm-request-access]', function () {
        const $b = $(this);
        $b.prop('disabled', true);
        api('anchor_fm_request_access', {
            entity_type: $b.data('entity-type'),
            entity_id: $b.data('entity-id'),
            label: $b.data('label') || ''
        }).then(res => {
            const $n = $modalBody.find('[data-afm-request-notice]').prop('hidden', false);
            $n.text(res && res.success ? 'Request sent. The site team has been notified.' : 'Could not send request.');
        });
    });

    // Drag to move files into folders (admin/manage only)
    let dragFileId = null;
    let dragFolderId = null;

    $root.on('dragstart', '[data-afm-file-card]', function (e) {
        const id = Number($(this).data('afm-file-card'));
        if (!AnchorFM.isAdmin) return;
        dragFileId = id;
        dragFolderId = null;
        if (e.originalEvent && e.originalEvent.dataTransfer) {
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', String(id));
        }
        $root.addClass('afm--dragMode');
    });

    $root.on('dragstart', '[data-afm-folder-card], [data-afm-open-folder]', function (e) {
        const id = Number($(this).data('afm-folder-card') || $(this).data('afm-open-folder'));
        if (!AnchorFM.isAdmin) return;
        dragFolderId = id;
        dragFileId = null;
        if (e.originalEvent && e.originalEvent.dataTransfer) {
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', String(id));
        }
        $root.addClass('afm--dragMode');
    });

    $root.on('dragend', '[data-afm-file-card],[data-afm-folder-card],[data-afm-open-folder]', function () {
        dragFileId = null;
        dragFolderId = null;
        $root.removeClass('afm--drag');
        $root.removeClass('afm--dragMode');
        $root.find('.afm__card--folder, .afm__treeBtn').removeClass('is-drop');
    });

    // Use capture on document to better catch drops over folder cards
    $(document).on('dragover', function (e) {
        if (!AnchorFM.isAdmin) return;
        if (!dragFileId && !dragFolderId) return;
        const el = document.elementFromPoint(e.clientX, e.clientY);
        const $target = $(el).closest('[data-afm-folder-card], [data-afm-open-folder]');
        $root.find('.afm__card--folder, .afm__treeBtn').removeClass('is-drop');
        if ($target.length) {
            e.preventDefault();
            if (e.originalEvent && e.originalEvent.dataTransfer) {
                e.originalEvent.dataTransfer.dropEffect = 'move';
            }
            $target.addClass('is-drop');
        }
    });

    $root.on('dragleave', '[data-afm-folder-card], [data-afm-open-folder]', function () {
        $(this).removeClass('is-drop');
    });
    $root.on('drop', '[data-afm-folder-card], [data-afm-open-folder]', function (e) {
        if (!AnchorFM.isAdmin) return;
        if (!dragFileId && !dragFolderId) return;
        e.preventDefault();
        const folderId = Number($(this).data('afm-folder-card') || $(this).data('afm-open-folder'));
        if (productDocsFolderId && folderId === productDocsFolderId) return;
        handleDropOnFolder($(this), folderId);
    });

    $(document).on('drop', function (e) {
        if (!AnchorFM.isAdmin) return;
        if (!dragFileId && !dragFolderId) return;
        const el = document.elementFromPoint(e.clientX, e.clientY);
        const $target = $(el).closest('[data-afm-folder-card], [data-afm-open-folder]');
        if ($target.length) {
            e.preventDefault();
        const folderId = Number($target.data('afm-folder-card') || $target.data('afm-open-folder'));
        if (productDocsFolderId && folderId === productDocsFolderId) return;
        handleDropOnFolder($target, folderId);
        }
    });

    function handleDropOnFolder($el, folderId) {
        $el.removeClass('is-drop');
        if (dragFileId) {
            api('anchor_fm_move_file', { file_id: dragFileId, folder_id: folderId }).done(res => {
                if (res && res.success) {
                    flashDrop($el);
                    loadFolder(state.currentFolderId);
                }
            });
        } else if (dragFolderId) {
            if (folderId === dragFolderId) return;
            api('anchor_fm_move_folder', { folder_id: dragFolderId, target_folder_id: folderId }).done(res => {
                if (res && res.success) {
                    flashDrop($el);
                    bootstrap();
                    loadFolder(folderId);
                }
            });
        }
    }

    function flashDrop($el) {
        $el.addClass('is-drop-flash');
        window.setTimeout(() => $el.removeClass('is-drop-flash'), 500);
    }

    // Panels follow the tree selection; Product Docs panel is admin-only and triggered by the admin tab.

    // Permissions: user search/add/remove
    let lastUserResults = [];
    $root.on('input', '[data-afm-user-search]', function () {
        const term = String($(this).val() || '').trim();
        const $suggest = $modalBody.find('[data-afm-suggest]');
        if (!term || term.length < 2) {
            $suggest.prop('hidden', true).html('');
            lastUserResults = [];
            return;
        }
        api('anchor_fm_user_search', { term }).done(res => {
            if (!res || !res.success) return;
            lastUserResults = res.data.users || [];
            if (!lastUserResults.length) {
                $suggest.prop('hidden', false).html('<div class="afm__suggestItem is-empty">No matches</div>');
                return;
            }
            $suggest.prop('hidden', false).html(lastUserResults.map(u => `
                <button type="button" class="afm__suggestItem" data-afm-pick-user="${u.id}">
                    <div class="afm__suggestName">${esc(u.displayName)}</div>
                    <div class="afm__suggestEmail">${esc(u.email)}</div>
                </button>
            `).join(''));
        });
    });
    $root.on('click', '[data-afm-pick-user]', function () {
        const id = Number($(this).data('afm-pick-user'));
        const u = lastUserResults.find(x => Number(x.id) === id);
        if (!u) return;
        state.usersDraft.push({ id: String(u.id), name: u.displayName });
        renderPermissionsEditor();
    });
    $root.on('click', '[data-afm-action="remove-user-perm"]', function () {
        const idx = Number($(this).data('afm-user-idx'));
        if (idx >= 0) {
            state.usersDraft.splice(idx, 1);
            renderPermissionsEditor();
        }
    });
    $root.on('click', '[data-afm-action="add-user-perm"]', function () {
        const term = String($modalBody.find('[data-afm-user-search]').val() || '').trim();
        if (!term) return;
        if (/^\d+$/.test(term)) {
            state.usersDraft.push({ id: term, name: term });
            renderPermissionsEditor();
        }
    });

    // Product docs helpers
    function loadMyProductDocs() {
        if (!$productDocs.length) return;
        $productDocs.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        api('anchor_pd_my_docs', {}).done(res => {
            if (!res || !res.success) {
                $productDocs.html(`<div class="afm__empty">${esc(res && res.data && res.data.message ? res.data.message : 'Unable to load documents.')}</div>`);
                return;
            }
            const docs = res.data.docs || [];
            if (!docs.length) {
                $productDocs.html('<div class="afm__empty">No documents available.</div>');
                return;
            }
            // Group by product
            const byProduct = {};
            docs.forEach(d => {
                const key = `${d.productId || d.product || 'Product'}`;
                if (!byProduct[key]) byProduct[key] = { name: d.product || key, id: d.productId || 0, docs: [] };
                byProduct[key].docs.push(d);
            });
            const cards = Object.values(byProduct).map(group => {
                const items = group.docs.map(d => `
                    <span class="afm__pill">
                        ${esc(d.title)}
                        ${AnchorFM.isAdmin ? `<button type="button" class="afm__pillClose" data-afm-doc-remove="${d.fileId}" data-afm-doc-product="${group.id}" aria-label="Remove">×</button>` : ''}
                    </span>
                `).join('');
                return `
                    <div class="afm__card afm__card--stack">
                        <div class="afm__cardMain">
                            <div class="afm__cardTitle">${esc(group.name)}</div>
                            <div class="afm__cardSub">${group.docs.length} document(s)</div>
                            <div class="afm__pillRow">${items}</div>
                        </div>
                    </div>
                `;
            });
            $productDocs.html(cards.join(''));
        });
    }

    function loadProducts() {
        if (!AnchorFM.isAdmin || !$productSelect.length) return;
        $productDocsManage.html('<div class="afm__skeleton afm__skeleton--share"></div>');
        api('anchor_pd_products', {}).done(res => {
            if (!res || !res.success) {
                $productDocsManage.html(`<div class="afm__empty">${esc(res && res.data && res.data.message ? res.data.message : 'Unable to load products.')}</div>`);
                return;
            }
            state.products = res.data.products || [];
            const options = state.products.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
            $productSelect.html(options);
            if (state.products.length) {
                const firstId = state.products[0].id;
                $productSelect.val(firstId);
                setProductDocsDraft(firstId);
            } else {
                $productDocsManage.html('<div class="afm__empty">No products.</div>');
            }
        });
    }

    function setProductDocsDraft(productId) {
        const product = (state.products || []).find(p => Number(p.id) === Number(productId));
        state.productDocsDraft = product && product.docs ? product.docs.slice() : [];
        renderProductDocsManage();
    }

    function renderProductDocsManage() {
        if (!AnchorFM.isAdmin || !$productDocsManage.length) return;
        if (!state.productDocsDraft || !state.productDocsDraft.length) {
            state.productDocsDraft = [{ title: '', fileId: '', fileName: '', expires: '' }];
        }
        const d = state.productDocsDraft[0];
        const html = `
            <div class="afm__help">Upload or replace the document for this product. Set an optional expiry date (YYYY-MM-DD).</div>
            <div class="aap__item afm__formCard">
                <div class="aap__itemName">
                    <label>Title</label>
                    <input type="text" class="afm__input" data-afm-doc-title="0" value="${esc(d.title || '')}">
                </div>
                <div class="aap__itemSub">
                    <label>File</label>
                    <div class="afm__fieldInline">
                        <input type="text" class="afm__input" data-afm-doc-file-display="0" value="${esc(d.fileName || (d.fileId ? 'File #' + d.fileId : ''))}" readonly placeholder="Choose a file">
                        <button type="button" class="afm__btn afm__btn--secondary" data-afm-action="replace-doc" data-afm-doc-idx="0">
                            <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                            Upload
                        </button>
                    </div>
                </div>
                <div class="aap__itemSub">
                    <label>Expires</label>
                    <input type="text" class="afm__input" placeholder="YYYY-MM-DD" data-afm-doc-expires="0" value="${esc(d.expires || '')}">
                </div>
            </div>
        `;
        $productDocsManage.html(html);
    }

    $productSelect.on('change', function () {
        const pid = Number($(this).val());
        setProductDocsDraft(pid);
    });

    $root.on('click', '[data-afm-action="add-doc"]', function () {
        state.productDocsDraft.push({ title: '', fileId: '', fileName: '', expires: '' });
        renderProductDocsManage();
    });
    $root.on('click', '[data-afm-action="remove-doc"]', function () {
        const idx = Number($(this).data('afm-doc-idx'));
        if (idx >= 0) {
            state.productDocsDraft.splice(idx, 1);
            renderProductDocsManage();
        }
    });
    $root.on('input', '[data-afm-doc-title]', function () {
        const idx = Number($(this).data('afm-doc-title'));
        if (state.productDocsDraft[idx]) state.productDocsDraft[idx].title = String($(this).val());
    });
    $root.on('input', '[data-afm-doc-expires]', function () {
        const idx = Number($(this).data('afm-doc-expires'));
        if (state.productDocsDraft[idx]) state.productDocsDraft[idx].expires = String($(this).val());
    });

    // Upload/replace doc file
    let replaceIdx = null;
    $root.on('click', '[data-afm-action="replace-doc"]', function () {
        replaceIdx = Number($(this).data('afm-doc-idx'));
        $productDocUpload.val('');
        $productDocUpload.trigger('click');
    });
    $productDocUpload.on('change', function () {
        const file = this.files && this.files[0];
        if (!file || replaceIdx === null) return;
        const pid = Number($productSelect.val());
        const fd = new FormData();
        fd.append('action', 'anchor_pd_upload');
        fd.append('nonce', AnchorFM.nonce);
        fd.append('product_id', pid);
        fd.append('file', file, file.name);
        $productDocsNotice.prop('hidden', true);
        $.ajax({
            url: AnchorFM.ajax,
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
        }).done(res => {
            if (!res || !res.success) {
                $productDocsNotice.text(res && res.data && res.data.message ? res.data.message : 'Upload failed.').prop('hidden', false);
                return;
            }
            const info = res.data.file || {};
            if (!state.productDocsDraft[replaceIdx]) state.productDocsDraft[replaceIdx] = {};
            state.productDocsDraft[replaceIdx].fileId = info.id;
            state.productDocsDraft[replaceIdx].fileName = info.name;
            if (!state.productDocsDraft[replaceIdx].title) {
                state.productDocsDraft[replaceIdx].title = info.name;
            }
            renderProductDocsManage();
            replaceIdx = null;
            $productDocUpload.val('');
        }).fail(() => {
            $productDocsNotice.text('Upload failed.').prop('hidden', false);
        });
    });

    $root.on('click', '[data-afm-action="save-product-docs"]', function () {
        if (!AnchorFM.isAdmin) return;
        if (!$productDocsNotice.length) return;
        $productDocsNotice.prop('hidden', true);
        const pid = Number($productSelect.val());
        const docs = state.productDocsDraft.map(d => ({
            title: d.title || '',
            fileId: d.fileId || '',
            fileName: d.fileName || '',
            expires: d.expires || '',
        }));
        api('anchor_pd_save_docs', { product_id: pid, docs }).done(res => {
            if (!res || !res.success) {
                $productDocsNotice.text(res && res.data && res.data.message ? res.data.message : 'Unable to save.').prop('hidden', false);
                return;
            }
            $productDocsNotice.text('Saved.').prop('hidden', false);
            state.productDocsDraft = [{ title: '', fileId: '', fileName: '', expires: '' }];
            renderProductDocsManage();
            loadProducts();
            loadMyProductDocs();
        });
    });

    // Remove doc via badge (admin)
    $root.on('click', '[data-afm-doc-remove]', function () {
        if (!AnchorFM.isAdmin) return;
        const fid = Number($(this).data('afm-doc-remove'));
        const pid = Number($(this).data('afm-doc-product') || 0);
        if (!pid || !fid) return;
        const product = (state.products || []).find(p => Number(p.id) === pid);
        if (!product) return;
        const newDocs = (product.docs || []).filter(d => Number(d.fileId) !== fid);
        api('anchor_pd_save_docs', { product_id: pid, docs: newDocs }).done(() => {
            loadProducts();
            loadMyProductDocs();
        });
    });

    $root.on('anchorfm:refresh', function () {
        loadFolder(state.currentFolderId);
    });

    $root.on('anchorfm:showProductDocs', function () {
        if (!AnchorFM.isAdmin || !$productDocs.length) return;
        $root.find('[data-afm-panel]').removeClass('is-active');
        $root.find('[data-afm-panel="product-docs"]').addClass('is-active');
        loadMyProductDocs();
        loadProducts();
    });

    bootstrap();
});
