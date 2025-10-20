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

  function isFiniteNumber(n){
    if (Number.isFinite) {
      return Number.isFinite(n);
    }
    return isFinite(n);
  }

  function toInt(value){
    var n = parseInt(value, 10);
    return isFiniteNumber(n) ? n : 0;
  }

  function normalizeState(entry){
    if(!entry) return null;
    return {
      role: entry.role || '',
      last_delivered: toInt(entry.last_delivered),
      last_read: toInt(entry.last_read)
    };
  }

  function statusFromTarget(messageId, target){
    if(!target) return null;
    if(target.last_read >= messageId) return 'read';
    if(target.last_delivered >= messageId) return 'delivered';
    return 'sent';
  }

  function computeStatusFromParticipants(node, participants){
    if(!participants || !node) return null;
    if(node.getAttribute('data-incoming') === '1') return null;
    var messageId = parseInt(node.getAttribute('data-id'), 10);
    if(!isFiniteNumber(messageId)) return null;
    var role = node.getAttribute('data-sender-role');
    if(!role) return null;

    var client = normalizeState(participants.client);
    var pm = normalizeState(participants.pm);
    var adminStates = [];
    if(Array.isArray(participants.admins)){
      participants.admins.forEach(function(entry){
        var norm = normalizeState(entry);
        if(norm){ adminStates.push(norm); }
      });
    }

    if(role === 'pm'){
      return statusFromTarget(messageId, client);
    }
    if(role === 'client'){
      return statusFromTarget(messageId, pm);
    }
    if(role === 'admin'){
      var targets = [];
      if(client) targets.push(client);
      if(pm) targets.push(pm);
      if(!targets.length) return null;
      var allDelivered = targets.every(function(target){ return target.last_delivered >= messageId; });
      var allRead = targets.every(function(target){ return target.last_read >= messageId; });
      if(allRead) return 'read';
      if(allDelivered) return 'delivered';
      return 'sent';
    }

    // Fallback: try to locate matching participant by role name
    if(participants[role] && !Array.isArray(participants[role])){
      return statusFromTarget(messageId, normalizeState(participants[role]));
    }

    // Or by matching admins with same role hint
    var matchingAdmin = null;
    for(var i=0;i<adminStates.length;i++){
      if(adminStates[i].role === role){ matchingAdmin = adminStates[i]; break; }
    }
    if(matchingAdmin){
      return statusFromTarget(messageId, matchingAdmin);
    }

    return null;
  }

  function refreshFromParticipants(win, participants){
    if(!win || !participants) return;
    var scope = win.querySelector('.elxao-chat-messages') || win;
    scope.querySelectorAll('.elxao-message').forEach(function(node){
      var status = computeStatusFromParticipants(node, participants);
      if(!status) return;
      node.setAttribute('data-status', status);
    });
    initStatuses(scope);
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
      var overlapTop = Math.max(rect.top, containerRect.top);
      var overlapBottom = Math.min(rect.bottom, containerRect.bottom);
      var visibleHeight = overlapBottom - overlapTop;
      var nodeHeight = rect.height || (rect.bottom - rect.top);
      if(visibleHeight <= 0) return;
      if(nodeHeight <= 0) return;
      var ratio = visibleHeight / nodeHeight;
      if(ratio < 0.25) return;
      var id = parseInt(node.getAttribute('data-id'), 10);
      if(isFiniteNumber(id) && id > maxId){ maxId = id; }
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
  window.ELXAO_STATUS_UI = { initStatuses, markVisibleAsRead, refreshFromParticipants };

  document.addEventListener('visibilitychange', function(){
    if(document.visibilityState !== 'hidden'){ markVisibleAsRead(); }
  });

  window.addEventListener('resize', function(){
    markVisibleAsRead();
  });
})();
