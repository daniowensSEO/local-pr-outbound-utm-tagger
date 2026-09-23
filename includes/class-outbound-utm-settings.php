<?php
/**
 * Stores the sitewide settings and draws the settings screen.
 */

defined( 'ABSPATH' ) || exit;

final class Outbound_UTM_Settings {

	const VERSION = '0.2.1';
	const OPTION  = 'outbound_utm_settings';
	const GROUP  = 'outbound_utm_tagger';
	const PAGE   = 'outbound-utm-tagger';

	/**
	 * Settings for this request, read once.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	public static function init() {
		// update_option fires on later saves, add_option on the very first one.
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'after_save' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'after_save' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'register' ) );
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_notices', array( __CLASS__, 'cache_notice' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( OUTBOUND_UTM_FILE ), array( __CLASS__, 'action_links' ) );
		}
	}

	/**
	 * After a save with no Speed Optimizer to call, says the cache still needs clearing.
	 */
	public static function cache_notice() {
		if ( ! current_user_can( 'manage_options' ) || function_exists( 'sg_cachepress_purge_cache' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the screen, changing nothing.
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$saved = isset( $_GET['settings-updated'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( self::PAGE !== $page || ! $saved ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html( 'Your page cache was not cleared, because SiteGround Speed Optimizer is not active. Clear it yourself, or visitors keep seeing links with the old UTMs.' );
		echo '</p></div>';
	}

	/** The five UTM fields, in the order they are added to a link. */
	public static function fields() {
		return array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );
	}

	public static function defaults() {
		return array(
			'enabled'      => 0,
			'mode'         => 'page',
			'tidy'         => 1,
			'utm_source'   => Outbound_UTM_Context::domain(),
			'utm_medium'   => 'referral',
			'utm_campaign' => '',
			'utm_term'     => '',
			'utm_content'  => '{slug}',
			'skip'         => array(),
		);
	}

	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$saved       = get_option( self::OPTION );
		self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		return self::$cache;
	}

	/** Version used to bust the browser cache on the click mode script. */
	public static function asset_version() {
		return self::VERSION;
	}

	public static function tidy_enabled() {
		$settings = self::get();
		return ! empty( $settings['tidy'] );
	}

	/**
	 * Clears SiteGround's cache after a save, since settings change every page at once.
	 */
	public static function after_save() {
		self::$cache = null;
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
	}

	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/**
	 * Cleans the settings form. WordPress can run this twice on a first save,
	 * so it accepts both raw form input and values it has already cleaned.
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$mode  = isset( $input['mode'] ) ? (string) $input['mode'] : 'page';
		$clean = array(
			'enabled' => empty( $input['enabled'] ) ? 0 : 1,
			'mode'    => 'click' === $mode ? 'click' : 'page',
			'tidy'    => empty( $input['tidy'] ) ? 0 : 1,
		);

		foreach ( self::fields() as $field ) {
			$clean[ $field ] = isset( $input[ $field ] ) ? self::clean_value( $input[ $field ] ) : '';
		}

		$skip = isset( $input['skip'] ) ? $input['skip'] : array();
		if ( ! is_array( $skip ) ) {
			$skip = preg_split( '/[\s,]+/', (string) $skip );
		}
		$skip          = is_array( $skip ) ? $skip : array();
		$clean['skip'] = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'clean_domain' ), $skip ) ) ) );

		return $clean;
	}

	/**
	 * Cleans one field. sanitize_text_field() is deliberately not used here because it
	 * strips percent encoded characters, which are legal in a UTM value.
	 */
	private static function clean_value( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( (string) $value, true );
		$value = wp_strip_all_tags( $value );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		$value = (string) preg_replace( '/\s{2,}/u', ' ', $value );
		return mb_substr( trim( $value ), 0, 200 );
	}

	/** Turns "https://www.Partner.com/page" into "partner.com". Returns '' when it is not a domain. */
	public static function clean_domain( $raw ) {
		$raw = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
		if ( '' === $raw ) {
			return '';
		}
		if ( false === strpos( $raw, '//' ) ) {
			$raw = 'http://' . $raw;
		}
		$host = wp_parse_url( $raw, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}
		$host = (string) preg_replace( '/^(\*\.|www\.)/', '', rtrim( $host, '.' ) );

		// Letters, digits, dots and hyphens, plus non ASCII for international domains.
		return preg_match( '/^[a-z0-9.\-\x80-\xff]+$/', $host ) ? $host : '';
	}

	public static function menu() {
		add_options_page( 'Outbound UTM Tagger', 'Outbound UTMs', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Settings</a>' );
		return $links;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		$name     = self::OPTION;
		$help     = array(
			'utm_source'   => 'Your domain, so the other site can tell who sent the visit. Analytics tools expect this one to be filled in.',
			'utm_medium'   => 'referral files the visit under the Referral channel in the other site\'s Google Analytics.',
			'utm_campaign' => 'Left blank here for now. Rules will set this per part of your site in stage 2.',
			'utm_term'     => 'Rarely used. It was built for paid search keywords.',
			'utm_content'  => 'Tells the other site which of your pages sent the click. {slug} fills in the page slug for you.',
		);
		$placeholders = array(
			'{slug}'      => 'the page\'s slug; on a category or tag page, that term\'s slug. Empty on search results, 404s, and date archives',
			'{path}'      => 'everything after your domain, such as /reviews/laptops',
			'{post_id}'   => 'the page\'s ID',
			'{post_type}' => 'its type, such as post or page',
			'{category}'  => 'the post\'s primary category, or its first one',
			'{term:name}' => 'the primary term from any taxonomy you name, such as {term:product_cat}',
			'{author}'    => 'the author\'s name as it appears in URLs',
			'{parent}'    => 'the parent page\'s slug',
			'{year}'      => 'the year it was published',
			'{month}'     => 'the month it was published',
			'{link_text}' => 'the words in the link itself, tidied and shortened',
			'{domain}'    => 'this site\'s domain',
		);
		?>
		<div class="wrap">
			<style>
				.outbound-utm-toggle { position: relative; display: inline-flex; align-items: center; gap: 10px; cursor: pointer; }
				.outbound-utm-toggle input { position: absolute; top: 0; left: 0; width: 44px; height: 24px; margin: 0; opacity: 0; cursor: pointer; }
				.outbound-utm-track { display: inline-block; position: relative; width: 44px; height: 24px; border-radius: 12px; background: #8c8f94; }
				.outbound-utm-knob { position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; }
				.outbound-utm-toggle input:checked + .outbound-utm-track { background: #00a32a; }
				.outbound-utm-toggle input:checked + .outbound-utm-track .outbound-utm-knob { left: 23px; }
				.outbound-utm-toggle input:focus + .outbound-utm-track { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2271b1; }
				.outbound-utm-toggle-text { font-size: 14px; font-weight: 600; }
			</style>
			<h1>Outbound UTM Tagger</h1>
			<p>Adds these UTM parameters to links that point to other websites. Links that already have UTM parameters are never changed.</p>
			<div class="notice notice-info inline" style="margin: 12px 0;">
				<p>Stage 1 of the build: sitewide defaults only. Rules, per page settings, and the admin bar readout come later. Tagging starts switched off, so activating the plugin changes nothing until you turn it on below and save.</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Status</th>
						<td>
							<label class="outbound-utm-toggle">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
								<span class="outbound-utm-track" aria-hidden="true"><span class="outbound-utm-knob"></span></span>
								<span class="outbound-utm-toggle-text">Turn Plugin On</span>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">How UTM Codes Will Be Added:</th>
						<td>
							<fieldset>
								<label style="display: block; margin-bottom: 8px;">
									<input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="page" <?php checked( 'click' !== $settings['mode'] ); ?>>
									HTML (Visible on the Page)
								</label>
								<p class="description" style="margin: 0 0 12px 24px;">Links carry the UTMs in the HTML. Works for every visitor, including anyone with JavaScript off, and you can check it with View Source. Search engines see the tagged links.</p>
								<label style="display: block; margin-bottom: 8px;">
									<input type="radio" name="<?php echo esc_attr( $name ); ?>[mode]" value="click" <?php checked( 'click' === $settings['mode'] ); ?>>
									JavaScript (Not Visible on the Page)
								</label>
								<p class="description" style="margin: 0 0 0 24px;">The page keeps clean links and a small script adds the UTMs as someone clicks. Crawlers and anyone copying a link get the clean version. Visitors with JavaScript off send nothing, and a plugin that delays JavaScript needs this script excluded.</p>
							</fieldset>
						</td>
					</tr>
					<?php foreach ( self::fields() as $field ) : ?>
						<tr>
							<th scope="row"><label for="outbound-utm-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $field ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="outbound-utm-<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $name . '[' . $field . ']' ); ?>" value="<?php echo esc_attr( $settings[ $field ] ); ?>">
								<p class="description"><?php echo esc_html( $help[ $field ] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row">Placeholders</th>
						<td>
							<p class="description">These work in the fields above. Each one describes the page, never the visitor, because a tagged page is cached and served to everyone.</p>
							<ul class="ul-disc">
								<?php foreach ( $placeholders as $token => $meaning ) : ?>
									<li><code><?php echo esc_html( $token ); ?></code> <?php echo esc_html( $meaning ); ?></li>
								<?php endforeach; ?>
							</ul>
							<p class="description">A placeholder that comes back empty leaves its field off the link.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Tidy values</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[tidy]" value="1" <?php checked( ! empty( $settings['tidy'] ) ); ?>>
								Lower case values and turn spaces into hyphens
							</label>
							<p class="description">Google Analytics counts Reviews and reviews as two campaigns, so this keeps them as one.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="outbound-utm-skip">Never tag links to</label></th>
						<td>
							<textarea class="large-text code" rows="4" id="outbound-utm-skip" name="<?php echo esc_attr( $name ); ?>[skip]"><?php echo esc_textarea( implode( "\n", (array) $settings['skip'] ) ); ?></textarea>
							<p class="description">
								One per line, subdomains included. <?php echo esc_html( Outbound_UTM_Context::domain() ); ?> is always skipped.
								Add any other site of yours that shares your analytics, and any site whose links break when parameters are added to them.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Good to know</h2>
			<ul class="ul-disc">
				<li>Links that already carry any utm_ parameter are left exactly as they are.</li>
				<li>To skip one link, add the CSS class <code>noutm</code> to it, or to the block that contains it.</li>
				<?php if ( 'click' === $settings['mode'] ) : ?>
					<li>UTMs are added as a link is clicked, so View Source shows clean links. Check it by clicking an external link and reading the address bar on the other side.</li>
				<?php else : ?>
					<li>UTMs are written into the page on the server, so View Source shows them.</li>
				<?php endif; ?>
				<li>
					<?php if ( function_exists( 'sg_cachepress_purge_cache' ) ) : ?>
						Saving clears your SiteGround cache through Speed Optimizer.
					<?php else : ?>
						Speed Optimizer is not active, so clear your page cache yourself after saving.
					<?php endif; ?>
				</li>
			</ul>
		</div>
		<?php
	}
}
