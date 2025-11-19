(function ($) {
    function toggleFlexiownFields() {
        var container = $('#flexiown-extra-fields');
        if (!container.length) {
            return;
        }

        var selectedMethod = $('input[name="payment_method"]:checked').val();
        if (selectedMethod === 'flexiown') {
            container.stop(true, true).slideDown('fast');
        } else {
            container.stop(true, true).slideUp('fast');
        }
    }

    function bindFlexiownFieldToggle() {
        $(document.body).on('change', 'input[name="payment_method"]', toggleFlexiownFields);
        $(document.body).on('updated_checkout', toggleFlexiownFields);
        toggleFlexiownFields();
    }

    $(document).ready(bindFlexiownFieldToggle);
})(jQuery);
