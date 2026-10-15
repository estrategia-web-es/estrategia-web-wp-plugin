<?php
/**
 * HTML template for the notifications. Simple on purpose: tables and inline
 * styles, which is the only thing every email client respects.
 *
 * A site replaces it without touching the plugin by copying it to
 * <theme>/estrategia-web-forms/email.php (works in the child or the parent).
 *
 * Available variables:
 * @var string $content_html Notification body already converted to HTML and escaped.
 * @var string $subject      Subject, plain text.
 * @var string $site_name    Site name, plain text.
 * @var string $footer       Footer configured in Settings (plain text, may be empty).
 * @var string $lang         Language of the submission.
 *
 * The trap: whatever is written here goes out in ALL the site's
 * notifications, in every language. A new fixed text goes with esc_html__()
 * and its entry in the .pot, or the English acknowledgement will carry a
 * Spanish footer.
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', $lang ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;">
	<tr>
		<td align="center" style="padding:24px 12px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:6px;">
				<tr>
					<td style="padding:24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1e1e1e;">
						<?php echo $content_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in Notifier. ?>
					</td>
				</tr>
				<tr>
					<td style="padding:12px 24px 24px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.4;color:#757575;border-top:1px solid #e5e5e5;">
						<?php echo '' !== $footer ? nl2br( esc_html( $footer ), false ) : esc_html( $site_name ); ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
