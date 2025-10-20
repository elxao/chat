(function($){
  function ajaxURL(){
    if (window.ELXAO_CHAT && ELXAO_CHAT.ajaxurl) return ELXAO_CHAT.ajaxurl;
    if (window.ajaxurl) return window.ajaxurl;
    return '/wp-admin/admin-ajax.php';
  }
  function safeJSONPost(data){
    return $.ajax({
      url: ajaxURL(),
      type: 'POST',
      data: data,
      dataType: 'json',
      timeout: 10000
    });
  }
  function minimalRender(m){
    var cls='elxao-chat-message'; if(m.mine) cls+=' me';
    var attrs=' data-id="'+m.id+'"';
    if(typeof m.incoming !== 'undefined'){ attrs += ' data-incoming="'+(m.incoming ? '1':'0')+'"'; }
    if(m.status){ attrs += ' data-status="'+m.status+'"'; }
    if(m.sender_role){ attrs += ' data-sender-role="'+m.sender_role+'"'; }
    var meta = '<div class="elxao-chat-meta">'+(m.sender||'')+' • '+(m.time||'')+'</div>';
    return '<div class="'+cls+'"'+attrs+'><div class="bubble"><div class="text">'+m.message+'</div>'+meta+'</div></div>';
  }
  function appendMsgs($box, msgs){
    if (typeof window.appendUnique === 'function'){ window.appendUnique($box, msgs); return; }
    var html=''; for(var i=0;i<msgs.length;i++){ var m=msgs[i]; html += (typeof renderMessage==='function'? renderMessage(m): minimalRender(m)); }
    if(html){ $box.append(html); window.requestAnimationFrame(function(){ $box.scrollTop($box[0].scrollHeight); }); }
  }
  function fetchOnce($win){
    if($win.data('loading')) return;
    $win.data('loading',true);
    var chatId=parseInt($win.data('chat'),10);
    var $box=$win.find('.elxao-chat-messages');
    var lastId=parseInt($box.attr('data-last')||'0',10);
    // Abort previous inflight if any
    var prev = $win.data('xhr'); if(prev && prev.abort) { try{ prev.abort(); }catch(e){} }
    var req = safeJSONPost({action:'elxao_fetch_messages',nonce:(window.ELXAO_CHAT?ELXAO_CHAT.nonce:''),chat_id:chatId,after_id:lastId});
    $win.data('xhr', req);
    req.done(function(resp){
      $win.data('loading',false);
      $box.find('.elxao-chat-loading').remove();
      var msgs=(resp && resp.data && resp.data.messages)?resp.data.messages:[];
      if(msgs.length){
        appendMsgs($box,msgs);
        for(var i=0;i<msgs.length;i++){ var id=msgs[i].id||0; if(id>lastId) lastId=id; }
        $box.attr('data-last', lastId);
      }
    }).fail(function(){
      $win.data('loading',false);
      $box.find('.elxao-chat-loading').remove();
    });
  }
  function schedulePolling($win){
    var base = (window.ELXAO_CHAT && ELXAO_CHAT.fetchFreq) ? ELXAO_CHAT.fetchFreq : 1500;
    function tick(){ fetchOnce($win); }
    function mk(){ var freq = document.hidden ? Math.max(base,5000) : base; return setInterval(tick, freq); }
    var itv = mk(); $win.data('interval', itv);
    document.addEventListener('visibilitychange', function(){ clearInterval(itv); itv=mk(); $win.data('interval', itv); });
  }
  function loadThread($thread){
    if($thread.hasClass('active')) return;
    $thread.addClass('active').siblings().removeClass('active');
    var chatId=parseInt($thread.data('chat'),10);
    var $inbox=$thread.closest('.elxao-inbox'); var $right=$inbox.find('.inbox-right');
    clearBadge(chatId);
    // cleanup previous
    var $prev=$right.find('.elxao-chat-window');
    if($prev.length){
      if($prev.data('interval')){ clearInterval($prev.data('interval')); $prev.removeData('interval'); }
      var pxhr=$prev.data('xhr'); if(pxhr && pxhr.abort){ try{pxhr.abort();}catch(e){} }
    }
    $right.find('.elxao-chat-window').remove();
    // draw window rapidly (and trim loader on slow nets)
    var html='<div class="elxao-chat-window" data-chat="'+chatId+'">'+
      '<div class="elxao-chat-messages" data-last="0"><div class="elxao-chat-loading">Loading messages…</div></div>'+
      '<div class="elxao-chat-input">'+
        '<textarea rows="2" placeholder="" aria-label="Type a message"></textarea>'+
        '<button class="elxao-chat-send send-icon" data-chat="'+chatId+'" aria-label="Send message" title="Send">'+
          '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"></path></svg>'+
        '</button>'+
      '</div>'+
    '</div>';
    $right.prepend(html);
    var $win=$right.find('.elxao-chat-window').first();
    // quick safety: remove loader if nothing in 1200ms (UI stays responsive)
    setTimeout(function(){ $win.find('.elxao-chat-loading').remove(); }, 1200);
    fetchOnce($win);
    schedulePolling($win);
  }
  $(document).on('click','.elxao-inbox .thread',function(){ loadThread($(this)); });

  function clearBadge(chatId){
    var $thread = $('.elxao-inbox .thread[data-chat="'+chatId+'"]').first();
    if(!$thread.length) return;
    $thread.find('.badge').remove();
  }

  function handleReadEvent(data){
    if(!data || typeof data.chatId === 'undefined') return;
    clearBadge(data.chatId);
  }

  if (window.jQuery) {
    $(document).on('elxaoChatRead', function(_, payload){ handleReadEvent(payload); });
  }
  document.addEventListener('elxaoChatRead', function(ev){ handleReadEvent(ev.detail); });
})(jQuery);
