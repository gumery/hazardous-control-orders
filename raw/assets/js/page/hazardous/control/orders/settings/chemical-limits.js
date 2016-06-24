define('page/hazardous/control/orders/settings/chemical-limits', ['jquery', 'bootstrap'], function($) {
    function showDialog() {
        $.get('ajax/settings/chemical-limits/get-request-modal', function(data) {
            $(data).modal({
                show: true
                ,backdrop: 'static'
            });
        });
    }

    $(document).on('click', '.app-handler-append-limit-request', showDialog);
    $(document).on('submit', '.form-append-limit-request', function() {
        return false;
    });
});

