<?php
/**
 * Plantilla HTML de los avisos. Sencilla a propósito: tablas y estilos en
 * línea, que es lo único que respetan todos los clientes de correo.
 *
 * Una web la sustituye sin tocar el plugin copiándola a
 * <tema>/estrategia-web-forms/email.php (vale en el hijo o en el padre).
 *
 * Variables disponibles:
 * @var string $content_html Cuerpo del aviso ya convertido a HTML y escapado.
 * @var string $subject      Asunto, texto plano.
 * @var string $site_name    Nombre del sitio, texto plano.
 * @var string $footer       Pie configurado en Ajustes (texto plano, puede estar vacío).
 * @var string $lang         Idioma del envío.
 *
 * La trampa: lo que se escriba aquí sale en TODOS los avisos de la web, en
 * todos los idiomas. Un texto fijo nuevo va con esc_html__() y su entrada en
 * el .pot, o el acuse en inglés llevará un pie en castellano.
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
						<?php echo $content_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado y escapado en Notifier. ?>
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
