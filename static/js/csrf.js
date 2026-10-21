/* Keep concurrent uploads and administrative POSTs compatible with CI's CSRF cookie. */
(function ($) {
    'use strict';
    if (!$) return;
    $.ajaxPrefilter(function (options) {
        if (options.crossDomain || !/^(POST|PUT|PATCH|DELETE)$/i.test(options.type)) return;
        var name = document.querySelector('meta[name="csrf-name"]');
        var token = document.querySelector('meta[name="csrf-token"]');
        if (!name || !token) return;
        if (options.data instanceof FormData) {
            options.data.append(name.content, token.content);
        } else {
            var data = typeof options.data === 'string' ? options.data : $.param(options.data || {});
            options.data = data + (data ? '&' : '') + encodeURIComponent(name.content) + '=' + encodeURIComponent(token.content);
        }
    });
}(window.jQuery));
