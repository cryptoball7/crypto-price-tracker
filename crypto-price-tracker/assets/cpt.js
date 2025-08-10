(function($){
  function fetchPrice($el){
    var symbol = $el.data('symbol');
    var currency = $el.data('currency');
    var nonce = cpt_ajax.nonce;
    $.ajax({
      url: cpt_ajax.ajax_url,
      method: 'POST',
      dataType: 'json',
      data: { action: 'cpt_fetch_price', symbol: symbol, currency: currency, nonce: nonce },
      success: function(res){
        if(res.success && res.data){
          $el.find('.cpt-price-value').text(res.data.formatted);
          $el.find('.cpt-price-meta').text('Updated: ' + new Date(res.data.updated_at * 1000).toLocaleString());
        }
      }
    });
  }

  $(document).ready(function(){
    $('.cpt-price').each(function(){
      var $el = $(this);
      var refresh = parseInt($el.data('refresh')) || 60;
      setTimeout(function(){ fetchPrice($el); }, 1000);
      setInterval(function(){ fetchPrice($el); }, refresh * 1000);
    });
  });
})(jQuery);
