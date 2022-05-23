/*
( function ( wp ) {
    wp.data.dispatch( 'core/notices' ).createNotice(
        'success', // Can be one of: success, info, warning, error.
        'Post published.', // Text string to display.
        {
            isDismissible: true, // Whether the user can dismiss the notice.
            // Any actions the user can perform.
            actions: [
                {
                    url: '#',
                    label: 'View post',
                },
            ],
        }
    );
} )( window.wp );
 */
jQuery(document).ready(function() {
    // We listen to Ajax calls made via fetch
    var temp_fetch = window.fetch;
    window.fetch = function() {
        return new Promise((resolve, reject) => {
            temp_fetch.apply(this, arguments)
                .then((response) => {
                    console.log('update', response);
                    if( response.url.indexOf("/wp-json/wp/v2/posts") > -1 &&
                        response.type === 'basic'
                    ){
                        var clone = response.clone();
                        clone.json().then(function (json) {
                           // console.log(json, response);
                            if( typeof json.code !== 'undefined' &&
                                typeof json.code === 'string' &&
                                typeof json.message !== 'undefined' &&
                                typeof json.message === 'string'
                            ){
                                wp.data.dispatch("core/notices").createNotice(
                                    json.code,
                                    json.message,
                                    {
                                        // type: "snackbar", // Optional
                                        id: 'custom_post_site_save',
                                        isDismissible: true
                                    }
                                );

                                // Close default "Post saved" notice
                                wp.data.dispatch( 'core/notices' ).removeNotice('SAVE_POST_NOTICE_ID');

                                // You can see all active notices by this command:
                                // wp.data.select('core/notices').getNotices();
                            }
                        });
                    }

                    resolve(response);
                })
                .catch((error) => {
                    reject(error);
                })
        });
    };

    // If you want to listen to Ajax calls made via jQuery
    // jQuery(document).bind("ajaxSend", function(event, request, settings){
    // }).bind("ajaxComplete", function(event, request, settings){
    // });
});