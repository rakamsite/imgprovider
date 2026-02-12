jQuery(function ($) {
  if (!window.safaeiImageLoader) {
    return;
  }

  if (!$('#safaei-inline-focus-style').length) {
    $('head').append(
      '<style id="safaei-inline-focus-style">' +
        'tr.safaei-inline-focus td > * { display: none; }' +
        'tr.safaei-inline-focus td > .safaei-inline-loader,' +
        'tr.safaei-inline-focus td > .inline-edit-save { display: block; }' +
      '</style>'
    );
  }

  function getText(key, fallback) {
    return safaeiImageLoader[key] || fallback;
  }

  function renderCandidates($container, candidates, message, productId) {
    $container.empty();
    $container.css({
      display: 'flex',
      flexWrap: 'nowrap',
      gap: '12px',
      overflowX: 'auto',
      alignItems: 'flex-start'
    });

    if (!candidates || !candidates.length) {
      $container.css('display', 'block');
      $container.append('<p>' + (message || safaeiImageLoader.errorText) + '</p>');
      return;
    }

    candidates.forEach(function (candidate) {
      var thumb = candidate.thumb_url || candidate.image_url;
      var button = $('<button type="button" class="button button-small">' + safaeiImageLoader.setText + '</button>');
      button.on('click', function () {
        setCandidate(candidate.image_url, productId);
      });

      var item = $('<div class="safaei-candidate" style="flex:0 0 auto; width:240px;"></div>');
      item.append('<img src="' + thumb + '" style="width:100%; height:auto; display:block; margin-bottom:5px;" />');
      item.append(button);
      $container.append(item);
    });
  }

  function searchCandidates(productId, customQuery, $container) {
    $.post(safaeiImageLoader.ajaxUrl, {
      action: 'safaei_search_candidates',
      nonce: safaeiImageLoader.nonce,
      product_id: productId,
      custom_query: customQuery || ''
    }).done(function (response) {
      if (response.success) {
        renderCandidates($container, response.data.candidates || [], '', productId);
      } else {
        renderCandidates($container, [], (response.data && response.data.message) || safaeiImageLoader.errorText, productId);
      }
    }).fail(function () {
      renderCandidates($container, [], safaeiImageLoader.errorText, productId);
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

  function renderQuickEditLoader(productId, refcode) {
    var $row = $('#edit-' + productId);
    if (!$row.length) {
      return;
    }

    var $target = $row.find('td').first();
    var blockId = 'safaei-inline-loader-' + productId;
    var $block = $('#' + blockId);

    if (!$block.length) {
      var refcodeLabel = getText('refcodeLabel', 'Refcode');
      var searchHelpText = getText('searchHelpText', 'Leave empty to search by refcode.');
      var searchPlaceholder = getText('searchPlaceholder', 'Type to customize the search query');
      var searchText = getText('searchText', 'Search Now');

      $block = $(
        '<div id="' + blockId + '" class="safaei-inline-loader" style="margin-top:12px; padding-top:12px; border-top:1px solid #dcdcde;">' +
          '<p><strong>' + getText('modalTitle', 'Find Image') + '</strong></p>' +
          '<p><strong>' + refcodeLabel + ':</strong> <span class="safaei-inline-refcode"></span></p>' +
          '<p>' +
            '<input type="text" class="regular-text safaei-inline-query" placeholder="' + searchPlaceholder + '" /> ' +
            '<button type="button" class="button button-primary safaei-inline-search">' + searchText + '</button><br />' +
            '<span class="description">' + searchHelpText + '</span>' +
          '</p>' +
          '<div class="safaei-inline-candidates" style="max-height:260px; overflow:auto;"></div>' +
        '</div>'
      );

      $target.append($block);

      $block.find('.safaei-inline-search').on('click', function (e) {
        e.preventDefault();
        var customQuery = $block.find('.safaei-inline-query').val() || '';
        searchCandidates(productId, customQuery, $block.find('.safaei-inline-candidates'));
      });
    }

    $block.find('.safaei-inline-refcode').text(refcode || '-');

    $row.addClass('safaei-inline-focus');
  }

  $('#safaei-search-now').on('click', function (e) {
    e.preventDefault();
    if (!safaeiImageLoader.productId) {
      return;
    }

    searchCandidates(safaeiImageLoader.productId, '', $('#safaei-candidates'));
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

  $(document).on('click', 'a.safaei-find-image', function (e) {
    if (typeof inlineEditPost === 'undefined' || !inlineEditPost.edit) {
      return;
    }

    e.preventDefault();

    var $link = $(this);
    var productId = parseInt($link.data('product-id'), 10);
    var refcode = $link.data('refcode') || '';

    if (!productId) {
      return;
    }

    $('.inline-edit-row').removeClass('safaei-inline-focus');
    inlineEditPost.edit(productId);
    renderQuickEditLoader(productId, refcode);
  });

  $(document).on('click', '.editinline, .cancel', function () {
    $('.inline-edit-row').removeClass('safaei-inline-focus');
  });

  if (safaeiImageLoader.quotaReached) {
    $('#safaei-search-now, #safaei-enqueue-job').prop('disabled', true).addClass('disabled');
    renderCandidates($('#safaei-candidates'), [], safaeiImageLoader.quotaText, safaeiImageLoader.productId || 0);

    $(document).on('click', '.safaei-inline-search', function (e) {
      e.preventDefault();
      var $block = $(this).closest('.safaei-inline-loader');
      renderCandidates($block.find('.safaei-inline-candidates'), [], safaeiImageLoader.quotaText, 0);
    });
  }
});
