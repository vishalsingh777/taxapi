require([
    'jquery',
    'domReady!'
], function ($) {
    'use strict';
    
    console.log('Tax Rate Select All script loaded');

    /**
     * Initialize select all functionality
     */
    function initSelectAll() {
        console.log('Attempting to initialize Select All buttons...');
        
        // Target the tax rate select field
        var $select = $('select[name="tax_rate[]"]');
        
        if ($select.length === 0) {
            $select = $('select[id="tax_rate"]');
        }

        if ($select.length > 0) {
            console.log('Tax rate select found!');
            
            // Check if already initialized
            if ($select.data('select-all-initialized')) {
                console.log('Already initialized, skipping...');
                return;
            }
            
            $select.data('select-all-initialized', true);
            
            // Find the container
            var $wrapper = $select.closest('.admin__field-control');

            // Create button container
            var $btnContainer = $('<div class="tax-rate-buttons"></div>');
            $btnContainer.css({
                'margin-top': '10px',
                'padding': '10px',
                'background': '#f0f0f0',
                'border': '1px solid #ccc',
                'border-radius': '4px'
            });
            
            // Create SELECT ALL button
            var $selectAllBtn = $('<button type="button">SELECT ALL</button>').css({
                'padding': '8px 16px',
                'margin-right': '10px',
                'background': '#007bdb',
                'color': 'white',
                'border': 'none',
                'border-radius': '3px',
                'cursor': 'pointer',
                'font-weight': 'bold'
            });
            
            // Create DESELECT ALL button
            var $deselectBtn = $('<button type="button">DESELECT ALL</button>').css({
                'padding': '8px 16px',
                'margin-right': '10px',
                'background': '#e74c3c',
                'color': 'white',
                'border': 'none',
                'border-radius': '3px',
                'cursor': 'pointer',
                'font-weight': 'bold'
            });
            
            // ========== NEW: Create SHOW ONLY SELECTED button ==========
            var $showSelectedBtn = $('<button type="button">SHOW ONLY SELECTED (<span class="selected-badge">0</span>)</button>').css({
                'padding': '8px 16px',
                'background': '#ff6b35',
                'color': 'white',
                'border': 'none',
                'border-radius': '3px',
                'cursor': 'pointer',
                'font-weight': 'bold'
            });
            
            // Filter state
            var isFiltering = false;
            // ========== END NEW ==========

            // Create counter display
            var $counter = $('<div class="selection-counter"></div>').css({
                'margin-top': '8px',
                'font-weight': 'bold',
                'color': '#333'
            });
            
            // SELECT ALL functionality
            $selectAllBtn.on('click', function(e) {
                e.preventDefault();
                $selectAllBtn.prop('disabled', true).text('Selecting...');
                
                console.log('SELECT ALL clicked');
                
                var $checkboxes = $wrapper.find('input[type="checkbox"]');
                var unchecked = $checkboxes.filter(':not(:checked)');
                
                console.log('Need to check ' + unchecked.length + ' checkboxes');
                
                var index = 0;
                function clickNext() {
                    if (index < unchecked.length) {
                        unchecked[index].click();
                        index++;
                        
                        if (index % 50 === 0) {
                            console.log('Progress: ' + index + '/' + unchecked.length);
                        }
                        
                        if (index % 10 === 0) {
                            setTimeout(clickNext, 1);
                        } else {
                            clickNext();
                        }
                    } else {
                        console.log('All checkboxes clicked!');
                        setTimeout(function() {
                            updateCounter();
                            var selected = $select.val() ? $select.val().length : 0;
                            $selectAllBtn.prop('disabled', false).text('SELECT ALL');
                        }, 200);
                    }
                }
                
                clickNext();
            });
            
            // DESELECT ALL functionality
            $deselectBtn.on('click', function(e) {
                e.preventDefault();
                $deselectBtn.prop('disabled', true).text('Deselecting...');
                
                console.log('DESELECT ALL clicked');
                
                var $checkboxes = $wrapper.find('input[type="checkbox"]');
                var checked = $checkboxes.filter(':checked');
                
                console.log('Need to uncheck ' + checked.length + ' checkboxes');
                
                var index = 0;
                function clickNext() {
                    if (index < checked.length) {
                        checked[index].click();
                        index++;
                        
                        if (index % 50 === 0) {
                            console.log('Progress: ' + index + '/' + checked.length);
                        }
                        
                        if (index % 10 === 0) {
                            setTimeout(clickNext, 1);
                        } else {
                            clickNext();
                        }
                    } else {
                        console.log('All checkboxes unchecked!');
                        setTimeout(function() {
                            // Force UI refresh
                            var $mselectList = $wrapper.find('.mselect-list');
                            $mselectList.hide();
                            setTimeout(function() {
                                $mselectList.show();
                            }, 10);
                            
                            $checkboxes.each(function() {
                                var $label = $(this).closest('label');
                                if ($label.length) {
                                    $label.removeClass('checked');
                                }
                            });
                            
                            updateCounter();
                            $deselectBtn.prop('disabled', false).text('DESELECT ALL');
                        }, 200);
                    }
                }
                
                clickNext();
            });
            
            // ========== NEW: SHOW ONLY SELECTED functionality ==========
            $showSelectedBtn.on('click', function(e) {
                e.preventDefault();
                
                if (isFiltering) {
                    // Show all items
                    showAllItems();
                    $showSelectedBtn.css('background', '#ff6b35');
                    $showSelectedBtn.html('SHOW ONLY SELECTED (<span class="selected-badge">' + getSelectedCount() + '</span>)');
                    isFiltering = false;
                } else {
                    // Show only selected
                    var selectedCount = getSelectedCount();
                    if (selectedCount === 0) {
                        alert('No tax rates selected!');
                        return;
                    }
                    showOnlySelected();
                    $showSelectedBtn.css('background', '#27ae60');
                    $showSelectedBtn.html('SHOWING SELECTED (<span class="selected-badge">' + selectedCount + '</span>) - CLICK TO SHOW ALL');
                    isFiltering = true;
                }
            });
            
            function getSelectedCount() {
                var val = $select.val();
                return val ? val.length : 0;
            }
            
            function showOnlySelected() {
                var selectedValues = $select.val() || [];
                console.log('Filtering to show only selected:', selectedValues.length);
                
                var $checkboxes = $wrapper.find('input[type="checkbox"]');
                
                $checkboxes.each(function() {
                    var $checkbox = $(this);
                    var value = $checkbox.val();
                    var $item = $checkbox.closest('li, label, .mselect-item, div[class*="item"]');
                    
                    if (!$item.length) {
                        $item = $checkbox.parent();
                    }
                    
                    if (selectedValues.indexOf(value) === -1) {
                        $item.hide();
                    } else {
                        $item.show();
                    }
                });
                
                var $mselectList = $wrapper.find('.mselect-list');
                var $notice = $('<div class="filter-notice"></div>').css({
                    'background': '#d4edda',
                    'border': '1px solid #c3e6cb',
                    'color': '#155724',
                    'padding': '12px',
                    'margin-bottom': '10px',
                    'border-radius': '4px',
                    'font-weight': 'bold',
                    'text-align': 'center'
                }).html('🔍 Showing only ' + selectedValues.length + ' selected tax rate(s). Click button to show all.');
                
                $mselectList.prepend($notice);
                console.log('✓ Filtered to selected items');
            }
            
            function showAllItems() {
                console.log('Showing all items again');
                
                $wrapper.find('.filter-notice').remove();
                
                var $checkboxes = $wrapper.find('input[type="checkbox"]');
                
                $checkboxes.each(function() {
                    var $checkbox = $(this);
                    var $item = $checkbox.closest('li, label, .mselect-item, div[class*="item"]');
                    
                    if (!$item.length) {
                        $item = $checkbox.parent();
                    }
                    
                    $item.show();
                });
                
                console.log('✓ All items visible');
            }
            
            function updateBadge() {
                var count = getSelectedCount();
                $('.selected-badge').text(count);
            }
            // ========== END NEW ==========
            
            // Update counter function
            function updateCounter() {
                var selected = $select.val() ? $select.val().length : 0;
                var total = $wrapper.find('input[type="checkbox"]:not(:disabled)').length;
                $counter.html('Selected: <span style="color: #007bdb;">' + selected + '</span> of <span style="color: #666;">' + total + '</span>');
                updateBadge(); // NEW: Update badge when counter updates
            }
            
            // Update counter on select change
            $select.on('change', updateCounter);
            
            // Assemble and insert buttons
            $btnContainer.append('<strong style="display:block; margin-bottom: 8px;">🎯 Tax Rate Helper</strong>');
            $btnContainer.append($selectAllBtn);
            $btnContainer.append($deselectBtn);
            $btnContainer.append($showSelectedBtn); // NEW: Add the new button
            $btnContainer.append($counter);
            
            // Insert after the select element
            $select.after($btnContainer);
            
            // Initial counter update
            updateCounter();
            
            console.log('✓ Tax Rate Select All initialized successfully!');
        } else {
            console.log('Tax rate select field not found');
        }
    }

    // Initialize on page load
    initSelectAll();

    // Re-initialize if content loads dynamically
    var checkInterval = setInterval(function () {
        var $taxRateSelect = $('select[name="tax_rate[]"]');
        
        if ($taxRateSelect.length > 0 && !$taxRateSelect.data('select-all-initialized')) {
            initSelectAll();
        }
    }, 1000);

    // Stop checking after 10 seconds
    setTimeout(function () {
        clearInterval(checkInterval);
    }, 10000);
});