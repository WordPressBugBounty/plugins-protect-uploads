(function($) {
    'use strict';

    // Labels are user input: build the list with text nodes, not HTML.
    function renderPasswords(container, passwords) {
        var list = container.find('.existing-passwords').empty();
        if (!passwords.length) {
            return;
        }
        list.append($('<h4>').text(protectUploadsPasswords.i18n.existingPasswords));
        var ul = $('<ul>');
        passwords.forEach(function(pass) {
            ul.append(
                $('<li>').text(pass.password_label + ' ').append(
                    $('<a href="#" class="delete-password">').attr('data-id', pass.id).text(protectUploadsPasswords.i18n.delete)
                )
            );
        });
        list.append(ul);
    }

    $(document).ready(function() {
        // Handle adding new passwords
        $('.protect-uploads-passwords .add-password-button').on('click', function(e) {
            e.preventDefault();
            var container = $(this).closest('.protect-uploads-passwords');
            var label = container.find('input[name="protect_uploads_password_label"]').val();
            var password = container.find('input[name="protect_uploads_password"]').val();
            var attachmentId = container.data('attachment-id');

            if (!label || !password) {
                alert(protectUploadsPasswords.i18n.enterBothFields);
                return;
            }

            $(this).prop('disabled', true).text(protectUploadsPasswords.i18n.addingPassword);

            $.ajax({
                url: protectUploadsPasswords.ajaxurl,
                type: 'POST',
                data: {
                    action: 'protect_uploads_add_password',
                    nonce: protectUploadsPasswords.nonce,
                    attachment_id: attachmentId,
                    label: label,
                    password: password
                },
                success: function(response) {
                    if (response.success) {
                        // Clear inputs
                        container.find('input[name="protect_uploads_password_label"]').val('');
                        container.find('input[name="protect_uploads_password"]').val('');
                        
                        // Update password list
                        renderPasswords(container, response.data.passwords);
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert(protectUploadsPasswords.i18n.addError);
                },
                complete: function() {
                    container.find('.add-password-button').prop('disabled', false)
                        .text(protectUploadsPasswords.i18n.addPassword);
                }
            });
        });

        // Handle deleting passwords
        $(document).on('click', '.protect-uploads-passwords .delete-password', function(e) {
            e.preventDefault();
            if (!confirm(protectUploadsPasswords.i18n.confirmDelete)) {
                return;
            }

            var link = $(this);
            var container = link.closest('.protect-uploads-passwords');
            var attachmentId = container.data('attachment-id');
            var passwordId = link.data('id');

            link.text(protectUploadsPasswords.i18n.deletingPassword);

            $.ajax({
                url: protectUploadsPasswords.ajaxurl,
                type: 'POST',
                data: {
                    action: 'protect_uploads_delete_password',
                    nonce: protectUploadsPasswords.nonce,
                    attachment_id: attachmentId,
                    password_id: passwordId
                },
                success: function(response) {
                    if (response.success) {
                        // Update password list
                        renderPasswords(container, response.data.passwords);
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert(protectUploadsPasswords.i18n.deleteError);
                }
            });
        });
    });
})(jQuery); 