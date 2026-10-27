(function () {
    'use strict';
    var dialog = document.getElementById('branding-picker');
    if (!dialog) return;
    var field, page = 1, search = '', requestVersion = 0;
    var status = document.getElementById('branding-status');
    var grid = document.getElementById('branding-grid');
    var previous = document.getElementById('branding-prev');
    var next = document.getElementById('branding-next');
    var titles = { background: '首页背景', logo: '网站 Logo', favicon: '网站图标' };

    function select(value, url, message) {
        var row = document.querySelector('[data-branding="' + field + '"]');
        row.querySelector('input').value = value;
        var preview = row.querySelector('img');
        preview.hidden = !url;
        if (url) preview.src = url;
        else preview.removeAttribute('src');
        row.querySelector('.branding-preview span').hidden = !!url;
        row.querySelector('.branding-selection').textContent = message + '；点击“保存”后生效。';
    }

    async function load() {
        var version = ++requestVersion;
        var selectedField = field;
        status.textContent = '正在加载图片…';
        grid.replaceChildren();
        previous.disabled = next.disabled = true;
        document.getElementById('branding-page').textContent = '第 ' + page + ' 页';
        try {
            var response = await fetch('/setting/images?' + new URLSearchParams({field: field, page: page, search: search}), {credentials: 'same-origin'});
            if (!response.ok) throw new Error('无法加载图片，请检查登录状态后重试。');
            var data = await response.json();
            if (version !== requestVersion) return;
            status.textContent = data.items.length ? '点击合适的图片进行选择，尺寸不符的图片会显示原因。' : '没有找到图片。请先到首页上传，或换一个搜索词。';
            data.items.forEach(function (item) {
                var card = document.createElement('button');
                card.type = 'button';
                card.className = 'branding-card';
                card.disabled = !item.eligible;
                if (item.url) {
                    var image = document.createElement('img');
                    image.src = item.url;
                    image.alt = item.name || item.imgid;
                    image.loading = 'lazy';
                    card.appendChild(image);
                }
                var name = document.createElement('strong');
                name.textContent = item.name || item.imgid;
                card.appendChild(name);
                var info = document.createElement('span');
                info.textContent = item.width + ' × ' + item.height + ' 像素';
                card.appendChild(info);
                var reason = document.createElement('span');
                reason.textContent = item.eligible ? '尺寸合适 · 选择此图' : item.reason;
                card.appendChild(reason);
                card.addEventListener('click', function () {
                    field = selectedField;
                    select(item.imgid, item.url, '已选择 ' + (item.name || item.imgid) + '（' + item.width + ' × ' + item.height + '）');
                    dialog.close();
                });
                grid.appendChild(card);
            });
            previous.disabled = page <= 1;
            next.disabled = !data.more;
        } catch (error) {
            if (version !== requestVersion) return;
            status.textContent = error.message || '加载失败，请重新打开选择器重试。';
        }
    }

    document.querySelectorAll('[data-pick]').forEach(function (button) {
        button.addEventListener('click', function () {
            field = button.dataset.pick;
            page = 1;
            search = '';
            document.getElementById('branding-query').value = '';
            document.getElementById('branding-picker-title').textContent = '选择' + titles[field];
            dialog.showModal();
            load();
        });
    });
    document.querySelectorAll('[data-reset]').forEach(function (button) {
        button.addEventListener('click', function () {
            field = button.dataset.reset;
            select('default', button.closest('[data-branding]').dataset.default, '已选择恢复默认');
        });
    });
    document.getElementById('branding-close').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('close', function () { requestVersion++; });
    document.getElementById('branding-search').addEventListener('submit', function (event) {
        event.preventDefault();
        search = document.getElementById('branding-query').value.trim();
        page = 1;
        load();
    });
    previous.addEventListener('click', function () { if (page > 1) { page--; load(); } });
    next.addEventListener('click', function () { page++; load(); });
}());
