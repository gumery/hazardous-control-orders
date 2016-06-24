define('page/hazardous/control/orders/settings/chemical-limits', ['jquery', 'bootstrap', 'bootbox', 'bootstrap-select', 'ajax-bootstrap-select'], function($, Bootstrap, Bootbox) {
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
        showLoadingDialog();
        var $form = $(this);
        var url = $form.attr('action');
        $.post(url, $form.serialize(), function(data) {
            data = data || {};
            if (!data.code) {
                window.location.reload();
                return;
            }
            clearLoadingDialog();
            Bootbox.alert(data.message);
        });
        return false;
    });

    function init() {
        $(".selectpicker").selectpicker({
            style: 'btn-blank'
        });
        $(".chemical-selectpicker").each(function(index, el) {
            $(el).ajaxSelectPicker(initOptions());
        });
    }

    function initOptions() {
        var options = {
            ajax: {
                url: 'ajax/settings/chemical-limits/search-chemical'
                ,type: 'GET'
                ,dataType: 'json'
                ,data: function() {
                    var type = $('.form-setting-requset-application-volume select[name=type]').val();
                    var params = {
                        q: '{{{q}}}'
                        ,type: type
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

    var clearLoadingDialog = function() {
        if (!loadingDialog || ! loadingDialog.length) return;
        loadingDialog.prev('.modal-backdrop').remove();
        loadingDialog.remove();
    };
    var showLoadingDialog = function() {
        clearLoadingDialog();
        loadingDialog = $('<div class="modal"><div class="modal-dialog"><div class="modal-content"><h2 class="text-center"><span class="fa fa-spinner fa-spin fa-2x"></span></h2></div></div></div>');
        loadingDialog.modal({
            show: true
            ,backdrop: 'static'
        });
    };

    return {
        loopMe: init
    };
});

