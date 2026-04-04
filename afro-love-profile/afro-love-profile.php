<?php
/**
 * Plugin Name: Afro Love Life - Profile Builder + [afro_member_grid]
 * Description: Front-end profile creation, member browsing, server-side match filtering, like activity tracking, and profile viewing for Afro Love Life dating site.
 * Version: 1.4.0
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
		'1.4.0'
	);
}
add_action( 'init', 'all_profile_builder_assets' );
/* ============================================================
 * LOCATION DATA ACCESS HELPERS
 * ============================================================ */

/**
 * Return supported locations table name.
 *
 * @return string
 */
function afl_supported_locations_table() {
	global $wpdb;
	return $wpdb->prefix . 'afl_supported_locations';
}

/**
 * Return active supported countries.
 *
 * @return array
 */
function afl_get_supported_countries() {
	global $wpdb;

	$table = afl_supported_locations_table();

	$rows = $wpdb->get_col(
		"SELECT DISTINCT country_name
		 FROM {$table}
		 WHERE is_active = 1
		 ORDER BY country_name ASC"
	);

	if ( ! is_array( $rows ) ) {
		return [];
	}

	return array_values( array_filter( array_map( 'strval', $rows ) ) );
}

/**
 * Return active supported cities for a country.
 *
 * @param string $country Country name.
 * @return array
 */
function afl_get_supported_cities_by_country( $country ) {
	global $wpdb;

	$country = trim( (string) $country );

	if ( '' === $country ) {
		return [];
	}

	$table = afl_supported_locations_table();

	$rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT city_name
			 FROM {$table}
			 WHERE is_active = 1
			   AND country_name = %s
			 ORDER BY city_name ASC",
			$country
		)
	);

	if ( ! is_array( $rows ) ) {
		return [];
	}

	return array_values( array_filter( array_map( 'strval', $rows ) ) );
}

/**
 * Validate that a submitted country/city pair exists in supported locations.
 *
 * @param string $country Country name.
 * @param string $city    City name.
 * @return bool
 */
function afl_is_supported_country_city( $country, $city ) {
	global $wpdb;

	$country = trim( (string) $country );
	$city    = trim( (string) $city );

	if ( '' === $country || '' === $city ) {
		return false;
	}

	$table = afl_supported_locations_table();

	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$table}
			 WHERE is_active = 1
			   AND country_name = %s
			   AND city_name = %s",
			$country,
			$city
		)
	);

	return $count > 0;
}


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
 * Normalize a stored array of user IDs.
 *
 * @param mixed $value Meta value.
 * @return array
 */
function afl_normalize_user_id_array( $value ) {
	if ( ! is_array( $value ) ) {
		$value = [];
	}

	$value = array_map( 'intval', $value );
	$value = array_filter(
		$value,
		function ( $id ) {
			return $id > 0;
		}
	);

	return array_values( array_unique( $value ) );
}

/**
 * Refresh incoming like counters for a user.
 *
 * @param int $user_id User ID.
 * @return int
 */
function afl_refresh_received_like_count( $user_id ) {
	$user_id = (int) $user_id;

	if ( $user_id <= 0 ) {
		return 0;
	}

	$likers = get_user_meta( $user_id, 'afl_activity_likers', true );
	$likers = afl_normalize_user_id_array( $likers );

	update_user_meta( $user_id, 'afl_activity_likers', $likers );
	update_user_meta( $user_id, 'afl_activity_likes', count( $likers ) );

	return count( $likers );
}

/**
 * Add an incoming like record for the target user.
 *
 * @param int $target_user_id Target user ID.
 * @param int $liker_user_id  Liker user ID.
 * @return int
 */
function afl_add_received_like( $target_user_id, $liker_user_id ) {
	$target_user_id = (int) $target_user_id;
	$liker_user_id  = (int) $liker_user_id;

	if ( $target_user_id <= 0 || $liker_user_id <= 0 || $target_user_id === $liker_user_id ) {
		return 0;
	}

	$likers   = get_user_meta( $target_user_id, 'afl_activity_likers', true );
	$likers   = afl_normalize_user_id_array( $likers );
	$likers[] = $liker_user_id;
	$likers   = afl_normalize_user_id_array( $likers );

	update_user_meta( $target_user_id, 'afl_activity_likers', $likers );
	update_user_meta( $target_user_id, 'afl_activity_likes', count( $likers ) );

	return count( $likers );
}

/**
 * Remove an incoming like record for the target user.
 *
 * @param int $target_user_id Target user ID.
 * @param int $liker_user_id  Liker user ID.
 * @return int
 */
function afl_remove_received_like( $target_user_id, $liker_user_id ) {
	$target_user_id = (int) $target_user_id;
	$liker_user_id  = (int) $liker_user_id;

	if ( $target_user_id <= 0 || $liker_user_id <= 0 || $target_user_id === $liker_user_id ) {
		return afl_refresh_received_like_count( $target_user_id );
	}

	$likers = get_user_meta( $target_user_id, 'afl_activity_likers', true );
	$likers = afl_normalize_user_id_array( $likers );
	$likers = array_values( array_diff( $likers, [ $liker_user_id ] ) );

	update_user_meta( $target_user_id, 'afl_activity_likers', $likers );
	update_user_meta( $target_user_id, 'afl_activity_likes', count( $likers ) );

	return count( $likers );
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

		$me = get_current_user_id();

		if ( $me === $target ) {
			wp_send_json_error( [ 'msg' => 'You cannot like your own profile.' ], 400 );
		}

		$result = afl_toggle_user_list_meta( $me, 'afl_likes', $target );

		if ( $result['active'] ) {
			$received_count = afl_add_received_like( $target, $me );
		} else {
			$received_count = afl_remove_received_like( $target, $me );
		}

		wp_send_json_success(
			[
				'active'         => $result['active'],
				'count'          => count( $result['list'] ),
				'received_count' => $received_count,
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
	$country = sanitize_text_field( $data['country'] ?? '' );
$city    = sanitize_text_field( $data['city'] ?? '' );

$afl_location_map = [
	'Benin' => ['Cotonou','Abomey-Calavi','Porto-Novo','Parakou','Djougou','Bohicon','Kandi','Ouidah','Abomey','Natitingou','Lokossa','Comè','Allada','Sèmè-Kpodji','Savè','Savalou','Dassa-Zoumé','Nikki','Malanville','Tanguiéta','Glazoue'],
	'Togo' => ['Lomé','Sokodé','Kara','Atakpamé','Kpalimé','Tsévié','Dapaong','Aného','Bassar','Mango','Notsé','Kandé','Vogan','Tabligbo','Bafilo','Sotouboua','Blitta','Pagouda','Cinkassé','Badou'],
	'Niger' => ['Niamey','Maradi','Zinder','Tahoua','Agadez','Dosso','Diffa','Tillabéri','Arlit','Gaya','Tessaoua','Magaria','Dakoro',"Birni N'Konni",'Madarounfa','Filingué','Balleyara','Say','Téra','Nguigmi'],
	'Burkina Faso' => ['Ouagadougou','Bobo-Dioulasso','Koudougou','Ouahigouya','Banfora','Kaya','Tenkodogo',"Fada N'gourma",'Dédougou','Gaoua','Ziniaré','Kombissiri','Pô','Houndé','Boromo','Réo','Kongoussi','Zorgho','Tougan','Dori'],
	'Senegal' => ['Dakar','Pikine','Touba','Thiès','Rufisque','Kaolack','Ziguinchor','Saint-Louis','Mbour','Diourbel','Kolda','Tambacounda','Louga','Matam','Sédhiou','Kaffrine','Kédougou','Richard-Toll','Podor','Dagana'],
	'Mali' => ['Bamako','Sikasso','Mopti','Ségou','Koutiala','Kayes','Gao','Tombouctou','Kati','San','Kita','Bougouni','Niono','Markala','Kolokani','Banamba','Nara','Douentza','Bandiagara','Diré'],
	'Cameroon' => ['Douala','Yaoundé','Garoua','Bamenda','Maroua','Bafoussam','Ngaoundéré','Bertoua','Kumba','Nkongsamba','Limbe','Buea','Kribi','Edéa','Ebolowa','Foumban','Dschang','Kumbo','Yagoua','Guider'],
	'Cote D’ivoire' => ['Abidjan','Bouaké','Daloa','Yamoussoukro','San-Pédro','Korhogo','Man','Gagnoa','Divo','Abengourou','Agboville','Grand-Bassam','Bondoukou','Odienné','Séguéla','Soubré','Sassandra','Ferkessédougou','Katiola','Dimbokro'],
	'Nigeria' => ['Lagos','Kano','Ibadan','Abuja','Port Harcourt','Benin City','Maiduguri','Zaria','Aba','Jos','Ilorin','Oyo','Enugu','Abeokuta','Kaduna','Warri','Calabar','Uyo','Owerri','Onitsha'],
];

if (
	isset( $afl_location_map[ $country ] ) &&
	in_array( $city, $afl_location_map[ $country ], true )
) {
	update_user_meta( $user_id, 'all_country', $country );
	update_user_meta( $user_id, 'all_city', $city );
} else {
	update_user_meta( $user_id, 'all_country', '' );
	update_user_meta( $user_id, 'all_city', '' );
}
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
	update_user_meta( $user_id, 'all_partner_gender', sanitize_text_field( $data['partner_gender'] ?? '' ) );
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
	$partner_gender  = get_user_meta( $user_id, 'all_partner_gender', true );
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
	
	$supported_countries = [
	'Benin',
	'Togo',
	'Niger',
	'Burkina Faso',
	'Senegal',
	'Mali',
	'Cameroon',
	'Cote D’ivoire',
	'Nigeria',
];

$supported_cities = [];

	if ( isset( $_POST['all_profile_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['all_profile_nonce'] ) ), 'all_save_profile' ) ) {
		all_profile_save_data( $user_id, $_POST );
		wp_safe_redirect( site_url( '/meet-singles/' ) );
		exit;
	}

	$photo_id  = (int) get_user_meta( $user_id, 'all_profile_photo', true );
	$photo_url = $photo_id ? wp_get_attachment_image_url( $photo_id, 'medium' ) : 'https://www.gravatar.com/avatar/?s=200&d=mp';
	$photo_big = $photo_id ? wp_get_attachment_image_url( $photo_id, 'full' ) : $photo_url;

   
	$afl_location_map = [
	'Benin' => ['Cotonou','Abomey-Calavi','Porto-Novo','Parakou','Djougou','Bohicon','Kandi','Ouidah','Abomey','Natitingou','Lokossa','Comè','Allada','Sèmè-Kpodji','Savè','Savalou','Dassa-Zoumé','Nikki','Malanville','Tanguiéta','Glazoue'],
	'Togo' => ['Lomé','Sokodé','Kara','Atakpamé','Kpalimé','Tsévié','Dapaong','Aného','Bassar','Mango','Notsé','Kandé','Vogan','Tabligbo','Bafilo','Sotouboua','Blitta','Pagouda','Cinkassé','Badou'],
	'Niger' => ['Niamey','Maradi','Zinder','Tahoua','Agadez','Dosso','Diffa','Tillabéri','Arlit','Gaya','Tessaoua','Magaria','Dakoro',"Birni N'Konni",'Madarounfa','Filingué','Balleyara','Say','Téra','Nguigmi'],
	'Burkina Faso' => ['Ouagadougou','Bobo-Dioulasso','Koudougou','Ouahigouya','Banfora','Kaya','Tenkodogo',"Fada N'gourma",'Dédougou','Gaoua','Ziniaré','Kombissiri','Pô','Houndé','Boromo','Réo','Kongoussi','Zorgho','Tougan','Dori'],
	'Senegal' => ['Dakar','Pikine','Touba','Thiès','Rufisque','Kaolack','Ziguinchor','Saint-Louis','Mbour','Diourbel','Kolda','Tambacounda','Louga','Matam','Sédhiou','Kaffrine','Kédougou','Richard-Toll','Podor','Dagana'],
	'Mali' => ['Bamako','Sikasso','Mopti','Ségou','Koutiala','Kayes','Gao','Tombouctou','Kati','San','Kita','Bougouni','Niono','Markala','Kolokani','Banamba','Nara','Douentza','Bandiagara','Diré'],
	'Cameroon' => ['Douala','Yaoundé','Garoua','Bamenda','Maroua','Bafoussam','Ngaoundéré','Bertoua','Kumba','Nkongsamba','Limbe','Buea','Kribi','Edéa','Ebolowa','Foumban','Dschang','Kumbo','Yagoua','Guider'],
	'Cote D’ivoire' => ['Abidjan','Bouaké','Daloa','Yamoussoukro','San-Pédro','Korhogo','Man','Gagnoa','Divo','Abengourou','Agboville','Grand-Bassam','Bondoukou','Odienné','Séguéla','Soubré','Sassandra','Ferkessédougou','Katiola','Dimbokro'],
	'Nigeria' => ['Lagos','Kano','Ibadan','Abuja','Port Harcourt','Benin City','Maiduguri','Zaria','Aba','Jos','Ilorin','Oyo','Enugu','Abeokuta','Kaduna','Warri','Calabar','Uyo','Owerri','Onitsha'],
];

if ( isset( $afl_location_map[ $country ] ) ) {
	$supported_cities = $afl_location_map[ $country ];
}

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

				<p class="all-profile-headline"></p>
				<p>Add a short line that grabs attention. Max 25 characters.</p>
				<input
					type="text"
					name="headline"
					placeholder="Add a short line that grabs attention. Max 25 characters."
					value="<?php echo esc_attr( $headline ); ?>"
				>
			</div>
		</div>

		<div class="all-gallery-container" id="photos">
			<p>Bronze members can upload 1 main profile photo, Silver members up to 4 photos, and Gold members up to 7 photos. Upload JPG or PNG images only, with a maximum file size of 1 MB per image. Upgrade here.</p>
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

			<label>Describe the kind of partner and relationship you hope to find. Max 150 words.</label>
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

					<!--
					<label>Country</label>
					<input type="text" name="country" value="<?php echo esc_attr( $country ); ?>">

					<label>City / Region</label>
					<input type="text" name="city" value="<?php echo esc_attr( $city ); ?>">
					-->

					<label>Country</label>
					<select name="country" id="afl-profile-country" required>
						<option value="">Select country</option>
						<option value="Benin" <?php selected( $country, 'Benin' ); ?>>Benin</option>
						<option value="Togo" <?php selected( $country, 'Togo' ); ?>>Togo</option>
						<option value="Niger" <?php selected( $country, 'Niger' ); ?>>Niger</option>
						<option value="Burkina Faso" <?php selected( $country, 'Burkina Faso' ); ?>>Burkina Faso</option>
						<option value="Senegal" <?php selected( $country, 'Senegal' ); ?>>Senegal</option>
						<option value="Mali" <?php selected( $country, 'Mali' ); ?>>Mali</option>
						<option value="Cameroon" <?php selected( $country, 'Cameroon' ); ?>>Cameroon</option>
						<option value="Cote D’ivoire" <?php selected( $country, 'Cote D’ivoire' ); ?>>Cote D’ivoire</option>
						<option value="Nigeria" <?php selected( $country, 'Nigeria' ); ?>>Nigeria</option>
					</select>

					<label>City / Region</label>
					<select name="city" id="afl-profile-city" required>
						<option value="">Select city</option>

						<?php if ( 'Benin' === $country ) : ?>
							<option value="Cotonou" <?php selected( $city, 'Cotonou' ); ?>>Cotonou</option>
							<option value="Abomey-Calavi" <?php selected( $city, 'Abomey-Calavi' ); ?>>Abomey-Calavi</option>
							<option value="Porto-Novo" <?php selected( $city, 'Porto-Novo' ); ?>>Porto-Novo</option>
							<option value="Parakou" <?php selected( $city, 'Parakou' ); ?>>Parakou</option>
							<option value="Djougou" <?php selected( $city, 'Djougou' ); ?>>Djougou</option>
							<option value="Bohicon" <?php selected( $city, 'Bohicon' ); ?>>Bohicon</option>
							<option value="Kandi" <?php selected( $city, 'Kandi' ); ?>>Kandi</option>
							<option value="Ouidah" <?php selected( $city, 'Ouidah' ); ?>>Ouidah</option>
							<option value="Abomey" <?php selected( $city, 'Abomey' ); ?>>Abomey</option>
							<option value="Natitingou" <?php selected( $city, 'Natitingou' ); ?>>Natitingou</option>
							<option value="Lokossa" <?php selected( $city, 'Lokossa' ); ?>>Lokossa</option>
							<option value="Comè" <?php selected( $city, 'Comè' ); ?>>Comè</option>
							<option value="Allada" <?php selected( $city, 'Allada' ); ?>>Allada</option>
							<option value="Sèmè-Kpodji" <?php selected( $city, 'Sèmè-Kpodji' ); ?>>Sèmè-Kpodji</option>
							<option value="Savè" <?php selected( $city, 'Savè' ); ?>>Savè</option>
							<option value="Savalou" <?php selected( $city, 'Savalou' ); ?>>Savalou</option>
							<option value="Dassa-Zoumé" <?php selected( $city, 'Dassa-Zoumé' ); ?>>Dassa-Zoumé</option>
							<option value="Nikki" <?php selected( $city, 'Nikki' ); ?>>Nikki</option>
							<option value="Malanville" <?php selected( $city, 'Malanville' ); ?>>Malanville</option>
							<option value="Tanguiéta" <?php selected( $city, 'Tanguiéta' ); ?>>Tanguiéta</option>
							<option value="Glazoue" <?php selected( $city, 'Glazoue' ); ?>>Glazoue</option>

						<?php elseif ( 'Togo' === $country ) : ?>
							<option value="Lomé" <?php selected( $city, 'Lomé' ); ?>>Lomé</option>
							<option value="Sokodé" <?php selected( $city, 'Sokodé' ); ?>>Sokodé</option>
							<option value="Kara" <?php selected( $city, 'Kara' ); ?>>Kara</option>
							<option value="Atakpamé" <?php selected( $city, 'Atakpamé' ); ?>>Atakpamé</option>
							<option value="Kpalimé" <?php selected( $city, 'Kpalimé' ); ?>>Kpalimé</option>
							<option value="Tsévié" <?php selected( $city, 'Tsévié' ); ?>>Tsévié</option>
							<option value="Dapaong" <?php selected( $city, 'Dapaong' ); ?>>Dapaong</option>
							<option value="Aného" <?php selected( $city, 'Aného' ); ?>>Aného</option>
							<option value="Bassar" <?php selected( $city, 'Bassar' ); ?>>Bassar</option>
							<option value="Mango" <?php selected( $city, 'Mango' ); ?>>Mango</option>
							<option value="Notsé" <?php selected( $city, 'Notsé' ); ?>>Notsé</option>
							<option value="Kandé" <?php selected( $city, 'Kandé' ); ?>>Kandé</option>
							<option value="Vogan" <?php selected( $city, 'Vogan' ); ?>>Vogan</option>
							<option value="Tabligbo" <?php selected( $city, 'Tabligbo' ); ?>>Tabligbo</option>
							<option value="Bafilo" <?php selected( $city, 'Bafilo' ); ?>>Bafilo</option>
							<option value="Sotouboua" <?php selected( $city, 'Sotouboua' ); ?>>Sotouboua</option>
							<option value="Blitta" <?php selected( $city, 'Blitta' ); ?>>Blitta</option>
							<option value="Pagouda" <?php selected( $city, 'Pagouda' ); ?>>Pagouda</option>
							<option value="Cinkassé" <?php selected( $city, 'Cinkassé' ); ?>>Cinkassé</option>
							<option value="Badou" <?php selected( $city, 'Badou' ); ?>>Badou</option>

						<?php elseif ( 'Niger' === $country ) : ?>
							<option value="Niamey" <?php selected( $city, 'Niamey' ); ?>>Niamey</option>
							<option value="Maradi" <?php selected( $city, 'Maradi' ); ?>>Maradi</option>
							<option value="Zinder" <?php selected( $city, 'Zinder' ); ?>>Zinder</option>
							<option value="Tahoua" <?php selected( $city, 'Tahoua' ); ?>>Tahoua</option>
							<option value="Agadez" <?php selected( $city, 'Agadez' ); ?>>Agadez</option>
							<option value="Dosso" <?php selected( $city, 'Dosso' ); ?>>Dosso</option>
							<option value="Diffa" <?php selected( $city, 'Diffa' ); ?>>Diffa</option>
							<option value="Tillabéri" <?php selected( $city, 'Tillabéri' ); ?>>Tillabéri</option>
							<option value="Arlit" <?php selected( $city, 'Arlit' ); ?>>Arlit</option>
							<option value="Gaya" <?php selected( $city, 'Gaya' ); ?>>Gaya</option>
							<option value="Tessaoua" <?php selected( $city, 'Tessaoua' ); ?>>Tessaoua</option>
							<option value="Magaria" <?php selected( $city, 'Magaria' ); ?>>Magaria</option>
							<option value="Dakoro" <?php selected( $city, 'Dakoro' ); ?>>Dakoro</option>
							<option value="Birni N'Konni" <?php selected( $city, "Birni N'Konni" ); ?>>Birni N'Konni</option>
							<option value="Madarounfa" <?php selected( $city, 'Madarounfa' ); ?>>Madarounfa</option>
							<option value="Filingué" <?php selected( $city, 'Filingué' ); ?>>Filingué</option>
							<option value="Balleyara" <?php selected( $city, 'Balleyara' ); ?>>Balleyara</option>
							<option value="Say" <?php selected( $city, 'Say' ); ?>>Say</option>
							<option value="Téra" <?php selected( $city, 'Téra' ); ?>>Téra</option>
							<option value="Nguigmi" <?php selected( $city, 'Nguigmi' ); ?>>Nguigmi</option>

						<?php elseif ( 'Burkina Faso' === $country ) : ?>
							<option value="Ouagadougou" <?php selected( $city, 'Ouagadougou' ); ?>>Ouagadougou</option>
							<option value="Bobo-Dioulasso" <?php selected( $city, 'Bobo-Dioulasso' ); ?>>Bobo-Dioulasso</option>
							<option value="Koudougou" <?php selected( $city, 'Koudougou' ); ?>>Koudougou</option>
							<option value="Ouahigouya" <?php selected( $city, 'Ouahigouya' ); ?>>Ouahigouya</option>
							<option value="Banfora" <?php selected( $city, 'Banfora' ); ?>>Banfora</option>
							<option value="Kaya" <?php selected( $city, 'Kaya' ); ?>>Kaya</option>
							<option value="Tenkodogo" <?php selected( $city, 'Tenkodogo' ); ?>>Tenkodogo</option>
							<option value="Fada N'gourma" <?php selected( $city, "Fada N'gourma" ); ?>>Fada N'gourma</option>
							<option value="Dédougou" <?php selected( $city, 'Dédougou' ); ?>>Dédougou</option>
							<option value="Gaoua" <?php selected( $city, 'Gaoua' ); ?>>Gaoua</option>
							<option value="Ziniaré" <?php selected( $city, 'Ziniaré' ); ?>>Ziniaré</option>
							<option value="Kombissiri" <?php selected( $city, 'Kombissiri' ); ?>>Kombissiri</option>
							<option value="Pô" <?php selected( $city, 'Pô' ); ?>>Pô</option>
							<option value="Houndé" <?php selected( $city, 'Houndé' ); ?>>Houndé</option>
							<option value="Boromo" <?php selected( $city, 'Boromo' ); ?>>Boromo</option>
							<option value="Réo" <?php selected( $city, 'Réo' ); ?>>Réo</option>
							<option value="Kongoussi" <?php selected( $city, 'Kongoussi' ); ?>>Kongoussi</option>
							<option value="Zorgho" <?php selected( $city, 'Zorgho' ); ?>>Zorgho</option>
							<option value="Tougan" <?php selected( $city, 'Tougan' ); ?>>Tougan</option>
							<option value="Dori" <?php selected( $city, 'Dori' ); ?>>Dori</option>

						<?php elseif ( 'Senegal' === $country ) : ?>
							<option value="Dakar" <?php selected( $city, 'Dakar' ); ?>>Dakar</option>
							<option value="Pikine" <?php selected( $city, 'Pikine' ); ?>>Pikine</option>
							<option value="Touba" <?php selected( $city, 'Touba' ); ?>>Touba</option>
							<option value="Thiès" <?php selected( $city, 'Thiès' ); ?>>Thiès</option>
							<option value="Rufisque" <?php selected( $city, 'Rufisque' ); ?>>Rufisque</option>
							<option value="Kaolack" <?php selected( $city, 'Kaolack' ); ?>>Kaolack</option>
							<option value="Ziguinchor" <?php selected( $city, 'Ziguinchor' ); ?>>Ziguinchor</option>
							<option value="Saint-Louis" <?php selected( $city, 'Saint-Louis' ); ?>>Saint-Louis</option>
							<option value="Mbour" <?php selected( $city, 'Mbour' ); ?>>Mbour</option>
							<option value="Diourbel" <?php selected( $city, 'Diourbel' ); ?>>Diourbel</option>
							<option value="Kolda" <?php selected( $city, 'Kolda' ); ?>>Kolda</option>
							<option value="Tambacounda" <?php selected( $city, 'Tambacounda' ); ?>>Tambacounda</option>
							<option value="Louga" <?php selected( $city, 'Louga' ); ?>>Louga</option>
							<option value="Matam" <?php selected( $city, 'Matam' ); ?>>Matam</option>
							<option value="Sédhiou" <?php selected( $city, 'Sédhiou' ); ?>>Sédhiou</option>
							<option value="Kaffrine" <?php selected( $city, 'Kaffrine' ); ?>>Kaffrine</option>
							<option value="Kédougou" <?php selected( $city, 'Kédougou' ); ?>>Kédougou</option>
							<option value="Richard-Toll" <?php selected( $city, 'Richard-Toll' ); ?>>Richard-Toll</option>
							<option value="Podor" <?php selected( $city, 'Podor' ); ?>>Podor</option>
							<option value="Dagana" <?php selected( $city, 'Dagana' ); ?>>Dagana</option>

						<?php elseif ( 'Mali' === $country ) : ?>
							<option value="Bamako" <?php selected( $city, 'Bamako' ); ?>>Bamako</option>
							<option value="Sikasso" <?php selected( $city, 'Sikasso' ); ?>>Sikasso</option>
							<option value="Mopti" <?php selected( $city, 'Mopti' ); ?>>Mopti</option>
							<option value="Ségou" <?php selected( $city, 'Ségou' ); ?>>Ségou</option>
							<option value="Koutiala" <?php selected( $city, 'Koutiala' ); ?>>Koutiala</option>
							<option value="Kayes" <?php selected( $city, 'Kayes' ); ?>>Kayes</option>
							<option value="Gao" <?php selected( $city, 'Gao' ); ?>>Gao</option>
							<option value="Tombouctou" <?php selected( $city, 'Tombouctou' ); ?>>Tombouctou</option>
							<option value="Kati" <?php selected( $city, 'Kati' ); ?>>Kati</option>
							<option value="San" <?php selected( $city, 'San' ); ?>>San</option>
							<option value="Kita" <?php selected( $city, 'Kita' ); ?>>Kita</option>
							<option value="Bougouni" <?php selected( $city, 'Bougouni' ); ?>>Bougouni</option>
							<option value="Niono" <?php selected( $city, 'Niono' ); ?>>Niono</option>
							<option value="Markala" <?php selected( $city, 'Markala' ); ?>>Markala</option>
							<option value="Kolokani" <?php selected( $city, 'Kolokani' ); ?>>Kolokani</option>
							<option value="Banamba" <?php selected( $city, 'Banamba' ); ?>>Banamba</option>
							<option value="Nara" <?php selected( $city, 'Nara' ); ?>>Nara</option>
							<option value="Douentza" <?php selected( $city, 'Douentza' ); ?>>Douentza</option>
							<option value="Bandiagara" <?php selected( $city, 'Bandiagara' ); ?>>Bandiagara</option>
							<option value="Diré" <?php selected( $city, 'Diré' ); ?>>Diré</option>

						<?php elseif ( 'Cameroon' === $country ) : ?>
							<option value="Douala" <?php selected( $city, 'Douala' ); ?>>Douala</option>
							<option value="Yaoundé" <?php selected( $city, 'Yaoundé' ); ?>>Yaoundé</option>
							<option value="Garoua" <?php selected( $city, 'Garoua' ); ?>>Garoua</option>
							<option value="Bamenda" <?php selected( $city, 'Bamenda' ); ?>>Bamenda</option>
							<option value="Maroua" <?php selected( $city, 'Maroua' ); ?>>Maroua</option>
							<option value="Bafoussam" <?php selected( $city, 'Bafoussam' ); ?>>Bafoussam</option>
							<option value="Ngaoundéré" <?php selected( $city, 'Ngaoundéré' ); ?>>Ngaoundéré</option>
							<option value="Bertoua" <?php selected( $city, 'Bertoua' ); ?>>Bertoua</option>
							<option value="Kumba" <?php selected( $city, 'Kumba' ); ?>>Kumba</option>
							<option value="Nkongsamba" <?php selected( $city, 'Nkongsamba' ); ?>>Nkongsamba</option>
							<option value="Limbe" <?php selected( $city, 'Limbe' ); ?>>Limbe</option>
							<option value="Buea" <?php selected( $city, 'Buea' ); ?>>Buea</option>
							<option value="Kribi" <?php selected( $city, 'Kribi' ); ?>>Kribi</option>
							<option value="Edéa" <?php selected( $city, 'Edéa' ); ?>>Edéa</option>
							<option value="Ebolowa" <?php selected( $city, 'Ebolowa' ); ?>>Ebolowa</option>
							<option value="Foumban" <?php selected( $city, 'Foumban' ); ?>>Foumban</option>
							<option value="Dschang" <?php selected( $city, 'Dschang' ); ?>>Dschang</option>
							<option value="Kumbo" <?php selected( $city, 'Kumbo' ); ?>>Kumbo</option>
							<option value="Yagoua" <?php selected( $city, 'Yagoua' ); ?>>Yagoua</option>
							<option value="Guider" <?php selected( $city, 'Guider' ); ?>>Guider</option>

						<?php elseif ( 'Cote D’ivoire' === $country ) : ?>
							<option value="Abidjan" <?php selected( $city, 'Abidjan' ); ?>>Abidjan</option>
							<option value="Bouaké" <?php selected( $city, 'Bouaké' ); ?>>Bouaké</option>
							<option value="Daloa" <?php selected( $city, 'Daloa' ); ?>>Daloa</option>
							<option value="Yamoussoukro" <?php selected( $city, 'Yamoussoukro' ); ?>>Yamoussoukro</option>
							<option value="San-Pédro" <?php selected( $city, 'San-Pédro' ); ?>>San-Pédro</option>
							<option value="Korhogo" <?php selected( $city, 'Korhogo' ); ?>>Korhogo</option>
							<option value="Man" <?php selected( $city, 'Man' ); ?>>Man</option>
							<option value="Gagnoa" <?php selected( $city, 'Gagnoa' ); ?>>Gagnoa</option>
							<option value="Divo" <?php selected( $city, 'Divo' ); ?>>Divo</option>
							<option value="Abengourou" <?php selected( $city, 'Abengourou' ); ?>>Abengourou</option>
							<option value="Agboville" <?php selected( $city, 'Agboville' ); ?>>Agboville</option>
							<option value="Grand-Bassam" <?php selected( $city, 'Grand-Bassam' ); ?>>Grand-Bassam</option>
							<option value="Bondoukou" <?php selected( $city, 'Bondoukou' ); ?>>Bondoukou</option>
							<option value="Odienné" <?php selected( $city, 'Odienné' ); ?>>Odienné</option>
							<option value="Séguéla" <?php selected( $city, 'Séguéla' ); ?>>Séguéla</option>
							<option value="Soubré" <?php selected( $city, 'Soubré' ); ?>>Soubré</option>
							<option value="Sassandra" <?php selected( $city, 'Sassandra' ); ?>>Sassandra</option>
							<option value="Ferkessédougou" <?php selected( $city, 'Ferkessédougou' ); ?>>Ferkessédougou</option>
							<option value="Katiola" <?php selected( $city, 'Katiola' ); ?>>Katiola</option>
							<option value="Dimbokro" <?php selected( $city, 'Dimbokro' ); ?>>Dimbokro</option>

						<?php elseif ( 'Nigeria' === $country ) : ?>
							<option value="Lagos" <?php selected( $city, 'Lagos' ); ?>>Lagos</option>
							<option value="Kano" <?php selected( $city, 'Kano' ); ?>>Kano</option>
							<option value="Ibadan" <?php selected( $city, 'Ibadan' ); ?>>Ibadan</option>
							<option value="Abuja" <?php selected( $city, 'Abuja' ); ?>>Abuja</option>
							<option value="Port Harcourt" <?php selected( $city, 'Port Harcourt' ); ?>>Port Harcourt</option>
							<option value="Benin City" <?php selected( $city, 'Benin City' ); ?>>Benin City</option>
							<option value="Maiduguri" <?php selected( $city, 'Maiduguri' ); ?>>Maiduguri</option>
							<option value="Zaria" <?php selected( $city, 'Zaria' ); ?>>Zaria</option>
							<option value="Aba" <?php selected( $city, 'Aba' ); ?>>Aba</option>
							<option value="Jos" <?php selected( $city, 'Jos' ); ?>>Jos</option>
							<option value="Ilorin" <?php selected( $city, 'Ilorin' ); ?>>Ilorin</option>
							<option value="Oyo" <?php selected( $city, 'Oyo' ); ?>>Oyo</option>
							<option value="Enugu" <?php selected( $city, 'Enugu' ); ?>>Enugu</option>
							<option value="Abeokuta" <?php selected( $city, 'Abeokuta' ); ?>>Abeokuta</option>
							<option value="Kaduna" <?php selected( $city, 'Kaduna' ); ?>>Kaduna</option>
							<option value="Warri" <?php selected( $city, 'Warri' ); ?>>Warri</option>
							<option value="Calabar" <?php selected( $city, 'Calabar' ); ?>>Calabar</option>
							<option value="Uyo" <?php selected( $city, 'Uyo' ); ?>>Uyo</option>
							<option value="Owerri" <?php selected( $city, 'Owerri' ); ?>>Owerri</option>
							<option value="Onitsha" <?php selected( $city, 'Onitsha' ); ?>>Onitsha</option>
						<?php endif; ?>
					</select>

					<label>Education</label>
					<input type="text" name="education" value="<?php echo esc_attr( $education ); ?>" required>

					<label>Occupation</label>
					<input type="text" name="occupation" value="<?php echo esc_attr( $occupation ); ?>" required>
				</div>

				<div>
					<h3>I'm Looking For</h3>

					<label>Preferred Gender</label>
					<select name="partner_gender">
						<option value="">Select</option>
						<option value="Male" <?php selected( $partner_gender, 'Male' ); ?>>Male</option>
						<option value="Female" <?php selected( $partner_gender, 'Female' ); ?>>Female</option>
						<option value="Other" <?php selected( $partner_gender, 'Other' ); ?>>Other</option>
						<option value="Any" <?php selected( $partner_gender, 'Any' ); ?>>Any</option>
					</select>

					<label>Preferred Age Range</label>
					<div class="all-flex">
						<input type="number" name="partner_age_min" placeholder="18" min="18" max="90" value="<?php echo esc_attr( $partner_age_min ); ?>" required>
						<input type="number" name="partner_age_max" placeholder="18" min="18" max="90" value="<?php echo esc_attr( $partner_age_max ); ?>" required>
					</div>

					<label>Preferred Country / Region</label>
					<select name="partner_country">
						<option value="">Select preferred country / region</option>

						<optgroup label="Benin">
							<option value="Benin - Cotonou" <?php selected( $partner_country, 'Benin - Cotonou' ); ?>>Cotonou</option>
							<option value="Benin - Abomey-Calavi" <?php selected( $partner_country, 'Benin - Abomey-Calavi' ); ?>>Abomey-Calavi</option>
							<option value="Benin - Porto-Novo" <?php selected( $partner_country, 'Benin - Porto-Novo' ); ?>>Porto-Novo</option>
							<option value="Benin - Parakou" <?php selected( $partner_country, 'Benin - Parakou' ); ?>>Parakou</option>
							<option value="Benin - Djougou" <?php selected( $partner_country, 'Benin - Djougou' ); ?>>Djougou</option>
							<option value="Benin - Bohicon" <?php selected( $partner_country, 'Benin - Bohicon' ); ?>>Bohicon</option>
							<option value="Benin - Kandi" <?php selected( $partner_country, 'Benin - Kandi' ); ?>>Kandi</option>
							<option value="Benin - Ouidah" <?php selected( $partner_country, 'Benin - Ouidah' ); ?>>Ouidah</option>
							<option value="Benin - Abomey" <?php selected( $partner_country, 'Benin - Abomey' ); ?>>Abomey</option>
							<option value="Benin - Natitingou" <?php selected( $partner_country, 'Benin - Natitingou' ); ?>>Natitingou</option>
							<option value="Benin - Lokossa" <?php selected( $partner_country, 'Benin - Lokossa' ); ?>>Lokossa</option>
							<option value="Benin - Come" <?php selected( $partner_country, 'Benin - Come' ); ?>>Comè</option>
							<option value="Benin - Allada" <?php selected( $partner_country, 'Benin - Allada' ); ?>>Allada</option>
							<option value="Benin - Seme-Kpodji" <?php selected( $partner_country, 'Benin - Seme-Kpodji' ); ?>>Sèmè-Kpodji</option>
							<option value="Benin - Save" <?php selected( $partner_country, 'Benin - Save' ); ?>>Savè</option>
							<option value="Benin - Savalou" <?php selected( $partner_country, 'Benin - Savalou' ); ?>>Savalou</option>
							<option value="Benin - Dassa-Zoume" <?php selected( $partner_country, 'Benin - Dassa-Zoume' ); ?>>Dassa-Zoumé</option>
							<option value="Benin - Nikki" <?php selected( $partner_country, 'Benin - Nikki' ); ?>>Nikki</option>
							<option value="Benin - Malanville" <?php selected( $partner_country, 'Benin - Malanville' ); ?>>Malanville</option>
							<option value="Benin - Tanguieta" <?php selected( $partner_country, 'Benin - Tanguieta' ); ?>>Tanguiéta</option>
							<option value="Benin - Glazoue" <?php selected( $partner_country, 'Benin - Glazoue' ); ?>>Glazoue</option>
						</optgroup>

						<optgroup label="Togo">
							<option value="Togo - Lome" <?php selected( $partner_country, 'Togo - Lome' ); ?>>Lomé</option>
							<option value="Togo - Sokode" <?php selected( $partner_country, 'Togo - Sokode' ); ?>>Sokodé</option>
							<option value="Togo - Kara" <?php selected( $partner_country, 'Togo - Kara' ); ?>>Kara</option>
							<option value="Togo - Atakpame" <?php selected( $partner_country, 'Togo - Atakpame' ); ?>>Atakpamé</option>
							<option value="Togo - Kpalime" <?php selected( $partner_country, 'Togo - Kpalime' ); ?>>Kpalimé</option>
							<option value="Togo - Tsevie" <?php selected( $partner_country, 'Togo - Tsevie' ); ?>>Tsévié</option>
							<option value="Togo - Dapaong" <?php selected( $partner_country, 'Togo - Dapaong' ); ?>>Dapaong</option>
							<option value="Togo - Aneho" <?php selected( $partner_country, 'Togo - Aneho' ); ?>>Aného</option>
							<option value="Togo - Bassar" <?php selected( $partner_country, 'Togo - Bassar' ); ?>>Bassar</option>
							<option value="Togo - Mango" <?php selected( $partner_country, 'Togo - Mango' ); ?>>Mango</option>
							<option value="Togo - Notse" <?php selected( $partner_country, 'Togo - Notse' ); ?>>Notsé</option>
							<option value="Togo - Kande" <?php selected( $partner_country, 'Togo - Kande' ); ?>>Kandé</option>
							<option value="Togo - Vogan" <?php selected( $partner_country, 'Togo - Vogan' ); ?>>Vogan</option>
							<option value="Togo - Tabligbo" <?php selected( $partner_country, 'Togo - Tabligbo' ); ?>>Tabligbo</option>
							<option value="Togo - Bafilo" <?php selected( $partner_country, 'Togo - Bafilo' ); ?>>Bafilo</option>
							<option value="Togo - Sotouboua" <?php selected( $partner_country, 'Togo - Sotouboua' ); ?>>Sotouboua</option>
							<option value="Togo - Blitta" <?php selected( $partner_country, 'Togo - Blitta' ); ?>>Blitta</option>
							<option value="Togo - Pagouda" <?php selected( $partner_country, 'Togo - Pagouda' ); ?>>Pagouda</option>
							<option value="Togo - Cinkasse" <?php selected( $partner_country, 'Togo - Cinkasse' ); ?>>Cinkassé</option>
							<option value="Togo - Badou" <?php selected( $partner_country, 'Togo - Badou' ); ?>>Badou</option>
						</optgroup>

						<optgroup label="Niger">
							<option value="Niger - Niamey" <?php selected( $partner_country, 'Niger - Niamey' ); ?>>Niamey</option>
							<option value="Niger - Maradi" <?php selected( $partner_country, 'Niger - Maradi' ); ?>>Maradi</option>
							<option value="Niger - Zinder" <?php selected( $partner_country, 'Niger - Zinder' ); ?>>Zinder</option>
							<option value="Niger - Tahoua" <?php selected( $partner_country, 'Niger - Tahoua' ); ?>>Tahoua</option>
							<option value="Niger - Agadez" <?php selected( $partner_country, 'Niger - Agadez' ); ?>>Agadez</option>
							<option value="Niger - Dosso" <?php selected( $partner_country, 'Niger - Dosso' ); ?>>Dosso</option>
							<option value="Niger - Diffa" <?php selected( $partner_country, 'Niger - Diffa' ); ?>>Diffa</option>
							<option value="Niger - Tillaberi" <?php selected( $partner_country, 'Niger - Tillaberi' ); ?>>Tillabéri</option>
							<option value="Niger - Arlit" <?php selected( $partner_country, 'Niger - Arlit' ); ?>>Arlit</option>
							<option value="Niger - Gaya" <?php selected( $partner_country, 'Niger - Gaya' ); ?>>Gaya</option>
							<option value="Niger - Tessaoua" <?php selected( $partner_country, 'Niger - Tessaoua' ); ?>>Tessaoua</option>
							<option value="Niger - Magaria" <?php selected( $partner_country, 'Niger - Magaria' ); ?>>Magaria</option>
							<option value="Niger - Dakoro" <?php selected( $partner_country, 'Niger - Dakoro' ); ?>>Dakoro</option>
							<option value="Niger - Birni N'Konni" <?php selected( $partner_country, "Niger - Birni N'Konni" ); ?>>Birni N'Konni</option>
							<option value="Niger - Madarounfa" <?php selected( $partner_country, 'Niger - Madarounfa' ); ?>>Madarounfa</option>
							<option value="Niger - Filingue" <?php selected( $partner_country, 'Niger - Filingue' ); ?>>Filingué</option>
							<option value="Niger - Balleyara" <?php selected( $partner_country, 'Niger - Balleyara' ); ?>>Balleyara</option>
							<option value="Niger - Say" <?php selected( $partner_country, 'Niger - Say' ); ?>>Say</option>
							<option value="Niger - Tera" <?php selected( $partner_country, 'Niger - Tera' ); ?>>Téra</option>
							<option value="Niger - Nguigmi" <?php selected( $partner_country, 'Niger - Nguigmi' ); ?>>Nguigmi</option>
						</optgroup>

						<optgroup label="Burkina Faso">
							<option value="Burkina Faso - Ouagadougou" <?php selected( $partner_country, 'Burkina Faso - Ouagadougou' ); ?>>Ouagadougou</option>
							<option value="Burkina Faso - Bobo-Dioulasso" <?php selected( $partner_country, 'Burkina Faso - Bobo-Dioulasso' ); ?>>Bobo-Dioulasso</option>
							<option value="Burkina Faso - Koudougou" <?php selected( $partner_country, 'Burkina Faso - Koudougou' ); ?>>Koudougou</option>
							<option value="Burkina Faso - Ouahigouya" <?php selected( $partner_country, 'Burkina Faso - Ouahigouya' ); ?>>Ouahigouya</option>
							<option value="Burkina Faso - Banfora" <?php selected( $partner_country, 'Burkina Faso - Banfora' ); ?>>Banfora</option>
							<option value="Burkina Faso - Kaya" <?php selected( $partner_country, 'Burkina Faso - Kaya' ); ?>>Kaya</option>
							<option value="Burkina Faso - Tenkodogo" <?php selected( $partner_country, 'Burkina Faso - Tenkodogo' ); ?>>Tenkodogo</option>
							<option value="Burkina Faso - Fada N'gourma" <?php selected( $partner_country, "Burkina Faso - Fada N'gourma" ); ?>>Fada N'gourma</option>
							<option value="Burkina Faso - Dedougou" <?php selected( $partner_country, 'Burkina Faso - Dedougou' ); ?>>Dédougou</option>
							<option value="Burkina Faso - Gaoua" <?php selected( $partner_country, 'Burkina Faso - Gaoua' ); ?>>Gaoua</option>
							<option value="Burkina Faso - Zিনiare" <?php selected( $partner_country, 'Burkina Faso - Ziniaré' ); ?>>Ziniaré</option>
							<option value="Burkina Faso - Kombissiri" <?php selected( $partner_country, 'Burkina Faso - Kombissiri' ); ?>>Kombissiri</option>
							<option value="Burkina Faso - Po" <?php selected( $partner_country, 'Burkina Faso - Po' ); ?>>Pô</option>
							<option value="Burkina Faso - Hounde" <?php selected( $partner_country, 'Burkina Faso - Hounde' ); ?>>Houndé</option>
							<option value="Burkina Faso - Boromo" <?php selected( $partner_country, 'Burkina Faso - Boromo' ); ?>>Boromo</option>
							<option value="Burkina Faso - Reo" <?php selected( $partner_country, 'Burkina Faso - Reo' ); ?>>Réo</option>
							<option value="Burkina Faso - Kongoussi" <?php selected( $partner_country, 'Burkina Faso - Kongoussi' ); ?>>Kongoussi</option>
							<option value="Burkina Faso - Zorgho" <?php selected( $partner_country, 'Burkina Faso - Zorgho' ); ?>>Zorgho</option>
							<option value="Burkina Faso - Tougan" <?php selected( $partner_country, 'Burkina Faso - Tougan' ); ?>>Tougan</option>
							<option value="Burkina Faso - Dori" <?php selected( $partner_country, 'Burkina Faso - Dori' ); ?>>Dori</option>
						</optgroup>

						<optgroup label="Senegal">
							<option value="Senegal - Dakar" <?php selected( $partner_country, 'Senegal - Dakar' ); ?>>Dakar</option>
							<option value="Senegal - Pikine" <?php selected( $partner_country, 'Senegal - Pikine' ); ?>>Pikine</option>
							<option value="Senegal - Touba" <?php selected( $partner_country, 'Senegal - Touba' ); ?>>Touba</option>
							<option value="Senegal - Thies" <?php selected( $partner_country, 'Senegal - Thies' ); ?>>Thiès</option>
							<option value="Senegal - Rufisque" <?php selected( $partner_country, 'Senegal - Rufisque' ); ?>>Rufisque</option>
							<option value="Senegal - Kaolack" <?php selected( $partner_country, 'Senegal - Kaolack' ); ?>>Kaolack</option>
							<option value="Senegal - Ziguinchor" <?php selected( $partner_country, 'Senegal - Ziguinchor' ); ?>>Ziguinchor</option>
							<option value="Senegal - Saint-Louis" <?php selected( $partner_country, 'Senegal - Saint-Louis' ); ?>>Saint-Louis</option>
							<option value="Senegal - Mbour" <?php selected( $partner_country, 'Senegal - Mbour' ); ?>>Mbour</option>
							<option value="Senegal - Diourbel" <?php selected( $partner_country, 'Senegal - Diourbel' ); ?>>Diourbel</option>
							<option value="Senegal - Kolda" <?php selected( $partner_country, 'Senegal - Kolda' ); ?>>Kolda</option>
							<option value="Senegal - Tambacounda" <?php selected( $partner_country, 'Senegal - Tambacounda' ); ?>>Tambacounda</option>
							<option value="Senegal - Louga" <?php selected( $partner_country, 'Senegal - Louga' ); ?>>Louga</option>
							<option value="Senegal - Matam" <?php selected( $partner_country, 'Senegal - Matam' ); ?>>Matam</option>
							<option value="Senegal - Sedhiou" <?php selected( $partner_country, 'Senegal - Sedhiou' ); ?>>Sédhiou</option>
							<option value="Senegal - Kaffrine" <?php selected( $partner_country, 'Senegal - Kaffrine' ); ?>>Kaffrine</option>
							<option value="Senegal - Kedougou" <?php selected( $partner_country, 'Senegal - Kedougou' ); ?>>Kédougou</option>
							<option value="Senegal - Richard-Toll" <?php selected( $partner_country, 'Senegal - Richard-Toll' ); ?>>Richard-Toll</option>
							<option value="Senegal - Podor" <?php selected( $partner_country, 'Senegal - Podor' ); ?>>Podor</option>
							<option value="Senegal - Dagana" <?php selected( $partner_country, 'Senegal - Dagana' ); ?>>Dagana</option>
						</optgroup>

						<optgroup label="Mali">
							<option value="Mali - Bamako" <?php selected( $partner_country, 'Mali - Bamako' ); ?>>Bamako</option>
							<option value="Mali - Sikasso" <?php selected( $partner_country, 'Mali - Sikasso' ); ?>>Sikasso</option>
							<option value="Mali - Mopti" <?php selected( $partner_country, 'Mali - Mopti' ); ?>>Mopti</option>
							<option value="Mali - Segou" <?php selected( $partner_country, 'Mali - Segou' ); ?>>Ségou</option>
							<option value="Mali - Koutiala" <?php selected( $partner_country, 'Mali - Koutiala' ); ?>>Koutiala</option>
							<option value="Mali - Kayes" <?php selected( $partner_country, 'Mali - Kayes' ); ?>>Kayes</option>
							<option value="Mali - Gao" <?php selected( $partner_country, 'Mali - Gao' ); ?>>Gao</option>
							<option value="Mali - Tombouctou" <?php selected( $partner_country, 'Mali - Tombouctou' ); ?>>Tombouctou</option>
							<option value="Mali - Kati" <?php selected( $partner_country, 'Mali - Kati' ); ?>>Kati</option>
							<option value="Mali - San" <?php selected( $partner_country, 'Mali - San' ); ?>>San</option>
							<option value="Mali - Kita" <?php selected( $partner_country, 'Mali - Kita' ); ?>>Kita</option>
							<option value="Mali - Bougouni" <?php selected( $partner_country, 'Mali - Bougouni' ); ?>>Bougouni</option>
							<option value="Mali - Niono" <?php selected( $partner_country, 'Mali - Niono' ); ?>>Niono</option>
							<option value="Mali - Markala" <?php selected( $partner_country, 'Mali - Markala' ); ?>>Markala</option>
							<option value="Mali - Kolokani" <?php selected( $partner_country, 'Mali - Kolokani' ); ?>>Kolokani</option>
							<option value="Mali - Banamba" <?php selected( $partner_country, 'Mali - Banamba' ); ?>>Banamba</option>
							<option value="Mali - Nara" <?php selected( $partner_country, 'Mali - Nara' ); ?>>Nara</option>
							<option value="Mali - Douentza" <?php selected( $partner_country, 'Mali - Douentza' ); ?>>Douentza</option>
							<option value="Mali - Bandiagara" <?php selected( $partner_country, 'Mali - Bandiagara' ); ?>>Bandiagara</option>
							<option value="Mali - Dire" <?php selected( $partner_country, 'Mali - Dire' ); ?>>Diré</option>
						</optgroup>

						<optgroup label="Cameroon">
							<option value="Cameroon - Douala" <?php selected( $partner_country, 'Cameroon - Douala' ); ?>>Douala</option>
							<option value="Cameroon - Yaounde" <?php selected( $partner_country, 'Cameroon - Yaounde' ); ?>>Yaoundé</option>
							<option value="Cameroon - Garoua" <?php selected( $partner_country, 'Cameroon - Garoua' ); ?>>Garoua</option>
							<option value="Cameroon - Bamenda" <?php selected( $partner_country, 'Cameroon - Bamenda' ); ?>>Bamenda</option>
							<option value="Cameroon - Maroua" <?php selected( $partner_country, 'Cameroon - Maroua' ); ?>>Maroua</option>
							<option value="Cameroon - Bafoussam" <?php selected( $partner_country, 'Cameroon - Bafoussam' ); ?>>Bafoussam</option>
							<option value="Cameroon - Ngaoundere" <?php selected( $partner_country, 'Cameroon - Ngaoundere' ); ?>>Ngaoundéré</option>
							<option value="Cameroon - Bertoua" <?php selected( $partner_country, 'Cameroon - Bertoua' ); ?>>Bertoua</option>
							<option value="Cameroon - Kumba" <?php selected( $partner_country, 'Cameroon - Kumba' ); ?>>Kumba</option>
							<option value="Cameroon - Nkongsamba" <?php selected( $partner_country, 'Cameroon - Nkongsamba' ); ?>>Nkongsamba</option>
							<option value="Cameroon - Limbe" <?php selected( $partner_country, 'Cameroon - Limbe' ); ?>>Limbe</option>
							<option value="Cameroon - Buea" <?php selected( $partner_country, 'Cameroon - Buea' ); ?>>Buea</option>
							<option value="Cameroon - Kribi" <?php selected( $partner_country, 'Cameroon - Kribi' ); ?>>Kribi</option>
							<option value="Cameroon - Edea" <?php selected( $partner_country, 'Cameroon - Edea' ); ?>>Edéa</option>
							<option value="Cameroon - Ebolowa" <?php selected( $partner_country, 'Cameroon - Ebolowa' ); ?>>Ebolowa</option>
							<option value="Cameroon - Foumban" <?php selected( $partner_country, 'Cameroon - Foumban' ); ?>>Foumban</option>
							<option value="Cameroon - Dschang" <?php selected( $partner_country, 'Cameroon - Dschang' ); ?>>Dschang</option>
							<option value="Cameroon - Kumbo" <?php selected( $partner_country, 'Cameroon - Kumbo' ); ?>>Kumbo</option>
							<option value="Cameroon - Yagoua" <?php selected( $partner_country, 'Cameroon - Yagoua' ); ?>>Yagoua</option>
							<option value="Cameroon - Guider" <?php selected( $partner_country, 'Cameroon - Guider' ); ?>>Guider</option>
						</optgroup>

						<optgroup label="Cote D’ivoire">
							<option value="Cote D’ivoire - Abidjan" <?php selected( $partner_country, 'Cote D’ivoire - Abidjan' ); ?>>Abidjan</option>
							<option value="Cote D’ivoire - Bouake" <?php selected( $partner_country, 'Cote D’ivoire - Bouake' ); ?>>Bouaké</option>
							<option value="Cote D’ivoire - Daloa" <?php selected( $partner_country, 'Cote D’ivoire - Daloa' ); ?>>Daloa</option>
							<option value="Cote D’ivoire - Yamoussoukro" <?php selected( $partner_country, 'Cote D’ivoire - Yamoussoukro' ); ?>>Yamoussoukro</option>
							<option value="Cote D’ivoire - San-Pedro" <?php selected( $partner_country, 'Cote D’ivoire - San-Pedro' ); ?>>San-Pédro</option>
							<option value="Cote D’ivoire - Korhogo" <?php selected( $partner_country, 'Cote D’ivoire - Korhogo' ); ?>>Korhogo</option>
							<option value="Cote D’ivoire - Man" <?php selected( $partner_country, 'Cote D’ivoire - Man' ); ?>>Man</option>
							<option value="Cote D’ivoire - Gagnoa" <?php selected( $partner_country, 'Cote D’ivoire - Gagnoa' ); ?>>Gagnoa</option>
							<option value="Cote D’ivoire - Divo" <?php selected( $partner_country, 'Cote D’ivoire - Divo' ); ?>>Divo</option>
							<option value="Cote D’ivoire - Abengourou" <?php selected( $partner_country, 'Cote D’ivoire - Abengourou' ); ?>>Abengourou</option>
							<option value="Cote D’ivoire - Agboville" <?php selected( $partner_country, 'Cote D’ivoire - Agboville' ); ?>>Agboville</option>
							<option value="Cote D’ivoire - Grand-Bassam" <?php selected( $partner_country, 'Cote D’ivoire - Grand-Bassam' ); ?>>Grand-Bassam</option>
							<option value="Cote D’ivoire - Bondoukou" <?php selected( $partner_country, 'Cote D’ivoire - Bondoukou' ); ?>>Bondoukou</option>
							<option value="Cote D’ivoire - Odienne" <?php selected( $partner_country, 'Cote D’ivoire - Odienne' ); ?>>Odienné</option>
							<option value="Cote D’ivoire - Seguela" <?php selected( $partner_country, 'Cote D’ivoire - Seguela' ); ?>>Séguéla</option>
							<option value="Cote D’ivoire - Soubre" <?php selected( $partner_country, 'Cote D’ivoire - Soubre' ); ?>>Soubré</option>
							<option value="Cote D’ivoire - Sassandra" <?php selected( $partner_country, 'Cote D’ivoire - Sassandra' ); ?>>Sassandra</option>
							<option value="Cote D’ivoire - Ferkessedougou" <?php selected( $partner_country, 'Cote D’ivoire - Ferkessedougou' ); ?>>Ferkessédougou</option>
							<option value="Cote D’ivoire - Katiola" <?php selected( $partner_country, 'Cote D’ivoire - Katiola' ); ?>>Katiola</option>
							<option value="Cote D’ivoire - Dimbokro" <?php selected( $partner_country, 'Cote D’ivoire - Dimbokro' ); ?>>Dimbokro</option>
						</optgroup>

						<optgroup label="Nigeria">
							<option value="Nigeria - Lagos" <?php selected( $partner_country, 'Nigeria - Lagos' ); ?>>Lagos</option>
							<option value="Nigeria - Kano" <?php selected( $partner_country, 'Nigeria - Kano' ); ?>>Kano</option>
							<option value="Nigeria - Ibadan" <?php selected( $partner_country, 'Nigeria - Ibadan' ); ?>>Ibadan</option>
							<option value="Nigeria - Abuja" <?php selected( $partner_country, 'Nigeria - Abuja' ); ?>>Abuja</option>
							<option value="Nigeria - Port Harcourt" <?php selected( $partner_country, 'Nigeria - Port Harcourt' ); ?>>Port Harcourt</option>
							<option value="Nigeria - Benin City" <?php selected( $partner_country, 'Nigeria - Benin City' ); ?>>Benin City</option>
							<option value="Nigeria - Maiduguri" <?php selected( $partner_country, 'Nigeria - Maiduguri' ); ?>>Maiduguri</option>
							<option value="Nigeria - Zaria" <?php selected( $partner_country, 'Nigeria - Zaria' ); ?>>Zaria</option>
							<option value="Nigeria - Aba" <?php selected( $partner_country, 'Nigeria - Aba' ); ?>>Aba</option>
							<option value="Nigeria - Jos" <?php selected( $partner_country, 'Nigeria - Jos' ); ?>>Jos</option>
							<option value="Nigeria - Ilorin" <?php selected( $partner_country, 'Nigeria - Ilorin' ); ?>>Ilorin</option>
							<option value="Nigeria - Oyo" <?php selected( $partner_country, 'Nigeria - Oyo' ); ?>>Oyo</option>
							<option value="Nigeria - Enugu" <?php selected( $partner_country, 'Nigeria - Enugu' ); ?>>Enugu</option>
							<option value="Nigeria - Abeokuta" <?php selected( $partner_country, 'Nigeria - Abeokuta' ); ?>>Abeokuta</option>
							<option value="Nigeria - Kaduna" <?php selected( $partner_country, 'Nigeria - Kaduna' ); ?>>Kaduna</option>
							<option value="Nigeria - Warri" <?php selected( $partner_country, 'Nigeria - Warri' ); ?>>Warri</option>
							<option value="Nigeria - Calabar" <?php selected( $partner_country, 'Nigeria - Calabar' ); ?>>Calabar</option>
							<option value="Nigeria - Uyo" <?php selected( $partner_country, 'Nigeria - Uyo' ); ?>>Uyo</option>
							<option value="Nigeria - Owerri" <?php selected( $partner_country, 'Nigeria - Owerri' ); ?>>Owerri</option>
							<option value="Nigeria - Onitsha" <?php selected( $partner_country, 'Nigeria - Onitsha' ); ?>>Onitsha</option>
						</optgroup>
					</select>

					<!--
					<label>Ideal Partner Description</label>
					<textarea name="partner_traits" rows="4"><?php echo esc_textarea( $partner_traits ); ?></textarea>
					-->
				</div>
			</div>
		</section>

		<section class="all-section">
			<h2>Appearance</h2>

			<div class="all-grid-3">
				<div>
					<label>Height (cm)</label>
					<input type="number" name="height" min="50" max="300" step="1" value="<?php echo esc_attr( $height ); ?>" required>
				</div>

				<div>
					<label>Weight (kg)</label>
					<input type="number" name="weight" min="20" max="300" step="1" value="<?php echo esc_attr( $weight ); ?>" required>
				</div>

				<div>
					<label>Body Type</label>
					<input type="text" name="body_type" value="<?php echo esc_attr( $body_type ); ?>">
				</div>

				<div>
					<label>Hair Color</label>
					<input type="text" name="hair_color" value="<?php echo esc_attr( $hair_color ); ?>">
				</div>

				<div>
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

			<!--
			<label>Hobbies &amp; Interests</label>
			<textarea name="hobbies" rows="3"><?php echo esc_textarea( $hobbies ); ?></textarea>
			-->

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
	</form>
</div>

			<div class="all-submit-wrap">
				<button type="submit" class="all-btn-primary">Save Profile &amp; Continue</button>
			</div>
		</form>
		
	<script>
		document.addEventListener("DOMContentLoaded", function () {
		  const uploadBtn = document.querySelector(".all-photo-upload-btn");
		  const fileInput = document.getElementById("all-photo-input");
		  const previewImg = document.querySelector(".all-photo-img");
		  const countrySelect = document.getElementById("afl-profile-country");
		  const citySelect = document.getElementById("afl-profile-city");
		  const locationMap = <?php echo wp_json_encode( $afl_location_map ); ?>;
		  const selectedCity = <?php echo wp_json_encode( (string) $city ); ?>;

		  function rebuildCities(countryValue, keepValue) {
			if (!citySelect) return;

			const cities = Array.isArray(locationMap[countryValue]) ? locationMap[countryValue] : [];
			citySelect.innerHTML = '<option value="">Select city</option>';

			cities.forEach(function(city){
			  const opt = document.createElement("option");
			  opt.value = city;
			  opt.textContent = city;
			  if (keepValue && city === keepValue) {
				opt.selected = true;
			  }
			  citySelect.appendChild(opt);
			});
		  }

		  if (countrySelect && citySelect) {
			rebuildCities(countrySelect.value, selectedCity);

			countrySelect.addEventListener("change", function () {
			  rebuildCities(this.value, "");
			});
		  }

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
	
<!--

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
-->

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
	$photo_id = (int) get_user_meta( $user_id, 'all_profile_photo', true );

	if ( $photo_id <= 0 ) {
		return false;
	}

	$url = wp_get_attachment_image_url( $photo_id, 'medium' );
	return ! empty( $url );
}

/**
 * Return a safe member profile photo URL.
 *
 * @param int    $user_id User ID.
 * @param string $size    Image size.
 * @return string
 */
function afl_member_profile_photo_url( $user_id, $size = 'medium' ) {
	$photo_id = (int) get_user_meta( $user_id, 'all_profile_photo', true );

	if ( $photo_id > 0 ) {
		$url = wp_get_attachment_image_url( $photo_id, $size );

		if ( $url ) {
			return $url;
		}
	}

	$avatar_url = get_avatar_url(
		$user_id,
		[
			'size' => ( 'thumbnail' === $size ) ? 150 : 400,
		]
	);

	return $avatar_url ? $avatar_url : 'https://www.gravatar.com/avatar/?s=200&d=mp';
}

/**
 * Return gallery lightbox URLs.
 *
 * @param int $user_id User ID.
 * @return array
 */
function afl_member_lightbox_urls( $user_id ) {
	$urls     = [];
	$photo_id = (int) get_user_meta( $user_id, 'all_profile_photo', true );

	if ( $photo_id > 0 ) {
		$full = wp_get_attachment_image_url( $photo_id, 'full' );
		if ( $full ) {
			$urls[] = $full;
		}
	}

	$gallery_ids = get_user_meta( $user_id, 'all_gallery_photos', true );

	if ( is_array( $gallery_ids ) ) {
		foreach ( $gallery_ids as $gid ) {
			$gid  = (int) $gid;
			$full = $gid ? wp_get_attachment_image_url( $gid, 'full' ) : '';
			if ( $full ) {
				$urls[] = $full;
			}
		}
	}

	return array_values( array_unique( array_filter( $urls ) ) );
}

/**
 * Check whether a member is verified.
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

					$a_count = count( afl_member_lightbox_urls( $a->ID ) ) + ( is_array( $a_gallery ) ? count( $a_gallery ) : 0 );
					$b_count = count( afl_member_lightbox_urls( $b->ID ) ) + ( is_array( $b_gallery ) ? count( $b_gallery ) : 0 );

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

				if ( 'mutual' === $tab || 'mutual_matches' === $tab ) {
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
	  .afl-icon-btn{width:34px;height:34px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:#f3f4f6;text-decoration:none;border:1px solid #e5e7eb;position:relative;}
	  .afl-icon-btn .dashicons{font-size:20px;width:20px;height:20px;line-height:20px;}
	  .afl-icon-btn:hover{opacity:.9;}
	  .afl-icon-btn.is-active{background:#fee2e2;border-color:#fecaca;}
	  .afl-photo-btn{position:relative;}
	  .afl-photo-count,.afl-like-count{position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:#111827;color:#fff;font-size:10px;padding:0 5px;}
	  .afl-like-count{display:none;}
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

			$photo_url     = afl_member_profile_photo_url( $uid, 'medium' );
			$lightbox_urls = afl_member_lightbox_urls( $uid );
			$photo_full    = ! empty( $lightbox_urls[0] ) ? $lightbox_urls[0] : $photo_url;
			$photo_count   = count( $lightbox_urls );

			$age       = get_user_meta( $uid, 'all_age', true );
			$city_u    = get_user_meta( $uid, 'all_city', true );
			$country_u = get_user_meta( $uid, 'all_country', true );
			$headline  = get_user_meta( $uid, 'all_headline', true );

			$seek_gender = get_user_meta( $uid, 'all_partner_gender', true );
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
			$liked = in_array( $uid, array_map( 'intval', $my_likes ), true );

			$msg_url = add_query_arg( 'to', $uid, home_url( '/messages/' ) );

			$lb_group = 'afl-card-' . $uid;
			$lb_first = $photo_full;
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
				$popup_avatar = afl_member_profile_photo_url( $uid, 'thumbnail' );

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
	$partner_gender  = get_user_meta( $member_id, 'all_partner_gender', true );
	$partner_country = get_user_meta( $member_id, 'all_partner_country', true );
	$partner_traits  = get_user_meta( $member_id, 'all_partner_traits', true );

	$photo_url     = afl_member_profile_photo_url( $member_id, 'large' );
	$lightbox_urls = afl_member_lightbox_urls( $member_id );
	$photo_full    = ! empty( $lightbox_urls[0] ) ? $lightbox_urls[0] : $photo_url;

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
				<div><span class="afl-field-label">Preferred Gender</span><span class="afl-field-value"><?php echo esc_html( $partner_gender ); ?></span></div>

				<div><span class="afl-field-label">Country</span><span class="afl-field-value"><?php echo esc_html( $country ); ?></span></div>
				<div><span class="afl-field-label">Preferred Age Range</span><span class="afl-field-value"><?php if ( $partner_age_min || $partner_age_max ) { echo esc_html( $partner_age_min ) . ' - ' . esc_html( $partner_age_max ); } ?></span></div>

				<div><span class="afl-field-label">City / Region</span><span class="afl-field-value"><?php echo esc_html( $city ); ?></span></div>
				<div><span class="afl-field-label">Preferred Country / Region</span><span class="afl-field-value"><?php echo esc_html( $partner_country ); ?></span></div>

				<div><span class="afl-field-label">Education</span><span class="afl-field-value"><?php echo esc_html( $education ); ?></span></div>
				<div><span class="afl-field-label">Ideal Partner Description</span><span class="afl-field-value"><?php echo nl2br( esc_html( $partner_traits ) ); ?></span></div>

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