(function($){
  function safeJSONPost(data, onOk, onFail){
    $.ajax({
      url: (window.ELXAO_CHAT && ELXAO_CHAT.ajaxurl) ? ELXAO_CHAT.ajaxurl : (window.ajaxurl || '/wp-admin/admin-ajax.php'),
      type: 'POST',
      data: data,
      dataType: 'json',
      timeout: 12000
    }).done(function(resp){
      if(resp && resp.success){ onOk && onOk(resp); }
      else { onFail && onFail(resp); }
    }).fail(function(xhr){
      onFail && onFail(xhr);
    });
  }

  function renderMessage(m){
    var classes = 'elxao-chat-message elxao-message';
    if(m.mine) classes += ' me';

    var attrs = ' data-id="'+m.id+'"';
    if(typeof m.incoming !== 'undefined'){
      attrs += ' data-incoming="'+(m.incoming ? '1' : '0')+'"';
    }
    if(m.status){ attrs += ' data-status="'+m.status+'"'; }
    if(m.delivered_at){ attrs += ' data-delivered-at="'+m.delivered_at+'"'; }
    if(m.read_at){ attrs += ' data-read-at="'+m.read_at+'"'; }

    var metaParts = [];
    if(m.sender){ metaParts.push(m.sender); }
    if(m.time){ metaParts.push(m.time); }
    var metaText = metaParts.join(' • ');
    var meta = '<div class="elxao-chat-meta">';
    if(metaText){ meta += '<span class="meta-text">'+metaText+'</span>'; }
    if(m.mine){
      meta += '<span class="elxao-msg-status"><i class="icon" aria-hidden="true"></i></span>';
    }
    meta += '</div>';

    return '<div class="'+classes+'"'+attrs+'><div class="bubble"><div class="text">'+m.message+'</div>'+meta+'</div></div>';
  }

  function applyStatusData($node, m){
    if(typeof m.incoming !== 'undefined'){
      $node.attr('data-incoming', m.incoming ? '1' : '0');
    }
    if(m.status){
      $node.attr('data-status', m.status);
    } else {
      $node.removeAttr('data-status');
    }
    if(m.delivered_at){
      $node.attr('data-delivered-at', m.delivered_at);
    } else {
      $node.removeAttr('data-delivered-at');
    }
    if(m.read_at){
      $node.attr('data-read-at', m.read_at);
    } else {
      $node.removeAttr('data-read-at');
    }
  }

  window.appendUnique = function($box,msgs){
    var fragHTML = '';
    var maxId = parseInt($box.attr('data-last')||'0',10);
    for (var i=0;i<msgs.length;i++){
      var m = msgs[i];
      var $existing = $box.find('.elxao-chat-message[data-id="'+m.id+'"]');
      if ($existing.length){
        applyStatusData($existing, m);
        if(m.mine){
          if(!$existing.find('.elxao-msg-status').length){
            $existing.find('.elxao-chat-meta').append('<span class="elxao-msg-status"><i class="icon" aria-hidden="true"></i></span>');
          }
        } else {
          $existing.find('.elxao-msg-status').remove();
        }
        if(m.id>maxId) maxId=m.id;
        continue;
      }
      fragHTML += renderMessage(m);
      if(m.id>maxId) maxId=m.id;
    }
    if (fragHTML){
      $box.append(fragHTML);
      window.requestAnimationFrame(function(){ $box.scrollTop($box[0].scrollHeight); });
    }
    $box.attr('data-last', maxId);
    if(window.ELXAO_STATUS_UI && typeof window.ELXAO_STATUS_UI.initStatuses === 'function'){
      window.ELXAO_STATUS_UI.initStatuses($box[0]);
    }
  };

  function ensureStatusUI($box){
    if(window.ELXAO_STATUS_UI){
      if(typeof window.ELXAO_STATUS_UI.initStatuses === 'function'){
        window.ELXAO_STATUS_UI.initStatuses($box[0]);
      }
      if(typeof window.ELXAO_STATUS_UI.markVisibleAsRead === 'function'){
        window.requestAnimationFrame(function(){ window.ELXAO_STATUS_UI.markVisibleAsRead(); });
      }
    }
  }

  function markMessagesAsRead($win){
    if(!window.ELXAO_CHAT){ return; }
    var $box=$win.find('.elxao-chat-messages');
    var ids=$box.find('.elxao-chat-message').map(function(){ return parseInt($(this).attr('data-id'),10)||0; }).get();
    if(!ids.length){ return; }
    var max=0;
    for(var i=0;i<ids.length;i++){ if(ids[i]>max) max=ids[i]; }
    if(!max){ return; }
    safeJSONPost({action:'elxao_mark_read',nonce:(ELXAO_CHAT?ELXAO_CHAT.nonce:''),chat_id:parseInt($win.data('chat'),10),last_id:max});
  }

  function handleMessages($win, msgs){
    var $box=$win.find('.elxao-chat-messages');
    if(msgs && msgs.length){
      window.appendUnique($box,msgs);
    }
    ensureStatusUI($box);
    window.requestAnimationFrame(function(){ markMessagesAsRead($win); });
    if(supportsRealtime() && !$win.data('stream')){
      var stream = openRealtimeStream($win);
      if(stream){
        $win.data('stream', stream);
      }
    }
  }

  function supportsRealtime(){
    return !!(window.EventSource && window.ELXAO_CHAT && ELXAO_CHAT.realtime && ELXAO_CHAT.realtime.enabled && ELXAO_CHAT.realtime.endpoint);
  }

  function buildStreamUrl(chatId, lastId){
    var endpoint = ELXAO_CHAT.realtime.endpoint;
    var params = ['chat_id='+encodeURIComponent(chatId), 'nonce='+encodeURIComponent(ELXAO_CHAT.nonce||'')];
    if(lastId){ params.push('last_id='+encodeURIComponent(lastId)); }
    return endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + params.join('&');
  }

  function openRealtimeStream($win){
    if(!supportsRealtime()){ return null; }
    var chatId=parseInt($win.data('chat'),10);
    if(!chatId){ return null; }
    var $box=$win.find('.elxao-chat-messages');
    var lastId=parseInt($box.attr('data-last')||'0',10);
    var url = buildStreamUrl(chatId, lastId);
    var source;
    try {
      source = new EventSource(url);
    } catch(err){
      return null;
    }

    source.addEventListener('messages', function(evt){
      if(!evt || !evt.data){ return; }
      try {
        var payload = JSON.parse(evt.data);
        if(payload && payload.messages){
          handleMessages($win, payload.messages);
          if(payload.meta && payload.meta.newest_id){
            $box.attr('data-last', payload.meta.newest_id);
          }
        }
      } catch(e){
        if(window.console && console.warn){ console.warn('ELXAO chat stream parse error', e); }
      }
    });

    source.addEventListener('open', function(){
      $win.trigger('elxao:stream-open');
    });

    source.addEventListener('error', function(){
      $win.trigger('elxao:stream-error');
    });

    return source;
  }

  function fetchMessages($win){
    if($win.data('loading')) return;
    $win.data('loading',true);
    var chatId=parseInt($win.data('chat'),10);
    var $box=$win.find('.elxao-chat-messages');
    var lastId=parseInt($box.attr('data-last')||'0',10);
    safeJSONPost({action:'elxao_fetch_messages',nonce:(ELXAO_CHAT?ELXAO_CHAT.nonce:''),chat_id:chatId,after_id:lastId}, function(resp){
      $win.data('loading',false);
      $box.find('.elxao-chat-loading').remove();
      var msgs = (resp && resp.data && resp.data.messages) ? resp.data.messages : [];
      handleMessages($win, msgs);
    }, function(){
      $win.data('loading',false);
      $box.find('.elxao-chat-loading').remove();
    });
  }

  function sendMessage($btn){
    var chatId=parseInt($btn.data('chat'),10);
    var $win=$btn.closest('.elxao-chat-window');
    var $input=$win.find('textarea');
    var text=$input.val().trim(); if(!text) return;
    $btn.prop('disabled',true);
    safeJSONPost({action:'elxao_send_message',nonce:(ELXAO_CHAT?ELXAO_CHAT.nonce:''),chat_id:chatId,text:text}, function(){
      $btn.prop('disabled',false);
      $input.val('');
      setTimeout(function(){ fetchMessages($win); }, 100);
    }, function(){
      $btn.prop('disabled',false);
      alert('Message not sent.');
    });
  }

  $(document).on('click','.elxao-chat-send',function(){ sendMessage($(this)); });
  $(document).on('keydown','.elxao-chat-input textarea',function(e){
    if((e.metaKey||e.ctrlKey) && e.key==='Enter'){ e.preventDefault(); $(this).closest('.elxao-chat-window').find('.elxao-chat-send').click(); }
  });

  $(function(){
    $('.elxao-chat-window').each(function(){
      var $w=$(this);
      var baseFreq = (window.ELXAO_CHAT && ELXAO_CHAT.fetchFreq) ? ELXAO_CHAT.fetchFreq : 4000;
      var fallbackFreq = (window.ELXAO_CHAT && ELXAO_CHAT.realtime && ELXAO_CHAT.realtime.fallbackFreq) ? ELXAO_CHAT.realtime.fallbackFreq : Math.max(baseFreq, 15000);
      var pollFreq = baseFreq;
      var poller = null;

      function schedule(){
        if(poller){ clearInterval(poller); }
        var freq = document.hidden ? Math.max(pollFreq, fallbackFreq) : pollFreq;
        poller = setInterval(function(){ fetchMessages($w); }, freq);
        $w.data('interval', poller);
      }

      fetchMessages($w);
      schedule();

      document.addEventListener('visibilitychange', function(){
        if(poller){ schedule(); }
      });

      if(supportsRealtime()){
        $w.on('elxao:stream-open', function(){
          pollFreq = fallbackFreq;
          schedule();
        });
        $w.on('elxao:stream-error', function(){
          pollFreq = baseFreq;
          schedule();
        });
      }
    });

    $(window).on('beforeunload', function(){
      $('.elxao-chat-window').each(function(){
        var stream = $(this).data('stream');
        if(stream && typeof stream.close === 'function'){
          stream.close();
        }
      });
    });
  });
})(jQuery);
