/* ELXAO Chat - Message status UI (sent/delivered/read) */

(function(){
  function statusFromData(msg){
    if (msg.read_at) return 'read';
    if (msg.delivered_at) return 'delivered';
    return msg.status || 'sent';
  }

  function initStatuses(scope){
    (scope || document).querySelectorAll('.elxao-message').forEach(function(node){
      if(node.getAttribute('data-incoming') === '1'){
        const stray = node.querySelector('.elxao-msg-status');
        if(stray){ stray.remove(); }
        return;
      }
      const msg = {
        status: node.getAttribute('data-status'),
        delivered_at: node.getAttribute('data-delivered-at'),
        read_at: node.getAttribute('data-read-at')
      };
      const s = statusFromData(msg);

      let statusEl = node.querySelector('.elxao-msg-status');
      if (!statusEl) {
        statusEl = document.createElement('span');
        statusEl.className = 'elxao-msg-status';
        statusEl.innerHTML = '<i class="icon" aria-hidden="true"></i>';
        // Essaie de l’insérer dans la zone meta si elle existe, sinon à la fin
        const metaContainer = node.querySelector('.elxao-chat-meta') || node.querySelector('.meta') || node;
        metaContainer.appendChild(statusEl);
      }
      statusEl.classList.remove('sent','delivered','read');
      statusEl.classList.add(s);
      statusEl.setAttribute('aria-label', s);
      node.setAttribute('data-status', s);
    });
  }

  function isAtBottom(box){
    if(!box) return false;
    var diff = box.scrollHeight - box.scrollTop - box.clientHeight;
    return diff <= 12;
  }

  function highestVisibleIncomingId(box){
    if(!box) return 0;
    var containerRect = box.getBoundingClientRect();
    var maxId = 0;
    box.querySelectorAll('.elxao-message[data-id][data-incoming="1"]').forEach(function(node){
      if(node.offsetParent === null) return;
      var rect = node.getBoundingClientRect();
      if(rect.bottom <= containerRect.bottom + 1 && rect.top >= containerRect.top - 1){
        var id = parseInt(node.getAttribute('data-id'), 10);
        if(Number.isFinite(id) && id > maxId){ maxId = id; }
      }
    });
    return maxId;
  }

  function markVisibleAsRead() {
    if (document.visibilityState === 'hidden') return;
    if (typeof window.ELXAO_CHAT_MARK_READ !== 'function') return;

    document.querySelectorAll('.elxao-chat-window').forEach(function(win){
      if(win.offsetParent === null) return;
      var chatId = parseInt(win.getAttribute('data-chat'), 10);
      if(!chatId) return;
      var box = win.querySelector('.elxao-chat-messages');
      if(!box) return;
      if(!isAtBottom(box)) return;
      var maxId = highestVisibleIncomingId(box);
      if(maxId){
        window.ELXAO_CHAT_MARK_READ(chatId, maxId);
      }
    });
  }

  // Initialise au chargement
  document.addEventListener('DOMContentLoaded', function(){
    initStatuses(document);
    markVisibleAsRead();
  });

  // Expose si tu re-rendes dynamiquement
  window.ELXAO_STATUS_UI = { initStatuses, markVisibleAsRead };

  document.addEventListener('visibilitychange', function(){
    if(document.visibilityState !== 'hidden'){ markVisibleAsRead(); }
  });
})();
