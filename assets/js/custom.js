// assets/js/custom.js
$(document).ready(function() {
    // Auto-save on change for all editable fields
    $('.ttl-sam, .hour-input').on('change', function() {
        // The onchange attribute handles this
    });
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl+S to save
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            saveAll();
        }
    });
    
    // Print functionality
    $('#printBtn').on('click', function() {
        window.print();
    });
    
    // Export functionality
    $('#exportBtn').on('click', function() {
        window.location.href = 'exports/export.php';
    });
});

function saveAll() {
    var data = {};
    var date = $('#reportDate').val();
    
    $('.ttl-sam').each(function() {
        var devition = $(this).data('devition');
        var unit = $(this).data('unit');
        var value = $(this).val();
        data[devition + '_' + unit + '_ttl'] = value;
    });
    
    $('.hour-input').each(function() {
        var devition = $(this).data('devition');
        var unit = $(this).data('unit');
        var hour = $(this).data('hour');
        var value = $(this).val();
        data[devition + '_' + unit + '_hour_' + hour] = value;
    });
    
    $.ajax({
        url: 'save_data.php',
        type: 'POST',
        data: {
            action: 'save_all',
            date: date,
            data: data
        },
        success: function(response) {
            if (response.success) {
                showNotification('Saved successfully!', 'success');
            } else {
                showNotification('Error saving: ' + response.message, 'error');
            }
        }
    });
}

function showNotification(message, type) {
    var color = type === 'success' ? '#d4edda' : '#f8d7da';
    var textColor = type === 'success' ? '#155724' : '#721c24';
    var border = type === 'success' ? '#c3e6cb' : '#f5c6cb';
    
    var notification = $('<div>')
        .css({
            position: 'fixed',
            top: '20px',
            right: '20px',
            padding: '15px 25px',
            background: color,
            color: textColor,
            border: '1px solid ' + border,
            borderRadius: '4px',
            zIndex: 9999,
            boxShadow: '0 4px 12px rgba(0,0,0,0.15)'
        })
        .html(message)
        .appendTo('body');
    
    setTimeout(function() {
        notification.fadeOut(500, function() {
            $(this).remove();
        });
    }, 3000);
}