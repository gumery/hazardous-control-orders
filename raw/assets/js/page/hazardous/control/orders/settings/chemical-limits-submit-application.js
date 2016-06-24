define('page/hazardous/control/orders/settings/chemical-limits-submit-application', ['jquery', 'bootbox'], function($, Bootbox) {
    $(document).on('submit', '.form-setting-requset-application-volume', function() {
        var $form = $(this);
        var url = $form.attr('action');
        $.post(url, $form.serialize(), function(data) {
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

