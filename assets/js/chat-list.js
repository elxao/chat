(function($){
  $(document).on('click','.elxao-toggle-chat',function(){
    var $card=$(this).closest('.elxao-chat-card');
    var $inline=$card.find('.elxao-chat-inline');
    $inline.slideToggle(150);
  });
  $(document).on('input','.elxao-chat-search',function(){
    var term=$(this).val().toLowerCase();
    $('.elxao-chat-card').each(function(){
      var t=$(this).find('.title').text().toLowerCase();
      var p=$(this).find('.preview').text().toLowerCase();
      $(this).toggle(t.indexOf(term)!==-1 || p.indexOf(term)!==-1);
    });
  });
})(jQuery);
