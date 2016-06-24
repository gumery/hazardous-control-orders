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
    });

    function init() {
        $(".chemical-selectpicker").each(function(index, el) {
            $(el).ajaxSelectPicker();
        });
    }

    function initOptions() {
        var options = {
            ajax: {
                url: 'ajax/settings/chemical-limits/search-chemical'
                ,type: 'GET'
                ,dataType: 'json'
                ,data: function() {
                    var form = $('.form-setting-requset-application-volume').serialize();
                    var params = {
                        q: '{{{q}}}'
                        ,post: form
                    };
                    return params;
                }
            }
            ,cache: false
            ,preserveSelected: false
            ,preprocessData: function(data) {
                var i, l = data.length,
                array = [];
                if (l) {
                    for (i = 0; i < l; i++) {
                        array.push($.extend(true, data[i], {
                            text: data[i].value
                            ,value: data[i].key
                            ,
                        }));
                    }
                    array.unshift({
                        text: '--'
                        ,value: ''
                    });
                }
                return array;
            }
        };
        return options;
    }

    return {
        loopMe: init
    };
});

