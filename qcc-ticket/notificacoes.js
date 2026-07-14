/*
 * KIAMI — Alertas de ambiente de trabalho para novos tickets
 *
 * Este script consulta periodicamente o endpoint api/notificacoes.php e,
 * sempre que surge uma notificação nova (ex.: ticket aberto para a área do
 * utilizador), dispara um alerta nativo do sistema operativo / navegador,
 * semelhante às notificações do computador.
 *
 * Inclusão: <script src="notificacoes.js"></script> antes de </body>.
 */
(function () {
    "use strict";

    // Intervalo entre consultas ao servidor (milissegundos)
    var INTERVALO_MS = 20000;
    // Endpoint que devolve as notificações não lidas
    var ENDPOINT = "api/notificacoes.php";
    // Chave em localStorage que guarda o maior id de notificação já visto
    var CHAVE_ULTIMO_ID = "kiami_ultima_notificacao_id";

    // Pede autorização para notificações nativas do SO (uma única vez).
    // Mesmo sem autorização, os pop-ups (toasts) dentro do navegador continuam a funcionar.
    function pedirPermissao() {
        if (("Notification" in window) && Notification.permission === "default") {
            Notification.requestPermission();
        }
    }

    // Lê o último id visto (0 se ainda não houver)
    function obterUltimoId() {
        var v = parseInt(localStorage.getItem(CHAVE_ULTIMO_ID) || "0", 10);
        return isNaN(v) ? 0 : v;
    }

    function guardarUltimoId(id) {
        localStorage.setItem(CHAVE_ULTIMO_ID, String(id));
    }

    // Mostra o alerta nativo do sistema operativo (se o utilizador autorizou)
    function mostrarAlerta(notif) {
        if (!("Notification" in window) || Notification.permission !== "granted") {
            return;
        }
        var titulo = notif.tipo || "KIAMI";
        var corpo = notif.mensagem || "Nova notificação no sistema.";
        try {
            var n = new Notification("🔔 " + titulo, {
                body: corpo,
                tag: "kiami-" + notif.id,
                requireInteraction: false
            });
            // Ao clicar, abre os detalhes do ticket (se existir) ou o painel
            n.onclick = function () {
                window.focus();
                if (notif.id_ticket) {
                    window.location.href = "ticket_detalhes.php?id=" + notif.id_ticket;
                } else {
                    window.location.href = "index.php";
                }
                n.close();
            };
        } catch (e) {
            // Alguns navegadores exigem gesto do utilizador; ignora silenciosamente
        }
    }

    // Garante que existe o contentor dos pop-ups no canto do ecrã
    function obterContentorToasts() {
        var c = document.getElementById("kiami-toasts");
        if (!c) {
            c = document.createElement("div");
            c.id = "kiami-toasts";
            c.className = "kiami-toasts";
            document.body.appendChild(c);
        }
        return c;
    }

    /*
     * Mostra um pop-up (toast) DENTRO do navegador, sempre visível, mesmo que
     * o utilizador não tenha autorizado as notificações nativas do sistema.
     * Desaparece sozinho ao fim de alguns segundos ou ao clicar em fechar.
     */
    function mostrarToast(notif) {
        if (!document.body) {
            return;
        }
        var contentor = obterContentorToasts();

        var titulo = notif.tipo || "KIAMI";
        var corpo = notif.mensagem || "Nova notificação no sistema.";

        var toast = document.createElement("div");
        toast.className = "kiami-toast";

        var head = document.createElement("div");
        head.className = "kiami-toast-head";

        var strong = document.createElement("strong");
        strong.textContent = "🔔 " + titulo;
        head.appendChild(strong);

        var fechar = document.createElement("span");
        fechar.className = "kiami-toast-close";
        fechar.textContent = "×";
        fechar.setAttribute("role", "button");
        fechar.setAttribute("aria-label", "Fechar");
        head.appendChild(fechar);

        var body = document.createElement("div");
        body.className = "kiami-toast-body";
        body.textContent = corpo;

        toast.appendChild(head);
        toast.appendChild(body);
        contentor.appendChild(toast);

        // Animação de entrada
        requestAnimationFrame(function () {
            toast.classList.add("visivel");
        });

        var timer = null;

        function remover() {
            if (timer) {
                clearTimeout(timer);
            }
            toast.classList.remove("visivel");
            setTimeout(function () {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }

        // Clicar no corpo abre o ticket relacionado
        body.style.cursor = "pointer";
        body.addEventListener("click", function () {
            if (notif.id_ticket) {
                window.location.href = "ticket_detalhes.php?id=" + notif.id_ticket;
            } else {
                window.location.href = "index.php";
            }
        });

        fechar.addEventListener("click", remover);

        // Fecha automaticamente ao fim de 8 segundos
        timer = setTimeout(remover, 8000);
    }

    // Apresenta a notificação nova nos dois formatos: pop-up no navegador + nativo
    function apresentarNotificacao(notif) {
        mostrarToast(notif);
        mostrarAlerta(notif);
    }

    // Consulta o servidor e processa as notificações novas
    function verificarNotificacoes() {
        fetch(ENDPOINT, { credentials: "same-origin" })
            .then(function (resp) {
                if (!resp.ok) {
                    throw new Error("Falha na resposta");
                }
                return resp.json();
            })
            .then(function (data) {
                var lista = (data && data.notificacoes) || [];
                if (lista.length === 0) {
                    return;
                }

                var ultimoId = obterUltimoId();
                // As notificações vêm da mais recente para a mais antiga
                var maiorId = ultimoId;
                var novas = [];

                lista.forEach(function (notif) {
                    var id = parseInt(notif.id, 10);
                    if (id > ultimoId) {
                        novas.push(notif);
                    }
                    if (id > maiorId) {
                        maiorId = id;
                    }
                });

                // Primeira execução (sem histórico): apenas define a base, sem alertar
                if (ultimoId === 0) {
                    guardarUltimoId(maiorId);
                    return;
                }

                // Mostra as novas da mais antiga para a mais recente
                novas.reverse().forEach(apresentarNotificacao);
                guardarUltimoId(maiorId);
            })
            .catch(function () {
                // Ignora erros de rede/sessão sem interromper a página
            });
    }

    // Arranque
    pedirPermissao();
    verificarNotificacoes();
    setInterval(verificarNotificacoes, INTERVALO_MS);
})();
