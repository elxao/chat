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
