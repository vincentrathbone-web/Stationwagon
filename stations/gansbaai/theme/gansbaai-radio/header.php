<?php
/** The top of every page: the menu bar. The home page's bar sits over the video until you scroll. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$gbcr_nav = array(
	array( home_url( '/' ), 'Home', is_front_page() ),
	array( gbcr_page_url( 'line-up' ), 'Line-up', is_page( 'line-up' ) ),
	array( gbcr_page_url( 'contact' ), 'Contact', is_page( 'contact' ) ),
);
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( is_front_page() ? 'home' : '' ); ?>>
<?php wp_body_open(); ?>
<a class="skip" href="#main">Skip to content</a>
<header class="site-head">
	<div class="wrap">
		<a class="logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo esc_url( gbcr_asset( 'assets/img/gbcr-logo-horizontal.svg' ) ); ?>" alt="Gansbaai Community Radio" width="133" height="52"></a>
		<button class="menu-btn" type="button" aria-expanded="false" aria-controls="nav" aria-label="Menu"><?php echo gbcr_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
		<nav class="site-nav" id="nav" aria-label="Main">
			<?php foreach ( $gbcr_nav as $gbcr_item ) : ?>
				<a href="<?php echo esc_url( $gbcr_item[0] ); ?>"<?php echo $gbcr_item[2] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $gbcr_item[1] ); ?></a>
			<?php endforeach; ?>
			<a class="staff" href="<?php echo esc_url( gbcr_studio_url() ); ?>" title="For the station's presenters and staff">Studio login</a>
			<a class="btn small" href="#listen" data-listen><span class="live-dot"></span>Listen live</a>
		</nav>
	</div>
</header>
