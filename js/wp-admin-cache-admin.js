jQuery(document).ready(function($) {
    $('.wp-admin-cache-include-toggle').on('change', function() {
        var postId = $(this).data('postid');
        var include = $(this).is(':checked') ? 'true' : 'false';
        $.ajax({
            url: wpAdminCache.ajax_url,
            type: 'POST',
            data: {
                action: 'wp_admin_cache_toggle_include',
                nonce: wpAdminCache.nonce,
                post_id: postId,
                include: include
            },
            success: function(response) {
                if ( response.success ) {
                    console.log('Cache inclusion toggled successfully for post ' + postId);
                }
            }
        });
    });
});
