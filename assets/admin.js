jQuery(function ($) {
  if (!window.safaeiImageLoader) {
    return;
  }

  function renderCandidates(candidates, message, productId) {
    var $container = $('#safaei-candidates');
    $container.empty();
    if (!candidates || !candidates.length) {
      $container.append('<p>' + (message || safaeiImageLoader.errorText) + '</p>');
      return;
    }
    candidates.forEach(function (candidate) {
      var thumb = candidate.thumb_url || candidate.image_url;
      var button = $('<button type="button" class="button button-small">' + safaeiImageLoader.setText + '</button>');
      button.on('click', function () {
        setCandidate(candidate.image_url, productId);
      });
      var item = $('<div class="safaei-candidate" style="margin-bottom:10px;"></div>');
      item.append('<img src="' + thumb + '" style="max-width:100%; height:auto; display:block; margin-bottom:5px;" />');
      item.append(button);
      $container.append(item);
    });
  }

  function searchCandidates(productId, customQuery) {
    $.post(safaeiImageLoader.ajaxUrl, {
      action: 'safaei_search_candidates',
      nonce: safaeiImageLoader.nonce,
      product_id: productId,
      custom_query: customQuery || ''
    }).done(function (response) {
      if (response.success) {
        renderCandidates(response.data.candidates || [], '', productId);
      } else {
        renderCandidates([], (response.data && response.data.message) || safaeiImageLoader.errorText, productId);
      }
    }).fail(function () {
      renderCandidates([], safaeiImageLoader.errorText, productId);
    });
  }

  function setCandidate(imageUrl, productId) {
    $.post(safaeiImageLoader.ajaxUrl, {
      action: 'safaei_set_candidate',
      nonce: safaeiImageLoader.nonce,
      product_id: productId,
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
    if (!safaeiImageLoader.productId) {
      return;
    }
    searchCandidates(safaeiImageLoader.productId, '');
  });

  $('#safaei-enqueue-job').on('click', function (e) {
    e.preventDefault();
    if (!safaeiImageLoader.productId) {
      return;
    }
    $.post(safaeiImageLoader.ajaxUrl, {
      action: 'safaei_enqueue_job',
      nonce: safaeiImageLoader.nonce,
      product_id: safaeiImageLoader.productId
    }).done(function (response) {
      if (response && response.success) {
        location.reload();
        return;
      }
      alert((response.data && response.data.message) || safaeiImageLoader.errorText);
    }).fail(function () {
      alert(safaeiImageLoader.errorText);
    });
  });

  if (safaeiImageLoader.quotaReached) {
    $('#safaei-search-now, #safaei-enqueue-job')
      .prop('disabled', true)
      .addClass('disabled');
    renderCandidates([], safaeiImageLoader.quotaText, safaeiImageLoader.productId || 0);
  }
});
