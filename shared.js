// ══════════════════════════════════════════════════════
// PARASITE — shared.js
// API helpers, auth, and utilities used across all pages
// ══════════════════════════════════════════════════════

const API = 'http://localhost/parasite_backend';

// ── Auth helpers ──────────────────────────────────────
function getToken()         { return localStorage.getItem('parasite_token'); }
function getUser()          { return JSON.parse(localStorage.getItem('parasite_user') || 'null'); }
function setAuth(token, user){ localStorage.setItem('parasite_token', token); localStorage.setItem('parasite_user', JSON.stringify(user)); }
function clearAuth()        { localStorage.removeItem('parasite_token'); localStorage.removeItem('parasite_user'); }
function isLoggedIn()       { return !!getToken(); }

// Redirect to login if not authenticated
function requireAuth() {
    if (!isLoggedIn()) {
        window.location.href = 'login.html';
        return false;
    }
    return true;
}

// ── API call helper ───────────────────────────────────
async function api(endpoint, method, body) {
    method = method || 'GET';
    var opts = {
        method: method,
        headers: { 'Content-Type': 'application/json' }
    };
    var token = getToken();
    if (token) opts.headers['Authorization'] = 'Bearer ' + token;
    if (body)  opts.body = JSON.stringify(body);

    try {
        var res  = await fetch(API + '/' + endpoint, opts);
        var data = await res.json();
        return data;
    } catch(e) {
        console.error('API error:', e);
        return { success: false, error: e.message };
    }
}

// ── Nav active link ───────────────────────────────────
function setActiveNav() {
    var page = window.location.pathname.split('/').pop();
    document.querySelectorAll('.nav-links a').forEach(function(a) {
        var href = a.getAttribute('href');
        if (href === page) {
            a.style.color = 'var(--accent)';
        }
    });

    // Show/hide nav items based on auth
    var user = getUser();
    var navCta = document.querySelector('.nav-cta');
    if (user && navCta) {
        navCta.textContent = user.name;
        navCta.onclick = function() { window.location.href = 'dashboard.html'; };
    }
}

// ── Format money ──────────────────────────────────────
function fmt(amount) {
    return '₦' + parseFloat(amount || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ── Animate counter ───────────────────────────────────
function animCount(el, target, prefix, suffix, dur) {
    prefix = prefix || '';
    suffix = suffix || '';
    dur    = dur    || 2000;
    if (!el) return;
    var start = null;
    (function step(ts) {
        if (!start) start = ts;
        var p = Math.min((ts - start) / dur, 1);
        var e = 1 - Math.pow(1 - p, 3);
        el.textContent = prefix + Math.floor(e * target).toLocaleString() + suffix;
        if (p < 1) requestAnimationFrame(step);
    })(performance.now());
}

// ── Scroll reveal ─────────────────────────────────────
function initReveal() {
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
            if (e.isIntersecting) e.target.classList.add('visible');
        });
    }, { threshold: 0.1 });
    document.querySelectorAll('.reveal').forEach(function(el) { observer.observe(el); });
}

// ── Modal ─────────────────────────────────────────────
function openModal()  { var m = document.getElementById('modal'); if(m) m.classList.add('open'); }
function closeModal() { var m = document.getElementById('modal'); if(m) m.classList.remove('open'); }

// ── Run on every page ─────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    setActiveNav();
    initReveal();
    var modal = document.getElementById('modal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
    }
});
