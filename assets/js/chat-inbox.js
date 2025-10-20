(function($){
  function q(sel, ctx){ return (ctx||document).querySelector(sel); }
  function qa(sel, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(sel)); }

  function findThreadEl(chatId){
    return q('.elxao-inbox .inbox-list .thread[data-chat="'+chatId+'"]') ||
           q('.elxao-chat-card[data-chat="'+chatId+'"]');
  }

  function setBadge(el, count){
    if (!el) return;
    var badge = el.querySelector('.badge');
    if (count > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'badge';
        var host = el.querySelector('.title-wrap, .elxao-chat-card-head');
        if (host) {
          host.appendChild(badge);
        }
      }
      badge.textContent = String(count);
      el.classList.add('has-unread');
    } else {
      if (badge) badge.remove();
      el.classList.remove('has-unread');
    }
  }

  function clearBadge(chatId){
    var el = findThreadEl(chatId);
    if (!el) return;
    setBadge(el, 0);
  }

  function incrementBadge(chatId, by){
    var el = findThreadEl(chatId);
    if (!el) return;
    var badge = el.querySelector('.badge');
    var cur = badge ? parseInt(badge.textContent || '0', 10) : 0;
    setBadge(el, cur + (by||1));
  }

  var pendingRequest = null;

  function destroyExistingWindows(){
    if (typeof window.ELXAO_CHAT_DESTROY === 'function') {
      var right = q('.elxao-inbox .inbox-right');
      if (right) {
        window.ELXAO_CHAT_DESTROY(right);
      }
    }
  }

  function renderRight(content){
    var right = q('.elxao-inbox .inbox-right');
    if (!right) return;
    destroyExistingWindows();
    right.innerHTML = content;
  }

  function showLoading(){
    renderRight('<div class="elxao-chat-loading">Loading chat…</div>');
  }

  function showError(message){
    renderRight('<div class="elxao-chat-error">'+message+'</div>');
  }

  function activateThread(thread){
    qa('.elxao-inbox .inbox-list .thread.active').forEach(function(el){
      if (el !== thread) {
        el.classList.remove('active');
      }
    });
    thread.classList.add('active');
  }

  function bootstrapNewWindows(){
    if (typeof window.ELXAO_CHAT_BOOTSTRAP === 'function') {
      var right = q('.elxao-inbox .inbox-right');
      if (right) {
        window.ELXAO_CHAT_BOOTSTRAP(right);
      }
    }
    if (window.ELXAO_STATUS_UI && typeof window.ELXAO_STATUS_UI.markVisibleAsRead === 'function') {
      window.requestAnimationFrame(function(){ window.ELXAO_STATUS_UI.markVisibleAsRead(); });
    }
  }

  function loadChatWindow(chatId, projectId){
    if (!chatId) {
      showError('No chat selected.');
      return;
    }
    if (!projectId) {
      showError('Unable to determine the project for this chat.');
      return;
    }
    if (!window.ELXAO_CHAT || !ELXAO_CHAT.ajaxurl) {
      showError('Chat service is not available.');
      return;
    }

    showLoading();

    if (pendingRequest && typeof pendingRequest.abort === 'function') {
      pendingRequest.abort();
    }

    pendingRequest = $.ajax({
      url: ELXAO_CHAT.ajaxurl,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'elxao_inbox_load_chat',
        nonce: ELXAO_CHAT.nonce || '',
        chat_id: chatId,
        project_id: projectId
      }
    }).done(function(resp){
      if (resp && resp.success && resp.data && resp.data.html) {
        var newChatId = resp.data.chat_id ? parseInt(resp.data.chat_id, 10) : chatId;
        if (!isNaN(newChatId)) {
          if (!window.ELXAO_CHAT) { window.ELXAO_CHAT = {}; }
          ELXAO_CHAT.chatId = newChatId;
        }
        renderRight(resp.data.html);
        bootstrapNewWindows();
      } else {
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Unable to load chat.';
        showError(msg);
      }
    }).fail(function(){
      showError('Unable to load chat. Please try again.');
    }).always(function(){
      pendingRequest = null;
    });
  }

  function handleThreadClick(ev){
    var thread = ev.target.closest('.thread');
    if (!thread || thread.classList.contains('active')) return;
    ev.preventDefault();
    var chatId = parseInt(thread.getAttribute('data-chat') || '0', 10);
    var projectId = parseInt(thread.getAttribute('data-project') || '0', 10);
    activateThread(thread);
    clearBadge(chatId);
    loadChatWindow(chatId, projectId);
  }

  document.addEventListener('click', function(ev){
    if (!ev.target.closest) return;
    var thread = ev.target.closest('.elxao-inbox .inbox-list .thread');
    if (thread) {
      handleThreadClick(ev);
    }
  });

  // Listen to "read" notifications from status.js
  document.addEventListener('elxaoChatRead', function(ev){
    var d = ev && ev.detail ? ev.detail : null;
    if (!d || typeof d.chatId === 'undefined') return;
    clearBadge(d.chatId);
  });

  // When new incoming messages arrive elsewhere, raise unread on their thread
  document.addEventListener('elxaoNewMessages', function(ev){
    var d = ev && ev.detail ? ev.detail : null;
    if (!d || typeof d.chatId === 'undefined') return;
    if (d.incomingCount && !d.isActive) {
      incrementBadge(d.chatId, d.incomingCount);
    }
  });

  // If DOM already contains unread counts per thread via data-unread, reflect it
  function initFromDom(){
    qa('.elxao-inbox .inbox-list .thread, .elxao-chat-card').forEach(function(el){
      var c = parseInt(el.getAttribute('data-unread')||'0',10);
      if (c>0) setBadge(el, c);
    });
  }

  // Watch for inbox list re-renders
  var mo = new MutationObserver(function(muts){
    var needInit=false;
    muts.forEach(function(m){
      Array.prototype.forEach.call(m.addedNodes || [], function(n){
        if (n.nodeType===1 && (n.matches('.elxao-inbox, .inbox-list, .elxao-chat-card') || (n.querySelector && n.querySelector('.elxao-chat-card, .thread')))) {
          needInit=true;
        }
      });
    });
    if (needInit) initFromDom();
  });
  mo.observe(document.documentElement || document.body, {subtree:true, childList:true});

  // First run
  initFromDom();
})(jQuery);
