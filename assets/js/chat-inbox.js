(function($){
  function plainText(html){
    if(!html) return '';
    return $('<div>').html(html).text().replace(/\s+/g,' ').trim();
  }
  function shorten(text){
    if(text.length <= 140) return text;
    return text.slice(0,137) + '…';
  }
  function moveThreadToTop(chatId, message){
    var $inbox = $('.elxao-inbox');
    if(!$inbox.length) return;
    var $thread = $inbox.find('.thread[data-chat="'+chatId+'"]').first();
    if(!$thread.length) return;
    var text = shorten(plainText(message && message.message ? message.message : ''));
    if(text){
      $thread.find('.preview').text(text);
    }
    if(message && message.time){
      $thread.find('.time').text(message.time);
    }
    var $list = $thread.parent();
    $thread.detach().prependTo($list);
  }
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
    var meta = '<div class="elxao-chat-meta">'+(m.sender||'')+' • '+(m.time||'')+'</div>';
    return '<div class="'+cls+'" data-id="'+m.id+'"><div class="bubble"><div class="text">'+m.message+'</div>'+meta+'</div></div>';
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
      var ids=$box.find('.elxao-chat-message').map(function(){return parseInt($(this).attr('data-id'),10)||0;}).get();
      if(ids.length){
        var max=ids[0]; for(var i=1;i<ids.length;i++){ if(ids[i]>max) max=ids[i]; }
        safeJSONPost({action:'elxao_mark_read',nonce:(window.ELXAO_CHAT?ELXAO_CHAT.nonce:''),chat_id:chatId,last_id:max});
      }
    }).fail(function(){
      $win.data('loading',false);
      $box.find('.elxao-chat-loading').remove();
    });
  }
  $(document).on('elxao:messages-appended','.elxao-chat-messages',function(ev,data){
    if(!data || !data.messages || !data.messages.length) return;
    var chatId = data.chatId;
    if(typeof chatId!=='number' || isNaN(chatId)){
      chatId = parseInt($(this).closest('.elxao-chat-window').data('chat'),10);
    }
    if(!chatId) return;
    var message = data.messages[data.messages.length-1];
    moveThreadToTop(chatId, message);
  });
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
})(jQuery);
