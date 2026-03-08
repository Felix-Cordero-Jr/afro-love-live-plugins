jQuery(function ($) {
  function postAction(action, target, $btn) {
    return $.post(aflAjax.ajaxurl, {
      action,
      nonce: aflAjax.nonce,
      target
    }).done(function (res) {
      if (!res || !res.success) return;
      $btn.toggleClass("is-active", !!res.data.active);
    });
  }

  $(document).on("click", ".afl-icon-btn[data-action='like']", function (e) {
    e.preventDefault();
    const $btn = $(this);
    postAction("afl_toggle_like", $btn.data("target"), $btn);
  });

  $(document).on("click", ".afl-icon-btn[data-action='favorite']", function (e) {
    e.preventDefault();
    const $btn = $(this);
    postAction("afl_toggle_favorite", $btn.data("target"), $btn);
  });
});
