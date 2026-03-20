<?php
/**
 * Plugin Name: Afro Love Life - Profile Builder + [afro_member_grid]
 * Description: Front-end profile creation, member browsing, server-side match filtering, and profile viewing for Afro Love Life dating site.
 * Version: 1.3.1
 * Author: Felix Frederick G. Cordero Jr.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
 * GLOBAL FRONT-END BEHAVIOR
 * ============================================================ */

/**
 * Hide WP admin bar on the front-end for all users.
 *
 * Admin bar remains visible inside /wp-admin.
 *
 * @param bool $show Whether to show the admin bar.
 * @return bool
 */
function afl_hide_admin_bar_on_front( $show ) {
	if ( is_admin() ) {
		return $show;
	}
	return false;
}
add_filter( 'show_admin_bar', 'afl_hide_admin_bar_on_front' );

/**
 * Register profile builder stylesheet.
 *
 * @return void
 */
function all_profile_builder_assets() {
	wp_register_style(
		'all-profile-builder',
		plugins_url( 'profile-builder.css', __FILE__ ),
		[],
		'1.0'
	);
}
add_action( 'init', 'all_profile_builder_assets' );

/* ============================================================
 * PRESENCE TRACKING
 * ============================================================ */

/**
 * Front-end presence ping.
 *
 * Updates afl_last_active every 30 seconds while a logged-in user
 * is browsing front-end pages.
 *
 * @return void
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_user_logged_in() || is_admin() ) {
			return;
		}

		wp_register_script( 'afl-presence', false, [ 'jquery' ], '1.0', true );
		wp_localize_script(
			'afl-presence',
			'aflPresence',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'afl_presence_nonce' ),
			]
		);

		$js = <<<JS
(function($){
  function ping(){
    $.post(aflPresence.ajaxurl, {
      action: 'afl_presence_ping',
      nonce: aflPresence.nonce
    });
  }
  ping();
  setInterval(ping, 30000);
})(jQuery);
JS;

		wp_add_inline_script( 'afl-presence', $js );
		wp_enqueue_script( 'afl-presence' );
	},
	20
);

/**
 * AJAX callback for presence updates.
 *
 * @return void
 */
add_action(
	'wp_ajax_afl_presence_ping',
	function () {
		if ( ! is_user_logged_in() ) {
			wp_die();
		}

		check_ajax_referer( 'afl_presence_nonce', 'nonce' );
		update_user_meta( get_current_user_id(), 'afl_last_active', time() );
		wp_die( 'ok' );
	}
);

/**
 * Backup presence update on init for logged-in users.
 *
 * @return void
 */
function afl_update_last_active() {
	if ( is_user_logged_in() ) {
		update_user_meta( get_current_user_id(), 'afl_last_active', time() );
	}
}
add_action( 'init', 'afl_update_last_active' );

/* ============================================================
 * LIGHTBOX + ACTION SCRIPTS
 * ============================================================ */

/**
 * Enqueue Dashicons, like/favorite actions, and lightbox assets.
 *
 * @return void
 */
add_action(
	'wp_enqueue_scripts',
	function () {

		if ( is_user_logged_in() ) {
			wp_enqueue_style( 'dashicons' );
		}

		wp_register_script(
			'afl-actions',
			plugins_url( 'afl-actions.js', __FILE__ ),
			[ 'jquery' ],
			'1.0',
			true
		);

		wp_localize_script(
			'afl-actions',
			'aflAjax',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'afl_actions_nonce' ),
			]
		);

		wp_register_style( 'afl-lightbox', false, [], '1.0' );
		wp_register_script( 'afl-lightbox', false, [], '1.0', true );

		$css = '
/* AFL Lightbox */
.afl-lightbox-overlay{
  position:fixed; inset:0; z-index:999999;
  background:rgba(0,0,0,.82);
  display:none; align-items:center; justify-content:center;
  padding:24px;
}
.afl-lightbox-overlay.is-open{display:flex;}
.afl-lightbox-panel{
  position:relative;
  max-width:min(1100px, 96vw);
  max-height:86vh;
  width:auto;
  display:flex;
  align-items:center;
  justify-content:center;
}
.afl-lightbox-img{
  max-width:100%;
  max-height:86vh;
  border-radius:14px;
  box-shadow:0 20px 60px rgba(0,0,0,.35);
  background:#111;
  display:block;
}
.afl-lightbox-panel.is-loading::after{
  content:"";
  position:absolute;
  width:44px; height:44px;
  border-radius:999px;
  border:3px solid rgba(255,255,255,.25);
  border-top-color:#fff;
  animation:aflspin .9s linear infinite;
}
@keyframes aflspin{to{transform:rotate(360deg)}}
.afl-lightbox-close{
  position:absolute;
  top:-14px; right:-14px;
  width:38px; height:38px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.25);
  background:rgba(17,17,17,.8);
  color:#fff;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:18px;
  line-height:1;
}
.afl-lightbox-close:hover{opacity:.9;}
.afl-lightbox-nav{
  position:absolute;
  top:50%;
  transform:translateY(-50%);
  width:44px; height:44px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.25);
  background:rgba(17,17,17,.65);
  color:#fff;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:20px;
  line-height:1;
  user-select:none;
}
.afl-lightbox-prev{left:-54px;}
.afl-lightbox-next{right:-54px;}
@media(max-width:640px){
  .afl-lightbox-prev{left:8px;}
  .afl-lightbox-next{right:8px;}
  .afl-lightbox-close{top:8px; right:8px;}
}
.afl-lb-open{cursor:zoom-in;}
';

		$js = '
(function(){
  function q(sel, root){return (root||document).querySelector(sel);}
  function qa(sel, root){return Array.prototype.slice.call((root||document).querySelectorAll(sel));}

  var currentGroup = [];
  var currentIndex = 0;

  function ensureLightbox(){
    if (q("#aflLightbox")) return;

    var overlay = document.createElement("div");
    overlay.id = "aflLightbox";
    overlay.className = "afl-lightbox-overlay";
    overlay.setAttribute("role","dialog");
    overlay.setAttribute("aria-modal","true");

    overlay.innerHTML = `
      <div class="afl-lightbox-panel">
        <button type="button" class="afl-lightbox-close" aria-label="Close">✕</button>
        <button type="button" class="afl-lightbox-nav afl-lightbox-prev" aria-label="Previous">‹</button>
        <img class="afl-lightbox-img" alt="">
        <button type="button" class="afl-lightbox-nav afl-lightbox-next" aria-label="Next">›</button>
      </div>
    `;
    document.body.appendChild(overlay);

    overlay.addEventListener("click", function(e){
      if (e.target === overlay) closeLightbox();
    });

    q(".afl-lightbox-close", overlay).addEventListener("click", closeLightbox);
    q(".afl-lightbox-prev", overlay).addEventListener("click", function(){ step(-1); });
    q(".afl-lightbox-next", overlay).addEventListener("click", function(){ step(1); });

    document.addEventListener("keydown", function(e){
      if (!overlay.classList.contains("is-open")) return;
      if (e.key === "Escape") closeLightbox();
      if (e.key === "ArrowLeft") step(-1);
      if (e.key === "ArrowRight") step(1);
    });
  }

  function setImg(src){
    var overlay = q("#aflLightbox");
    if (!overlay) return;
    var img = q(".afl-lightbox-img", overlay);
    var panel = q(".afl-lightbox-panel", overlay);
    if (!img || !panel || !src) return;

    panel.classList.add("is-loading");

    var pre = new Image();
    pre.onload = function(){
      img.src = src;
      panel.classList.remove("is-loading");
      preloadNeighbors();
    };
    pre.onerror = function(){
      img.src = src;
      panel.classList.remove("is-loading");
    };
    pre.src = src;
  }

  function preloadNeighbors(){
    if (!currentGroup.length) return;
    var next = currentGroup[(currentIndex + 1) % currentGroup.length];
    var prev = currentGroup[(currentIndex - 1 + currentGroup.length) % currentGroup.length];
    [next, prev].forEach(function(s){
      if (!s) return;
      var i = new Image();
      i.src = s;
    });
  }

  function openLightbox(group, index){
    ensureLightbox();

    currentGroup = (group || []).filter(Boolean);
    currentIndex = (typeof index === "number" ? index : 0);

    if (!currentGroup.length || !currentGroup[currentIndex]) return;

    var overlay = q("#aflLightbox");
    overlay.classList.add("is-open");
    document.documentElement.style.overflow = "hidden";

    var hasMany = currentGroup.length > 1;
    q(".afl-lightbox-prev", overlay).style.display = hasMany ? "flex" : "none";
    q(".afl-lightbox-next", overlay).style.display = hasMany ? "flex" : "none";

    setImg(currentGroup[currentIndex]);
  }

  function closeLightbox(){
    var overlay = q("#aflLightbox");
    if (!overlay) return;
    overlay.classList.remove("is-open");
    document.documentElement.style.overflow = "";
    var img = q(".afl-lightbox-img", overlay);
    if (img) img.src = "";
  }

  function step(dir){
    if (!currentGroup.length) return;
    currentIndex = (currentIndex + dir + currentGroup.length) % currentGroup.length;
    setImg(currentGroup[currentIndex]);
  }

  document.addEventListener("click", function(e){
    var el = e.target.closest("[data-afl-lightbox]");
    if (!el) return;

    e.preventDefault();

    var groupKey = el.getAttribute("data-afl-group") || "default";
    var selector = "[data-afl-lightbox][data-afl-group=\\"" + groupKey + "\\"]";
    var groupEls = qa(selector);
    var groupSrcs = groupEls
      .map(function(x){ return x.getAttribute("data-afl-lightbox"); })
      .filter(function(src){ return !!src; });

    var clickedSrc = el.getAttribute("data-afl-lightbox");
    var idx = groupSrcs.indexOf(clickedSrc);
    if (idx < 0) idx = 0;

    openLightbox(groupSrcs, idx);
  });
})();
';

		wp_add_inline_style( 'afl-lightbox', $css );
		wp_add_inline_script( 'afl-lightbox', $js );

		wp_enqueue_style( 'afl-lightbox' );
		wp_enqueue_script( 'afl-lightbox' );
	}
);

/* ============================================================
 * LIKE / FAVORITE HELPERS
 * ============================================================ */

/**
 * Toggle a target user ID inside a user meta array.
 *
 * @param int    $current_user_id Current user ID.
 * @param string $meta_key        User meta key.
 * @param int    $target_user_id  Target user ID.
 * @return array
 */
function afl_toggle_user_list_meta( $current_user_id, $meta_key, $target_user_id ) {
	$list = get_user_meta( $current_user_id, $meta_key, true );

	if ( ! is_array( $list ) ) {
		$list = [];
	}

	$target_user_id = (int) $target_user_id;

	if ( in_array( $target_user_id, $list, true ) ) {
		$list = array_values( array_diff( $list, [ $target_user_id ] ) );
		update_user_meta( $current_user_id, $meta_key, $list );

		return [
			'active' => false,
			'list'   => $list,
		];
	}

	$list[] = $target_user_id;
	$list   = array_values( array_unique( array_map( 'intval', $list ) ) );
	update_user_meta( $current_user_id, $meta_key, $list );

	return [
		'active' => true,
		'list'   => $list,
	];
}

/**
 * AJAX: Toggle like.
 *
 * @return void
 */
add_action(
	'wp_ajax_afl_toggle_like',
	function () {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'msg' => 'Not logged in' ], 403 );
		}

		check_ajax_referer( 'afl_actions_nonce', 'nonce' );

		$target = isset( $_POST['target'] ) ? (int) $_POST['target'] : 0;

		if ( ! $target ) {
			wp_send_json_error( [ 'msg' => 'Missing target' ], 400 );
		}

		$me     = get_current_user_id();
		$result = afl_toggle_user_list_meta( $me, 'afl_likes', $target );

		wp_send_json_success(
			[
				'active' => $result['active'],
				'count'  => count( $result['list'] ),
			]
		);
	}
);

/**
 * AJAX: Toggle favorite.
 *
 * @return void
 */
add_action(
	'wp_ajax_afl_toggle_favorite',
	function () {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'msg' => 'Not logged in' ], 403 );
		}

		check_ajax_referer( 'afl_actions_nonce', 'nonce' );

		$target = isset( $_POST['target'] ) ? (int) $_POST['target'] : 0;

		if ( ! $target ) {
			wp_send_json_error( [ 'msg' => 'Missing target' ], 400 );
		}

		$me     = get_current_user_id();
		$result = afl_toggle_user_list_meta( $me, 'afl_favorites', $target );

		wp_send_json_success(
			[
				'active' => $result['active'],
				'count'  => count( $result['list'] ),
			]
		);
	}
);

/* ============================================================
 * PROFILE SAVE
 * ============================================================ */

/**
 * Save profile data and uploaded photos.
 *
 * @param int   $user_id User ID.
 * @param array $data    Submitted profile data.
 * @return void
 */
function all_profile_save_data( $user_id, $data ) {

	update_user_meta( $user_id, 'all_headline', sanitize_text_field( $data['headline'] ?? '' ) );
	update_user_meta( $user_id, 'all_overview', wp_kses_post( $data['overview'] ?? '' ) );
	update_user_meta( $user_id, 'all_seeking_desc', wp_kses_post( $data['seeking_desc'] ?? '' ) );

	update_user_meta( $user_id, 'all_gender', sanitize_text_field( $data['gender'] ?? '' ) );
	update_user_meta( $user_id, 'all_age', intval( $data['age'] ?? 0 ) );
	update_user_meta( $user_id, 'all_country', sanitize_text_field( $data['country'] ?? '' ) );
	update_user_meta( $user_id, 'all_city', sanitize_text_field( $data['city'] ?? '' ) );
	update_user_meta( $user_id, 'all_education', sanitize_text_field( $data['education'] ?? '' ) );
	update_user_meta( $user_id, 'all_occupation', sanitize_text_field( $data['occupation'] ?? '' ) );

	update_user_meta( $user_id, 'all_height', sanitize_text_field( $data['height'] ?? '' ) );
	update_user_meta( $user_id, 'all_weight', sanitize_text_field( $data['weight'] ?? '' ) );
	update_user_meta( $user_id, 'all_body_type', sanitize_text_field( $data['body_type'] ?? '' ) );
	update_user_meta( $user_id, 'all_hair_color', sanitize_text_field( $data['hair_color'] ?? '' ) );
	update_user_meta( $user_id, 'all_eye_color', sanitize_text_field( $data['eye_color'] ?? '' ) );

	update_user_meta( $user_id, 'all_drink', sanitize_text_field( $data['drink'] ?? '' ) );
	update_user_meta( $user_id, 'all_smoke', sanitize_text_field( $data['smoke'] ?? '' ) );
	update_user_meta( $user_id, 'all_marital_status', sanitize_text_field( $data['marital_status'] ?? '' ) );
	update_user_meta( $user_id, 'all_have_children', sanitize_text_field( $data['have_children'] ?? '' ) );

	update_user_meta( $user_id, 'all_hobbies', wp_kses_post( $data['hobbies'] ?? '' ) );
	update_user_meta( $user_id, 'all_favorite_music', sanitize_text_field( $data['favorite_music'] ?? '' ) );
	update_user_meta( $user_id, 'all_favorite_food', sanitize_text_field( $data['favorite_food'] ?? '' ) );
	update_user_meta( $user_id, 'all_favorite_movie', sanitize_text_field( $data['favorite_movie'] ?? '' ) );

	update_user_meta( $user_id, 'all_partner_age_min', intval( $data['partner_age_min'] ?? 0 ) );
	update_user_meta( $user_id, 'all_partner_age_max', intval( $data['partner_age_max'] ?? 0 ) );
	update_user_meta( $user_id, 'all_partner_country', sanitize_text_field( $data['partner_country'] ?? '' ) );
	update_user_meta( $user_id, 'all_partner_traits', wp_kses_post( $data['partner_traits'] ?? '' ) );

	if ( isset( $_FILES['profile_photo'] ) && ! empty( $_FILES['profile_photo']['name'] ) ) {

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploaded = wp_handle_upload( $_FILES['profile_photo'], [ 'test_form' => false ] );

		if ( isset( $uploaded['file'] ) ) {

			$editor = wp_get_image_editor( $uploaded['file'] );

			if ( ! is_wp_error( $editor ) ) {
				$editor->resize( 800, 800, false );
				$editor->save( $uploaded['file'] );
			}

			$attachment = [
				'post_mime_type' => $uploaded['type'],
				'post_title'     => basename( $uploaded['file'] ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			];

			$attach_id   = wp_insert_attachment( $attachment, $uploaded['file'] );
			$attach_data = wp_generate_attachment_metadata( $attach_id, $uploaded['file'] );
			wp_update_attachment_metadata( $attach_id, $attach_data );

			update_user_meta( $user_id, 'all_profile_photo', (int) $attach_id );
		}
	}

	$existing_gallery = get_user_meta( $user_id, 'all_gallery_photos', true );

	if ( ! is_array( $existing_gallery ) ) {
		$existing_gallery = [];
	}

	if ( ! empty( $data['remove_gallery'] ) && is_array( $data['remove_gallery'] ) ) {

		$remove_ids       = array_map( 'intval', $data['remove_gallery'] );
		$existing_gallery = array_values( array_diff( $existing_gallery, $remove_ids ) );

		foreach ( $remove_ids as $rid ) {
			if ( $rid ) {
				wp_delete_attachment( $rid, true );
			}
		}

		update_user_meta( $user_id, 'all_gallery_photos', $existing_gallery );
	}

	if ( isset( $_FILES['gallery_photos'] ) && ! empty( $_FILES['gallery_photos']['name'][0] ) ) {

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$files = $_FILES['gallery_photos'];

		for ( $i = 0; $i < count( $files['name'] ); $i++ ) {

			if ( count( $existing_gallery ) >= 4 ) {
				break;
			}

			if ( empty( $files['name'][ $i ] ) ) {
				continue;
			}

			$file = [
				'name'     => $files['name'][ $i ],
				'type'     => $files['type'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			];

			$uploaded = wp_handle_upload( $file, [ 'test_form' => false ] );

			if ( ! isset( $uploaded['file'] ) ) {
				continue;
			}

			$editor = wp_get_image_editor( $uploaded['file'] );

			if ( ! is_wp_error( $editor ) ) {
				$editor->resize( 800, 800, false );
				$editor->save( $uploaded['file'] );
			}

			$attachment_id = wp_insert_attachment(
				[
					'post_mime_type' => $uploaded['type'],
					'post_title'     => basename( $uploaded['file'] ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				],
				$uploaded['file']
			);

			$metadata = wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] );
			wp_update_attachment_metadata( $attachment_id, $metadata );

			$existing_gallery[] = (int) $attachment_id;
		}

		update_user_meta( $user_id, 'all_gallery_photos', $existing_gallery );
	}

	update_user_meta( $user_id, 'all_profile_complete', 1 );
}

/* ============================================================
 * PROFILE BUILDER SHORTCODE
 * ============================================================ */

/**
 * Shortcode: [afro_profile_builder]
 *
 * @return string
 */
function all_profile_builder_shortcode() {

	if ( ! is_user_logged_in() ) {
		return '<p>You need to be logged in to complete your profile.</p>';
	}

	wp_enqueue_style( 'all-profile-builder' );

	$user_id = get_current_user_id();

	$headline        = get_user_meta( $user_id, 'all_headline', true );
	$overview        = get_user_meta( $user_id, 'all_overview', true );
	$seeking_desc    = get_user_meta( $user_id, 'all_seeking_desc', true );

	$gender          = get_user_meta( $user_id, 'all_gender', true );
	$age             = get_user_meta( $user_id, 'all_age', true );
	$country         = get_user_meta( $user_id, 'all_country', true );
	$city            = get_user_meta( $user_id, 'all_city', true );
	$education       = get_user_meta( $user_id, 'all_education', true );
	$occupation      = get_user_meta( $user_id, 'all_occupation', true );

	$partner_age_min = get_user_meta( $user_id, 'all_partner_age_min', true );
	$partner_age_max = get_user_meta( $user_id, 'all_partner_age_max', true );
	$partner_country = get_user_meta( $user_id, 'all_partner_country', true );
	$partner_traits  = get_user_meta( $user_id, 'all_partner_traits', true );

	$height          = get_user_meta( $user_id, 'all_height', true );
	$weight          = get_user_meta( $user_id, 'all_weight', true );
	$body_type       = get_user_meta( $user_id, 'all_body_type', true );
	$hair_color      = get_user_meta( $user_id, 'all_hair_color', true );
	$eye_color       = get_user_meta( $user_id, 'all_eye_color', true );

	$drink           = get_user_meta( $user_id, 'all_drink', true );
	$smoke           = get_user_meta( $user_id, 'all_smoke', true );
	$marital_status  = get_user_meta( $user_id, 'all_marital_status', true );
	$have_children   = get_user_meta( $user_id, 'all_have_children', true );

	$hobbies         = get_user_meta( $user_id, 'all_hobbies', true );
	$favorite_music  = get_user_meta( $user_id, 'all_favorite_music', true );
	$favorite_food   = get_user_meta( $user_id, 'all_favorite_food', true );
	$favorite_movie  = get_user_meta( $user_id, 'all_favorite_movie', true );

	if ( isset( $_POST['all_profile_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['all_profile_nonce'] ) ), 'all_save_profile' ) ) {
		all_profile_save_data( $user_id, $_POST );
		wp_safe_redirect( site_url( '/meet-singles/' ) );
		exit;
	}

	$photo_id  = (int) get_user_meta( $user_id, 'all_profile_photo', true );
	$photo_url = $photo_id ? wp_get_attachment_image_url( $photo_id, 'medium' ) : 'https://www.gravatar.com/avatar/?s=200&d=mp';
	$photo_big = $photo_id ? wp_get_attachment_image_url( $photo_id, 'full' ) : $photo_url;

	ob_start();
	?>
	<div class="all-profile-wrapper">

		<form id="allProfileForm" class="all-profile-form" method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'all_save_profile', 'all_profile_nonce' ); ?>

			<div class="all-profile-header">

				<div class="all-profile-photo">
					<div class="all-photo-preview">
						<a href="#" class="afl-lb-open"
						   data-afl-group="afl-profile-<?php echo (int) $user_id; ?>"
						   data-afl-lightbox="<?php echo esc_url( $photo_big ); ?>">
							<img src="<?php echo esc_url( $photo_url ); ?>" class="all-photo-img" alt="Profile Photo">
						</a>
					</div>

					<button type="button" class="all-photo-upload-btn">Upload Photo</button>
					<input type="file" name="profile_photo" id="all-photo-input" accept="image/*" style="display:none;">
				</div>

				<div class="all-profile-main">
					<h1><?php echo esc_html( wp_get_current_user()->display_name ); ?></h1>

					<p class="all-profile-headline">
						<input
							type="text"
							name="headline"
							placeholder="Write a short headline about you"
							value="<?php echo esc_attr( $headline ); ?>"
						>
					</p>
				</div>
			</div>

			<div class="all-gallery-container" id="photos">
				<label class="all-gallery-title">Additional Photos (up to 4)</label>

				<div class="all-gallery-list">
					<?php
					$gallery = get_user_meta( $user_id, 'all_gallery_photos', true );
					if ( ! is_array( $gallery ) ) {
						$gallery = [];
					}

					foreach ( $gallery as $g_photo_id ) :
						$thumb = wp_get_attachment_image_url( $g_photo_id, 'thumbnail' );
						$full  = wp_get_attachment_image_url( $g_photo_id, 'full' );
						?>
						<div class="all-gallery-thumb" style="text-align:center;">
							<a href="#" class="afl-lb-open"
							   data-afl-group="afl-profile-<?php echo (int) $user_id; ?>"
							   data-afl-lightbox="<?php echo esc_url( $full ? $full : $thumb ); ?>">
								<?php echo wp_get_attachment_image( $g_photo_id, 'thumbnail' ); ?>
							</a>

							<label style="display:block; margin-top:6px; font-size:12px;">
								<input type="checkbox" name="remove_gallery[]" value="<?php echo esc_attr( $g_photo_id ); ?>">
								Remove
							</label>
						</div>
					<?php endforeach; ?>

					<?php if ( count( $gallery ) < 4 ) : ?>
						<div class="all-gallery-upload">
							<input type="file" name="gallery_photos[]" multiple accept="image/*">
							<span class="all-upload-text">+ Add Photos</span>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<section class="all-section">
				<h2>Member Overview</h2>

				<label>About Me</label>
				<textarea name="overview" rows="4" required><?php echo esc_textarea( $overview ); ?></textarea>

				<label>What I'm Looking For</label>
				<textarea name="seeking_desc" rows="4" required><?php echo esc_textarea( $seeking_desc ); ?></textarea>
			</section>

			<section class="all-section">
				<h2>More About Me &amp; Who I'm Looking For</h2>

				<div class="all-grid-2">
					<div>
						<h3>My Basic Info</h3>

						<label>Gender</label>
						<select name="gender" required>
							<option value="">Select</option>
							<option value="Male" <?php selected( $gender, 'Male' ); ?>>Male</option>
							<option value="Female" <?php selected( $gender, 'Female' ); ?>>Female</option>
							<option value="Other" <?php selected( $gender, 'Other' ); ?>>Other</option>
						</select>

						<label>Age</label>
						<input type="number" name="age" min="18" max="90" value="<?php echo esc_attr( $age ); ?>" required>

						<label>Country</label>
						<input type="text" name="country" value="<?php echo esc_attr( $country ); ?>">

						<label>City / Region</label>
						<input type="text" name="city" value="<?php echo esc_attr( $city ); ?>">

						<label>Education</label>
						<input type="text" name="education" value="<?php echo esc_attr( $education ); ?>" required>

						<label>Occupation</label>
						<input type="text" name="occupation" value="<?php echo esc_attr( $occupation ); ?>" required>
					</div>

					<div>
						<h3>I'm Looking For</h3>

						<label>Preferred Age Range</label>
						<div class="all-flex">
							<input type="number" name="partner_age_min" min="18" max="90" placeholder="From" value="<?php echo esc_attr( $partner_age_min ); ?>" required>
							<input type="number" name="partner_age_max" min="18" max="90" placeholder="To" value="<?php echo esc_attr( $partner_age_max ); ?>" required>
						</div>

						<label>Preferred Country / Region</label>
						<input type="text" name="partner_country" value="<?php echo esc_attr( $partner_country ); ?>">

						<label>Ideal Partner Description</label>
						<textarea name="partner_traits" rows="4"><?php echo esc_textarea( $partner_traits ); ?></textarea>
					</div>
				</div>
			</section>

			<section class="all-section">
				<h2>Appearance</h2>

				<div class="all-grid-2">
					<div>
						<label>Height</label>
						<input type="text" name="height" value="<?php echo esc_attr( $height ); ?>" required>

						<label>Weight</label>
						<input type="text" name="weight" value="<?php echo esc_attr( $weight ); ?>" required>

						<label>Body Type</label>
						<input type="text" name="body_type" value="<?php echo esc_attr( $body_type ); ?>">
					</div>

					<div>
						<label>Hair Color</label>
						<input type="text" name="hair_color" value="<?php echo esc_attr( $hair_color ); ?>">

						<label>Eye Color</label>
						<input type="text" name="eye_color" value="<?php echo esc_attr( $eye_color ); ?>">
					</div>
				</div>
			</section>

			<section class="all-section">
				<h2>Lifestyle</h2>

				<div class="all-grid-2">
					<div>
						<label>Drink</label>
						<input type="text" name="drink" value="<?php echo esc_attr( $drink ); ?>">

						<label>Smoke</label>
						<input type="text" name="smoke" value="<?php echo esc_attr( $smoke ); ?>">
					</div>

					<div>
						<label>Marital Status</label>
						<input type="text" name="marital_status" value="<?php echo esc_attr( $marital_status ); ?>" required>

						<label>Have Children?</label>
						<input type="text" name="have_children" value="<?php echo esc_attr( $have_children ); ?>">
					</div>
				</div>
			</section>

			<section class="all-section">
				<h2>Hobbies &amp; Interests</h2>

				<label>Hobbies &amp; Interests</label>
				<textarea name="hobbies" rows="3"><?php echo esc_textarea( $hobbies ); ?></textarea>

				<div class="all-grid-3">
					<div>
						<label>Favorite Music</label>
						<input type="text" name="favorite_music" value="<?php echo esc_attr( $favorite_music ); ?>">
					</div>
					<div>
						<label>Favorite Food</label>
						<input type="text" name="favorite_food" value="<?php echo esc_attr( $favorite_food ); ?>">
					</div>
					<div>
						<label>Favorite Movie</label>
						<input type="text" name="favorite_movie" value="<?php echo esc_attr( $favorite_movie ); ?>">
					</div>
				</div>
			</section>

			<div class="all-submit-wrap">
				<button type="submit" class="all-btn-primary">Save Profile &amp; Continue</button>
			</div>
		</form>

		<script>
		document.addEventListener("DOMContentLoaded", function () {
		  const uploadBtn = document.querySelector(".all-photo-upload-btn");
		  const fileInput = document.getElementById("all-photo-input");
		  const previewImg = document.querySelector(".all-photo-img");

		  if (uploadBtn && fileInput && previewImg) {
			uploadBtn.addEventListener("click", () => fileInput.click());
			fileInput.addEventListener("change", function () {
			  if (this.files && this.files[0]) {
				const reader = new FileReader();
				reader.onload = e => previewImg.src = e.target.result;
				reader.readAsDataURL(this.files[0]);
			  }
			});
		  }
		});
		</script>

	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'afro_profile_builder', 'all_profile_builder_shortcode' );

/* ============================================================
 * MEMBER GRID FILTERING HELPERS
 * ============================================================ */

/**
 * Normalize text for case-insensitive comparisons.
 *
 * @param mixed $value Input value.
 * @return string
 */
function afl_norm_text( $value ) {
	return strtolower( trim( wp_strip_all_tags( (string) $value ) ) );
}

/**
 * Check whether a member has a main profile photo.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function afl_member_has_photo( $user_id ) {
	return (int) get_user_meta( $user_id, 'all_profile_photo', true ) > 0;
}

/**
 * Check whether a member is verified.
 *
 * Adjust the meta key if your site uses a different verified field.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function afl_member_is_verified( $user_id ) {
	$value = get_user_meta( $user_id, 'afl_verified', true );
	return ( '1' === (string) $value || 1 === (int) $value || true === $value );
}

/**
 * Shuffle users using an optional deterministic seed.
 *
 * @param array  $users Array of WP_User objects.
 * @param string $seed  Shuffle seed.
 * @return array
 */
function afl_grid_shuffle_users( $users, $seed = '' ) {
	if ( empty( $users ) || ! is_array( $users ) ) {
		return $users;
	}

	if ( '' === $seed ) {
		shuffle( $users );
		return $users;
	}

	usort(
		$users,
		function ( $a, $b ) use ( $seed ) {
			$a_hash = md5( $seed . '|' . (int) $a->ID );
			$b_hash = md5( $seed . '|' . (int) $b->ID );
			return strcmp( $a_hash, $b_hash );
		}
	);

	return $users;
}

/**
 * Resolve sortable member first name.
 *
 * Priority:
 * - WordPress first_name user meta.
 * - First word from display_name.
 *
 * @param int $user_id User ID.
 * @return string
 */
function afl_member_sort_first_name( $user_id ) {
	$first_name = trim( (string) get_user_meta( $user_id, 'first_name', true ) );

	if ( '' !== $first_name ) {
		return afl_norm_text( $first_name );
	}

	$user = get_user_by( 'id', $user_id );

	if ( ! $user ) {
		return '';
	}

	$display_name = trim( (string) $user->display_name );

	if ( '' === $display_name ) {
		return '';
	}

	$parts = preg_split( '/\s+/', $display_name );
	$first = isset( $parts[0] ) ? $parts[0] : '';

	return afl_norm_text( $first );
}

/**
 * Check whether a candidate passes the toolbar filters.
 *
 * @param int   $candidate_id Candidate user ID.
 * @param array $filters      Filter set.
 * @return bool
 */
function afl_member_passes_toolbar_filters( $candidate_id, $filters ) {
	$gender  = afl_norm_text( get_user_meta( $candidate_id, 'all_gender', true ) );
	$age     = (int) get_user_meta( $candidate_id, 'all_age', true );
	$country = afl_norm_text( get_user_meta( $candidate_id, 'all_country', true ) );
	$city    = afl_norm_text( get_user_meta( $candidate_id, 'all_city', true ) );

	if ( ! empty( $filters['seek'] ) && 'any' !== $filters['seek'] ) {
		if ( $gender !== afl_norm_text( $filters['seek'] ) ) {
			return false;
		}
	}

	if ( ! empty( $filters['ageMin'] ) && $age > 0 && $age < (int) $filters['ageMin'] ) {
		return false;
	}

	if ( ! empty( $filters['ageMax'] ) && $age > 0 && $age > (int) $filters['ageMax'] ) {
		return false;
	}

	if ( ! empty( $filters['country'] ) && $country !== afl_norm_text( $filters['country'] ) ) {
		return false;
	}

	if ( ! empty( $filters['city'] ) && $city !== afl_norm_text( $filters['city'] ) ) {
		return false;
	}

	if ( ! empty( $filters['with_photo'] ) && ! afl_member_has_photo( $candidate_id ) ) {
		return false;
	}

	if ( ! empty( $filters['verified'] ) && ! afl_member_is_verified( $candidate_id ) ) {
		return false;
	}

	return true;
}

/**
 * Check whether a candidate matches current user's saved partner preferences.
 *
 * @param int $current_user_id Current user ID.
 * @param int $candidate_id    Candidate user ID.
 * @return bool
 */
function afl_member_matches_current_user_preferences( $current_user_id, $candidate_id ) {
	$candidate_age     = (int) get_user_meta( $candidate_id, 'all_age', true );
	$candidate_country = afl_norm_text( get_user_meta( $candidate_id, 'all_country', true ) );

	$pref_age_min = (int) get_user_meta( $current_user_id, 'all_partner_age_min', true );
	$pref_age_max = (int) get_user_meta( $current_user_id, 'all_partner_age_max', true );
	$pref_country = afl_norm_text( get_user_meta( $current_user_id, 'all_partner_country', true ) );

	if ( $pref_age_min > 0 && $candidate_age > 0 && $candidate_age < $pref_age_min ) {
		return false;
	}

	if ( $pref_age_max > 0 && $candidate_age > 0 && $candidate_age > $pref_age_max ) {
		return false;
	}

	if ( '' !== $pref_country && '' !== $candidate_country && $pref_country !== $candidate_country ) {
		return false;
	}

	return true;
}

/**
 * Check whether two users are a mutual match.
 *
 * @param int $current_user_id Current user ID.
 * @param int $candidate_id    Candidate user ID.
 * @return bool
 */
function afl_member_is_mutual_match( $current_user_id, $candidate_id ) {
	return (
		afl_member_matches_current_user_preferences( $current_user_id, $candidate_id ) &&
		afl_member_matches_current_user_preferences( $candidate_id, $current_user_id )
	);
}

/**
 * Sort filtered members.
 *
 * @param array  $users Array of WP_User objects.
 * @param string $sort  Sort mode.
 * @return array
 */
function afl_sort_filtered_members( $users, $sort = '' ) {
	if ( empty( $users ) || ! is_array( $users ) ) {
		return $users;
	}

	switch ( $sort ) {
		case 'name_asc':
			usort(
				$users,
				function ( $a, $b ) {
					$a_name = afl_member_sort_first_name( $a->ID );
					$b_name = afl_member_sort_first_name( $b->ID );

					$cmp = strcmp( $a_name, $b_name );

					if ( 0 !== $cmp ) {
						return $cmp;
					}

					return strcmp(
						afl_norm_text( $a->display_name ),
						afl_norm_text( $b->display_name )
					);
				}
			);
			break;

		case 'name_desc':
			usort(
				$users,
				function ( $a, $b ) {
					$a_name = afl_member_sort_first_name( $a->ID );
					$b_name = afl_member_sort_first_name( $b->ID );

					$cmp = strcmp( $b_name, $a_name );

					if ( 0 !== $cmp ) {
						return $cmp;
					}

					return strcmp(
						afl_norm_text( $b->display_name ),
						afl_norm_text( $a->display_name )
					);
				}
			);
			break;

		case 'last_active':
			usort(
				$users,
				function ( $a, $b ) {
					$a_last = (int) get_user_meta( $a->ID, 'afl_last_active', true );
					$b_last = (int) get_user_meta( $b->ID, 'afl_last_active', true );
					return $b_last <=> $a_last;
				}
			);
			break;

		case 'photos':
			usort(
				$users,
				function ( $a, $b ) {
					$a_gallery = get_user_meta( $a->ID, 'all_gallery_photos', true );
					$b_gallery = get_user_meta( $b->ID, 'all_gallery_photos', true );

					$a_count = (int) afl_member_has_photo( $a->ID ) + ( is_array( $a_gallery ) ? count( $a_gallery ) : 0 );
					$b_count = (int) afl_member_has_photo( $b->ID ) + ( is_array( $b_gallery ) ? count( $b_gallery ) : 0 );

					return $b_count <=> $a_count;
				}
			);
			break;

		case 'newest':
			usort(
				$users,
				function ( $a, $b ) {
					return strtotime( $b->user_registered ) <=> strtotime( $a->user_registered );
				}
			);
			break;
	}

	return $users;
}

/* ============================================================
 * MEMBER GRID SHORTCODE
 * ============================================================ */

/**
 * Shortcode: [afro_member_grid]
 *
 * @return string
 */
function all_member_grid_shortcode() {

	if ( ! is_user_logged_in() ) {
		return '<p>You need to be logged in to view members.</p>';
	}

	wp_enqueue_script( 'afl-actions' );

	$current_user_id = get_current_user_id();
	$total_slots     = 20;

	$tab          = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
	$seek         = isset( $_GET['seek'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['seek'] ) ) ) : '';
	$age_min      = isset( $_GET['ageMin'] ) ? (int) $_GET['ageMin'] : 0;
	$age_max      = isset( $_GET['ageMax'] ) ? (int) $_GET['ageMax'] : 0;
	$country      = isset( $_GET['country'] ) ? sanitize_text_field( wp_unslash( $_GET['country'] ) ) : '';
	$city         = isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( $_GET['city'] ) ) : '';
	$verified     = isset( $_GET['verified'] ) ? sanitize_text_field( wp_unslash( $_GET['verified'] ) ) : '';
	$with_photo   = isset( $_GET['with_photo'] ) ? sanitize_text_field( wp_unslash( $_GET['with_photo'] ) ) : '';
	$sort         = isset( $_GET['sort'] ) ? sanitize_text_field( wp_unslash( $_GET['sort'] ) ) : '';
	$shuffle_seed = isset( $_GET['shuffle_seed'] ) ? sanitize_text_field( wp_unslash( $_GET['shuffle_seed'] ) ) : '';

	$filters = [
		'seek'       => $seek,
		'ageMin'     => $age_min,
		'ageMax'     => $age_max,
		'country'    => $country,
		'city'       => $city,
		'verified'   => $verified,
		'with_photo' => $with_photo,
	];

	$users = get_users(
		[
			'role__in' => [ 'subscriber' ],
			'number'   => -1,
			'orderby'  => 'registered',
			'order'    => 'DESC',
			'exclude'  => [ $current_user_id ],
		]
	);

	$users = array_values(
		array_filter(
			$users,
			function ( $u ) use ( $filters, $tab, $current_user_id ) {
				$uid = (int) $u->ID;

				if ( ! (int) get_user_meta( $uid, 'all_profile_complete', true ) ) {
					return false;
				}

				if ( ! afl_member_passes_toolbar_filters( $uid, $filters ) ) {
					return false;
				}

				if ( 'matches' === $tab ) {
					return afl_member_matches_current_user_preferences( $current_user_id, $uid );
				}

				if ( 'mutual' === $tab ) {
					return afl_member_is_mutual_match( $current_user_id, $uid );
				}

				return true;
			}
		)
	);

	if ( in_array( $sort, [ 'name_asc', 'name_desc', 'newest', 'last_active', 'photos' ], true ) ) {
		$users = afl_sort_filtered_members( $users, $sort );
	} else {
		$users = afl_grid_shuffle_users( $users, $shuffle_seed );
	}

	$users        = array_slice( $users, 0, $total_slots );
	$member_count = count( $users );

	ob_start();
	?>
	<style>
	  .afl-members-grid-wrapper{max-width:1400px;margin:30px auto;padding:0 20px 40px;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
	  .afl-members-title{text-align:center;font-size:20px;font-weight:600;margin-bottom:20px;color:#fff;}
	  .afl-members-grid{display:flex;flex-wrap:wrap;gap:16px;justify-content:flex-start;}
	  .afl-member-card{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,.06);display:flex;flex-direction:column;flex:0 0 calc(18% - 12px);}
	  .afl-member-photo{width:100%;height:310px;background:#eee;overflow:hidden;}
	  .afl-member-photo img{width:100%;height:100%;object-fit:cover;display:block;}
	  .afl-member-body{padding:10px 12px 12px;font-size:12px;text-align:left;}
	  .afl-member-name{font-size:13px;font-weight:700;margin:0 0 4px;color:#111827;}
	  .afl-member-meta{font-size:11px;color:#6b7280;margin:0 0 6px;}
	  .afl-member-seeking{font-size:11px;margin:0 0 6px;}
	  .afl-seek-label{color:#6b7280;margin-right:4px;}
	  .afl-seek-value{color:#111827;font-weight:600;}
	  .afl-member-status{display:flex;align-items:center;gap:6px;font-size:11px;color:#6b7280;margin:0 0 8px;}
	  .afl-dot{width:8px;height:8px;border-radius:50%;display:inline-block;background:#9ca3af;}
	  .afl-dot.is-online{background:#22c55e;}
	  .afl-dot.is-offline{background:#9ca3af;}
	  .afl-member-headline{font-size:11px;color:#111827;margin:0 0 10px;min-height:28px;}
	  .afl-icon-row{display:flex;gap:10px;align-items:center;margin:8px 0 10px;}
	  .afl-icon-btn{width:34px;height:34px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:#f3f4f6;text-decoration:none;border:1px solid #e5e7eb;}
	  .afl-icon-btn .dashicons{font-size:20px;width:20px;height:20px;line-height:20px;}
	  .afl-icon-btn:hover{opacity:.9;}
	  .afl-icon-btn.is-active{background:#fee2e2;border-color:#fecaca;}
	  .afl-photo-btn{position:relative;}
	  .afl-photo-count{position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:#111827;color:#fff;font-size:10px;padding:0 5px;}
	  .afl-member-actions{display:flex;justify-content:flex-start;gap:8px;margin-top:4px;}
	  .afl-card-btn{padding:6px 12px;border-radius:999px;border:none;font-size:11px;cursor:pointer;background:#a00026;color:#fff;text-decoration:none;display:inline-block;}
	  .afl-card-btn.afl-outline{background:#fff;color:#a00026;border:1px solid #a00026;}
	  .afl-card-btn:hover{opacity:.9;}
	  .afl-placeholder img{opacity:.5;}
	  @media (max-width:1000px){.afl-member-card{flex:0 0 calc(33.33% - 12px);}}
	  @media (max-width:700px){.afl-member-card{flex:0 0 calc(50% - 12px);}}
	  @media (max-width:420px){.afl-member-card{flex:0 0 100%;}}
	</style>

	<div class="afl-members-grid-wrapper">
	  <h2 class="afl-members-title">Meet Singles</h2>

	  <div class="afl-members-grid">
		<?php foreach ( $users as $u ) : ?>
			<?php
			$uid     = (int) $u->ID;
			$is_self = ( $current_user_id === $uid );

			$photo_id   = (int) get_user_meta( $uid, 'all_profile_photo', true );
			$photo_url  = $photo_id ? wp_get_attachment_image_url( $photo_id, 'medium' ) : 'https://www.gravatar.com/avatar/?s=200&d=mp';
			$photo_full = $photo_id ? wp_get_attachment_image_url( $photo_id, 'full' ) : '';

			$gallery_ids = get_user_meta( $uid, 'all_gallery_photos', true );
			if ( ! is_array( $gallery_ids ) ) {
				$gallery_ids = [];
			}

			$lightbox_urls = [];
			if ( $photo_full ) {
				$lightbox_urls[] = $photo_full;
			}

			foreach ( $gallery_ids as $gid ) {
				$gid  = (int) $gid;
				$full = $gid ? wp_get_attachment_image_url( $gid, 'full' ) : '';
				if ( $full ) {
					$lightbox_urls[] = $full;
				}
			}

			$lightbox_urls = array_values( array_unique( array_filter( $lightbox_urls ) ) );
			$photo_count   = count( $lightbox_urls );

			$age       = get_user_meta( $uid, 'all_age', true );
			$city_u    = get_user_meta( $uid, 'all_city', true );
			$country_u = get_user_meta( $uid, 'all_country', true );
			$headline  = get_user_meta( $uid, 'all_headline', true );

			$seek_gender = get_user_meta( $uid, 'all_gender', true );
			$seek_min    = get_user_meta( $uid, 'all_partner_age_min', true );
			$seek_max    = get_user_meta( $uid, 'all_partner_age_max', true );

			$age_city_country = trim(
				( $age ? intval( $age ) : '' ) .
				( $age && ( $city_u || $country_u ) ? ', ' : '' ) .
				trim( $city_u . ( $city_u && $country_u ? ', ' : '' ) . $country_u )
			);

			$seek_age = '';
			if ( $seek_min || $seek_max ) {
				$seek_age = trim( $seek_min . ( $seek_min && $seek_max ? ' - ' : '' ) . $seek_max );
			}

			$last_active = (int) get_user_meta( $uid, 'afl_last_active', true );
			$online      = ( $last_active && ( time() - $last_active ) <= 300 );

			$profile_url = add_query_arg( 'member', $uid, home_url( '/datingsite/view-profile/' ) );

			$my_likes = get_user_meta( $current_user_id, 'afl_likes', true );
			if ( ! is_array( $my_likes ) ) {
				$my_likes = [];
			}
			$liked = in_array( $uid, $my_likes, true );

			$msg_url = add_query_arg( 'to', $uid, home_url( '/messages/' ) );

			$lb_group = 'afl-card-' . $uid;
			$lb_first = ! empty( $lightbox_urls[0] ) ? $lightbox_urls[0] : '';
			?>
		  <div class="afl-member-card">
			<div class="afl-member-photo">
			  <?php if ( $lb_first ) : ?>
				<a href="#" class="afl-lb-open"
				   data-afl-group="<?php echo esc_attr( $lb_group ); ?>"
				   data-afl-lightbox="<?php echo esc_url( $lb_first ); ?>">
				  <img src="<?php echo esc_url( $photo_url ); ?>" alt="">
				</a>
			  <?php else : ?>
				<img src="<?php echo esc_url( $photo_url ); ?>" alt="">
			  <?php endif; ?>
			</div>

			<div class="afl-member-body">

			  <div class="afl-member-name"><?php echo esc_html( $u->display_name ); ?></div>

			  <?php if ( $age_city_country ) : ?>
				<div class="afl-member-meta"><?php echo esc_html( $age_city_country ); ?>.</div>
			  <?php endif; ?>

			  <div class="afl-member-seeking">
				<span class="afl-seek-label">Seeking for:</span>
				<span class="afl-seek-value">
				  <?php echo esc_html( $seek_gender ? $seek_gender : '—' ); ?>
				  <?php if ( $seek_age ) : ?>
					<?php echo ', ' . esc_html( $seek_age ); ?>
				  <?php endif; ?>
				</span>
			  </div>

			  <div class="afl-member-status">
				<span class="afl-dot <?php echo $online ? 'is-online' : 'is-offline'; ?>"></span>
				<span><?php echo $online ? 'Online' : 'Offline'; ?></span>
			  </div>

			  <?php if ( $headline ) : ?>
				<div class="afl-member-headline"><?php echo esc_html( $headline ); ?></div>
			  <?php endif; ?>

			  <?php if ( $photo_count > 0 ) : ?>
				<div style="display:none;">
				  <?php foreach ( $lightbox_urls as $url ) : ?>
					<a href="#"
					   data-afl-group="<?php echo esc_attr( $lb_group ); ?>"
					   data-afl-lightbox="<?php echo esc_url( $url ); ?>"></a>
				  <?php endforeach; ?>
				</div>
			  <?php endif; ?>

			  <div class="afl-icon-row">
				<a href="#" class="afl-icon-btn <?php echo $liked ? 'is-active' : ''; ?>"
				   data-action="like" data-target="<?php echo esc_attr( $uid ); ?>" title="Like">
				  <span class="dashicons dashicons-heart"></span>
				</a>

				<?php
				$popup_avatar = $photo_id
					? wp_get_attachment_image_url( $photo_id, 'thumbnail' )
					: 'https://www.gravatar.com/avatar/?s=96&d=mp';

				$popup_sub = trim(
					( $age ? intval( $age ) : '' ) .
					( $city_u ? ' • ' . $city_u : '' ) .
					( $country_u ? ', ' . $country_u : '' )
				);
				?>

				<?php if ( ! $is_self ) : ?>
				  <a href="<?php echo esc_url( $msg_url ); ?>"
					 class="afl-icon-btn"
					 data-afl-chat-open="<?php echo esc_attr( $uid ); ?>"
					 data-afl-name="<?php echo esc_attr( $u->display_name ); ?>"
					 data-afl-sub="<?php echo esc_attr( $popup_sub ); ?>"
					 data-afl-avatar="<?php echo esc_url( $popup_avatar ); ?>"
					 title="Message">
					<span class="dashicons dashicons-format-chat"></span>
				  </a>
				<?php endif; ?>

				<?php if ( $lb_first ) : ?>
				  <a href="#"
					 class="afl-icon-btn afl-photo-btn afl-lb-open"
					 data-afl-group="<?php echo esc_attr( $lb_group ); ?>"
					 data-afl-lightbox="<?php echo esc_url( $lb_first ); ?>"
					 title="Photos">
					<span class="dashicons dashicons-camera"></span>
					<span class="afl-photo-count"><?php echo (int) $photo_count; ?></span>
				  </a>
				<?php else : ?>
				  <a class="afl-icon-btn afl-photo-btn" href="<?php echo esc_url( $profile_url ); ?>" title="Profile">
					<span class="dashicons dashicons-camera"></span>
					<span class="afl-photo-count">0</span>
				  </a>
				<?php endif; ?>
			  </div>

			  <div class="afl-member-actions">
				<a class="afl-card-btn afl-outline" href="<?php echo esc_url( $profile_url ); ?>">Profile</a>
			  </div>

			</div>
		  </div>
		<?php endforeach; ?>

		<?php for ( $i = $member_count; $i < $total_slots; $i++ ) : ?>
		  <div class="afl-member-card afl-placeholder">
			<div class="afl-member-photo">
			  <img src="https://www.gravatar.com/avatar/?s=200&d=mp" alt="">
			</div>
			<div class="afl-member-body" style="text-align:left;">
			  <div class="afl-member-name">New Member</div>
			  <div class="afl-member-meta">---</div>
			  <div class="afl-member-headline">Join Afro Love Life to appear here.</div>
			  <div class="afl-member-actions">
				<button class="afl-card-btn afl-outline" type="button" disabled>Coming soon</button>
			  </div>
			</div>
		  </div>
		<?php endfor; ?>

	  </div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'afro_member_grid', 'all_member_grid_shortcode' );

/* ============================================================
 * VIEW PROFILE SHORTCODE
 * ============================================================ */

/**
 * Shortcode: [afro_view_profile]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function all_single_member_profile_shortcode( $atts ) {

	if ( ! is_user_logged_in() ) {
		return '<p>You need to be logged in to view profiles.</p>';
	}

	$member_id = isset( $_GET['member'] ) ? intval( $_GET['member'] ) : 0;

	$atts = shortcode_atts( [ 'member' => 0 ], $atts, 'afro_view_profile' );

	if ( ! $member_id && ! empty( $atts['member'] ) ) {
		$member_id = intval( $atts['member'] );
	}

	if ( ! $member_id ) {
		$member_id = get_current_user_id();
	}

	$user = get_user_by( 'id', $member_id );

	if ( ! $user ) {
		return '<p>Member not found.</p>';
	}

	$is_owner = ( get_current_user_id() === $member_id );

	$headline   = get_user_meta( $member_id, 'all_headline', true );
	$overview   = get_user_meta( $member_id, 'all_overview', true );
	$seeking    = get_user_meta( $member_id, 'all_seeking_desc', true );

	$gender     = get_user_meta( $member_id, 'all_gender', true );
	$age        = get_user_meta( $member_id, 'all_age', true );
	$country    = get_user_meta( $member_id, 'all_country', true );
	$city       = get_user_meta( $member_id, 'all_city', true );
	$education  = get_user_meta( $member_id, 'all_education', true );
	$occupation = get_user_meta( $member_id, 'all_occupation', true );

	$height     = get_user_meta( $member_id, 'all_height', true );
	$weight     = get_user_meta( $member_id, 'all_weight', true );
	$body_type  = get_user_meta( $member_id, 'all_body_type', true );
	$hair_color = get_user_meta( $member_id, 'all_hair_color', true );
	$eye_color  = get_user_meta( $member_id, 'all_eye_color', true );

	$drink          = get_user_meta( $member_id, 'all_drink', true );
	$smoke          = get_user_meta( $member_id, 'all_smoke', true );
	$marital_status = get_user_meta( $member_id, 'all_marital_status', true );
	$have_children  = get_user_meta( $member_id, 'all_have_children', true );

	$hobbies        = get_user_meta( $member_id, 'all_hobbies', true );
	$favorite_music = get_user_meta( $member_id, 'all_favorite_music', true );
	$favorite_food  = get_user_meta( $member_id, 'all_favorite_food', true );
	$favorite_movie = get_user_meta( $member_id, 'all_favorite_movie', true );

	$partner_age_min = get_user_meta( $member_id, 'all_partner_age_min', true );
	$partner_age_max = get_user_meta( $member_id, 'all_partner_age_max', true );
	$partner_country = get_user_meta( $member_id, 'all_partner_country', true );
	$partner_traits  = get_user_meta( $member_id, 'all_partner_traits', true );

	$photo_id   = (int) get_user_meta( $member_id, 'all_profile_photo', true );
	$photo_url  = $photo_id ? wp_get_attachment_image_url( $photo_id, 'large' ) : 'https://www.gravatar.com/avatar/?s=400&d=mp';
	$photo_full = $photo_id ? wp_get_attachment_image_url( $photo_id, 'full' ) : $photo_url;

	$gallery = get_user_meta( $member_id, 'all_gallery_photos', true );
	if ( ! is_array( $gallery ) ) {
		$gallery = [];
	}

	ob_start();
	?>
	<style>
	.afl-profile-view-wrapper{max-width:1000px;margin:40px auto;padding:20px;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.06);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
	.afl-profile-view-header{display:flex;gap:24px;margin-bottom:20px;}
	.afl-profile-view-photo{width:260px;height:260px;border-radius:12px;overflow:hidden;background:#eee;}
	.afl-profile-view-photo img{width:100%;height:100%;object-fit:cover;display:block;}
	.afl-profile-view-main h1{margin:0 0 6px;font-size:24px;}
	.afl-profile-view-location{margin:0 0 8px;color:#6b7280;font-size:13px;}
	.afl-profile-view-headline{font-size:14px;margin-bottom:10px;}
	.afl-profile-view-section{margin-top:22px;}
	.afl-profile-view-section h2{font-size:18px;margin:0 0 8px;border-bottom:1px solid #eee;padding-bottom:4px;}
	.afl-two-col{display:grid;grid-template-columns:repeat(2, minmax(0,1fr));gap:14px 30px;font-size:13px;}
	.afl-field-label{font-weight:600;color:#4b5563;display:block;margin-bottom:2px;}
	.afl-field-value{color:#111827;}
	.afl-profile-view-gallery{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;}
	.afl-profile-view-gallery img{width:100px;height:100px;object-fit:cover;border-radius:8px;}
	.afl-actions-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px;}
	@media (max-width:768px){
		.afl-profile-view-header{flex-direction:column;align-items:center;text-align:center;}
		.afl-profile-view-photo{width:220px;height:220px;}
		.afl-two-col{grid-template-columns:1fr;}
	}
	</style>

	<div class="afl-profile-view-wrapper">

		<div class="afl-profile-view-header">
			<div class="afl-profile-view-photo">
				<a href="#" class="afl-lb-open"
				   data-afl-group="afl-view-<?php echo (int) $member_id; ?>"
				   data-afl-lightbox="<?php echo esc_url( $photo_full ); ?>">
					<img src="<?php echo esc_url( $photo_url ); ?>" alt="">
				</a>
			</div>

			<div class="afl-profile-view-main">
				<h1>
					<?php echo esc_html( $user->display_name ); ?>
					<?php if ( $age ) : ?>
						<?php echo ', ' . intval( $age ); ?>
					<?php endif; ?>
				</h1>

				<p class="afl-profile-view-location">
					<?php echo esc_html( trim( $city . ( $city && $country ? ', ' : '' ) . $country ) ); ?>
				</p>

				<?php if ( $headline ) : ?>
					<p class="afl-profile-view-headline"><?php echo esc_html( $headline ); ?></p>
				<?php endif; ?>

				<?php if ( $is_owner ) : ?>
					<div class="afl-actions-row">
						<a class="afl-card-btn afl-outline" href="<?php echo esc_url( home_url( '/datingsite/create-profile/' ) ); ?>">Edit Profile</a>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( ! empty( $gallery ) ) : ?>
			<div class="afl-profile-view-section">
				<h2>More Photos</h2>
				<div class="afl-profile-view-gallery">
					<?php foreach ( $gallery as $gid ) : ?>
						<?php
						$g_thumb = wp_get_attachment_image_url( $gid, 'thumbnail' );
						$g_full  = wp_get_attachment_image_url( $gid, 'full' );
						?>
						<a href="#" class="afl-lb-open"
						   data-afl-group="afl-view-<?php echo (int) $member_id; ?>"
						   data-afl-lightbox="<?php echo esc_url( $g_full ? $g_full : $g_thumb ); ?>">
							<?php echo wp_get_attachment_image( $gid, 'thumbnail' ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $overview || $seeking ) : ?>
			<div class="afl-profile-view-section">
				<h2>Member Overview</h2>

				<?php if ( $overview ) : ?>
					<p><span class="afl-field-label">About Me</span>
					<span class="afl-field-value"><?php echo nl2br( esc_html( $overview ) ); ?></span></p>
				<?php endif; ?>

				<?php if ( $seeking ) : ?>
					<p><span class="afl-field-label">What I'm Looking For</span>
					<span class="afl-field-value"><?php echo nl2br( esc_html( $seeking ) ); ?></span></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="afl-profile-view-section">
			<h2>More About Me &amp; Who I'm Looking For</h2>

			<div class="afl-two-col">
				<div><span class="afl-field-label">Gender</span><span class="afl-field-value"><?php echo esc_html( $gender ); ?></span></div>
				<div><span class="afl-field-label">Preferred Age Range</span><span class="afl-field-value"><?php if ( $partner_age_min || $partner_age_max ) { echo esc_html( $partner_age_min ) . ' - ' . esc_html( $partner_age_max ); } ?></span></div>

				<div><span class="afl-field-label">Country</span><span class="afl-field-value"><?php echo esc_html( $country ); ?></span></div>
				<div><span class="afl-field-label">Preferred Country / Region</span><span class="afl-field-value"><?php echo esc_html( $partner_country ); ?></span></div>

				<div><span class="afl-field-label">City / Region</span><span class="afl-field-value"><?php echo esc_html( $city ); ?></span></div>
				<div><span class="afl-field-label">Ideal Partner Description</span><span class="afl-field-value"><?php echo nl2br( esc_html( $partner_traits ) ); ?></span></div>

				<div><span class="afl-field-label">Education</span><span class="afl-field-value"><?php echo esc_html( $education ); ?></span></div>
				<div><span class="afl-field-label">Occupation</span><span class="afl-field-value"><?php echo esc_html( $occupation ); ?></span></div>
			</div>
		</div>

		<div class="afl-profile-view-section">
			<h2>Appearance</h2>
			<div class="afl-two-col">
				<div><span class="afl-field-label">Height</span><span class="afl-field-value"><?php echo esc_html( $height ); ?></span></div>
				<div><span class="afl-field-label">Hair Color</span><span class="afl-field-value"><?php echo esc_html( $hair_color ); ?></span></div>
				<div><span class="afl-field-label">Weight</span><span class="afl-field-value"><?php echo esc_html( $weight ); ?></span></div>
				<div><span class="afl-field-label">Eye Color</span><span class="afl-field-value"><?php echo esc_html( $eye_color ); ?></span></div>
				<div><span class="afl-field-label">Body Type</span><span class="afl-field-value"><?php echo esc_html( $body_type ); ?></span></div>
			</div>
		</div>

		<div class="afl-profile-view-section">
			<h2>Lifestyle</h2>
			<div class="afl-two-col">
				<div><span class="afl-field-label">Drink</span><span class="afl-field-value"><?php echo esc_html( $drink ); ?></span></div>
				<div><span class="afl-field-label">Smoke</span><span class="afl-field-value"><?php echo esc_html( $smoke ); ?></span></div>
				<div><span class="afl-field-label">Marital Status</span><span class="afl-field-value"><?php echo esc_html( $marital_status ); ?></span></div>
				<div><span class="afl-field-label">Have Children?</span><span class="afl-field-value"><?php echo esc_html( $have_children ); ?></span></div>
			</div>
		</div>

		<div class="afl-profile-view-section">
			<h2>Hobbies &amp; Interests</h2>

			<?php if ( $hobbies ) : ?>
				<p><span class="afl-field-label">Hobbies &amp; Interests</span>
				<span class="afl-field-value"><?php echo nl2br( esc_html( $hobbies ) ); ?></span></p>
			<?php endif; ?>

			<div class="afl-two-col">
				<div><span class="afl-field-label">Favorite Music</span><span class="afl-field-value"><?php echo esc_html( $favorite_music ); ?></span></div>
				<div><span class="afl-field-label">Favorite Food</span><span class="afl-field-value"><?php echo esc_html( $favorite_food ); ?></span></div>
				<div><span class="afl-field-label">Favorite Movie</span><span class="afl-field-value"><?php echo esc_html( $favorite_movie ); ?></span></div>
			</div>
		</div>

	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'afro_view_profile', 'all_single_member_profile_shortcode' );