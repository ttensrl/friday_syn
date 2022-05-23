jQuery('#friday-sync-button').click(function (e) {
    let post_id = jQuery(this).data('postId'),
        data = {
            'action': 'friday_sync',
            'post_id': post_id
        };
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        dataType: 'JSON',
        data: data
    }).done(function (response){
        let actions = [];
        if(response.hasOwnProperty('permalink')) {
            actions = [{url: response.permalink, label: 'Visualizza post in produzione'}];
        }
        wp.data.dispatch( 'core/notices' ).removeNotice('SYNC_POST_SUCCESS');
        wp.data.dispatch( 'core/notices' ).createNotice(
            response.status, // Can be one of: success, info, warning, error.
            response.message, // Text string to display.
            {
                id: 'SYNC_POST_SUCCESS',
                isDismissible: true,
                actions: actions,
            }
        ).then(function (notice) {
            setTimeout(function() {
                wp.data.dispatch( 'core/notices' ).removeNotice('SYNC_POST_SUCCESS');
            }, 5000);
        });
    }).fail(function (jqXHR, textStatus, errorThrown) {
        wp.data.dispatch( 'core/notices' ).removeNotice('SYNC_POST_ERROR');
        wp.data.dispatch( 'core/notices' ).createNotice(
            'error', // Can be one of: success, info, warning, error.
            jqXHR.responseJSON.message,
            {
                id: 'SYNC_POST_ERROR',
                isDismissible: true, // Whether the user can dismiss the notice.
            }
        );
    });
});