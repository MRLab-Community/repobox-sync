<?php
// Exit if accessed directly
if( ! defined( 'ABSPATH' ) ) exit;

// Check if AI Features is available (connected AND active subscription) and AI Assistant is enabled
$wpf_ai_connected = isset( WPF()->ai_client ) && WPF()->ai_client->is_service_available();
$wpf_ai_assistant_enabled = $wpf_ai_connected && wpforo_setting( 'ai', 'assistant' );
$wpf_ai_search_enabled = $wpf_ai_assistant_enabled && wpforo_setting( 'ai', 'search' );
// AI Chat requires Professional plan or higher
$wpf_ai_chatbot_enabled = $wpf_ai_assistant_enabled
	&& isset( WPF()->ai_client ) && WPF()->ai_client->is_feature_available( 'ai_assistant_chatbot' )
	&& isset( WPF()->ai_chatbot ) && WPF()->ai_chatbot->is_enabled() && WPF()->ai_chatbot->user_can_chat();

// AI Search Preferences - only when AI is connected and for logged-in users
$wpf_ai_user_id = 0;
$wpf_ai_is_logged_in = false;
$wpf_ai_preferences = array();
$wpf_ai_languages = array();

if ( $wpf_ai_assistant_enabled ) {
	$wpf_ai_user_id = WPF()->current_userid;
	$wpf_ai_is_logged_in = $wpf_ai_user_id > 0;

	// Get default language: AI settings > Board locale > WordPress locale
	$wpf_ai_default_language = wpforo_setting( 'ai', 'search_language' );
	if ( empty( $wpf_ai_default_language ) ) {
		// Use board locale if available, otherwise WordPress locale
		$wpf_ai_default_language = wpfval( WPF()->board, 'locale' ) ?: get_locale();
	}

	// Get default max results from AI settings
	$wpf_ai_default_max_results = wpforo_setting( 'ai', 'search_max_results' );
	if ( empty( $wpf_ai_default_max_results ) || $wpf_ai_default_max_results < 1 ) {
		$wpf_ai_default_max_results = 5;
	}

	// Set defaults from AI Features settings
	$wpf_ai_preferences = array(
		'language'    => $wpf_ai_default_language,
		'max_results' => (int) $wpf_ai_default_max_results,
	);

	// Override with user preferences if logged in and user has saved preferences
	if ( $wpf_ai_is_logged_in ) {
		$saved_prefs = get_user_meta( $wpf_ai_user_id, 'wpforo_ai_search', true );
		if ( is_array( $saved_prefs ) && ! empty( $saved_prefs ) ) {
			// Only override if user has explicitly saved preferences
			if ( ! empty( $saved_prefs['language'] ) ) {
				$wpf_ai_preferences['language'] = $saved_prefs['language'];
			}
			if ( ! empty( $saved_prefs['max_results'] ) ) {
				$wpf_ai_preferences['max_results'] = (int) $saved_prefs['max_results'];
			}
		}
	}

	// Supported languages for AI responses (must match Settings.php + regional variants)
	$wpf_ai_languages = array(
		'en_US' => 'English',
		'en_GB' => 'English (UK)',
		'de_DE' => 'Deutsch',
		'es_ES' => 'Español',
		'fr_FR' => 'Français',
		'it_IT' => 'Italiano',
		'pt_BR' => 'Português (Brasil)',
		'pt_PT' => 'Português',
		'nl_NL' => 'Nederlands',
		'pl_PL' => 'Polski',
		'ru_RU' => 'Русский',
		'sv_SE' => 'Svenska',
		'ro_RO' => 'Română',
		'cs_CZ' => 'Čeština',
		'el_GR' => 'Ελληνικά',
		'he_IL' => 'עברית',
		'tr_TR' => 'Türkçe',
		'vi_VN' => 'Tiếng Việt',
		'id_ID' => 'Bahasa Indonesia',
		'ja'    => '日本語',
		'ko_KR' => '한국어',
		'zh_CN' => '简体中文',
		'zh_TW' => '繁體中文',
		'ar'    => 'العربية',
		'hi_IN' => 'हिन्दी',
	);
}
?>

	<?php do_action( 'wpforo_top_hook' ); ?>

	<?php if( wpforo_setting( 'components', 'top_bar' ) ): ?>
        <div id="wpforo-menu">
			<?php do_action( 'wpforo_menu_bar_start' ); ?>
            <div class="wpf-left" style="display:table-cell">
				<?php if( WPF()->tpl->has_menu() ): ?>
                    <span class="wpf-res-menu"><i class="fas fa-bars"></i></span>
					<?php WPF()->tpl->nav_menu() ?>
				<?php endif; ?>
				<?php do_action( 'wpforo_after_menu_items' ); ?>
            </div>
            <div class="wpf-bar-right wpf-search">
				<?php do_action( 'wpforo_before_search_toggle' ); ?>
				<?php if( wpforo_setting( 'components', 'top_bar_search' ) ): ?>
                    <div class="wpf-search-form">
                        <form action="<?php echo wpforo_home_url() ?>" method="get">
							<?php wpforo_make_hidden_fields_from_url( wpforo_home_url() ) ?>
                            <i class="fas fa-search"></i><input class="wpf-search-field" name="wpfs" type="text" value="" style="margin-right:10px;"/>
                        </form>
                    </div>
				<?php endif; ?>
            </div>
			<?php do_action( 'wpforo_menu_bar_end' ); ?>
        </div>
	<?php endif; ?>
	<?php if ( $wpf_ai_assistant_enabled ) : ?>
        <?php include( wpftpl( 'widgets-ai.php' ) ) ?>
    <?php endif; ?>
    <div class="wpforo-subtop">
		<?php if( wpforo_setting( 'components', 'breadcrumb' ) ): ?>
			<?php WPF()->tpl->breadcrumb( WPF()->current_object ) ?>
		<?php endif; ?>
		<?php wpforo_share_buttons( 'top' ); ?>
        <div class="wpf-clear"></div>
		<?php if( wpforo_setting( 'notifications', 'notifications' ) ): ?>
			<?php wpforo_notifications() ?>
		<?php endif; ?>
    </div>
	<?php do_action( 'wpforo_header_hook' ); ?>
