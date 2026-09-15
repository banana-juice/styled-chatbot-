// ============================================
// CHAT WIDGET — js/chatbot.js
// ============================================
// Floating customer-support chat bubble, powered by Claude via
// php/chatbot.php. This file only renders UI and relays messages — every
// piece of account data it displays is decided server-side, scoped to the
// logged-in session user. Loaded after js/main.js (uses getCurrentUser()).

(function () {
  const STORAGE_KEY = "styled_chat_history";
  const MAX_HISTORY = 8; // messages kept for conversational context

  let panelEl, messagesEl, formEl, inputEl, toggleBtn, sendBtn;
  let sending = false;

  function loadHistory() {
    try {
      return JSON.parse(sessionStorage.getItem(STORAGE_KEY)) || [];
    } catch (e) {
      return [];
    }
  }

  function saveHistory(history) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history));
    } catch (e) {
      // Ignore quota / privacy-mode errors — chat still works, just
      // without persistence across page loads.
    }
  }

  function buildWidget() {
    const wrap = document.createElement("div");
    wrap.className = "chat-widget";
    wrap.innerHTML = [
      '<button class="chat-bubble-btn" id="chat-bubble-btn" aria-label="Open customer support chat" aria-expanded="false">',
      '  <span aria-hidden="true">💬</span>',
      "</button>",
      '<div class="chat-panel" id="chat-panel" hidden>',
      '  <div class="chat-panel-header">',
      "    <span>Styled Support</span>",
      '    <button type="button" class="chat-close-btn" id="chat-close-btn" aria-label="Close chat">&times;</button>',
      "  </div>",
      '  <div class="chat-messages" id="chat-messages"></div>',
      '  <form class="chat-input-row" id="chat-form">',
      '    <input type="text" id="chat-input" placeholder="Ask about your order…" autocomplete="off" maxlength="1000" />',
      '    <button type="submit" id="chat-send-btn" aria-label="Send message">→</button>',
      "  </form>",
      "</div>",
    ].join("");
    document.body.appendChild(wrap);

    toggleBtn = wrap.querySelector("#chat-bubble-btn");
    panelEl = wrap.querySelector("#chat-panel");
    messagesEl = wrap.querySelector("#chat-messages");
    formEl = wrap.querySelector("#chat-form");
    inputEl = wrap.querySelector("#chat-input");
    sendBtn = wrap.querySelector("#chat-send-btn");

    toggleBtn.addEventListener("click", togglePanel);
    wrap.querySelector("#chat-close-btn").addEventListener("click", closePanel);
    formEl.addEventListener("submit", onSubmit);
  }

  function togglePanel() {
    if (panelEl.hidden) {
      openPanel();
    } else {
      closePanel();
    }
  }

  function openPanel() {
    panelEl.hidden = false;
    toggleBtn.setAttribute("aria-expanded", "true");
    if (!messagesEl.children.length) {
      renderHistoryOrGreeting();
    }
    inputEl.focus();
  }

  function closePanel() {
    panelEl.hidden = true;
    toggleBtn.setAttribute("aria-expanded", "false");
  }

  function renderHistoryOrGreeting() {
    const history = loadHistory();
    if (history.length === 0) {
      appendMessage(
        "assistant",
        "Hi! I'm the Styled support assistant. I can help with questions about your orders, sizing, shipping, and returns. How can I help today?",
        false,
      );
      return;
    }
    history.forEach((m) => appendMessage(m.role, m.content, false));
    scrollToBottom();
  }

  function appendMessage(role, text, persist) {
    const bubble = document.createElement("div");
    bubble.className = "chat-msg chat-msg-" + (role === "user" ? "user" : "assistant");
    bubble.textContent = text;
    messagesEl.appendChild(bubble);
    scrollToBottom();

    if (persist) {
      const history = loadHistory();
      history.push({ role: role, content: text });
      while (history.length > MAX_HISTORY) history.shift();
      saveHistory(history);
    }
  }

  function scrollToBottom() {
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function setSending(isSending) {
    sending = isSending;
    sendBtn.disabled = isSending;
    inputEl.disabled = isSending;
  }

  async function onSubmit(e) {
    e.preventDefault();
    if (sending) return;

    const text = inputEl.value.trim();
    if (!text) return;

    // Quick client-side UX hint only — php/chatbot.php enforces the real
    // session check server-side and returns 401 if the session is gone.
    const user = typeof getCurrentUser === "function" ? getCurrentUser() : null;
    if (!user) {
      inputEl.value = "";
      appendMessage(
        "assistant",
        "Please log in to your account to chat with support about your orders.",
        false,
      );
      return;
    }

    // Capture prior turns for context before this message is added.
    const priorHistory = loadHistory();

    appendMessage("user", text, true);
    inputEl.value = "";
    setSending(true);

    const typingEl = document.createElement("div");
    typingEl.className = "chat-msg chat-msg-assistant chat-msg-typing";
    typingEl.textContent = "…";
    messagesEl.appendChild(typingEl);
    scrollToBottom();

    try {
      const res = await fetch("php/chatbot.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify({ message: text, history: priorHistory }),
      });

      typingEl.remove();

      if (res.status === 401) {
        appendMessage(
          "assistant",
          "Your session has expired. Please log in again to continue chatting.",
          false,
        );
        return;
      }

      const data = await res.json();
      if (data.success) {
        appendMessage("assistant", data.reply, true);
      } else {
        appendMessage(
          "assistant",
          data.error || "Sorry, something went wrong. Please try again in a moment.",
          false,
        );
      }
    } catch (err) {
      typingEl.remove();
      appendMessage(
        "assistant",
        "Sorry, I couldn't reach support right now. Please check your connection and try again.",
        false,
      );
    } finally {
      setSending(false);
    }
  }

  document.addEventListener("DOMContentLoaded", buildWidget);
})();
