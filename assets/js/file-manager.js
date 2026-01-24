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
    };

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
        if (!crumbs || !crumbs.length) {
            $breadcrumbs.html('');
            return;
        }
        const html = crumbs.map((c, idx) => {
            const sep = idx ? `<span class="afm__crumbSep" aria-hidden="true">/</span>` : '';
            return `${sep}<span class="afm__crumbText">${esc(c.name)}</span>`;
        }).join('');
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

    function renderGrid(list, capability) {
        state.currentList = list;
        state.currentCapability = capability || 'view';

        const canManage = capRank(state.currentCapability) >= 3;
        const canUpload = canManage && state.currentFolderId > 0;
        const canAddLink = canManage && state.currentFolderId > 0;
        $root.toggleClass('afm--canCreateFolder', canManage);
        $root.toggleClass('afm--canUpload', canUpload);
        if ($uploadBtn.length) {
            $uploadBtn.prop('disabled', !canUpload);
        }
        if ($linkBtn.length) {
            $linkBtn.prop('disabled', !canAddLink);
        }

        const folders = (list.folders || []).filter(f => matchesSearch(f.name) && (!productDocsFolderId || Number(f.id) !== productDocsFolderId));
        const links = (list.links || []).filter(l => matchesSearch(l.title));
        const files = (list.files || []).filter(f => matchesSearch(f.name));

        const canAdminAct = !!AnchorFM.isAdmin;
        const folderCards = folders.map(f => `
            <div class="afm__card afm__card--folder" data-afm-folder-card="${f.id}" draggable="${AnchorFM.isAdmin ? 'true' : 'false'}" title="${esc(f.name)}">
                <div class="afm__cardIcon dashicons dashicons-category" aria-hidden="true"></div>
                <div class="afm__cardMain">
                    <div class="afm__cardTitle">${esc(f.name)}</div>
                    <div class="afm__cardSub">${f.isPrivate ? 'Private' : 'Folder'}</div>
                </div>
                ${canAdminAct ? `<button type="button" class="afm__kebab" data-afm-folder-menu="${f.id}" aria-label="Folder actions">
                    <span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
                </button>` : ''}
            </div>
        `).join('');

        const linkCards = links.map(l => `
            <div class="afm__card afm__card--link" data-afm-link-card="${l.id}" title="${esc(l.url)}">
                <div class="afm__cardIcon dashicons dashicons-admin-links" aria-hidden="true"></div>
                <div class="afm__cardMain">
                    <div class="afm__cardTitle">${esc(l.title)}</div>
                    <div class="afm__cardSub">${esc(l.url)}</div>
                </div>
                ${canAdminAct ? `<button type="button" class="afm__kebab" data-afm-link-menu="${l.id}" aria-label="Link actions">
                    <span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
                </button>` : ''}
            </div>
        `).join('');

        const fileCards = files.map(f => `
            <div class="afm__card afm__card--file" data-afm-file-card="${f.id}" draggable="${AnchorFM.isAdmin ? 'true' : 'false'}">
                <div class="afm__cardIcon dashicons dashicons-${iconForMime(f.mime)}" aria-hidden="true"></div>
                <div class="afm__cardMain">
                    <div class="afm__cardTitle">${esc(f.name)}</div>
                    <div class="afm__cardSub">${esc(f.mime)} • ${fmtSize(f.size)}</div>
                </div>
                ${canAdminAct ? `<button type="button" class="afm__kebab" data-afm-file-menu="${f.id}" aria-label="File actions">
                    <span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
                </button>` : ''}
            </div>
        `).join('');

        const empty = (!folderCards && !linkCards && !fileCards)
            ? `<div class="afm__empty">${esc(AnchorFM.i18n.noFiles)}</div>`
            : '';

        $grid.html(folderCards + linkCards + fileCards + empty);
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
            renderGrid({ folders: res.data.folders, links: res.data.links, files: res.data.files }, res.data.capability);
            $root.trigger('anchorfm:folderLoaded', {
                folderId: state.currentFolderId,
                capability: res.data.capability,
                isProductDocs: res.data.isProductDocs
            });
        }).always(() => {
            $root.removeClass('afm--busy');
        });
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
        $.ajax({
            url: AnchorFM.ajax,
            method: 'POST',
            data,
            processData: false,
            contentType: false,
        }).always(() => $root.removeClass('afm--busy'))
            .done(() => loadFolder(state.currentFolderId));
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
            loadFilePreview(fileId);
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

    // Events
    const filesTabActive = () => (!portalMode || $root.hasClass('apfm--tab-files'));
    const ignoreIfNotFilesTab = (evt) => {
        if (!filesTabActive()) {
            if (evt) evt.preventDefault();
            return true;
        }
        return false;
    };

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
        loadFilePreview(id);
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

    $search.on('input', function (e) {
        if (ignoreIfNotFilesTab(e)) return;
        state.search = $(this).val();
        renderGrid(state.currentList, state.currentCapability);
    });

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
