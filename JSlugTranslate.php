<?php
/**
 * Plugin Name: J Slug Translate
 * Plugin URI:  https://github.com/lacostechiu/j-slug-translate
 * Description: 發佈時用 Google Gemini 把中文標題自動翻成英文網址（slug）。取代已停止維護、IBM Watson 已下架的 Simple Slug Translate。
 * Version: 1.0.3
 * Author: Jay Chiu
 * Author URI: https://jclassroom.net
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 * Text Domain: jslugtranslate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JST_OPTION', 'jst_settings' );
define( 'JST_DEFAULT_MODEL', 'gemini-2.5-flash' );

/* ------------------------------------------------------------------
 * 1. 設定值
 * ------------------------------------------------------------------ */

function jst_settings() {
	$defaults = array(
		'api_key'    => '',
		'model'      => JST_DEFAULT_MODEL,
		'post_types' => array( 'post' ),
		'taxonomies' => array(),
	);
	$saved = get_option( JST_OPTION, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

// wp-config.php 定義 JST_GEMINI_API_KEY 時優先使用，金鑰就不會存在資料庫裡
function jst_api_key() {
	if ( defined( 'JST_GEMINI_API_KEY' ) && JST_GEMINI_API_KEY ) {
		return JST_GEMINI_API_KEY;
	}
	return trim( jst_settings()['api_key'] );
}

/* ------------------------------------------------------------------
 * 2. 翻譯
 * ------------------------------------------------------------------ */

function jst_prompt( $title ) {
	$prompt = <<<TXT
You create English URL slugs for a company website in Taiwan.
Translate the Traditional Chinese title below into a short, natural English slug.
Rules:
- 2-6 words, lowercase, words joined by hyphens, ASCII letters and digits only.
- Translate the meaning. Never use pinyin, even for short poetic names.
- If the title already contains English, prefer that English.
- Text after "▎" or "|" is a content label; keep it as a short suffix
  (設計師開箱 = designer-tour, 總監開箱 = director-tour, 客戶回饋 = client-review, MV = mv).
Examples:
雲棲 => cloud-dwelling
晨曦微光 => morning-glow
木與光的對話 => dialogue-of-wood-and-light
靜境 Quiet Room ▎設計師開箱 => quiet-room-designer-tour
Output only the slug, nothing else.

Title: {$title}
TXT;
	return apply_filters( 'jst_prompt', $prompt, $title );
}

/**
 * 把標題翻成英文 slug。失敗時回傳空字串，呼叫端就保留原本的中文 slug。
 */
function jst_translate( $title ) {
	$title = trim( wp_strip_all_tags( $title ) );

	// 回傳非 null 即可改用其他翻譯服務（或測試時替換）
	$pre = apply_filters( 'jst_pre_translate', null, $title );
	if ( null !== $pre ) {
		return jst_clean_slug( (string) $pre );
	}

	$key   = jst_api_key();
	if ( '' === $title || '' === $key ) {
		return '';
	}

	// 區塊編輯器一次儲存會觸發兩次，同一標題短時間內不重複呼叫 API
	$cache_key = 'jst_' . md5( $title );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$model    = jst_settings()['model'] ? jst_settings()['model'] : JST_DEFAULT_MODEL;
	$response = wp_remote_post(
		'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
		array(
			'timeout' => 10,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $key,
			),
			'body'    => wp_json_encode(
				array(
					'contents'         => array( array( 'parts' => array( array( 'text' => jst_prompt( $title ) ) ) ) ),
					'generationConfig' => array(
						'temperature'     => 0.2,
						'maxOutputTokens' => 60,
						'thinkingConfig'  => array( 'thinkingBudget' => 0 ),
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		jst_log_error( $response->get_error_message() );
		return '';
	}
	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $code ) {
		jst_log_error( 'HTTP ' . $code . ' ' . ( $body['error']['message'] ?? '' ) );
		return '';
	}

	$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
	$slug = jst_clean_slug( $text );
	if ( '' === $slug ) {
		jst_log_error( 'Empty or non-ASCII result for "' . $title . '": ' . $text );
		return '';
	}

	set_transient( $cache_key, $slug, HOUR_IN_SECONDS );
	return apply_filters( 'jst_translated_slug', $slug, $title );
}

// 只接受純英數加連字號，最長 80 字元（從連字號處截斷）
function jst_clean_slug( $text ) {
	$text = strtok( trim( $text ), "\n" );
	$slug = sanitize_title( remove_accents( (string) $text ) );
	if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
		return '';
	}
	if ( strlen( $slug ) > 80 ) {
		$slug = substr( $slug, 0, 80 );
		$slug = substr( $slug, 0, strrpos( $slug, '-' ) ?: 80 );
	}
	return $slug;
}

function jst_log_error( $message ) {
	update_option( 'jst_last_error', current_time( 'mysql' ) . ' — ' . $message, false );
}

// slug 含 % 代表 WordPress 從中文（或其他非英數）標題自動編碼出來的
function jst_is_encoded( $slug ) {
	return '' !== $slug && false !== strpos( $slug, '%' );
}

/* ------------------------------------------------------------------
 * 3. 文章：第一次發佈時翻譯
 * ------------------------------------------------------------------ */

add_filter( 'wp_insert_post_data', 'jst_filter_post_data', 99, 2 );

function jst_filter_post_data( $data, $postarr ) {
	if ( ! in_array( $data['post_type'], (array) jst_settings()['post_types'], true ) ) {
		return $data;
	}
	if ( ! in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
		return $data;
	}
	if ( ! jst_is_encoded( $data['post_name'] ) ) {
		return $data; // 已經是英文，或業主自己改過 slug
	}

	// 已經有過 slug 的文章不動（已上線、預約中，或曾上線後改回草稿），避免既有網址失效。
	// 從沒發佈過的草稿 post_name 是空的。
	$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	if ( $post_id && ( in_array( get_post_status( $post_id ), array( 'publish', 'future', 'private' ), true ) || '' !== get_post_field( 'post_name', $post_id, 'raw' ) ) ) {
		return $data;
	}

	$slug = jst_translate( $data['post_title'] );
	if ( '' === $slug ) {
		return $data;
	}

	$data['post_name'] = wp_unique_post_slug( $slug, $post_id, $data['post_status'], $data['post_type'], $data['post_parent'] );
	return $data;
}

/* ------------------------------------------------------------------
 * 4. 分類／標籤：新增時翻譯
 * ------------------------------------------------------------------ */

add_filter( 'wp_insert_term_data', 'jst_filter_term_data', 99, 3 );

function jst_filter_term_data( $data, $taxonomy, $args ) {
	if ( ! in_array( $taxonomy, (array) jst_settings()['taxonomies'], true ) || ! jst_is_encoded( $data['slug'] ) ) {
		return $data;
	}

	$slug = jst_translate( $data['name'] );
	if ( '' === $slug ) {
		return $data;
	}

	$term         = (object) array(
		'taxonomy' => $taxonomy,
		'parent'   => isset( $args['parent'] ) ? (int) $args['parent'] : 0,
	);
	$data['slug'] = wp_unique_term_slug( $slug, $term );
	return $data;
}

/* ------------------------------------------------------------------
 * 5. 設定頁（設定 → Slug 翻譯）
 * ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_options_page( 'J Slug Translate', 'Slug 翻譯', 'manage_options', 'jslugtranslate', 'jst_render_settings' );
} );

add_action( 'admin_init', function () {
	register_setting(
		'jst',
		JST_OPTION,
		array(
			'sanitize_callback' => function ( $input ) {
				$old = jst_settings();
				return array(
					// 欄位留空代表沿用原本的金鑰（畫面上不回顯金鑰）
					'api_key'    => '' !== trim( $input['api_key'] ?? '' ) ? sanitize_text_field( $input['api_key'] ) : $old['api_key'],
					'model'      => sanitize_text_field( $input['model'] ?? JST_DEFAULT_MODEL ),
					'post_types' => array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) ),
					'taxonomies' => array_map( 'sanitize_key', (array) ( $input['taxonomies'] ?? array() ) ),
				);
			},
		)
	);
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=jslugtranslate' ) ) . '">設定</a>' );
	return $links;
} );

function jst_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s          = jst_settings();
	$key_source = defined( 'JST_GEMINI_API_KEY' ) && JST_GEMINI_API_KEY ? 'wp-config.php（JST_GEMINI_API_KEY）' : ( $s['api_key'] ? '已儲存在設定' : '' );

	// 測試翻譯
	$test_title  = '';
	$test_result = null;
	if ( isset( $_POST['jst_test'] ) && check_admin_referer( 'jst_test' ) ) {
		$test_title = sanitize_text_field( wp_unslash( $_POST['jst_test_title'] ?? '' ) );
		delete_transient( 'jst_' . md5( $test_title ) );
		$test_result = jst_translate( $test_title );
	}

	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $post_types['attachment'] );
	$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
	unset( $taxonomies['post_format'] );
	$last_error = get_option( 'jst_last_error' );
	?>
	<div class="wrap">
		<h1>J Slug Translate</h1>
		<p>中文標題的文章<strong>第一次發佈</strong>時，用 Gemini 自動產生英文網址。已發佈的文章不會被改動；業主自己填了英文 slug 的也不會。翻譯失敗時保留原本的中文網址，不影響發佈。</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'jst' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="jst_api_key">Gemini API 金鑰</label></th>
					<td>
						<input type="password" id="jst_api_key" name="<?php echo esc_attr( JST_OPTION ); ?>[api_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $key_source ? '（' . esc_attr( $key_source ) . '，留空不變）' : ''; ?>">
						<p class="description">到 <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">Google AI Studio</a> 申請。也可以在 wp-config.php 加 <code>define( 'JST_GEMINI_API_KEY', '…' );</code>，會優先使用。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jst_model">模型</label></th>
					<td>
						<input type="text" id="jst_model" name="<?php echo esc_attr( JST_OPTION ); ?>[model]" value="<?php echo esc_attr( $s['model'] ); ?>" class="regular-text">
						<p class="description">預設 <code><?php echo esc_html( JST_DEFAULT_MODEL ); ?></code>。Google 下架舊模型時改這裡即可。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">文章類型</th>
					<td>
						<?php foreach ( $post_types as $pt ) : ?>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( JST_OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $s['post_types'], true ) ); ?>> <?php echo esc_html( $pt->label ); ?> <code><?php echo esc_html( $pt->name ); ?></code></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">分類法</th>
					<td>
						<?php foreach ( $taxonomies as $tax ) : ?>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( JST_OPTION ); ?>[taxonomies][]" value="<?php echo esc_attr( $tax->name ); ?>" <?php checked( in_array( $tax->name, $s['taxonomies'], true ) ); ?>> <?php echo esc_html( $tax->label ); ?> <code><?php echo esc_html( $tax->name ); ?></code></label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2>測試翻譯</h2>
		<form method="post">
			<?php wp_nonce_field( 'jst_test' ); ?>
			<input type="text" name="jst_test_title" value="<?php echo esc_attr( $test_title ); ?>" class="regular-text" placeholder="輸入一個中文標題">
			<button type="submit" name="jst_test" value="1" class="button">翻譯</button>
			<?php if ( null !== $test_result ) : ?>
				<p><?php echo '' !== $test_result ? '結果：<code>' . esc_html( $test_result ) . '</code>' : '翻譯失敗，請看下方錯誤訊息。'; ?></p>
			<?php endif; ?>
		</form>

		<?php if ( $last_error ) : ?>
			<h2>最近一次錯誤</h2>
			<p><code><?php echo esc_html( $last_error ); ?></code></p>
		<?php endif; ?>
	</div>
	<?php
}
