/*
 * Exercises the folder-expansion path in assets/js/file-manager.js.
 *
 * The preload is the whole point of 2.14.0 and all of it lives in the browser:
 * the server can be verified exhaustively and still tell you nothing about
 * whether clicking a disclosure arrow issues a request. This drives the real
 * file-manager.js through a stubbed jQuery, so the assertions below are about
 * the shipped code, not a reimplementation of it.
 *
 * Run: node tests/client-expand.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SRC = path.join(__dirname, '..', 'assets', 'js', 'file-manager.js');

let failures = 0;
function check(label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    if (!ok) failures++;
    console.log(`[${ok ? 'PASS' : 'FAIL'}] ${label}`);
    if (!ok) {
        console.log('   expected: ' + JSON.stringify(expected));
        console.log('   actual:   ' + JSON.stringify(actual));
    }
}

/* ---------------------------------------------------------------------------
 * A jQuery stand-in.
 *
 * Permissive on purpose: file-manager.js touches a great deal of DOM this test
 * does not care about, and a stub that throws on the first unknown method
 * would test nothing. Everything chains and returns itself; the parts the
 * expansion path actually depends on -- delegated handlers, .data(), and
 * $.post -- are real.
 * ------------------------------------------------------------------------ */
function makeHarness() {
    const handlers = [];   // { event, selector, fn }
    const posts = [];      // { action, data, deferred }

    function deferred() {
        let onDone = [], onFail = [], settled = null;
        const d = {
            _resolve(v) { settled = { ok: true, v }; onDone.forEach(f => f(v)); },
            _reject(e) { settled = { ok: false, v: e }; onFail.forEach(f => f(e)); },
        };
        const promise = {
            done(f) { settled && settled.ok ? f(settled.v) : onDone.push(f); return promise; },
            fail(f) { settled && !settled.ok ? f(settled.v) : onFail.push(f); return promise; },
            then(f, g) {
                const next = deferred();
                const run = (fn, v, ok) => {
                    if (!fn) { ok ? next._resolve(v) : next._reject(v); return; }
                    const r = fn(v);
                    if (r && typeof r.then === 'function') r.then(x => next._resolve(x));
                    else next._resolve(r);
                };
                promise.done(v => run(f, v, true));
                promise.fail(v => run(g, v, false));
                return next.promise;
            },
            catch(f) { return promise.then(null, f); },
            always(f) { promise.done(f); promise.fail(f); return promise; },
        };
        d.promise = promise;
        d.resolve = v => { d._resolve(v); return d; };
        d.reject = v => { d._reject(v); return d; };
        return d;
    }

    const NODE_DATA = new Map();   // for elements the test fabricates

    function node(desc) {
        let proxy;
        const self = {
            __afm: true,
            length: 1,
            selector: desc,
            data(k) {
                const bag = NODE_DATA.get(proxy) || {};
                return bag[k];
            },
            is() { return false; },
            hasClass() { return false; },
            eq() { return proxy; },
            get() { return {}; },
            find() { return node(desc + ' find'); },
            closest() { return node(desc + ' closest'); },
            each() { return proxy; },
            map() { return proxy; },
            on(event, sel, fn) {
                if (typeof sel === 'function') handlers.push({ event, selector: null, fn: sel });
                else handlers.push({ event, selector: sel, fn });
                return proxy;
            },
            trigger() { return proxy; },
            val() { return ''; },
            text() { return proxy; },
        };
        // Anything not modelled above chains. Returning the proxy rather than
        // the bare object matters: jQuery chains are used several calls deep
        // ($(this).addClass(..).removeClass(..)) and the second call would
        // otherwise land on an object with none of these methods.
        proxy = new Proxy(self, {
            get(target, prop) {
                if (prop in target) return target[prop];
                if (prop === 'then' || prop === Symbol.toPrimitive) return undefined;
                return () => proxy;
            },
        });
        return proxy;
    }

    const ready = [];
    const $ = function (sel) {
        if (typeof sel === 'function') { ready.push(sel); return node('ready'); }
        // $(this) inside a handler must hand back the same element, or its
        // .data() is lost and every assertion below tests the wrong branch.
        if (sel && sel.__afm) return sel;
        return node(String(sel));
    };
    $.Deferred = deferred;
    $.post = function (url, data) {
        const d = deferred();
        posts.push({ action: data.action, data, deferred: d });
        return d.promise;
    };
    $.each = function (obj, fn) { Object.keys(obj || {}).forEach(k => fn(k, obj[k])); };
    $.extend = Object.assign;

    return { $, handlers, posts, node, NODE_DATA, ready };
}

function load() {
    const h = makeHarness();
    const jQuery = function (fn) { return h.$(fn); };
    Object.assign(jQuery, h.$);

    const sandbox = {
        jQuery,
        $: h.$,
        AnchorFM: {
            ajax: '/wp-admin/admin-ajax.php',
            nonce: 'testnonce',
            isAdmin: true,
            user: { id: 1 },
            i18n: { download: 'Download', permissions: 'Permissions', delete: 'Delete', noFolders: 'None' },
            productDocsFolderId: 0,
        },
        window: { Intl, addEventListener() {}, setTimeout, clearTimeout, location: { hash: '' }, matchMedia: () => ({ matches: false, addEventListener() {} }) },
        document: { createElement: () => ({ style: {}, click() {}, setAttribute() {} }), body: { appendChild() {}, removeChild() {} }, addEventListener() {}, documentElement: { style: { setProperty() {} } } },
        console,
        setTimeout, clearTimeout, setInterval, clearInterval,
        Intl, JSON, Math, Date, Object, Array, String, Number, Boolean, RegExp, Error, Promise, Set, Map,
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        navigator: { userAgent: 'node' },
    };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(SRC, 'utf8'), sandbox, { filename: 'file-manager.js' });

    if (h.ready.length !== 1) {
        throw new Error('expected one jQuery ready callback, got ' + h.ready.length);
    }
    h.ready[0](h.$);
    return h;
}

/* ------------------------------------------------------------------------ */

function expandHandler(h) {
    const found = h.handlers.filter(x => x.event === 'click' && x.selector === '[data-afm-row-expand]');
    if (found.length !== 1) throw new Error('expected exactly one expand click handler, found ' + found.length);
    return found[0].fn;
}

function clickExpand(h, folderId) {
    const el = h.node('[data-afm-row-expand]');
    h.NODE_DATA.set(el, { 'afm-row-expand': folderId });
    const ev = { stopPropagation() {}, target: el };
    expandHandler(h).call(el, ev);
}

function settleListPost(h, payload) {
    const post = h.posts.find(p => p.action === 'anchor_fm_list' && !p.settled);
    if (!post) throw new Error('no pending anchor_fm_list request');
    post.settled = true;
    post.deferred.resolve({ success: true, data: payload });
    return post;
}

function listPayload(folderId, extra) {
    return Object.assign({
        folderId,
        breadcrumbs: [],
        folders: [], links: [], files: [], videos: [],
        capability: 'manage',
        isProductDocs: false,
    }, extra || {});
}

const CONTENTS = {
    '7': {
        folders: [],
        links: [],
        files: [{ id: 42, name: 'Guide.pdf', mime: 'application/pdf', size: 10, createdAt: '2026-01-01' }],
        videos: [],
    },
    '9': { folders: [], links: [], files: [], videos: [] },
};

console.log('--- client folder expansion ---');

// The bootstrap + first listing that every session begins with.
function boot(h, contents) {
    const bs = h.posts.find(p => p.action === 'anchor_fm_bootstrap');
    bs.settled = true;
    bs.deferred.resolve({ success: true, data: { tree: [], defaultFolderId: 0, productDocsFolderId: 0 } });
    settleListPost(h, listPayload(0, contents ? { contents } : {}));
}

// 1. It loads, and wires exactly one expand handler.
{
    const h = load();
    check('boots and issues anchor_fm_bootstrap',
        h.posts.filter(p => p.action === 'anchor_fm_bootstrap').length, 1);
    boot(h, CONTENTS);
    check('one expand handler registered',
        h.handlers.filter(x => x.event === 'click' && x.selector === '[data-afm-row-expand]').length, 1);
}

// 2. THE POINT OF THE RELEASE: a preloaded folder expands with no request.
{
    const h = load();
    boot(h, CONTENTS);
    const before = h.posts.length;
    clickExpand(h, 7);
    check('expanding a preloaded folder issues NO request', h.posts.length - before, 0);
}

// 3. Without a preload it still works, and asks exactly once.
{
    const h = load();
    boot(h, null);                       // server withheld contents (large store)
    const before = h.posts.length;
    clickExpand(h, 7);
    check('cold expand issues exactly one request', h.posts.length - before, 1);
    settleListPost(h, listPayload(7, { files: CONTENTS['7'].files }));

    clickExpand(h, 7);                   // collapse
    const afterCollapse = h.posts.length;
    clickExpand(h, 7);                   // re-expand
    check('re-expanding after collapse issues no further request',
        h.posts.length - afterCollapse, 0);
}

// 4. Hover warms the cache, and the click that follows does not re-ask.
{
    const h = load();
    boot(h, null);
    const hover = h.handlers.find(x => x.event === 'mouseenter focus' && x.selector === '[data-afm-row-expand]');
    check('hover prefetch handler registered', !!hover, true);

    const el = h.node('[data-afm-row-expand]');
    h.NODE_DATA.set(el, { 'afm-row-expand': 7 });
    const before = h.posts.length;
    hover.fn.call(el);
    check('hover issues one prefetch', h.posts.length - before, 1);
    settleListPost(h, listPayload(7, { files: CONTENTS['7'].files }));

    const afterHover = h.posts.length;
    clickExpand(h, 7);
    check('the click after a hover issues no request', h.posts.length - afterHover, 0);
}

// 5. Two fast hovers on the same folder share one in-flight request.
{
    const h = load();
    boot(h, null);
    const hover = h.handlers.find(x => x.event === 'mouseenter focus' && x.selector === '[data-afm-row-expand]');
    const el = h.node('[data-afm-row-expand]');
    h.NODE_DATA.set(el, { 'afm-row-expand': 7 });
    const before = h.posts.length;
    hover.fn.call(el);
    hover.fn.call(el);
    hover.fn.call(el);
    check('repeated hovers do not stack requests', h.posts.length - before, 1);
}

// 5b. Clicking while the hover's fetch is still in flight must join it rather
//     than start a second one. This is the path prefetchChildren's own guard
//     does NOT cover -- it is fetchChildren returning the pending promise.
{
    const h = load();
    boot(h, null);
    const hover = h.handlers.find(x => x.event === 'mouseenter focus' && x.selector === '[data-afm-row-expand]');
    const el = h.node('[data-afm-row-expand]');
    h.NODE_DATA.set(el, { 'afm-row-expand': 7 });

    const before = h.posts.length;
    hover.fn.call(el);                  // fetch starts, still in flight
    clickExpand(h, 7);                  // impatient click before it lands
    check('a click during an in-flight prefetch joins it instead of duplicating',
        h.posts.length - before, 1);

    settleListPost(h, listPayload(7, { files: CONTENTS['7'].files }));
    const afterSettle = h.posts.length;
    clickExpand(h, 7);                  // collapse
    clickExpand(h, 7);                  // expand again, now from cache
    check('and the result is cached once it lands', h.posts.length - afterSettle, 0);
}

// 6. A fetch still in flight across a navigation must not seed the cache the
//    navigation just cleared. This is the generation-token guard; without it
//    the stale callback repopulates childCache and also deletes a newer
//    pending entry for the same id.
{
    const h = load();
    boot(h, null);

    const hover = h.handlers.find(x => x.event === 'mouseenter focus' && x.selector === '[data-afm-row-expand]');
    const el = h.node('[data-afm-row-expand]');
    h.NODE_DATA.set(el, { 'afm-row-expand': 7 });
    hover.fn.call(el);                                   // fetch for folder 7 starts
    const stale = h.posts.find(p => p.action === 'anchor_fm_list' && !p.settled);

    // Navigate elsewhere while it is in flight.
    const open = h.handlers.find(x => x.event === 'click' && x.selector === '[data-afm-open-folder]');
    check('folder-open handler registered', !!open, true);
    const crumb = h.node('[data-afm-open-folder]');
    h.NODE_DATA.set(crumb, { 'afm-open-folder': 9 });
    open.fn.call(crumb, { stopPropagation() {}, preventDefault() {}, target: crumb });
    settleListPost(h, listPayload(9));                   // the navigation's own listing

    // Now the pre-navigation fetch comes back.
    stale.settled = true;
    stale.deferred.resolve({ success: true, data: listPayload(7, { files: CONTENTS['7'].files }) });

    const before = h.posts.length;
    clickExpand(h, 7);
    check('a fetch that outlived a navigation does not seed the cache',
        h.posts.length - before, 1);
}

console.log(failures === 0 ? '\nALL PASS\n' : `\n${failures} FAILURE(S)\n`);
process.exit(failures === 0 ? 0 : 1);
