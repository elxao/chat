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

  function parseId(node) {
    var raw = node ? node.getAttribute('data-id') : null;
    if (!raw) return 0;
    var id = parseInt(raw, 10);
    return isNaN(id) ? 0 : id;
  }

  function truthyAttr(node, attr, flag, value) {
    if (!node) return;
    if (flag) {
      node.setAttribute(attr, typeof value === 'undefined' ? '1' : String(value));
    } else {
      node.removeAttribute(attr);
    }
  }

  function stateAtThreshold(state, id) {
    if (!state) {
      return { delivered: false, read: false, has: false };
    }
    var delivered = (state.last_delivered || 0) >= id;
    var read = (state.last_read || 0) >= id;
    return { delivered: delivered, read: read, has: true };
  }

  function labelForState(state, id) {
    var info = stateAtThreshold(state, id);
    if (!info.has) return 'Sent';
    if (info.read) return 'Read';
    if (info.delivered) return 'Delivered';
    return 'Sent';
  }

  function setAdminBreakdown(node, id, participants) {
    if (!node) return;
    var client = participants && participants.client ? participants.client : null;
    var pm = participants && participants.pm ? participants.pm : null;

    var clientInfo = stateAtThreshold(client, id);
    var pmInfo = stateAtThreshold(pm, id);

    truthyAttr(node, 'data-client-delivered', clientInfo.has, clientInfo.delivered ? '1' : '0');
    truthyAttr(node, 'data-client-read', clientInfo.has, clientInfo.read ? '1' : '0');
    truthyAttr(node, 'data-pm-delivered', pmInfo.has, pmInfo.delivered ? '1' : '0');
    truthyAttr(node, 'data-pm-read', pmInfo.has, pmInfo.read ? '1' : '0');

    var title = 'Client: ' + labelForState(client, id) + ' — PM: ' + labelForState(pm, id);
    node.setAttribute('data-status-title', title);
  }

  function computeStatusFromParticipants(node, participants) {
    if (!node || !participants) return null;
    if (node.getAttribute('data-incoming') === '1') return null;

    var id = parseId(node);
    if (!id) return null;

    var role = node.getAttribute('data-sender-role') || '';
    if (role !== 'pm' && role !== 'client' && role !== 'admin') {
      role = 'admin';
    }
    var status = 'sent';
    var delivered = false;
    var read = false;

    function promote(state) {
      if (!state) return;
      if ((state.last_read || 0) >= id) {
        status = 'read';
        delivered = true;
        read = true;
      } else if ((state.last_delivered || 0) >= id) {
        if (!read) status = 'delivered';
        delivered = true;
      }
    }

    if (role === 'pm') {
      promote(participants.client);
    } else if (role === 'client') {
      promote(participants.pm);
    } else if (role === 'admin') {
      var targets = [];
      if (participants.client) {
        targets.push(participants.client);
      }
      if (participants.pm) {
        targets.push(participants.pm);
      }

      var hasState = false;
      var anyDelivered = false;
      var anyRead = false;

      targets.forEach(function (state) {
        if (!state) {
          return;
        }
        hasState = true;
        if ((state.last_delivered || 0) >= id) {
          anyDelivered = true;
        }
        if ((state.last_read || 0) >= id) {
          anyRead = true;
          anyDelivered = true;
        }
      });

      if (hasState && anyRead) {
        status = 'read';
        delivered = true;
        read = true;
      } else if (hasState && anyDelivered) {
        status = 'delivered';
        delivered = true;
        read = false;
      } else {
        status = 'sent';
        delivered = false;
        read = false;
      }

      setAdminBreakdown(node, id, participants);
    } else {
      // Fallback: take the "highest" status among known recipients
      promote(participants.client);
      promote(participants.pm);
    }

    return {
      id: id,
      status: status,
      delivered: delivered,
      read: read
    };
  }

  function applyStatusFromParticipants(node, participants) {
    var info = computeStatusFromParticipants(node, participants);
    if (!info) return;

    if (info.status) {
      node.setAttribute('data-status', info.status);
    } else {
      node.removeAttribute('data-status');
    }

    truthyAttr(node, 'data-delivered-at', info.delivered, info.id);
    truthyAttr(node, 'data-read-at', info.read, info.id);

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
  window.ELXAO_STATUS_UI.refreshFromParticipants = function (scope, participants) {
    if (!participants) return;
    var root = document;
    if (scope) {
      if (scope.jquery && scope.length) {
        scope = scope[0];
      }
      if (scope.querySelectorAll) {
        root = scope;
      } else if (scope.nodeType === 1 && scope.matches('.elxao-message, .elxao-chat-window, .elxao-chat-messages')) {
        root = scope;
      }
    }

    var nodes = [];
    if (root.matches && root.matches('.elxao-message')) {
      nodes.push(root);
    }
    if (root.querySelectorAll) {
      root.querySelectorAll('.elxao-message').forEach(function (node) {
        nodes.push(node);
      });
    }

    nodes.forEach(function (node) {
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
