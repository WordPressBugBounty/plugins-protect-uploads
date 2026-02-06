<?php
/**
 * Template for password protection prompt
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/templates
 */

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Protected Media - Password Required', 'protect-uploads' ); ?></title>
	<?php wp_head(); ?>
	<style>
		.protect-uploads-prompt {
			max-width: 400px;
			margin: 100px auto;
			padding: 20px;
			background: #fff;
			box-shadow: 0 1px 3px rgba(0,0,0,0.13);
			border-radius: 4px;
		}
		.protect-uploads-prompt h1 {
			margin: 0 0 20px;
			padding: 0;
			font-size: 24px;
			font-weight: 400;
			line-height: 1.3;
		}
		.protect-uploads-prompt form {
			margin: 0;
			padding: 0;
		}
		.protect-uploads-prompt .input-group {
			margin-bottom: 15px;
		}
		.protect-uploads-prompt label {
			display: block;
			margin-bottom: 5px;
		}
		.protect-uploads-prompt input[type="password"] {
			width: 100%;
			padding: 8px;
			font-size: 14px;
			line-height: 1.4;
			border: 1px solid #ddd;
			border-radius: 4px;
			box-sizing: border-box;
		}
		.protect-uploads-prompt .button {
			display: inline-block;
			padding: 8px 16px;
			font-size: 14px;
			line-height: 1.4;
			text-decoration: none;
			background: #0085ba;
			border: 1px solid #006799;
			border-radius: 4px;
			color: #fff;
			cursor: pointer;
		}
		.protect-uploads-prompt .button:hover {
			background: #008ec2;
		}
		.protect-uploads-prompt .error {
			color: #dc3232;
			margin: 0 0 15px;
			padding: 0;
		}
	</style>
</head>
<body class="protect-uploads-body">
	<div class="protect-uploads-prompt">
		<h1><?php esc_html_e( 'This media file is password protected', 'protect-uploads' ); ?></h1>
		
		<?php if ( ! empty( $error_message ) ) : ?>
			<p class="error"><?php echo esc_html( $error_message ); ?></p>
		<?php endif; ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'protect_uploads_verify_password', 'protect_uploads_nonce' ); ?>
			<input type="hidden" name="attachment_id" value="<?php echo esc_attr( $attachment_id ); ?>">
			
			<div class="input-group">
				<label for="password"><?php esc_html_e( 'Password:', 'protect-uploads' ); ?></label>
				<input type="password" name="password" id="password" required>
			</div>

			<button type="submit" class="button"><?php esc_html_e( 'Submit', 'protect-uploads' ); ?></button>
		</form>
	</div>
	<?php wp_footer(); ?>
</body>
</html> 