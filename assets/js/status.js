/* ELXAO Chat - Message status UI (sent/delivered/read) */

(function () {
  // Public API holder so chat.js (or others) can call us
  window.ELXAO_STATUS_UI = window.ELXAO_STATUS_UI || {};

  function statusFromData(node) {
    // Priority: explicit read_at → read; delivered_at → delivered; fallback to data-status
    var readAt = node.getAttribute('data-read-at');
    var deliveredAt = node.getAttribute('data-delivered-at');
    var st = node.getAttribute('data-status');
    if (readAt && readAt !== '0' && readAt !== '') return 'read';
    if (deliveredAt && deliveredAt !== '0' && deliveredAt !== '') return 'delivered';
    return st || 'sent';
  }

  function ensureBadge(metaEl) {
    var badge = metaEl.querySelector('.elxao-msg-status');
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'elxao-msg-status';
      var i = document.createElement('i');
      i.className = 'icon';
      i.setAttribute('aria-hidden', 'true');
      badge.appendChild(i);
      metaEl.appendChild(badge);
    }
    return badge;
  }

  function applyTick(node) {
    // Only for messages I sent (outgoing); incoming messages show no ticks
    var incoming = node.getAttribute('data-incoming') === '1';
    var meta = node.querySelector('.elxao-chat-meta');
    if (!meta) return;

    var badge = meta.querySelector('.elxao-msg-status');
    if (incoming) {
      if (badge) badge.remove();
      return;
    }

    badge = ensureBadge(meta);
    // Reset classes
    badge.classList.remove('sent', 'delivered', 'read');
    var st = statusFromData(node);
    badge.classList.add(st);
  }

  function initStatuses(scope) {
    (scope || document).querySelectorAll('.elxao-message').forEach(function (node) {
      applyTick(node);
    });
  }

  // Collect IDs of visible incoming messages to mark as read
  function getVisibleIncomingMessageIds() {
    var ids = [];
    var container = document.querySelector('.elxao-chat-messages');
    if (!container) return ids;

    var rectC = container.getBoundingClientRect();
    var nodes = container.querySelectorAll('.elxao-message[data-incoming="1"]');

    nodes.forEach(function (n) {
      var id = n.getAttribute('data-id');
      if (!id) return;

      var r = n.getBoundingClientRect();
      var verticallyVisible = r.top < rectC.bottom && r.bottom > rectC.top;
      var horizontallyVisible = r.left < rectC.right && r.right > rectC.left;

      if (verticallyVisible && horizontallyVisible) {
        ids.push(parseInt(id, 10));
      }
    });
    return ids;
  }

  var markBusy = false;
  function postRead(ids) {
    if (!window.ELXAO_STATUS || !ELXAO_STATUS.restUrl) return;
    var chatId = (window.ELXAO_CHAT && window.ELXAO_CHAT.chatId) ? parseInt(ELXAO_CHAT.chatId, 10) : 0;
    if (!chatId || !ids.length) return;

    markBusy = true;
    fetch(ELXAO_STATUS.restUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': ELXAO_STATUS.nonce || ''
      },
      body: JSON.stringify({ ids: ids, chat_id: chatId })
    })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function () {
        // Notify inbox UI to clear unread badges for this chat
        var evt = new CustomEvent('elxaoChatRead', { detail: { chatId: chatId, ids: ids } });
        document.dispatchEvent(evt);
      })
      .catch(function () { /* swallow */ })
      .finally(function () { markBusy = false; });
  }

  var readThrottle;
  function markVisibleAsRead() {
    if (markBusy) return;
    clearTimeout(readThrottle);
    readThrottle = setTimeout(function () {
      if (document.hidden) return;
      var ids = getVisibleIncomingMessageIds();
      if (ids.length) postRead(ids);
    }, 150);
  }

  function refreshAllTicks(scope) {
    (scope || document).querySelectorAll('.elxao-message').forEach(applyTick);
  }

  // Expose a small public API
  window.ELXAO_STATUS_UI.initStatuses = function (scope) {
    initStatuses(scope);
    markVisibleAsRead();
  };
  window.ELXAO_STATUS_UI.markVisibleAsRead = markVisibleAsRead;
  window.ELXAO_STATUS_UI.refreshAllTicks = refreshAllTicks;

  // Re-mark when the DOM changes (new messages appended, chat switches, etc.)
  var mo = new MutationObserver(function (mutations) {
    var needsInit = false;
    mutations.forEach(function (m) {
      m.addedNodes && m.addedNodes.forEach(function (node) {
        if (node.nodeType !== 1) return;
        if (node.matches && node.matches('.elxao-message, .elxao-chat-messages, .elxao-chat-window')) {
          needsInit = true;
        } else if (node.querySelector && node.querySelector('.elxao-chat-messages')) {
          needsInit = true;
        }
      });
    });
    if (needsInit) {
      initStatuses();
      requestAnimationFrame(markVisibleAsRead);
    }
  });
  mo.observe(document.documentElement || document.body, { subtree: true, childList: true });

  // Also re-run when tab becomes visible again
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      refreshAllTicks();
      markVisibleAsRead();
    }
  });

  // First run
  initStatuses();
  markVisibleAsRead();
})();
