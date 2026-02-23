// Main JavaScript file for Water Billing System

$(document).ready(function() {
    // Auto-format phone numbers
    $('input[type="tel"]').on('input', function() {
        let value = $(this).val().replace(/\D/g, '');
        
        if(value.startsWith('0')) {
            value = '254' + value.substr(1);
        } else if(!value.startsWith('254')) {
            value = '254' + value;
        }
        
        if(value.length > 12) {
            value = value.substr(0, 12);
        }
        
        $(this).val(value);
    });
    
    // Toggle password visibility
    $('.toggle-password').on('click', function() {
        const input = $(this).closest('.input-group').find('input');
        const icon = $(this).find('i');
        
        if(input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('bi-eye').addClass('bi-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('bi-eye-slash').addClass('bi-eye');
        }
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert:not(.alert-permanent)').fadeOut('slow');
    }, 5000);
});

// Format currency
function formatCurrency(amount) {
    return 'Ksh ' + parseFloat(amount).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// Show loading spinner
function showLoading(element) {
    element.prop('disabled', true);
    element.data('original-text', element.html());
    element.html('<span class="spinner-border spinner-border-sm"></span> Loading...');
}

// Hide loading spinner
function hideLoading(element) {
    element.prop('disabled', false);
    element.html(element.data('original-text'));
}
