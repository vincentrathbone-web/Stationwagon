<?php
/**
 * The email the contact form sends the station, ported from WordWave's plugin (Wordwave_Mail, the
 * "Status Ledger" look tuned for Outlook; its notes on Outlook are in that file) in Gansbaai's colours:
 * a light card on a pale sea-blue page, an uppercase pill with the topic, a badge and a bold headline,
 * a deep-blue rule, a label/value ledger, the message in a soft box and a "Reply" button.
 *
 * Email-safe on purpose: tables and inline styles only (no CSS variables, scripts, web fonts or images,
 * so nothing is loaded when the mail is opened), system fonts, every value escaped, a plain-text copy as
 * the alternative part.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GBCR_Mail {

	const ACCENT = '#0A6FA8';
	const SOFT   = '#DDF8F4';
	const DEEP   = '#075A89';
	const INK    = '#03182B';
	const MUTED  = '#4F6B7D';
	const LINE   = '#DCEBF0';
	const PAGE   = '#EAF5F7';
	const BOXBG  = '#F1FAFB';
	const WIDTH  = 600;

	const SANS = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
	const MONO = "SFMono-Regular,Consolas,'Liberation Mono',Menlo,monospace";

	const VML_NS   = 'xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"';
	const MSO_TEXT = 'margin:0;mso-margin-top-alt:0;mso-margin-bottom-alt:0;mso-line-height-rule:exactly;';

	/* ---------- Sending ---------- */

	/**
	 * Send an HTML email with a plain-text alternative. $headers are the extra headers (From, Reply-To);
	 * the content type is added here.
	 */
	public static function send( $to, $subject, $html, $text, array $headers ) {
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		$alt       = function ( $phpmailer ) use ( $text ) {
			$phpmailer->AltBody = $text;
		};
		add_action( 'phpmailer_init', $alt );
		$ok = wp_mail( $to, $subject, $html, $headers );
		remove_action( 'phpmailer_init', $alt );
		return $ok;
	}

	/* ---------- The email ---------- */

	/** The contact form message for the station: returns array( html, text ). */
	public static function contact( $topic, $name, $email, $phone, $message ) {
		$when   = wp_date( 'j M Y \a\t H:i', time(), new DateTimeZone( 'Africa/Johannesburg' ) ) . ' SAST';
		$inner  = self::tag( $topic );
		$inner .= self::head( 'New message', '<b style="color:' . self::INK . ';">' . esc_html( $name ) . '</b> &middot; ' . esc_html( $when ), '&#9993;' );
		$inner .= self::rule();
		$inner .= self::ledger(
			array(
				array( 'Topic', esc_html( $topic ) ),
				array( 'Name', esc_html( $name ) ),
				array( 'Email', '<a href="mailto:' . esc_attr( $email ) . '" style="color:' . self::DEEP . ';text-decoration:none;">' . esc_html( $email ) . '</a>' ),
				array( 'Phone', '' !== $phone ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '" style="color:' . self::DEEP . ';text-decoration:none;">' . esc_html( $phone ) . '</a>' : '&ndash;' ),
			)
		);
		$inner .= self::quote( $message );
		$inner .= self::button( 'mailto:' . $email, 'Reply to ' . self::first_name( $name ) );
		$foot   = 'Sent from the contact form on the Gansbaai Community Radio website. Press Reply to answer ' . esc_html( $name ) . ' directly.';

		$text = "Topic: $topic\r\nName: $name\r\nEmail: $email\r\nPhone: " . ( '' !== $phone ? $phone : '-' ) . "\r\nReceived: $when\r\n\r\n" . $message;

		return array( self::shell( $inner, $foot, 'New message from ' . $name ), $text );
	}

	private static function first_name( $name ) {
		$parts = preg_split( '/\s+/', trim( $name ) );
		$first = is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : '';
		return '' !== $first ? mb_substr( $first, 0, 30 ) : 'them';
	}

	/* ---------- The page and the card ---------- */

	/** The page, the wordmark and the white card. Returns a complete HTML document. */
	public static function shell( $inner, $footer_html, $title ) {
		$w = self::WIDTH;
		$f = self::SANS;
		$body =
			'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . self::PAGE . '" style="width:100%;background:' . self::PAGE . ';"><tr>'
			. '<td align="center" style="padding:32px 16px 40px;font-family:' . $f . ';">'
			. '<table role="presentation" width="' . $w . '" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:' . $w . 'px;">'
			. '<tr><td align="center" style="padding:0 0 16px;text-align:center;font-family:' . $f . ';">'
			. '<p style="' . self::MSO_TEXT . 'font-size:15px;line-height:20px;font-weight:900;letter-spacing:.22em;text-transform:uppercase;color:' . self::DEEP . ';">Gansbaai Community Radio</p>'
			. '</td></tr>'
			. '<tr><td>' . self::card( $inner, $footer_html ) . '</td></tr>'
			. '<tr><td align="center" style="padding:16px 0 0;font-family:' . $f . ';">'
			. '<p style="' . self::MSO_TEXT . 'font-size:11px;line-height:16px;color:' . self::MUTED . ';">Gansbaai Community Radio &middot; Gansbaai, Western Cape</p></td></tr>'
			. '</table></td></tr></table>';

		return '<!DOCTYPE html><html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office"><head>'
			. '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">'
			. '<meta http-equiv="X-UA-Compatible" content="IE=edge"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>'
			. '<style>table {border-collapse:collapse;} td {mso-line-height-rule:exactly;}</style><![endif]-->'
			. '</head><body style="margin:0;padding:0;background:' . self::PAGE . ';">'
			. $body
			. '</body></html>';
	}

	/**
	 * The white card. Other clients get the CSS card (border-radius). Outlook gets a 3x3 frame with four small
	 * VML quarter-circle corners (white on the page colour); the edge cells carry the thin border. Conditional
	 * comments cannot nest, so each copy gets its content pre-resolved: Outlook-only parts unwrapped in the
	 * Outlook copy and dropped in the other, and the other way round.
	 */
	private static function card( $inner, $foot ) {
		$f       = self::SANS;
		$line    = '1px solid ' . self::LINE;
		$outlook = preg_replace( '/<!--\[if !mso\]><!-->.*?<!--<!\[endif\]-->/s', '', $inner );
		$outlook = preg_replace( '/<!--\[if mso\]>(.*?)<!\[endif\]-->/s', '$1', $outlook );
		$others  = preg_replace( '/<!--\[if mso\]>.*?<!\[endif\]-->/s', '', $inner );
		$others  = str_replace( array( '<!--[if !mso]><!-->', '<!--<![endif]-->' ), '', $others );
		$foot_p  = '<p style="' . self::MSO_TEXT . 'font-size:12px;line-height:19px;color:' . self::MUTED . ';">' . $foot . '</p>';

		// Everyone but Outlook: one rounded table, content row and footnote row.
		$css = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;background:#ffffff;border:' . $line . ';border-collapse:separate;border-radius:20px;">'
			. '<tr><td align="left" bgcolor="#ffffff" style="background:#ffffff;border-radius:20px 20px 0 0;padding:28px 28px 22px;font-family:' . $f . ';color:' . self::INK . ';">' . $others . '</td></tr>'
			. '<tr><td align="left" bgcolor="#ffffff" style="background:#ffffff;border-radius:0 0 20px 20px;border-top:' . $line . ';padding:16px 28px 22px;font-family:' . $f . ';">' . $foot_p . '</td></tr>'
			. '</table>';

		// Outlook: the framed copy.
		$r      = 20;
		$page   = self::PAGE;
		$stroke = ' strokecolor="' . self::LINE . '" strokeweight="1px"';
		$corner = static function ( $fill_path, $arc_path ) use ( $r, $page, $stroke ) {
			$path = $arc_path . ' nf e ' . $fill_path . ' ns e'; // arc first, so no outline runs along the straight sides
			return '<td width="' . $r . '" height="' . $r . '" bgcolor="' . $page . '" style="width:' . $r . 'px;height:' . $r . 'px;font-size:1px;line-height:' . $r . 'px;mso-line-height-rule:exactly;">'
				. '<v:shape xmlns:v="urn:schemas-microsoft-com:vml" style="width:' . $r . 'px;height:' . $r . 'px;" coordsize="' . $r . ',' . $r . '" path="' . $path . '" fillcolor="#ffffff"' . $stroke . '></v:shape></td>';
		};
		$edge = static function ( $border ) use ( $r, $line ) {
			return '<td bgcolor="#ffffff" style="background:#ffffff;font-size:1px;line-height:' . $r . 'px;mso-line-height-rule:exactly;border-' . $border . ':' . $line . ';">&nbsp;</td>';
		};
		$side = static function ( $border, $extra = '' ) use ( $r, $line ) {
			return '<td width="' . $r . '" bgcolor="#ffffff" style="width:' . $r . 'px;background:#ffffff;border-' . $border . ':' . $line . ';' . $extra . '">&nbsp;</td>';
		};
		$sep = 'border-top:' . $line . ';';
		$mso = '<table role="presentation" width="' . self::WIDTH . '" cellpadding="0" cellspacing="0" border="0" style="width:' . self::WIDTH . 'px;">'
			. '<tr>' . $corner( 'm 0,20 qy 20,0 l 20,20 x', 'm 0,20 qy 20,0' ) . $edge( 'top' ) . $corner( 'm 0,0 qx 20,20 l 0,20 x', 'm 0,0 qx 20,20' ) . '</tr>'
			. '<tr>' . $side( 'left' ) . '<td align="left" bgcolor="#ffffff" style="background:#ffffff;padding:8px 8px 24px;font-family:' . $f . ';color:' . self::INK . ';font-size:14px;line-height:22px;mso-line-height-rule:exactly;">' . $outlook . '</td>' . $side( 'right' ) . '</tr>'
			. '<tr>' . $side( 'left', $sep ) . '<td align="left" bgcolor="#ffffff" style="background:#ffffff;padding:14px 8px 2px;font-family:' . $f . ';' . $sep . '">' . $foot_p . '</td>' . $side( 'right', $sep ) . '</tr>'
			. '<tr>' . $corner( 'm 20,20 qx 0,0 l 20,0 x', 'm 20,20 qx 0,0' ) . $edge( 'bottom' ) . $corner( 'm 20,0 qy 0,20 l 0,0 x', 'm 20,0 qy 0,20' ) . '</tr>'
			. '</table>';

		return '<!--[if mso]>' . $mso . '<![endif]-->'
			. '<!--[if !mso]><!-->' . $css . '<!--<![endif]-->';
	}

	/* ---------- Building blocks ---------- */

	/** An empty row of a fixed height. Word ignores margins on tables; a sized cell always works. */
	private static function spacer( $px ) {
		$px = (int) $px;
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
			. '<td height="' . $px . '" style="height:' . $px . 'px;font-size:1px;line-height:' . $px . 'px;mso-line-height-rule:exactly;">&nbsp;</td></tr></table>';
	}

	/** A small fixed-size VML rounded shape with centred text, for Outlook only. */
	private static function vml_pill( $w, $h, $arc, $fill, $color, $font_px, $text, $extra = '' ) {
		return '<v:roundrect ' . self::VML_NS . ' style="height:' . (int) $h . 'px;v-text-anchor:middle;width:' . (int) $w . 'px;" arcsize="' . $arc . '" stroke="f" fillcolor="' . $fill . '">'
			. '<w:anchorlock/><center style="' . self::MSO_TEXT . 'color:' . $color . ';font-family:Arial,sans-serif;font-size:' . (int) $font_px . 'px;font-weight:bold;' . $extra . '">' . $text . '</center>'
			. '</v:roundrect>';
	}

	/** The small uppercase pill above the headline. A table with no width shrinks to its text in every client. */
	private static function tag( $label ) {
		$label = strtoupper( $label );
		$html  = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
			. '<td bgcolor="' . self::SOFT . '" style="background:' . self::SOFT . ';border-radius:999px;padding:5px 11px;font-family:' . self::SANS . ';'
			. 'font-size:11px;line-height:13px;mso-line-height-rule:exactly;font-weight:700;letter-spacing:.08em;color:' . self::DEEP . ';">'
			. esc_html( $label ) . '</td></tr></table>';
		$w     = 24 + (int) ceil( mb_strlen( $label ) * 8.5 );
		return '<!--[if mso]>' . self::vml_pill( $w, 28, '50%', self::SOFT, self::DEEP, 11, esc_html( $label ), 'letter-spacing:1px;' ) . '<![endif]-->'
			. '<!--[if !mso]><!-->' . $html . '<!--<![endif]-->'
			. self::spacer( 14 );
	}

	/** Rounded badge with a glyph, a bold headline and a muted line. $meta_html is already safe HTML. */
	private static function head( $title, $meta_html, $glyph ) {
		$badge = '<td width="44" height="44" align="center" valign="middle" bgcolor="' . self::ACCENT . '" style="width:44px;height:44px;background:' . self::ACCENT . ';border-radius:13px;'
			. 'text-align:center;font-family:' . self::SANS . ';font-size:19px;line-height:44px;mso-line-height-rule:exactly;font-weight:700;color:#ffffff;">' . $glyph . '&#xFE0E;</td>';
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;"><tr>'
			. '<td width="44" valign="top" style="width:44px;">'
			. '<!--[if mso]>' . self::vml_pill( 44, 44, '30%', self::ACCENT, '#ffffff', 19, $glyph ) . '<![endif]-->'
			. '<!--[if !mso]><!--><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . $badge . '</tr></table><!--<![endif]-->'
			. '</td>'
			. '<td width="14" style="width:14px;font-size:1px;line-height:1px;">&nbsp;</td>'
			. '<td valign="top" style="font-family:' . self::SANS . ';">'
			. '<p style="' . self::MSO_TEXT . 'font-size:22px;line-height:28px;font-weight:800;letter-spacing:-.01em;color:' . self::INK . ';">' . esc_html( $title ) . '</p>'
			. '<p style="' . self::MSO_TEXT . 'font-size:13px;line-height:19px;color:' . self::MUTED . ';">' . $meta_html . '</p>'
			. '</td></tr></table>';
	}

	/** The 3px accent line under the header, as a coloured cell (Word drops an empty div with a background). */
	private static function rule() {
		return self::spacer( 18 )
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
			. '<td height="3" bgcolor="' . self::ACCENT . '" style="height:3px;background:' . self::ACCENT . ';border-radius:3px;font-size:1px;line-height:3px;mso-line-height-rule:exactly;">&nbsp;</td>'
			. '</tr></table>'
			. self::spacer( 8 );
	}

	/** A paragraph with a gap before and after. $html is already safe: only call with literals or escaped text. */
	private static function note( $html, $before = 8, $after = 14 ) {
		return self::spacer( $before )
			. '<p style="' . self::MSO_TEXT . 'font-family:' . self::SANS . ';font-size:15px;line-height:24px;color:' . self::INK . ';">' . $html . '</p>'
			. self::spacer( $after );
	}

	/** Label/value rows: label left with a blue tick (border on the cell), value right in monospace. Values are safe HTML. */
	private static function ledger( array $rows ) {
		$last = count( $rows ) - 1;
		$out  = self::spacer( 6 ) . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">';
		foreach ( $rows as $i => $r ) {
			$border = $i === $last ? '' : 'border-bottom:1px solid ' . self::LINE . ';';
			$out   .= '<tr>'
				. '<td width="40%" valign="top" style="width:40%;padding:10px 10px 10px 12px;' . $border . 'border-left:3px solid ' . self::ACCENT . ';">'
				. '<p style="' . self::MSO_TEXT . 'font-family:' . self::SANS . ';line-height:19px;font-size:12.5px;font-weight:700;letter-spacing:.03em;color:' . self::MUTED . ';">' . esc_html( $r[0] ) . '</p></td>'
				. '<td width="60%" valign="top" align="right" style="width:60%;padding:10px 0;' . $border . '">'
				. '<p style="' . self::MSO_TEXT . 'line-height:19px;font-family:' . self::MONO . ';font-size:14px;color:' . self::INK . ';text-align:right;">' . $r[1] . '</p></td></tr>';
		}
		// The gap below is a row of the same table: Word merges a separate spacer table into the one above and drops its height.
		return $out . '<tr><td colspan="2" height="22" style="height:22px;font-size:1px;line-height:22px;mso-line-height-rule:exactly;">&nbsp;</td></tr></table>' . self::spacer( 4 );
	}

	/** The visitor's message: a soft box with a blue bar. Plain text in, escaped here, line breaks kept. */
	private static function quote( $text ) {
		$safe = nl2br( esc_html( $text ), false );
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;"><tr>'
			. '<td width="3" bgcolor="' . self::ACCENT . '" style="width:3px;background:' . self::ACCENT . ';font-size:1px;line-height:1px;">&nbsp;</td>'
			. '<td bgcolor="' . self::BOXBG . '" style="background:' . self::BOXBG . ';padding:14px 16px;">'
			. '<p style="' . self::MSO_TEXT . 'font-family:' . self::SANS . ';font-size:15px;line-height:24px;color:' . self::INK . ';">' . $safe . '</p></td></tr></table>'
			. self::spacer( 22 );
	}

	/**
	 * Pill button. Outlook gets a VML roundrect (the label centred in it). Others get a table with no width
	 * (it hugs its text) holding a block anchor, with mso-padding-alt on the cell and the label in its own span
	 * so Word's blue "Hyperlink" style cannot show through.
	 */
	private static function button( $url, $label ) {
		$href  = esc_url( $url, array( 'http', 'https', 'mailto' ) );
		$html  = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
			. '<td bgcolor="' . self::ACCENT . '" style="background:' . self::ACCENT . ';border-radius:999px;mso-padding-alt:13px 24px;">'
			. '<a href="' . $href . '" target="_blank" style="display:block;padding:13px 24px;font-family:' . self::SANS . ';font-size:14px;font-weight:700;text-decoration:none;mso-line-height-rule:exactly;line-height:100%;">'
			. '<span style="color:#ffffff;text-decoration:none;">' . esc_html( $label ) . ' &rarr;</span></a></td></tr></table>';
		$w     = 48 + (int) ceil( mb_strlen( $label . ' →' ) * 8.2 );
		return '<!--[if mso]>' . self::spacer( 4 )
			. '<v:roundrect ' . self::VML_NS . ' href="' . $href . '" style="height:42px;v-text-anchor:middle;width:' . $w . 'px;" arcsize="50%" stroke="f" fillcolor="' . self::ACCENT . '">'
			. '<w:anchorlock/><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">' . esc_html( $label ) . ' &rarr;</center>'
			. '</v:roundrect><![endif]-->'
			. '<!--[if !mso]><!-->' . self::spacer( 4 ) . $html . '<!--<![endif]-->'
			. self::spacer( 4 );
	}
}
