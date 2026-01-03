jQuery(function ($) {
  function handleReset($button) {
    if (!window.safaeiUsage) {
      return;
    }
    if (!confirm(safaeiUsage.confirmText)) {
      return;
    }

    $.post(safaeiUsage.ajaxUrl, {
      action: 'safaei_reset_quota',
      nonce: safaeiUsage.nonce
    })
      .done(function (response) {
        if (response && response.success) {
          alert(safaeiUsage.successText);
          location.reload();
          return;
        }
        alert(safaeiUsage.errorText);
      })
      .fail(function () {
        alert(safaeiUsage.errorText);
      });
  }

  $(document).on('click', '.safaei-quota__reset', function (e) {
    e.preventDefault();
    handleReset($(this));
  });
});
