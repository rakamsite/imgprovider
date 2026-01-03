jQuery(function ($) {
  function renderCandidates(candidates) {
    var $container = $('#safaei-candidates');
    $container.empty();
    if (!candidates || !candidates.length) {
      $container.append('<p>' + safaeiImageLoader.errorText + '</p>');
      return;
    }
    candidates.forEach(function (candidate) {
      var thumb = candidate.thumb_url || candidate.image_url;
      var button = $('<button type="button" class="button button-small">' + safaeiImageLoader.setText + '</button>');
      button.on('click', function () {
        setCandidate(candidate.image_url);
      });
      var item = $('<div class="safaei-candidate" style="margin-bottom:10px;"></div>');
      item.append('<img src="' + thumb + '" style="max-width:100%; height:auto; display:block; margin-bottom:5px;" />');
      item.append(button);
      $container.append(item);
    });
  }

  function searchCandidates() {
    $.post(safaeiImageLoader.ajaxUrl, {
      action: 'safaei_search_candidates',
      nonce: safaeiImageLoader.nonce,
      product_id: safaeiImageLoader.productId
    }).done(function (response) {
      if (response.success) {
        renderCandidates(response.data.candidates || []);
      } else {
        renderCandidates([]);
      }
    }).fail(function () {
      renderCandidates([]);
    });
  }

  function setCandidate(imageUrl) {
    $.post(safaeiImageLoader.ajaxUrl, {
      action: 'safaei_set_candidate',
      nonce: safaeiImageLoader.nonce,
      product_id: safaeiImageLoader.productId,
      image_url: imageUrl
    }).done(function (response) {
      if (response.success) {
        location.reload();
      } else {
        alert(safaeiImageLoader.errorText);
      }
    }).fail(function () {
      alert(safaeiImageLoader.errorText);
    });
  }

  $('#safaei-search-now').on('click', function (e) {
    e.preventDefault();
    searchCandidates();
  });

  $('#safaei-enqueue-job').on('click', function (e) {
    e.preventDefault();
    $.post(safaeiImageLoader.ajaxUrl, {
      action: 'safaei_enqueue_job',
      nonce: safaeiImageLoader.nonce,
      product_id: safaeiImageLoader.productId
    }).done(function () {
      location.reload();
    });
  });
});
