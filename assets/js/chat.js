(function($){
  var readAckState = { pending: {}, confirmed: {} };
  window.ELXAO_CHAT_PARTICIPANTS = window.ELXAO_CHAT_PARTICIPANTS || {};

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

  function dispatchReadEvent(chatId, lastId){
    var detail = { chatId: chatId, lastId: lastId };
    try {
      var evt = new CustomEvent('elxaoChatRead', { detail: detail });
      document.dispatchEvent(evt);
    } catch (err) {
      var legacyEvt = document.createEvent('CustomEvent');
      legacyEvt.initCustomEvent('elxaoChatRead', true, true, detail);
      document.dispatchEvent(legacyEvt);
    }
    if (window.jQuery) {
      window.jQuery(document).trigger('elxaoChatRead', [ detail ]);
    }
  }

  window.ELXAO_CHAT_MARK_READ = function(chatId, lastId){
    chatId = parseInt(chatId, 10);
    lastId = parseInt(lastId, 10);
    if(!chatId || !lastId) return;
    if(readAckState.confirmed[chatId] && lastId <= readAckState.confirmed[chatId]) return;
    if(readAckState.pending[chatId] && lastId <= readAckState.pending[chatId]) return;

    readAckState.pending[chatId] = lastId;
    safeJSONPost({action:'elxao_mark_read',nonce:(ELXAO_CHAT?ELXAO_CHAT.nonce:''),chat_id:chatId,last_id:lastId}, function(resp){
      readAckState.confirmed[chatId] = Math.max(readAckState.confirmed[chatId] || 0, lastId);
      delete readAckState.pending[chatId];
      var participants = resp && resp.data ? resp.data.participants : null;
      if(participants){
        window.ELXAO_CHAT_PARTICIPANTS[chatId] = participants;
      }
      if(window.ELXAO_STATUS_UI){
        var $win = $('.elxao-chat-window[data-chat="'+chatId+'"]').first();
        if($win.length){
          if(participants && typeof window.ELXAO_STATUS_UI.refreshFromParticipants === 'function'){
            window.ELXAO_STATUS_UI.refreshFromParticipants($win[0], participants);
          } else if(typeof window.ELXAO_STATUS_UI.initStatuses === 'function'){
            window.ELXAO_STATUS_UI.initStatuses($win[0]);
          }
        }
      }
      dispatchReadEvent(chatId, lastId);
    }, function(){
      delete readAckState.pending[chatId];
    });
  };

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
    if(m.sender_role){ attrs += ' data-sender-role="'+m.sender_role+'"'; }
    if(m.recipients){
      var cr = m.recipients.client || {}, pr = m.recipients.pm || {};
      if(typeof cr.delivered!=='undefined') attrs += ' data-client-delivered="'+(cr.delivered?'1':'0')+'"';
      if(typeof cr.read!=='undefined') attrs += ' data-client-read="'+(cr.read?'1':'0')+'"';
      if(typeof pr.delivered!=='undefined') attrs += ' data-pm-delivered="'+(pr.delivered?'1':'0')+'"';
      if(typeof pr.read!=='undefined') attrs += ' data-pm-read="'+(pr.read?'1':'0')+'"';

      // Title to help admin: "Client: Read/Delivered — PM: Read/Delivered"
      var ct = 'Client: ' + (cr.read ? 'Read' : (cr.delivered ? 'Delivered' : 'Sent'));
      var pt = 'PM: ' + (pr.read ? 'Read' : (pr.delivered ? 'Delivered' : 'Sent'));
      attrs += ' data-status-title="'+(ct+' — '+pt).replace(/"/g,'&quot;')+'"';
    }


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
    if(m.sender_role){
      $node.attr('data-sender-role', m.sender_role);
    } else {
      $node.removeAttr('data-sender-role');
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

  function fetchMessages($win){
    if($win.data('loading')) return;
    $win.data('loading',true);
    var chatId=parseInt($win.data('chat'),10);
    var $box=$win.find('.elxao-chat-messages');
    var lastId=parseInt($box.attr('data-last')||'0',10);
    safeJSONPost({action:'elxao_fetch_messages',nonce:(ELXAO_CHAT?ELXAO_CHAT.nonce:''),chat_id:chatId,after_id:lastId}, function(resp){
      $win.data('loading',false);
      $box.find('.elxao-chat-loading').remove();
      if(resp && resp.data && resp.data.participants){
        window.ELXAO_CHAT_PARTICIPANTS[chatId] = resp.data.participants;
      }
      var msgs = (resp && resp.data && resp.data.messages) ? resp.data.messages : [];
      if(msgs.length){ window.appendUnique($box,msgs); }
      var participants = (resp && resp.data) ? resp.data.participants : null;
      if(window.ELXAO_STATUS_UI){
        if(participants && typeof window.ELXAO_STATUS_UI.refreshFromParticipants === 'function'){
          window.ELXAO_STATUS_UI.refreshFromParticipants($win[0], participants);
        } else if(typeof window.ELXAO_STATUS_UI.initStatuses === 'function'){
          window.ELXAO_STATUS_UI.initStatuses($box[0]);
        }
        if(typeof window.ELXAO_STATUS_UI.markVisibleAsRead === 'function'){
          window.requestAnimationFrame(function(){ window.ELXAO_STATUS_UI.markVisibleAsRead(); });
        }
      } else {
        window.requestAnimationFrame(function(){
          if(window.ELXAO_STATUS_UI){
            if(participants && typeof window.ELXAO_STATUS_UI.refreshFromParticipants === 'function'){
              window.ELXAO_STATUS_UI.refreshFromParticipants($win[0], participants);
            } else if(typeof window.ELXAO_STATUS_UI.initStatuses === 'function'){
              window.ELXAO_STATUS_UI.initStatuses($box[0]);
            }
            if(typeof window.ELXAO_STATUS_UI.markVisibleAsRead === 'function'){
              window.ELXAO_STATUS_UI.markVisibleAsRead();
            }
          }
        });
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
  $(document).on('scroll','.elxao-chat-messages',function(){
    if(window.ELXAO_STATUS_UI && typeof window.ELXAO_STATUS_UI.markVisibleAsRead === 'function'){
      window.ELXAO_STATUS_UI.markVisibleAsRead();
    }
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
        if(!document.hidden && window.ELXAO_STATUS_UI && typeof window.ELXAO_STATUS_UI.markVisibleAsRead === 'function'){
          window.ELXAO_STATUS_UI.markVisibleAsRead();
        }
      });
    });
  });
})(jQuery);
