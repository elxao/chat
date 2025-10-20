/* ELXAO Chat - Message status UI (sent/delivered/read) */

(function(){
  function statusFromData(msg){
    if (msg.read_at) return 'read';
    if (msg.delivered_at) return 'delivered';
    return msg.status || 'sent';
  }

  function initStatuses(scope){
    (scope || document).querySelectorAll('.elxao-message').forEach(function(node){
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
        (node.querySelector('.meta') || node).appendChild(statusEl);
      }
      statusEl.classList.remove('sent','delivered','read');
      statusEl.classList.add(s);
      statusEl.setAttribute('aria-label', s);
    });
  }

  async function markVisibleAsRead() {
    if (!window.ELXAO_STATUS || !ELXAO_STATUS.restUrl) return;
    const candidates = Array.from(document.querySelectorAll('.elxao-message[data-id][data-incoming="1"]'));
    const visibleIds = candidates
      .filter(n => n.offsetParent !== null)
      .map(n => parseInt(n.getAttribute('data-id'), 10))
      .filter(Number.isFinite);

    if (!visibleIds.length) return;

    try{
      await fetch(ELXAO_STATUS.restUrl, {
        method:'POST',
        headers:{
          'Content-Type':'application/json',
          'X-WP-Nonce': ELXAO_STATUS.nonce || ''
        },
        credentials:'same-origin',
        body: JSON.stringify({ ids: visibleIds })
      });
      // Met à jour l’UI immédiatement
      visibleIds.forEach(id => {
        const n = document.querySelector('.elxao-message[data-id="'+id+'"] .elxao-msg-status');
        if (n){ n.classList.remove('sent','delivered'); n.classList.add('read'); }
      });
    }catch(e){
      // silencieux
    }
  }

  // Initialise au chargement
  document.addEventListener('DOMContentLoaded', function(){
    initStatuses(document);
    markVisibleAsRead();
  });

  // Expose si tu re-rendes dynamiquement
  window.ELXAO_STATUS_UI = { initStatuses, markVisibleAsRead };
})();
