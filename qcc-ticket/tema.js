/**
 * KIAMI — Alternância de tema (modo escuro / modo claro)
 * ---------------------------------------------------------
 * - A preferência do utilizador fica guardada em localStorage.
 * - O tema é aplicado imediatamente (antes do body pintar) para evitar
 *   o "flash" de cor errada ao carregar a página.
 * - É criado um botão flutuante no canto inferior direito para alternar.
 */
(function () {
    'use strict';

    // Chave usada para persistir a escolha do utilizador
    var CHAVE = 'kiami_tema';

    // Devolve o tema guardado ('light' ou 'dark'). Por defeito é 'dark'.
    function obterTema() {
        return localStorage.getItem(CHAVE) === 'light' ? 'light' : 'dark';
    }

    // Aplica o tema ao elemento <html> através do atributo data-theme.
    function aplicar(tema) {
        if (tema === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        atualizarBotao(tema);
    }

    // Actualiza o texto/ícone do botão consoante o tema activo.
    function atualizarBotao(tema) {
        var btn = document.getElementById('kiami-tema-toggle');
        if (btn) {
            btn.innerHTML = tema === 'light' ? '🌙 Modo Escuro' : '☀️ Modo Claro';
            btn.setAttribute('aria-label', tema === 'light' ? 'Activar modo escuro' : 'Activar modo claro');
        }
    }

    // Aplica de imediato (script no <head>, corre antes do body renderizar)
    aplicar(obterTema());

    // Quando o DOM estiver pronto, cria o botão flutuante de alternância.
    document.addEventListener('DOMContentLoaded', function () {
        if (document.getElementById('kiami-tema-toggle')) {
            return; // já existe (evita duplicados)
        }
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
    });
})();
