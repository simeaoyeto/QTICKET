/**
 * KIAMI — Tema (claro/escuro) + navegação responsiva
 * ---------------------------------------------------------
 * - Preferência de tema em localStorage (sem flash ao carregar).
 * - Em ecrãs ≤900px: menu lateral off-canvas com botão hamburger.
 */
(function () {
    'use strict';

    var CHAVE = 'kiami_tema';
    var MQ_MOBILE = window.matchMedia('(max-width: 900px)');

    function obterTema() {
        return localStorage.getItem(CHAVE) === 'light' ? 'light' : 'dark';
    }

    function aplicar(tema) {
        if (tema === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        atualizarBotao(tema);
    }

    function atualizarBotao(tema) {
        var btn = document.getElementById('kiami-tema-toggle');
        if (!btn) return;
        var mobile = window.matchMedia('(max-width: 480px)').matches;
        if (mobile) {
            btn.innerHTML = tema === 'light' ? '🌙' : '☀️';
            btn.title = tema === 'light' ? 'Activar modo escuro' : 'Activar modo claro';
        } else {
            btn.innerHTML = tema === 'light' ? '🌙 Modo Escuro' : '☀️ Modo Claro';
        }
        btn.setAttribute('aria-label', tema === 'light' ? 'Activar modo escuro' : 'Activar modo claro');
    }

    aplicar(obterTema());

    function fecharSidebar() {
        document.body.classList.remove('sidebar-aberta');
        var overlay = document.getElementById('kiami-sidebar-overlay');
        if (overlay) overlay.classList.remove('visivel');
        var btn = document.getElementById('kiami-menu-btn');
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }

    function abrirSidebar() {
        document.body.classList.add('sidebar-aberta');
        var overlay = document.getElementById('kiami-sidebar-overlay');
        if (overlay) overlay.classList.add('visivel');
        var btn = document.getElementById('kiami-menu-btn');
        if (btn) btn.setAttribute('aria-expanded', 'true');
    }

    function alternarSidebar() {
        if (document.body.classList.contains('sidebar-aberta')) {
            fecharSidebar();
        } else {
            abrirSidebar();
        }
    }

    function inicializarMenuMobile() {
        var layout = document.querySelector('.app-layout');
        var sidebar = document.getElementById('sidebar');
        var main = document.getElementById('main-content');
        if (!layout || !sidebar || !main) return;

        // Overlay
        if (!document.getElementById('kiami-sidebar-overlay')) {
            var overlay = document.createElement('div');
            overlay.id = 'kiami-sidebar-overlay';
            overlay.className = 'sidebar-overlay';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.addEventListener('click', fecharSidebar);
            document.body.appendChild(overlay);
        }

        // Botão fechar dentro da sidebar
        if (!document.getElementById('kiami-sidebar-close')) {
            var closeBtn = document.createElement('button');
            closeBtn.id = 'kiami-sidebar-close';
            closeBtn.type = 'button';
            closeBtn.className = 'sidebar-close-btn';
            closeBtn.setAttribute('aria-label', 'Fechar menu');
            closeBtn.innerHTML = '✕';
            closeBtn.addEventListener('click', fecharSidebar);
            sidebar.insertBefore(closeBtn, sidebar.firstChild);
        }

        // Barra superior no conteúdo
        if (!document.getElementById('kiami-mobile-topbar')) {
            var topbar = document.createElement('div');
            topbar.id = 'kiami-mobile-topbar';
            topbar.className = 'mobile-topbar';
            topbar.innerHTML =
                '<button type="button" id="kiami-menu-btn" class="mobile-menu-btn" aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebar">☰</button>' +
                '<span class="mobile-topbar-brand">KIAMI</span>';
            main.insertBefore(topbar, main.firstChild);

            document.getElementById('kiami-menu-btn').addEventListener('click', alternarSidebar);
        }

        // Fechar ao clicar num link do menu (navegação)
        sidebar.querySelectorAll('a.nav-item, a.btn-danger').forEach(function (link) {
            link.addEventListener('click', function () {
                if (MQ_MOBILE.matches) fecharSidebar();
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') fecharSidebar();
        });

        function onMediaChange() {
            if (!MQ_MOBILE.matches) fecharSidebar();
            atualizarBotao(obterTema());
        }

        if (typeof MQ_MOBILE.addEventListener === 'function') {
            MQ_MOBILE.addEventListener('change', onMediaChange);
        } else if (typeof MQ_MOBILE.addListener === 'function') {
            MQ_MOBILE.addListener(onMediaChange);
        }

        window.addEventListener('resize', function () {
            atualizarBotao(obterTema());
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('kiami-tema-toggle')) {
            var btn = document.createElement('button');
            btn.id = 'kiami-tema-toggle';
            btn.className = 'theme-toggle';
            btn.type = 'button';
            btn.addEventListener('click', function () {
                var novo = obterTema() === 'light' ? 'dark' : 'light';
                localStorage.setItem(CHAVE, novo);
                aplicar(novo);
            });
            document.body.appendChild(btn);
            atualizarBotao(obterTema());
        }

        inicializarMenuMobile();
    });
})();
