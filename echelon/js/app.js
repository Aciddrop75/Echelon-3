/* Echelon 3 - site-wide behaviour (sidebar, search suggestions, confirmations) */
(function ($) {
    'use strict';

    /* ---- mobile sidebar ---- */
    $('#menu-toggle').on('click', function () { $('body').toggleClass('nav-open'); });
    $(document).on('click', function (e) {
        if ($('body').hasClass('nav-open') && !$(e.target).closest('#sidebar, #menu-toggle').length) {
            $('body').removeClass('nav-open');
        }
    });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') { $('body').removeClass('nav-open'); } });

    /* ---- client search suggestions (debounced, text-only rendering) ---- */
    var $input = $('#search'), $box = $('#suggestions'), $list = $('#suggestionsList');
    var timer = null, xhr = null, active = -1;

    function hide() { $box.hide(); active = -1; }
    function pick(text) { $input.val(text); hide(); $('#c-search').trigger('submit'); }

    function render(names) {
        $list.empty();
        if (!names.length) { hide(); return; }
        var $ul = $('<ul role="listbox">');
        names.forEach(function (n) {
            $('<li role="option">').text(n).on('mousedown', function (e) { e.preventDefault(); pick(n); }).appendTo($ul);
        });
        $list.append($ul);
        $box.show();
        active = -1;
    }

    $input.on('input', function () {
        var v = $.trim(this.value);
        clearTimeout(timer);
        if (xhr) { xhr.abort(); }
        if (v.length < 2) { hide(); return; }
        timer = setTimeout(function () {
            xhr = $.getJSON(window.ECH.path + 'actions/autosuggest.php', { s: v }).done(render).fail(hide);
        }, 220);
    }).on('keydown', function (e) {
        var $li = $list.find('li');
        if (!$li.length || !$box.is(':visible')) { return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + $li.length) % $li.length;
            $li.removeClass('on').eq(active).addClass('on');
        } else if (e.key === 'Enter' && active > -1) {
            e.preventDefault(); pick($li.eq(active).text());
        } else if (e.key === 'Escape') { hide(); }
    }).on('blur', function () { setTimeout(hide, 150); });

    /* ---- <form data-confirm="Are you sure?"> and <a/button data-confirm> ---- */
    $(document).on('submit', 'form[data-confirm]', function (e) {
        if (!window.confirm($(this).data('confirm'))) { e.preventDefault(); }
    });
    $(document).on('click', 'a[data-confirm], button[data-confirm]:not([type=submit])', function (e) {
        if (!window.confirm($(this).data('confirm'))) { e.preventDefault(); }
    });

    /* ---- hard deletes ask first ---- */
    $(document).on('click', '.harddel:not(.disabled)', function (e) {
        if (!window.confirm('Are you sure you want to delete this forever?')) { e.preventDefault(); }
    });

    /* ---- tooltips ---- */
    $('[data-toggle="tooltip"]').tooltip();

})(jQuery);
