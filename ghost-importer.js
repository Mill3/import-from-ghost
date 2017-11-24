jQuery(document).ready(function($) {
    if ($('[data-gi-trigger-import]').length) {
        var import_id = $('[data-gi-trigger-import]').data('gi-trigger-import');
        var import_file = $('[data-gi-import-file]').data('gi-import-file');
        var nonce = $('[data-gi-nonce]').data('gi-nonce');
        var dryrun = $('[data-gi-dryrun]').data('gi-dryrun');
        var ghost_url = $('[data-gi-ghost-url]').data('gi-ghost-url');
        console.log(ghost_url);

        // Trigger the initial import
        var postdata = {'action':'ghost_importer_trigger','gi_import_id':import_id,'gi_import_file':import_file,'gi-trigger-import':nonce,'gi_dryrun':dryrun, 'gi_ghost_url':ghost_url };
        $.post(ajaxurl, postdata);

        // Poll the log table
        pollLog();
        function pollLog() {
            var last_log_id = $('.gi-progress').data('last-log-id');
            $.post(ajaxurl, {'action':'ghost_importer_log','gi_import_id':import_id, 'gi_last_log_id':last_log_id}, function(data) {
                $('.gi-progress').show();
                
                var finished = false;
                var errored = false;
                $.each(data, function(i,e) {
                    if (e.is_error == "1") {
                        $('.gi-progress-log').prepend('<div class="gi-log-entry error-msg">'+e.text+'</div>');
                        errored = true;
                    } else {
                        $('.gi-progress-log').prepend('<div class="gi-log-entry">'+e.text+'</div>');
                    }
                    if ((i+1) == data.length) {
                        $('.gi-upto').text(e.up_to);        
                        $('.gi-totalposts').text(e.total_posts);
                        if (e.finished == "1") finished = true;
                    }
                    $('.gi-progress').data('last-log-id',e.id);
                });
                if (!finished) {
                    setTimeout(pollLog,1000);
                } else if (finished && errored) {
                    $('.gi-running-import').hide();
                    $('.gi-errored-import').show();
                } else if (finished) {
                    $('.gi-running-import').hide();
                    $('.gi-finished-import').show();
                }
            }, 'json');
        }
    } 
});