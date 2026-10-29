/*
 * Classic (shortcode) checkout: mounts the locker picker after the customer
 * details and keeps the four hidden fields the server reads in step.
 */
jQuery(function ($) {
    var root = document.getElementById('livraria-locker-picker');
    if (!root || !window.LivrariaLockerPicker || !window.livrariaLockerConfig) {
        return;
    }
    var config = $.extend({}, window.livrariaLockerConfig, {
        onChange: function (pick) {
            $('#livraria_delivery_mode').val(pick.mode);
            $('#livraria_locker_id').val(pick.id);
            $('#livraria_locker_name').val(pick.name);
            $('#livraria_locker_courier').val(pick.courier);
        }
    });
    window.LivrariaLockerPicker.mount(root, config);
});
