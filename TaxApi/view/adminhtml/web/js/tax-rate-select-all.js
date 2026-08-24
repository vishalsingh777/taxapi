require([
    'jquery',
    'domReady!'
], function ($) {
    'use strict';

    function initSelectAll() {
        var $select = $('select[name="tax_rate[]"]');
        if ($select.length === 0) { $select = $('select[id="tax_rate"]'); }
        if ($select.length === 0 || $select.data('select-all-initialized')) { return; }

        $select.data('select-all-initialized', true);
        var $wrapper = $select.closest('.admin__field-control');

        var $btnContainer = $('<div class="tax-rate-buttons"></div>').css({
            'margin-top': '10px', 'padding': '10px', 'background': '#f0f0f0',
            'border': '1px solid #ccc', 'border-radius': '4px'
        });

        var btnCss = { 'padding': '8px 16px', 'margin-right': '10px', 'color': 'white',
            'border': 'none', 'border-radius': '3px', 'cursor': 'pointer', 'font-weight': 'bold' };

        var $selectAllBtn = $('<button type="button">SELECT ALL</button>').css($.extend({}, btnCss, { 'background': '#007bdb' }));
        var $deselectBtn  = $('<button type="button">DESELECT ALL</button>').css($.extend({}, btnCss, { 'background': '#e74c3c' }));
        var $showSelBtn   = $('<button type="button">SHOW ONLY SELECTED (<span class="sel-badge">0</span>)</button>')
                             .css($.extend({}, btnCss, { 'background': '#ff6b35' }));
        var $counter      = $('<div></div>').css({ 'margin-top': '8px', 'font-weight': 'bold', 'color': '#333' });
        var isFiltering   = false;

        function getCount()    { var v = $select.val(); return v ? v.length : 0; }
        function updateBadge() { $('.sel-badge').text(getCount()); }
        function updateCounter() {
            var sel   = getCount();
            var total = $wrapper.find('input[type="checkbox"]:not(:disabled)').length;
            $counter.html('Selected: <span style="color:#007bdb">' + sel + '</span> of <span style="color:#666">' + total + '</span>');
            updateBadge();
        }

        function batchClick($boxes, done) {
            var idx = 0;
            (function next() {
                if (idx < $boxes.length) { $boxes[idx++].click(); idx % 10 === 0 ? setTimeout(next, 1) : next(); }
                else { setTimeout(done, 200); }
            })();
        }

        $selectAllBtn.on('click', function (e) {
            e.preventDefault();
            $selectAllBtn.prop('disabled', true).text('Selecting…');
            batchClick($wrapper.find('input[type="checkbox"]:not(:checked)'), function () {
                updateCounter(); $selectAllBtn.prop('disabled', false).text('SELECT ALL');
            });
        });

        $deselectBtn.on('click', function (e) {
            e.preventDefault();
            $deselectBtn.prop('disabled', true).text('Deselecting…');
            batchClick($wrapper.find('input[type="checkbox"]:checked'), function () {
                updateCounter(); $deselectBtn.prop('disabled', false).text('DESELECT ALL');
            });
        });

        $showSelBtn.on('click', function (e) {
            e.preventDefault();
            if (isFiltering) {
                $wrapper.find('.filter-notice').remove();
                $wrapper.find('input[type="checkbox"]').each(function () {
                    $(this).closest('li,label,.mselect-item,div[class*="item"]').show();
                });
                $showSelBtn.css('background', '#ff6b35').html('SHOW ONLY SELECTED (<span class="sel-badge">' + getCount() + '</span>)');
                isFiltering = false;
            } else {
                var selected = $select.val() || [];
                if (!selected.length) { alert('No tax rates selected!'); return; }
                $wrapper.find('input[type="checkbox"]').each(function () {
                    var $item = $(this).closest('li,label,.mselect-item,div[class*="item"]');
                    selected.indexOf($(this).val()) === -1 ? $item.hide() : $item.show();
                });
                var $notice = $('<div class="filter-notice"></div>').css({
                    'background': '#d4edda', 'border': '1px solid #c3e6cb', 'color': '#155724',
                    'padding': '12px', 'margin-bottom': '10px', 'border-radius': '4px',
                    'font-weight': 'bold', 'text-align': 'center'
                }).html('Showing only ' + selected.length + ' selected rate(s). Click to show all.');
                $wrapper.find('.mselect-list').prepend($notice);
                $showSelBtn.css('background', '#27ae60')
                    .html('SHOWING SELECTED (<span class="sel-badge">' + selected.length + '</span>) — CLICK TO SHOW ALL');
                isFiltering = true;
            }
        });

        $select.on('change', updateCounter);
        $btnContainer
            .append('<strong style="display:block;margin-bottom:8px">🎯 Tax Rate Helper</strong>')
            .append($selectAllBtn).append($deselectBtn).append($showSelBtn).append($counter);
        $select.after($btnContainer);
        updateCounter();
    }

    initSelectAll();
    var iv = setInterval(function () {
        var $s = $('select[name="tax_rate[]"]');
        if ($s.length && !$s.data('select-all-initialized')) { initSelectAll(); }
    }, 1000);
    setTimeout(function () { clearInterval(iv); }, 10000);
});
