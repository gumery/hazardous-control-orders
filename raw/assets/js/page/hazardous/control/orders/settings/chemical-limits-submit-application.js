define('page/hazardous/control/orders/settings/chemical-limits-submit-application', ['jquery', 'bootbox'], function($, Bootbox) {
    $(document).on('submit', '.form-setting-requset-application-volume', function() {
        var $that = $(this);
        var url = $that.attr('action');
        $.post(url, [], function(data) {
            data = data || {};
            if (!data.code) {
                window.location.reload();
                return;
            }
            Bootbox.alert(data.message);
        });
        return false;
    })
});

