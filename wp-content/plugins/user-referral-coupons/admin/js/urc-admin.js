jQuery(document).ready(function($) {
    var $initiateButton = $('#urc-initiate-bulk-email');
    var $feedbackDiv = $('#urc-bulk-email-feedback');
    var $progressBarContainer = $('#urc-bulk-email-progress-bar-container');
    var $progressBar = $('#urc-bulk-email-progress-bar');
    var $logDiv = $('#urc-bulk-email-log');
    var currentOffset = 0;
    var totalEligible = 0;
    var batchSize = 20; // Default, will be updated by initiation call
    var totalProcessedSuccessfully = 0;
    var totalFailed = 0;

    $initiateButton.on('click', function() {
        if ($(this).is(':disabled')) {
            return;
        }

        $(this).prop('disabled', true);
        $feedbackDiv.html('<p>' + urcAdminAjax.i18n.processing + '</p>').removeClass('notice-error notice-success notice-warning');
        $logDiv.html('').hide();
        $progressBarContainer.show();
        $progressBar.width('0%').text('0%');
        currentOffset = 0;
        totalProcessedSuccessfully = 0;
        totalFailed = 0;

        $.ajax({
            url: urcAdminAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'urc_initiate_bulk_email',
                nonce: urcAdminAjax.nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    totalEligible = parseInt(response.data.total_eligible, 10);
                    batchSize = parseInt(response.data.batch_size, 10);
                    if (totalEligible > 0) {
                        $feedbackDiv.html('<p>' + urcAdminAjax.i18n.processing + ' ' + totalEligible + ' ' + urcAdminAjax.i18n.users_eligible_for_email + '</p>').addClass('notice-info');
                        $logDiv.show();
                        sendNextBatch();
                    } else {
                        $feedbackDiv.html('<p>' . urcAdminAjax.i18n.no_users_to_email + '</p>').addClass('notice-warning');
                        $initiateButton.prop('disabled', false);
                        $progressBarContainer.hide();
                    }
                } else {
                    $feedbackDiv.html('<p>' + (response.data.message || urcAdminAjax.i18n.error_please_try) + '</p>').addClass('notice-error');
                    $initiateButton.prop('disabled', false);
                    $progressBarContainer.hide();
                }
            },
            error: function() {
                $feedbackDiv.html('<p>' + urcAdminAjax.i18n.error_please_try + '</p>').addClass('notice-error');
                $initiateButton.prop('disabled', false);
                $progressBarContainer.hide();
            }
        });
    });

    function sendNextBatch() {
        if (currentOffset >= totalEligible) {
            var summaryMessage = '<p>' + urcAdminAjax.i18n.complete + '<br>';
            summaryMessage += urcAdminAjax.i18n.successfully_sent_to + ' ' + totalProcessedSuccessfully + ' users.<br>';
            if (totalFailed > 0) {
                 summaryMessage += urcAdminAjax.i18n.failed_for + ' ' + totalFailed + ' users (check server error logs for details).';
            }
            summaryMessage += '</p>';
            $feedbackDiv.html(summaryMessage).addClass('notice-success');
            $initiateButton.prop('disabled', false); // Re-enable
            // Consider reloading the page or updating stats dynamically if needed
            // window.location.reload();
            return;
        }

        $.ajax({
            url: urcAdminAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'urc_process_bulk_email_batch',
                nonce: urcAdminAjax.nonce,
                offset: currentOffset
                // batch_size is now fetched from the initiate call, server side will use it from transient or default
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var processedInBatch = parseInt(response.data.processed_in_batch, 10);
                    var successesInBatch = parseInt(response.data.successes, 10);
                    var failuresInBatch = parseInt(response.data.failures, 10);

                    totalProcessedSuccessfully += successesInBatch;
                    totalFailed += failuresInBatch;
                    currentOffset += processedInBatch; // Server should return how many it actually processed from this offset

                    var percentage = Math.min(100, Math.round((currentOffset / totalEligible) * 100));
                    $progressBar.width(percentage + '%').text(percentage + '%');

                    var logMessage = urcAdminAjax.i18n.processed_users + ' ' + currentOffset + '/' + totalEligible + '. ';
                    logMessage += ' (Batch: ' + successesInBatch + ' sent, ' + failuresInBatch + ' failed.)';
                    $logDiv.append('<div>' + logMessage + '</div>');
                    $logDiv.scrollTop($logDiv[0].scrollHeight);


                    if (response.data.log_messages && response.data.log_messages.length > 0) {
                        response.data.log_messages.forEach(function(msg){
                             $logDiv.append('<div style="padding-left:15px; font-size:smaller;">' + msg + '</div>');
                        });
                        $logDiv.scrollTop($logDiv[0].scrollHeight);
                    }


                    sendNextBatch(); // Process next batch
                } else {
                    $feedbackDiv.html('<p>' + (response.data.message || urcAdminAjax.i18n.error_please_try) + '</p>').addClass('notice-error');
                    $logDiv.append('<div><strong>' + (response.data.message || urcAdminAjax.i18n.error_please_try) + '</strong></div>');
                    $initiateButton.prop('disabled', false);
                }
            },
            error: function() {
                $feedbackDiv.html('<p>' + urcAdminAjax.i18n.error_please_try + '</p>').addClass('notice-error');
                $logDiv.append('<div><strong>' + urcAdminAjax.i18n.error_please_try + ' (Network or server error)</strong></div>');
                $initiateButton.prop('disabled', false);
            }
        });
    }
});
