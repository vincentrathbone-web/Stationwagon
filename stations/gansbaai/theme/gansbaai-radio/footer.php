<?php
/** The bottom of every page: the footer and the player bar ("Listen live" opens the station's own player in it). */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$gbcr_contact = gbcr_page_url( 'contact' );
?>
<footer class="site-foot">
	<div class="wrap">
		<div class="cols">
			<div><img src="<?php echo esc_url( gbcr_asset( 'assets/img/gbcr-logo-horizontal.svg' ) ); ?>" alt="Gansbaai Community Radio" width="143" height="56"><p>The voice of the bay, from the harbour to the hills.</p></div>
			<div><h3>Listen</h3><ul><li><a href="#listen" data-listen>Listen live</a></li><li><a href="<?php echo esc_url( gbcr_page_url( 'line-up' ) ); ?>">Line-up</a></li></ul></div>
			<div><h3>Talk to us</h3><ul>
				<li><a href="<?php echo esc_url( $gbcr_contact ); ?>">Contact us</a></li>
				<li><a href="<?php echo esc_url( add_query_arg( 'topic', 'Shout-out', $gbcr_contact ) ); ?>">Send a shout-out</a></li>
				<li><a href="<?php echo esc_url( add_query_arg( 'topic', 'Advertise', $gbcr_contact ) ); ?>">Advertise</a></li>
				<li><a class="staff" href="<?php echo esc_url( gbcr_studio_url() ); ?>">Studio login</a></li>
			</ul></div>
		</div>
		<div class="legal"><span>&copy; <span data-year><?php echo esc_html( gmdate( 'Y' ) ); ?></span> Gansbaai Community Radio</span><a href="<?php echo esc_url( gbcr_privacy_url() ); ?>">Privacy policy</a></div>
	</div>
</footer>

<div class="player-bar" role="region" aria-label="Live radio player" inert>
	<div class="wrap">
		<img src="<?php echo esc_url( gbcr_asset( 'assets/img/gbcr-mark.svg' ) ); ?>" alt="" width="46" height="46">
		<iframe title="Gansbaai Community Radio live player" allow="autoplay"></iframe>
		<button class="close" type="button" aria-label="Close the player"><?php echo gbcr_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
	</div>
</div>
<?php wp_footer(); ?>
</body>
</html>
