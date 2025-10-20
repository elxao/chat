/* ELXAO Chat - Message status UI (sent/delivered/read) */

(function () {
  // Public API holder so chat.js can call us
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

    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'elxao-msg-status';
      var i = document.createElement('i');
      i.className = 'icon';
      i.setAttribute('aria-hidden', 'true');
      badge.appendChild(i);
      meta.appendChild(badge);
    }

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

  function normaliseParticipantState(raw) {
    if (!raw) return null;
    var lastDelivered = parseInt(
      raw.last_delivered !== undefined ? raw.last_delivered : (raw.lastDelivered || 0),
      10
    );
    var lastRead = parseInt(
      raw.last_read !== undefined ? raw.last_read : (raw.lastRead || 0),
      10
    );
    return {
      lastDelivered: isNaN(lastDelivered) ? 0 : lastDelivered,
      lastRead: isNaN(lastRead) ? 0 : lastRead,
    };
  }

  function computeStatusForMessage(messageId, senderRole, participants) {
    if (!messageId) return 'sent';
    if (!senderRole) senderRole = '';
    var role = String(senderRole).toLowerCase();
    var targets = [];

    if (role === 'pm') {
      if (participants && participants.client) {
        targets.push(normaliseParticipantState(participants.client));
      }
    } else if (role === 'client') {
      if (participants && participants.pm) {
        targets.push(normaliseParticipantState(participants.pm));
      }
    } else if (role === 'admin') {
      if (participants && participants.client) {
        targets.push(normaliseParticipantState(participants.client));
      }
      if (participants && participants.pm) {
        targets.push(normaliseParticipantState(participants.pm));
      }
    } else if (participants && participants[role]) {
      targets.push(normaliseParticipantState(participants[role]));
    }

    var hasTarget = false;
    var allDelivered = true;
    var allRead = true;

    targets.forEach(function (target) {
      if (!target) {
        allDelivered = false;
        allRead = false;
        return;
      }
      hasTarget = true;
      if (target.lastDelivered < messageId) {
        allDelivered = false;
      }
      if (target.lastRead < messageId) {
        allRead = false;
      }
    });

    if (!hasTarget) return 'sent';
    if (allRead) return 'read';
    if (allDelivered) return 'delivered';
    return 'sent';
  }

  function applyStatusFromParticipants(node, participants) {
    if (!node) return;
    var incomingAttr = node.getAttribute('data-incoming');
    var isOutgoing = incomingAttr === '0' || node.classList.contains('me');
    if (!isOutgoing) return;

    var id = parseInt(node.getAttribute('data-id') || '0', 10);
    if (!id) return;
    var role = node.getAttribute('data-sender-role') || '';
    var status = computeStatusForMessage(id, role, participants);

    if (status === 'read') {
      node.setAttribute('data-status', 'read');
      node.setAttribute('data-delivered-at', '1');
      node.setAttribute('data-read-at', '1');
    } else if (status === 'delivered') {
      node.setAttribute('data-status', 'delivered');
      node.setAttribute('data-delivered-at', '1');
      node.removeAttribute('data-read-at');
    } else {
      node.setAttribute('data-status', 'sent');
      node.removeAttribute('data-delivered-at');
      node.removeAttribute('data-read-at');
    }

    applyTick(node);
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
        // Optional: notify inbox UI to clear unread badges for this chat
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

  // Expose the small public API used by chat.js
  window.ELXAO_STATUS_UI.initStatuses = function (scope) {
    initStatuses(scope);
    markVisibleAsRead();
  };
  window.ELXAO_STATUS_UI.markVisibleAsRead = markVisibleAsRead;
  window.ELXAO_STATUS_UI.refreshAllTicks = refreshAllTicks;
  window.ELXAO_STATUS_UI.refreshFromParticipants = function (scope, participants) {
    if (!participants) return;
    var root = scope || document;
    root.querySelectorAll('.elxao-message').forEach(function (node) {
      applyStatusFromParticipants(node, participants);
    });
  };

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
      // Give layout a frame to settle, then mark as read
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
