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
    var cls='elxao-chat-message'; if(m.mine) cls+=' me';
    var meta = '<div class="elxao-chat-meta">'+(m.sender||'')+' • '+(m.time||'')+'</div>';
    return '<div class="'+cls+'" data-id="'+m.id+'"><div class="bubble"><div class="text">'+m.message+'</div>'+meta+'</div></div>';
  }

  window.appendUnique = function($box,msgs){
    var fragHTML = '';
    var maxId = parseInt($box.attr('data-last')||'0',10);
    for (var i=0;i<msgs.length;i++){
      var m = msgs[i];
      if ($box.find('.elxao-chat-message[data-id="'+m.id+'"]').length){
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
  };

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
      if(msgs.length){ window.appendUnique($box,msgs); }
      var ids = $box.find('.elxao-chat-message').map(function(){ return parseInt($(this).attr('data-id'),10)||0; }).get();
      if(ids.length){
        var max=ids[0]; for(var i=1;i<ids.length;i++){ if(ids[i]>max) max=ids[i]; }
        safeJSONPost({action:'elxao_mark_read',nonce:(ELXAO_CHAT?ELXAO_CHAT.nonce:''),chat_id:chatId,last_id:max});
      }
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
      fetchMessages($w);
      var baseFreq = (window.ELXAO_CHAT && ELXAO_CHAT.fetchFreq) ? ELXAO_CHAT.fetchFreq : 1500;
      function schedule(){
        var freq = document.hidden ? Math.max(baseFreq, 5000) : baseFreq;
        return setInterval(function(){ fetchMessages($w); }, freq);
      }
      var itv = schedule();
      $w.data('interval', itv);
      document.addEventListener('visibilitychange', function(){
        clearInterval(itv); itv = schedule();
      });
    });
  });
})(jQuery);
