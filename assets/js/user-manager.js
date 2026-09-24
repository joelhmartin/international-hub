/*
 * Users panel (administrators only): list, search, filter and page portal
 * users; add one person; import a CSV; change role, set password, email a
 * reset link, remove. Reuses file-manager.js's modal and helpers through
 * window.AnchorFMUI rather than carrying copies.
 */
jQuery(function ($) {
    const $root = $('[data-afm]');
    const $panel = $root.find('[data-afm-users]');
    const UI = window.AnchorFMUI;
    if (!$panel.length || !UI || !window.AnchorFM || !AnchorFM.isAdmin) return;

    const { api, esc, toast, errMessage, modal } = UI;
    const roles = Array.isArray(AnchorFM.roles) ? AnchorFM.roles : [];
    const roleLabel = key => (roles.find(r => r.key === key) || {}).label || key;
    const st = { search: '', role: '', page: 1, pages: 1, loaded: false, users: [] };
    let searchTimer = null;

    function roleOptions(selected) {
        return roles.map(r => `<option value="${esc(r.key)}"${r.key === selected ? ' selected' : ''}>${esc(r.label)}</option>`).join('');
    }

    function genPassword() {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        const buf = new Uint32Array(16);
        window.crypto.getRandomValues(buf);
        return Array.from(buf, n => chars[n % chars.length]).join('');
    }

    function passwordField(label, help) {
        return `<div class="afm__formRow">
            <label class="afm__label">${esc(label)}</label>
            <div class="afm__pwRow">
                <input type="text" class="afm__input" data-um-password autocomplete="new-password">
                <button type="button" class="afm__btn afm__btn--ghost" data-um-generate>Generate</button>
            </div>
            <div class="afm__help">${esc(help)}</div>
        </div>`;
    }

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d) ? '—' : d.toLocaleDateString();
    }

    function modalError($body, msg) {
        let $n = $body.find('[data-um-notice]');
        if (!$n.length) $n = $('<div class="afm__notice afm__notice--error" data-um-notice></div>').appendTo($body);
        $n.text(msg).prop('hidden', false);
    }

    function load() {
        $panel.find('[data-afm-users-table]').html('<div class="afm__skeleton"></div>');
        api('anchor_fm_users_list', { search: st.search, role: st.role, page: st.page })
            .done(res => {
                if (!res || !res.success) { renderError(errMessage(null, res, 'Could not load users.')); return; }
                st.users = res.data.users || [];
                st.pages = res.data.pages || 1;
                st.page = res.data.page || 1;
                renderTable(res.data.total || 0);
            })
            .fail(xhr => renderError(errMessage(xhr, null, 'Could not load users.')));
    }

    function renderError(msg) {
        $panel.find('[data-afm-users-table]').html(`<div class="afm__empty">${esc(msg)}</div>`);
        $panel.find('[data-afm-users-pager]').empty();
    }

    function renderTable(total) {
        if (!st.users.length) {
            renderError(st.search || st.role ? 'No users match.' : 'No users yet.');
            return;
        }
        const rows = st.users.map(u => `
            <tr data-um-id="${u.id}">
                <td data-label="Name"><strong>${esc(u.displayName || u.username)}</strong><div class="afm__muted">${esc(u.username)}</div></td>
                <td data-label="Email">${esc(u.email)}</td>
                <td data-label="Role">${esc((u.roles || []).map(roleLabel).join(', ') || '—')}</td>
                <td data-label="Added">${esc(fmtDate(u.registered))}</td>
                <td data-label="Last watched">${esc(u.lastWatched ? fmtDate(u.lastWatched) : 'Never')}</td>
                <td class="afm__usersActions">${u.manageable ? `
                    <button type="button" class="afm__linkBtn" data-um-act="role">Role</button>
                    <button type="button" class="afm__linkBtn" data-um-act="password">Password</button>
                    <button type="button" class="afm__linkBtn" data-um-act="reset">Email reset</button>
                    <button type="button" class="afm__linkBtn afm__linkBtn--danger" data-um-act="remove">Remove</button>`
                    : '<span class="afm__muted">—</span>'}</td>
            </tr>`).join('');
        $panel.find('[data-afm-users-table]').html(`
            <table class="afm__table">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Added</th><th>Last watched</th><th></th></tr></thead>
                <tbody>${rows}</tbody>
            </table>`);
        $panel.find('[data-afm-users-pager]').html(`
            <button type="button" class="afm__btn afm__btn--ghost" data-um-page="-1" ${st.page <= 1 ? 'disabled' : ''}>Prev</button>
            <span>Page ${st.page} of ${st.pages} · ${total} user${total === 1 ? '' : 's'}</span>
            <button type="button" class="afm__btn afm__btn--ghost" data-um-page="1" ${st.page >= st.pages ? 'disabled' : ''}>Next</button>`);
    }

    function userById(id) { return st.users.find(u => u.id === id); }

    // --- Add person ---
    function openAdd() {
        modal.open('Add person', `
            <div class="afm__formRow"><label class="afm__label">First name</label><input type="text" class="afm__input" data-um-first></div>
            <div class="afm__formRow"><label class="afm__label">Last name</label><input type="text" class="afm__input" data-um-last></div>
            <div class="afm__formRow"><label class="afm__label">Email</label><input type="email" class="afm__input" data-um-email></div>
            <div class="afm__formRow"><label class="afm__label">Username (optional)</label><input type="text" class="afm__input" data-um-username placeholder="e.g. j.smith"></div>
            <div class="afm__formRow"><label class="afm__label">Role</label><select class="afm__select" data-um-role>${roleOptions(AnchorFM.defaultRole || '')}</select></div>
            ${passwordField('Password (optional)', 'At least 10 characters. Leave blank to generate one they never see.')}
            <label class="afm__check"><input type="checkbox" data-um-send checked> Email a welcome / set-password link</label>
        `, 'Add person', $b => {
            modal.busy(true, 'Adding…');
            api('anchor_fm_user_create', {
                first_name: $b.find('[data-um-first]').val(),
                last_name: $b.find('[data-um-last]').val(),
                email: $b.find('[data-um-email]').val(),
                username: $b.find('[data-um-username]').val(),
                role: $b.find('[data-um-role]').val(),
                password: $b.find('[data-um-password]').val(),
                send_email: $b.find('[data-um-send]').is(':checked') ? '1' : '0',
            }).done(res => {
                if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not add this person.')); return; }
                modal.close();
                toast(`Added ${res.data.user.displayName || res.data.user.email}`);
                st.page = 1; load();
            }).fail(xhr => modalError($b, errMessage(xhr, null, 'Could not add this person.')))
              .always(() => modal.busy(false, 'Add person'));
        });
    }

    // --- Import CSV ---
    function openImport() {
        modal.open('Import users from CSV', `
            <p class="afm__importHint">Columns in this order: username, first name, last name, email, password. A header row is optional; username and password are optional.</p>
            <div class="afm__formRow"><label class="afm__label">CSV file</label><input type="file" accept=".csv,text/csv,text/plain" data-um-file></div>
            <div class="afm__formRow"><label class="afm__label">Role</label><select class="afm__select" data-um-role>${roleOptions(AnchorFM.defaultRole || '')}</select></div>
            ${passwordField('Password for everyone without one (optional)', 'Rows with their own password keep it. If both are blank, a password is generated.')}
            <label class="afm__check"><input type="checkbox" data-um-send checked> Email new users a link to set their password</label>
            <div class="afm__importResults" data-um-results hidden></div>
        `, 'Import', $b => {
            const file = ($b.find('[data-um-file]')[0] || {}).files;
            if (!file || !file[0]) { modalError($b, 'Please choose a CSV file first.'); return; }
            const data = new FormData();
            data.append('action', 'anchor_fm_bulk_import_users');
            data.append('nonce', AnchorFM.nonce);
            data.append('role', $b.find('[data-um-role]').val() || '');
            data.append('default_password', $b.find('[data-um-password]').val() || '');
            data.append('send_email', $b.find('[data-um-send]').is(':checked') ? '1' : '0');
            data.append('csv', file[0], file[0].name);
            modal.busy(true, 'Importing…');
            $.ajax({ url: AnchorFM.ajax, method: 'POST', data, processData: false, contentType: false })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Import failed.')); return; }
                    renderImportResults($b, res.data);
                    load();
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Import failed.')))
                .always(() => modal.busy(false, 'Import'));
        });
    }

    function renderImportResults($b, res) {
        const rows = Array.isArray(res.rows) ? res.rows : [];
        const body = rows.map(r => `<tr class="afm__importRow afm__importRow--${esc(r.status)}"><td>${esc(String(r.line || ''))}</td><td>${esc(r.username || '')}</td><td>${esc(r.email || '')}</td><td>${esc(r.status || '')}</td><td>${esc(r.message || '')}</td></tr>`).join('');
        $b.find('[data-um-notice]').prop('hidden', true);
        $b.find('[data-um-results]').html(
            `<div class="afm__importSummary">${esc(`${res.created || 0} created, ${res.skipped || 0} skipped, ${res.errors || 0} error(s)`)}</div>`
            + `<table class="afm__importTable"><thead><tr><th>#</th><th>Username</th><th>Email</th><th>Status</th><th>Message</th></tr></thead><tbody>${body}</tbody></table>`
        ).prop('hidden', false);
    }

    // --- Row actions ---
    function openRole(u) {
        modal.open(`Change role — ${u.displayName}`, `
            <div class="afm__formRow"><label class="afm__label">Role</label><select class="afm__select" data-um-role>${roleOptions((u.roles || [])[0] || '')}</select></div>
            <div class="afm__help">Folder access follows role, so this changes what they can see.</div>
        `, 'Save', $b => {
            modal.busy(true, 'Saving…');
            api('anchor_fm_user_set_role', { user_id: u.id, role: $b.find('[data-um-role]').val() })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not change the role.')); return; }
                    modal.close(); toast('Role updated'); load();
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Could not change the role.')))
                .always(() => modal.busy(false, 'Save'));
        });
    }

    function openPassword(u) {
        modal.open(`Set password — ${u.displayName}`, passwordField('New password', 'At least 10 characters. Share it with them yourself; it is not emailed.'), 'Set password', $b => {
            modal.busy(true, 'Saving…');
            api('anchor_fm_user_set_password', { user_id: u.id, password: $b.find('[data-um-password]').val() })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not set the password.')); return; }
                    modal.close(); toast('Password set');
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Could not set the password.')))
                .always(() => modal.busy(false, 'Set password'));
        });
    }

    function sendReset(u) {
        api('anchor_fm_user_send_reset', { user_id: u.id })
            .done(res => toast(res && res.success ? `Reset link sent to ${u.email}` : errMessage(null, res, 'Could not send the email.')))
            .fail(xhr => toast(errMessage(xhr, null, 'Could not send the email.')));
    }

    function openRemove(u) {
        modal.open('Remove user', `<div class="afm__help">Remove <strong>${esc(u.displayName)}</strong> (${esc(u.email)})? Their account, folder access and watch history are deleted. This cannot be undone.</div>`, 'Remove', $b => {
            modal.busy(true, 'Removing…');
            api('anchor_fm_user_delete', { user_id: u.id })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not remove the user.')); return; }
                    modal.close(); toast('User removed');
                    if (st.users.length === 1 && st.page > 1) st.page--;
                    load();
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Could not remove the user.')))
                .always(() => modal.busy(false, 'Remove'));
        });
    }

    // --- Wiring ---
    $panel.find('[data-afm-users-role]').html('<option value="">All roles</option>' + roleOptions(''));

    $root.on('anchorfm:showUsers', () => { if (!st.loaded) { st.loaded = true; load(); } });
    $panel.on('input', '[data-afm-users-search]', function () {
        clearTimeout(searchTimer);
        const v = String($(this).val() || '').trim();
        searchTimer = setTimeout(() => { st.search = v; st.page = 1; load(); }, 300);
    });
    $panel.on('change', '[data-afm-users-role]', function () { st.role = String($(this).val() || ''); st.page = 1; load(); });
    $panel.on('click', '[data-um-page]', function () {
        st.page = Math.min(st.pages, Math.max(1, st.page + Number($(this).data('um-page'))));
        load();
    });
    $panel.on('click', '[data-afm-action="users-add"]', openAdd);
    $panel.on('click', '[data-afm-action="users-import"]', openImport);
    $panel.on('click', '[data-um-act]', function () {
        const u = userById(Number($(this).closest('[data-um-id]').data('um-id')));
        if (!u) return;
        const act = String($(this).data('um-act'));
        if (act === 'role') openRole(u);
        else if (act === 'password') openPassword(u);
        else if (act === 'reset') sendReset(u);
        else if (act === 'remove') openRemove(u);
    });
    // Generate buttons live inside the shared modal, outside $panel.
    $root.on('click', '[data-um-generate]', function () {
        $(this).closest('.afm__pwRow').find('[data-um-password]').val(genPassword()).trigger('select');
    });
});
