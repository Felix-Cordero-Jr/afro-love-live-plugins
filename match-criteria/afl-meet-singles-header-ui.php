<?php
/**
 * Plugin Name: AFL Meet Singles Header UI (Top Nav + Profile Bar + Match Toolbar)
 * Description: One shortcode renders: responsive mobile top nav (hamburger) + message/activity badges + profile bar + match toolbar (criteria/sort). Shortcode: [afl_meet_singles_header]
 * Version: 1.0.0
 * Author: Felix Cordero Jr.
 */

if ( ! defined('ABSPATH') ) exit;

final class AFL_Meet_Singles_Header_UI {

  /* =========================
   * CONFIG
   * ========================= */
  const VERSION = '1.0.0';

  // Shortcode
  const SHORTCODE = 'afl_meet_singles_header';

  // Nonces
  const NONCE_TOPNAV  = 'afl_meet_header_topnav_nonce';
  const NONCE_PRES    = 'afl_meet_header_presence_nonce';

  // Chat table (prefix added in code)
  const CHAT_TABLE = 'afl_chat_messages';

  // Activity user_meta keys
  const META_LIKES        = 'afl_activity_likes';
  const META_PROFILEVIEWS = 'afl_activity_profile_views';
  const META_BLOCKS       = 'afl_activity_blocks';

  // Favorites meta (array of user ids)
  const INBOX_FAV_META_KEY = 'afl_inbox_fav_ids';

  // Presence meta
  const PRESENCE_META_KEY = 'afl_last_active';

  public static function init(){
    add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);

    add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 99);

    // Presence update (page load)
    add_action('init', [__CLASS__, 'presence_on_page_load']);

    // AJAX (logged-in)
    add_action('wp_ajax_afl_meet_header_message_count',  [__CLASS__, 'ajax_message_count']);
    add_action('wp_ajax_afl_meet_header_activity_counts',[__CLASS__, 'ajax_activity_counts']);
    add_action('wp_ajax_afl_meet_header_presence_ping',  [__CLASS__, 'ajax_presence_ping']);
    add_action('wp_ajax_afl_meet_header_presence_status',[__CLASS__, 'ajax_presence_status']);

    // AJAX (nopriv safety)
    add_action('wp_ajax_nopriv_afl_meet_header_message_count',   [__CLASS__, 'ajax_nopriv_counts']);
    add_action('wp_ajax_nopriv_afl_meet_header_activity_counts', [__CLASS__, 'ajax_nopriv_counts']);
    add_action('wp_ajax_nopriv_afl_meet_header_presence_ping',   [__CLASS__, 'ajax_nopriv_counts']);
    add_action('wp_ajax_nopriv_afl_meet_header_presence_status', [__CLASS__, 'ajax_nopriv_counts']);
  }

  /* =========================
   * HELPERS
   * ========================= */
  private static function table_chat(){
    global $wpdb;
    return $wpdb->prefix . self::CHAT_TABLE;
  }

  private static function int_meta($uid, $key){
    $v = get_user_meta($uid, $key, true);
    return max(0, (int) $v);
  }

  private static function array_meta_count($uid, $key){
    $v = get_user_meta($uid, $key, true);
    if (!is_array($v)) return 0;
    $v = array_values(array_unique(array_filter(array_map('intval', $v))));
    return count($v);
  }

  private static function avatar_url($user_id){
    // If you store profile photo attachment id in user meta, use it:
    $photo_id = (int) get_user_meta($user_id, 'all_profile_photo', true);
    if ($photo_id) {
      $u = wp_get_attachment_image_url($photo_id, 'thumbnail');
      if ($u) return $u;
    }
    $av = get_avatar_url($user_id, ['size' => 96]);
    return $av ? $av : 'https://www.gravatar.com/avatar/?s=96&d=mp';
  }

  /* =========================
   * ASSETS
   * ========================= */
  public static function assets(){
    if ( is_admin() ) return;
    if ( ! is_user_logged_in() ) return;

    wp_register_style('afl-meet-header-ui', false, [], self::VERSION);
    wp_register_script('afl-meet-header-ui', false, ['jquery'], self::VERSION, true);

    wp_localize_script('afl-meet-header-ui', 'aflMeetHeader', [
      'ajax' => admin_url('admin-ajax.php'),
      'nonceTop' => wp_create_nonce(self::NONCE_TOPNAV),
      'noncePres'=> wp_create_nonce(self::NONCE_PRES),
      'pollMs'   => 4000,
      'pingMs'   => 30000,
      'statusMs' => 15000,
    ]);

    $css = '
/* ============================================================
 * AFL MEET SINGLES HEADER UI
 * ============================================================ */

:root{
  --afl-black:#000;
  --afl-brand:#7b001a;
  --afl-border:#e5e7eb;
  --afl-text:#111827;
  --afl-badge:#ef4444;
  --afl-radius:14px;
}

/* ----------------------------
 * TOP NAV (Mobile responsive)
 * ---------------------------- */
.afl-hdr-nav-wrap{
  width:100%;
  background:var(--afl-black);
  position:relative;
  z-index:9999;
}

.afl-hdr-nav{
  max-width:1200px;
  margin:0 auto;
  min-height:64px;
  padding:10px 14px;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:18px;
  font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
  box-sizing:border-box;
}

/* Left slot (optional logo area if you want later) */
.afl-hdr-left{
  display:none;
}

/* Mobile hamburger */
.afl-hdr-toggle{
  display:none;
  width:44px;height:44px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.06);
  color:#fff;
  cursor:pointer;
  align-items:center;
  justify-content:center;
  flex:0 0 auto;
}
.afl-hdr-toggle:active{transform:scale(.98)}
.afl-hdr-burger{
  width:18px;height:14px; position:relative; display:block;
}
.afl-hdr-burger:before,
.afl-hdr-burger:after,
.afl-hdr-burger i{
  content:"";
  position:absolute; left:0; right:0;
  height:2px; background:#fff; border-radius:2px;
}
.afl-hdr-burger:before{top:0}
.afl-hdr-burger i{top:6px}
.afl-hdr-burger:after{bottom:0}

/* Menu */
.afl-hdr-menu{
  list-style:none !important;
  display:flex !important;
  align-items:center !important;
  justify-content:center !important;
  gap:22px !important;
  margin:0 !important;
  padding:0 !important;
  flex-wrap:nowrap !important;
}

.afl-hdr-menu li{
  list-style:none !important;
  margin:0 !important;
  padding:0 !important;
  position:relative;
}

.afl-hdr-menu a,
.afl-hdr-menu button.afl-hdr-linkbtn{
  color:#fff !important;
  text-decoration:none !important;
  font-weight:650 !important;
  font-size:16px !important;
  display:inline-flex !important;
  align-items:center !important;
  gap:8px !important;
  padding:10px 12px !important;
  border-radius:12px !important;
  line-height:1 !important;
  background:transparent !important;
  border:none !important;
  cursor:pointer !important;
  white-space:nowrap !important;
}

.afl-hdr-menu a:hover,
.afl-hdr-menu button.afl-hdr-linkbtn:hover{
  background:rgba(255,255,255,.08);
}

/* caret */
.afl-hdr-caret{
  display:inline-block;
  width:0;height:0;
  border-left:5px solid transparent;
  border-right:5px solid transparent;
  border-top:6px solid #fff;
  transform:translateY(1px);
  opacity:.95;
}

/* top badges */
.afl-hdr-badge,
.afl-hdr-act-badge{
  display:none;
  min-width:18px;
  height:18px;
  padding:0 6px;
  background:var(--afl-badge);
  color:#fff;
  font-size:11px;
  font-weight:900;
  border-radius:999px;
  align-items:center;
  justify-content:center;
  transform:translateY(-2px);
}

/* Activity dropdown */
.afl-hdr-activity-menu{
  position:absolute;
  top:48px;
  left:50%;
  transform:translateX(-50%);
  width:220px;
  background:#fff;
  border-radius:10px;
  box-shadow:0 10px 24px rgba(0,0,0,.22);
  border:1px solid rgba(0,0,0,.08);
  padding:8px 0;
  display:none;
  z-index:99999;
}

.afl-hdr-activity-menu:before{
  content:"";
  position:absolute;
  top:-8px;
  left:50%;
  transform:translateX(-50%);
  width:0;height:0;
  border-left:8px solid transparent;
  border-right:8px solid transparent;
  border-bottom:8px solid #fff;
  filter:drop-shadow(0 -1px 0 rgba(0,0,0,.08));
}

.afl-hdr-activity-item{
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  gap:10px !important;
  padding:10px 14px !important;
  background:#fff !important;
  text-decoration:none !important;
  color:var(--afl-text) !important;
  font-size:14px !important;
  font-weight:650 !important;
  line-height:1.2 !important;
}
.afl-hdr-activity-item:hover{background:#f3f4f6 !important}
.afl-hdr-activity-right{display:inline-flex;align-items:center;justify-content:flex-end;min-width:40px}
.afl-hdr-activity-count{
  display:none;
  width:22px;height:22px;
  border-radius:999px;
  background:var(--afl-badge);
  color:#fff;
  font-size:12px;
  font-weight:900;
  line-height:22px;
  text-align:center;
}

/* ----------------------------
 * PROFILE BAR (under nav)
 * ---------------------------- */
.afl-hdr-profile{
  background:var(--afl-brand);
  color:#fff;
  padding:14px 18px;
  position:relative;
  z-index:5;
}
.afl-hdr-profile-inner{
  max-width:1200px;
  margin:0 auto;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
}
.afl-hdr-profile-left{
  display:flex;
  align-items:center;
  gap:12px;
  min-width:0;
}
.afl-hdr-avatar{
  position:relative;
  width:58px;height:58px;
  border-radius:999px;
  overflow:hidden;
  background:rgba(255,255,255,.2);
  flex:0 0 auto;
}
.afl-hdr-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.afl-hdr-dot{
  position:absolute;
  right:2px;bottom:2px;
  width:12px;height:12px;
  border-radius:999px;
  background:var(--afl-brand);
  border:2px solid var(--afl-brand);
  box-shadow:0 0 0 2px rgba(255,255,255,.15);
}
.afl-hdr-dot.is-online{background:#22c55e;}
.afl-hdr-meta{min-width:0}
.afl-hdr-plan{margin-bottom:4px}
.afl-hdr-edit a{color:#ffd34d !important; text-decoration:none !important; font-weight:800 !important}
.afl-hdr-title{
  font-weight:900;
  font-size:18px;
  line-height:1.1;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width:75vw;
}
.afl-hdr-sub{
  margin-top:4px;
  font-size:13px;
  opacity:.95;
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
}
.afl-hdr-status{font-weight:900}
.afl-hdr-sep{opacity:.8}

/* ----------------------------
 * MATCH TOOLBAR (same UI you have)
 * ---------------------------- */
.afl-mt-wrap{width:100%;max-width:none;margin:12px auto 0;padding:0 14px;box-sizing:border-box;}
.afl-mt-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

/* Left controls */
.afl-mt-btn{
  display:inline-flex;align-items:center;gap:8px;
  height:40px;padding:0 12px;border-radius:10px;
  border:1px solid var(--afl-border);background:#fff;color:var(--afl-text);
  cursor:pointer;user-select:none;font-weight:800;font-size:13px;
  box-shadow:0 6px 16px rgba(0,0,0,.06);
}
.afl-mt-icon{font-size:16px;line-height:1}
.afl-mt-btn small{font-weight:700;opacity:.75}

/* Pills */
.afl-mt-pill{
  display:inline-flex;align-items:center;gap:8px;
  height:40px;padding:0 14px;border-radius:999px;
  border:1px solid var(--afl-border);background:#fff;color:var(--afl-text);
  cursor:pointer;user-select:none;font-weight:900;font-size:13px;
  text-decoration:none;
}
.afl-mt-pill.is-active{background:var(--afl-brand);color:#fff;border-color:var(--afl-brand)}
.afl-mt-pill .afl-mt-dot{width:10px;height:10px;border-radius:99px;background:#11182722;display:inline-block}
.afl-mt-pill.is-active .afl-mt-dot{background:#ffffff55}

/* Sort panel */
.afl-mt-panel{
  position:absolute;z-index:999999;
  background:#fff;border:1px solid var(--afl-border);border-radius:14px;
  box-shadow:0 18px 50px rgba(0,0,0,.18);
  min-width:260px;padding:10px;display:none;
}
.afl-mt-panel h4{margin:6px 8px 10px;font-size:14px}
.afl-mt-panel .afl-mt-row{display:flex;gap:8px;align-items:center;margin:8px}
.afl-mt-panel select,.afl-mt-panel input{
  width:100%;height:40px;border-radius:10px;border:1px solid var(--afl-border);padding:0 10px;font-size:13px;outline:none;
}
.afl-mt-panel .afl-mt-actions{display:flex;gap:10px;padding:10px 8px 4px}
.afl-mt-panel .afl-mt-ghost,
.afl-mt-panel .afl-mt-primary{
  height:40px;border-radius:10px;border:1px solid var(--afl-border);background:#fff;
  padding:0 14px;cursor:pointer;font-weight:900;
}
.afl-mt-panel .afl-mt-primary{background:var(--afl-brand);color:#fff;border-color:var(--afl-brand);flex:1}
.afl-mt-panel .afl-mt-ghost{flex:1}

/* Modal overlay */
.afl-mt-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.35);
  z-index:999998;display:none;
}

/* Modal */
.afl-mt-modal{
  position:fixed;
  left:50%;
  top:120px;                 /* keeps it below the header on all devices */
  transform:translateX(-50%);/* no vertical translate so it never jumps into the nav */
  width:min(520px,calc(100vw - 26px));
  background:#fff;border:1px solid var(--afl-border);border-radius:18px;
  z-index:999999;display:none;
  box-shadow:0 26px 70px rgba(0,0,0,.28);
}
.afl-mt-modal-head{padding:18px 18px 10px;display:flex;align-items:center;justify-content:space-between;}
.afl-mt-modal-title{font-size:22px;font-weight:900;margin:0}
.afl-mt-x{
  width:38px;height:38px;border-radius:999px;border:1px solid var(--afl-border);background:#fff;cursor:pointer;
  font-size:18px;display:flex;align-items:center;justify-content:center;
}
.afl-mt-tabs{display:flex;gap:10px;padding:0 18px 12px}
.afl-mt-tab{
  height:38px;padding:0 16px;border-radius:999px;border:1px solid var(--afl-border);background:#fff;cursor:pointer;font-weight:900;flex:1;
}
.afl-mt-tab.is-active{background:var(--afl-brand);color:#fff;border-color:var(--afl-brand)}
.afl-mt-modal-body{padding:0 18px 6px}

.afl-mt-section{margin:10px 0 14px}
.afl-mt-label{font-size:13px;font-weight:900;opacity:.75;margin:8px 2px}
.afl-mt-field{
  width:100%;height:48px;border-radius:12px;border:1px solid var(--afl-border);
  padding:0 12px;font-size:14px;outline:none;background:#fff;
}
.afl-mt-field:focus{border-color:#c7cbd1;box-shadow:0 0 0 3px rgba(123,0,26,.08)}
.afl-mt-grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:560px){.afl-mt-grid2{grid-template-columns:1fr}}

.afl-mt-seg{display:flex;gap:10px}
.afl-mt-seg button{
  flex:1;height:48px;border-radius:12px;border:1px solid var(--afl-border);background:#fff;cursor:pointer;font-weight:900;font-size:14px;
}
.afl-mt-seg button.is-active{background:var(--afl-brand);border-color:var(--afl-brand);color:#fff}

.afl-mt-modal-foot{padding:14px 18px 18px;display:flex;gap:12px}
.afl-mt-modal-foot .afl-mt-ghost,
.afl-mt-modal-foot .afl-mt-primary{
  height:48px;border-radius:12px;font-weight:900;cursor:pointer;width:100%;border:1px solid var(--afl-border);background:#fff;
}
.afl-mt-modal-foot .afl-mt-primary{flex:1;border-color:var(--afl-brand);background:var(--afl-brand);color:#fff}
.afl-mt-modal-foot .afl-mt-ghost{flex:1}

/* ----------------------------
 * MOBILE FIX (THIS IS THE MAIN FIX)
 * - Your screenshot shows the nav items stacking huge.
 * - On mobile we switch to hamburger + vertical drawer.
 * ---------------------------- */
@media (max-width: 820px){
  .afl-hdr-nav{justify-content:flex-start}
  .afl-hdr-toggle{display:inline-flex}
  .afl-hdr-menu{
    display:none !important;
    width:100%;
    flex-direction:column !important;
    align-items:stretch !important;
    justify-content:flex-start !important;
    gap:8px !important;
    padding:8px 0 4px !important;
    margin-top:6px !important;
  }
  .afl-hdr-nav.is-open .afl-hdr-menu{display:flex !important}

  .afl-hdr-menu a,
  .afl-hdr-menu button.afl-hdr-linkbtn{
    width:100% !important;
    justify-content:center !important;
    padding:14px 12px !important;
    border-radius:14px !important;
    background:rgba(255,255,255,.06) !important;
  }

  /* dropdown becomes full-width under Activity button */
  .afl-hdr-activity-menu{
    position:static;
    transform:none;
    width:100%;
    margin-top:6px;
    border-radius:14px;
  }
  .afl-hdr-activity-menu:before{display:none}

  /* profile bar tighter */
  .afl-hdr-profile{padding:12px}
  .afl-hdr-avatar{width:48px;height:48px}
  .afl-hdr-title{font-size:16px}
  .afl-hdr-sub{font-size:12px}
  
  
  /* AFL: force Match Toolbar to NOT be fixed/sticky */
.afl-mt-wrap,
.afl-mt-bar{
  position: relative !important;
  top: auto !important;
  left: auto !important;
  right: auto !important;
  bottom: auto !important;
  transform: none !important;
}

@media (max-width: 560px){
  .afl-mt-modal{
    top: 50px !important; /* adjust: 60px / 70px / 80px */
  }
}

/* If something is making the whole toolbar sticky */
.afl-mt-wrap{
  z-index: auto !important;
}

/* ============================================================
 * FIX: Mobile modal can’t reach bottom (SAFE)
 * - No display:flex !important (so Save/Cancel still works)
 * - Use .is-open class to control visibility
 * ============================================================ */

/* Keep default hidden */
.afl-mt-modal{ display:none; }
.afl-mt-overlay{ display:none; }

/* Only show when open */
.afl-mt-modal.is-open{
  display:flex;
  flex-direction:column;
}
.afl-mt-overlay.is-open{ display:block; }

/* Modal container: never exceed screen height */
.afl-mt-modal{
  position:fixed;
  left:50%;
  top:90px;
  transform:translateX(-50%);
  width:min(520px,calc(100vw - 26px));
  max-height:calc(100vh - 110px);
  overflow:hidden; /* body scrolls */
}

/* Make the content area scrollable */
.afl-mt-modal-body{
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
  flex:1 1 auto;
  padding-bottom:14px;
}

/* Footer stays visible */
.afl-mt-modal-foot{
  flex:0 0 auto;
  position:sticky;
  bottom:0;
  background:#fff;
  border-top:1px solid #e5e7eb;
  padding-bottom:calc(18px + env(safe-area-inset-bottom));
  z-index:2;
}

/* Smaller phones */
@media (max-width:420px){
  .afl-mt-modal{
    top:78px;
    max-height:calc(100vh - 94px);
  }
}
}

  /* match toolbar already wraps OK */
  
  

}
';

    $js = '
(function($){

  function clampBadge(n){
    n = parseInt(n || 0, 10);
    if (isNaN(n) || n < 0) n = 0;
    return (n > 99) ? "99+" : String(n);
  }

  function showTopBadge($el, n){
    n = parseInt(n || 0, 10);
    if(n > 0){
      $el.text(clampBadge(n)).css("display","inline-flex");
    }else{
      $el.hide();
    }
  }

  function showMenuCount($el, n){
    n = parseInt(n || 0, 10);
    if(n > 0){
      $el.text(clampBadge(n)).css("display","inline-block");
    }else{
      $el.hide();
    }
  }

  /* ----------------------------
   * MOBILE MENU TOGGLE
   * ---------------------------- */
  function closeMobileMenu(){
    $(".afl-hdr-nav").removeClass("is-open");
    $(".afl-hdr-toggle").attr("aria-expanded","false");
  }

  $(document).on("click", ".afl-hdr-toggle", function(e){
    e.preventDefault();
    var $nav = $(".afl-hdr-nav");
    var open = $nav.hasClass("is-open");
    $nav.toggleClass("is-open", !open);
    $(this).attr("aria-expanded", (!open) ? "true" : "false");

    // when opening, close any dropdown first
    if(!open){
      $(".afl-hdr-activity-menu").hide();
      $(".afl-hdr-activity-btn").attr("aria-expanded","false");
    }
  });

  /* ----------------------------
   * ACTIVITY DROPDOWN
   * ---------------------------- */
  function closeActivity(){
    $(".afl-hdr-activity-menu").hide();
    $(".afl-hdr-activity-btn").attr("aria-expanded","false");
  }

  $(document).on("click", ".afl-hdr-activity-btn", function(e){
    e.preventDefault();
    e.stopPropagation();

    var $li = $(this).closest("li");
    var $menu = $li.find(".afl-hdr-activity-menu");
    var isOpen = $menu.is(":visible");

    closeActivity();

    if(!isOpen){
      $menu.show();
      $(this).attr("aria-expanded","true");
    }
  });

  // click outside closes dropdown & mobile menu
  $(document).on("click", function(){
    closeActivity();
    closeMobileMenu();
  });

  // prevent inside clicks from closing
  $(document).on("click", ".afl-hdr-nav, .afl-hdr-activity-menu", function(e){
    e.stopPropagation();
  });

  $(document).on("keydown", function(e){
    if(e.key === "Escape"){
      closeActivity();
      closeMobileMenu();
    }
  });

  /* ----------------------------
   * POLLING: MESSAGES + ACTIVITY
   * ---------------------------- */
  function pollMessage(){
    $.post(aflMeetHeader.ajax, {
      action: "afl_meet_header_message_count",
      nonce: aflMeetHeader.nonceTop
    }).done(function(r){
      if(!r || !r.success) return;
      var n = (r.data && typeof r.data.count !== "undefined") ? r.data.count : 0;
      showTopBadge($(".afl-hdr-badge.afl-msg-badge"), n);
    });
  }

  function pollActivity(){
    $.post(aflMeetHeader.ajax, {
      action: "afl_meet_header_activity_counts",
      nonce: aflMeetHeader.nonceTop
    }).done(function(r){
      if(!r || !r.success) return;

      var d = r.data || {};
      showMenuCount($(".afl-act-like"), d.likes);
      showMenuCount($(".afl-act-inboxfav"), d.favorites);
      showMenuCount($(".afl-act-view"), d.profile_views);
      showMenuCount($(".afl-act-block"), d.blocks);

      showTopBadge($(".afl-hdr-act-badge"), d.total);
    });
  }

  /* ----------------------------
   * PRESENCE
   * ---------------------------- */
  function pingPresence(){
    $.post(aflMeetHeader.ajax, {
      action:"afl_meet_header_presence_ping",
      nonce: aflMeetHeader.noncePres
    });
  }

  function refreshPresence(){
    $.post(aflMeetHeader.ajax, {
      action:"afl_meet_header_presence_status",
      nonce: aflMeetHeader.noncePres
    }).done(function(res){
      if(!res || !res.success) return;
      var online = !!(res.data && res.data.online);
      $(".afl-hdr-dot").toggleClass("is-online", online);
      $(".afl-hdr-status").text(online ? "Online" : "Offline");
    });
  }

  /* ----------------------------
   * MATCH TOOLBAR UI (querystring)
   * ---------------------------- */
  function qsGet(){
    const out={};
    const p=new URLSearchParams(window.location.search);
    p.forEach((v,k)=>out[k]=v);
    return out;
  }
  function qsSet(obj){
    const p=new URLSearchParams();
    Object.keys(obj).forEach(k=>{
      const v=obj[k];
      if(v!==null && v!==undefined && String(v).trim()!=="") p.set(k,String(v));
    });
    const url=window.location.pathname + "?" + p.toString() + window.location.hash;
    window.location.href=url;
  }

  function openOverlay(){
  $(".afl-mt-overlay").addClass("is-open");
  $(".afl-mt-modal").addClass("is-open");
}

function closeOverlay(){
  $(".afl-mt-overlay").removeClass("is-open");
  $(".afl-mt-modal").removeClass("is-open");
  $(".afl-mt-panel").hide();
}

  function setActivePills(){
    const q=qsGet();
    $("[data-afl-pill]").each(function(){
      const key=$(this).data("afl-pill");
      const val=$(this).data("afl-val");
      const on=(q[key]===String(val));
      $(this).toggleClass("is-active", on);
    });
  }

  function positionPanel(btn, panel){
    const r=btn.getBoundingClientRect();
    const top = r.bottom + 8 + window.scrollY;
    const left = Math.min((r.left + window.scrollX), (window.scrollX + window.innerWidth - panel.offsetWidth - 12));
    panel.style.top = top + "px";
    panel.style.left = left + "px";
  }

  // Toggle Sort panel
  $(document).on("click",".afl-mt-open-sort",function(e){
    e.preventDefault(); e.stopPropagation();
    const panel=document.getElementById("aflMtSortPanel");
    if(!panel) return;
    const isOpen=$(panel).is(":visible");
    $(".afl-mt-panel").hide();
    if(isOpen){ $(panel).hide(); return; }
    $(panel).show();
    positionPanel(this, panel);
  });

  // Open Match Criteria modal
  $(document).on("click",".afl-mt-open-criteria",function(e){
  e.preventDefault(); e.stopPropagation();
  openOverlay(); // this already opens the modal now
});

  // Close modal
  $(document).on("click",".afl-mt-x, .afl-mt-overlay",function(){
    closeOverlay();
  });

  // Tabs
  $(document).on("click",".afl-mt-tab",function(){
    const t=$(this).data("tab");
    $(".afl-mt-tab").removeClass("is-active");
    $(this).addClass("is-active");
    $("[data-afl-tabpanel]").hide();
    $(`[data-afl-tabpanel="${t}"]`).show();
  });

  // Pills -> set query
  $(document).on("click","[data-afl-pill]",function(e){
    e.preventDefault();
    const key=$(this).data("afl-pill");
    const val=$(this).data("afl-val");
    const q=qsGet();
    q[key]=String(val);
    qsSet(q);
  });

  // Seeking segmented buttons
  $(document).on("click",".afl-mt-seg [data-seek]",function(e){
    e.preventDefault();
    const v = String($(this).data("seek") || "any");
    $("#aflSeek").val(v);
    $(".afl-mt-seg [data-seek]").removeClass("is-active");
    $(this).addClass("is-active");
  });

  // Apply Sort
  $(document).on("click",".afl-mt-apply-sort",function(){
    const q=qsGet();
    q.sort = $("#aflMtSortSelect").val() || "";
    qsSet(q);
  });

  // Apply Criteria
  $(document).on("click",".afl-mt-apply-criteria",function(){
    const q=qsGet();
    q.seek    = $("#aflSeek").val() || "any";
    q.ageMin  = $("#aflAgeMin").val() || "";
    q.ageMax  = $("#aflAgeMax").val() || "";
    q.country = $("#aflCountry").val() || "";
    q.city    = $("#aflCity").val() || "";
    q.area    = $("#aflArea").val() || "";
    q.within  = $("#aflWithin").val() || "";
    q.verified   = $("#aflVerified").is(":checked") ? "1" : "";
    q.with_photo = $("#aflWithPhoto").is(":checked") ? "1" : "";
    closeOverlay();
    qsSet(q);
  });

  $(document).on("click",".afl-mt-cancel",function(){ closeOverlay(); });

  // Close panels on outside click
  $(document).on("click",function(){ $(".afl-mt-panel").hide(); });
  $(document).on("click",".afl-mt-panel, .afl-mt-modal",function(e){ e.stopPropagation(); });

  /* ----------------------------
   * INIT
   * ---------------------------- */
  $(function(){
    // counts + presence
    pollMessage();
    pollActivity();
    pingPresence();
    refreshPresence();

    setInterval(function(){ pollMessage(); pollActivity(); }, parseInt(aflMeetHeader.pollMs || 4000,10));
    setInterval(pingPresence, parseInt(aflMeetHeader.pingMs || 30000,10));
    setInterval(refreshPresence, parseInt(aflMeetHeader.statusMs || 15000,10));

    // match toolbar init
    setActivePills();
    const q=qsGet();
    $("#aflMtSortSelect").val(q.sort || "");

    const seek = (q.seek || "any").toLowerCase();
    $("#aflSeek").val(seek);
    $(".afl-mt-seg [data-seek]").removeClass("is-active");
    $(`.afl-mt-seg [data-seek="${seek}"]`).addClass("is-active");

    $("#aflAgeMin").val(q.ageMin || "20");
    $("#aflAgeMax").val(q.ageMax || "25");
    $("#aflCountry").val(q.country || "");
    $("#aflCity").val(q.city || "");
    $("#aflArea").val(q.area || "");
    $("#aflWithin").val(q.within || "");
    $("#aflVerified").prop("checked", q.verified==="1");
    $("#aflWithPhoto").prop("checked", q.with_photo==="1");
  });

})(jQuery);
';

    wp_add_inline_style('afl-meet-header-ui', $css);
    wp_add_inline_script('afl-meet-header-ui', $js);

    wp_enqueue_style('afl-meet-header-ui');
    wp_enqueue_script('afl-meet-header-ui');
  }

  /* =========================
   * PRESENCE
   * ========================= */
  public static function presence_on_page_load(){
    if ( is_user_logged_in() && ! is_admin() ) {
      update_user_meta(get_current_user_id(), self::PRESENCE_META_KEY, time());
    }
  }

  public static function ajax_presence_ping(){
    if ( ! is_user_logged_in() ) wp_send_json_success(['ok'=>false]);
    check_ajax_referer(self::NONCE_PRES,'nonce');
    update_user_meta(get_current_user_id(), self::PRESENCE_META_KEY, time());
    wp_send_json_success(['ok'=>true]);
  }

  public static function ajax_presence_status(){
    if ( ! is_user_logged_in() ) wp_send_json_success(['online'=>false]);
    check_ajax_referer(self::NONCE_PRES,'nonce');
    $uid  = get_current_user_id();
    $last = (int) get_user_meta($uid, self::PRESENCE_META_KEY, true);
    $online = ($last && (time() - $last) <= 60);
    wp_send_json_success([
      'online' => $online,
      'last'   => $last,
      'last_human' => $last ? human_time_diff($last, time()) . ' ago' : '—',
    ]);
  }

  /* =========================
   * AJAX: COUNTS
   * ========================= */
  public static function ajax_nopriv_counts(){
    wp_send_json_success([
      'count'=>0,
      'likes'=>0,
      'favorites'=>0,
      'profile_views'=>0,
      'blocks'=>0,
      'total'=>0,
      'ok'=>false,
    ]);
  }

  public static function ajax_message_count(){
    if ( ! is_user_logged_in() ) wp_send_json_success(['count'=>0]);
    check_ajax_referer(self::NONCE_TOPNAV,'nonce');

    global $wpdb;
    $uid   = get_current_user_id();
    $table = self::table_chat();

    $count = (int) $wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE receiver_id=%d AND is_read=0", $uid)
    );

    wp_send_json_success(['count'=>$count]);
  }

  public static function ajax_activity_counts(){
    if ( ! is_user_logged_in() ) return self::ajax_nopriv_counts();
    check_ajax_referer(self::NONCE_TOPNAV,'nonce');

    $uid = get_current_user_id();

    $likes        = self::int_meta($uid, self::META_LIKES);
    $profileViews = self::int_meta($uid, self::META_PROFILEVIEWS);
    $blocks       = self::int_meta($uid, self::META_BLOCKS);
    $inboxFavs    = self::array_meta_count($uid, self::INBOX_FAV_META_KEY);

    $data = [
      'likes'         => $likes,
      'favorites'     => $inboxFavs,
      'profile_views' => $profileViews,
      'blocks'        => $blocks,
      'total'         => ($likes + $inboxFavs + $profileViews + $blocks),
    ];

    $data = apply_filters('afl_meet_header_activity_counts', $data, $uid);

    wp_send_json_success($data);
  }

  /* =========================
   * SHORTCODE OUTPUT
   * ========================= */
  public static function shortcode(){
    if ( ! is_user_logged_in() ) return '';

    // URLs (adjust if needed)
    $home = home_url('/meet-singles/');
    $msg  = home_url('/message/');
    $up   = home_url('/upgrade/');
    $out  = wp_logout_url(home_url('/home/'));

    $likesUrl  = home_url('/activity/likes/');
    $favUrl    = add_query_arg('tab','favorites',$msg);
    $viewsUrl  = home_url('/activity/profile-views/');
    $blocksUrl = home_url('/activity/blocks/');

    $u   = wp_get_current_user();
    $uid = (int) $u->ID;

    $avatar = esc_url(self::avatar_url($uid));
    $name   = esc_html($u->display_name);

    $last   = (int) get_user_meta($uid, self::PRESENCE_META_KEY, true);
    $online = ($last && (time() - $last) <= 60);
    $online_text = $online ? 'Online' : 'Offline';

    ob_start(); ?>

    <!-- TOP NAV -->
    <div class="afl-hdr-nav-wrap">
      <div class="afl-hdr-nav" role="navigation" aria-label="Top Navigation">

        <button class="afl-hdr-toggle" type="button" aria-expanded="false" aria-controls="aflHdrMenu">
          <span class="afl-hdr-burger"><i></i></span>
        </button>

        <ul id="aflHdrMenu" class="afl-hdr-menu">
          <li><a href="<?php echo esc_url($home); ?>">Home</a></li>

          <li>
            <a href="<?php echo esc_url($msg); ?>">
              Messages
              <span class="afl-hdr-badge afl-msg-badge" aria-label="Unread messages"></span>
            </a>
          </li>

          <li>
            <button type="button"
              class="afl-hdr-linkbtn afl-hdr-activity-btn"
              aria-haspopup="true"
              aria-expanded="false">
              Activity
              <span class="afl-hdr-act-badge" aria-label="Activity total"></span>
              <span class="afl-hdr-caret"></span>
            </button>

            <div class="afl-hdr-activity-menu" role="menu">

              <a class="afl-hdr-activity-item" href="<?php echo esc_url($likesUrl); ?>" role="menuitem">
                <span>Likes</span>
                <span class="afl-hdr-activity-right"><span class="afl-hdr-activity-count afl-act-like">0</span></span>
              </a>

              <a class="afl-hdr-activity-item" href="<?php echo esc_url($favUrl); ?>" role="menuitem">
                <span>Favorites</span>
                <span class="afl-hdr-activity-right"><span class="afl-hdr-activity-count afl-act-inboxfav">0</span></span>
              </a>

              <a class="afl-hdr-activity-item" href="<?php echo esc_url($viewsUrl); ?>" role="menuitem">
                <span>Profile View</span>
                <span class="afl-hdr-activity-right"><span class="afl-hdr-activity-count afl-act-view">0</span></span>
              </a>

              <a class="afl-hdr-activity-item" href="<?php echo esc_url($blocksUrl); ?>" role="menuitem">
                <span>Block List</span>
                <span class="afl-hdr-activity-right"><span class="afl-hdr-activity-count afl-act-block">0</span></span>
              </a>

            </div>
          </li>

          <li><a href="<?php echo esc_url($up); ?>">Upgrade</a></li>
          <li><a href="<?php echo esc_url($out); ?>">Logout</a></li>
        </ul>

      </div>
    </div>

    <!-- PROFILE BAR -->
    <div class="afl-hdr-profile" data-afl-pb="1">
      <div class="afl-hdr-profile-inner">
        <div class="afl-hdr-profile-left">

          <div class="afl-hdr-avatar">
            <img src="<?php echo $avatar; ?>" alt="">
            <span class="afl-hdr-dot <?php echo $online ? 'is-online' : ''; ?>" aria-hidden="true"></span>
          </div>

          <div class="afl-hdr-meta">
            <div class="afl-hdr-plan"><?php echo do_shortcode('[afl_plan_badge]'); ?></div>
            <div class="afl-hdr-edit"><a href="../create-profile/">Edit Profile</a></div>
            <div class="afl-hdr-title">Hi <?php echo $name; ?></div>
            <div class="afl-hdr-sub">
              <span class="afl-hdr-status"><?php echo esc_html($online_text); ?></span>
              <span class="afl-hdr-sep">•</span>
              <span>Learn about membership features</span>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- MATCH TOOLBAR -->
    <div class="afl-mt-wrap">
      <div class="afl-mt-bar">

        <button class="afl-mt-btn afl-mt-open-criteria" type="button">
          <span class="afl-mt-icon">⚙️</span>
          <span>Match Criteria</span>
        </button>

        <button class="afl-mt-btn afl-mt-open-sort" type="button">
          <span class="afl-mt-icon">⇅</span>
          <span>Sort</span>
          <small>▼</small>
        </button>

        <a href="#" class="afl-mt-pill" data-afl-pill="tab" data-afl-val="matches">
          <span class="afl-mt-dot"></span>Matches
        </a>
        <a href="#" class="afl-mt-pill" data-afl-pill="tab" data-afl-val="mutual">
          <span class="afl-mt-dot"></span>Mutual Matches
        </a>

      </div>
    </div>

    <!-- Sort Panel -->
    <div id="aflMtSortPanel" class="afl-mt-panel" aria-hidden="true">
      <h4>Sort</h4>
      <div class="afl-mt-row">
        <select id="aflMtSortSelect">
          <option value="">Relevance</option>
          <option value="newest">Newest</option>
          <option value="last_active">Last active</option>
          <option value="distance">Distance</option>
          <option value="photos">Most photos</option>
        </select>
      </div>
      <div class="afl-mt-actions">
        <button class="afl-mt-ghost afl-mt-cancel" type="button">Cancel</button>
        <button class="afl-mt-primary afl-mt-apply-sort" type="button">Save</button>
      </div>
    </div>

    <!-- Criteria Modal -->
    <div class="afl-mt-overlay" aria-hidden="true"></div>
    <div class="afl-mt-modal" role="dialog" aria-modal="true" aria-label="Match Criteria">
      <div class="afl-mt-modal-head">
        <h3 class="afl-mt-modal-title">Match Criteria</h3>
        <button class="afl-mt-x" type="button">✕</button>
      </div>

      <div class="afl-mt-tabs">
        <button class="afl-mt-tab is-active" data-tab="basic" type="button">Basic Filters</button>
        <button class="afl-mt-tab" data-tab="advanced" type="button">Advanced Filters</button>
      </div>

      <div class="afl-mt-modal-body">
        <!-- Basic -->
        <div data-afl-tabpanel="basic">

          <div class="afl-mt-section">
            <div class="afl-mt-label">I’m seeking</div>
            <input id="aflSeek" type="hidden" value="any" />
            <div class="afl-mt-seg" role="group" aria-label="Seeking">
              <button type="button" data-seek="any" class="is-active">Any</button>
              <button type="button" data-seek="male">Male</button>
              <button type="button" data-seek="female">Female</button>
            </div>
          </div>

          <div class="afl-mt-section">
            <div class="afl-mt-label">Aged between</div>
            <div class="afl-mt-grid2">
              <select id="aflAgeMin" class="afl-mt-field">
                <?php for($i=18;$i<=80;$i++): ?>
                  <option value="<?php echo esc_attr($i); ?>"><?php echo esc_html($i); ?></option>
                <?php endfor; ?>
              </select>
              <select id="aflAgeMax" class="afl-mt-field">
                <?php for($i=18;$i<=80;$i++): ?>
                  <option value="<?php echo esc_attr($i); ?>"><?php echo esc_html($i); ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>

          <div class="afl-mt-section">
            <div class="afl-mt-label">Living in</div>

            <select id="aflCountry" class="afl-mt-field">
              <option value="">Select country</option>
              <option value="Algeria">Algeria</option>
<option value="Angola">Angola</option>
<option value="Benin">Benin</option>
<option value="Botswana">Botswana</option>
<option value="Burkina Faso">Burkina Faso</option>
<option value="Burundi">Burundi</option>
<option value="Cabo Verde">Cabo Verde</option>
<option value="Cameroon">Cameroon</option>
<option value="Central African Republic">Central African Republic</option>
<option value="Chad">Chad</option>
<option value="Comoros">Comoros</option>
<option value="Republic of the Congo">Republic of the Congo</option>
<option value="Democratic Republic of the Congo">Democratic Republic of the Congo</option>
<option value="Côte d’Ivoire">Côte d’Ivoire</option>
<option value="Djibouti">Djibouti</option>
<option value="Egypt">Egypt</option>
<option value="Equatorial Guinea">Equatorial Guinea</option>
<option value="Eritrea">Eritrea</option>
<option value="Eswatini">Eswatini</option>
<option value="Ethiopia">Ethiopia</option>
<option value="Gabon">Gabon</option>
<option value="The Gambia">The Gambia</option>
<option value="Ghana">Ghana</option>
<option value="Guinea">Guinea</option>
<option value="Guinea-Bissau">Guinea-Bissau</option>
<option value="Kenya">Kenya</option>
<option value="Lesotho">Lesotho</option>
<option value="Liberia">Liberia</option>
<option value="Libya">Libya</option>
<option value="Madagascar">Madagascar</option>
<option value="Malawi">Malawi</option>
<option value="Mali">Mali</option>
<option value="Mauritania">Mauritania</option>
<option value="Mauritius">Mauritius</option>
<option value="Morocco">Morocco</option>
<option value="Mozambique">Mozambique</option>
<option value="Namibia">Namibia</option>
<option value="Niger">Niger</option>
<option value="Nigeria">Nigeria</option>
<option value="Rwanda">Rwanda</option>
<option value="São Tomé and Príncipe">São Tomé and Príncipe</option>
<option value="Senegal">Senegal</option>
<option value="Seychelles">Seychelles</option>
<option value="Sierra Leone">Sierra Leone</option>
<option value="Somalia">Somalia</option>
<option value="South Africa">South Africa</option>
<option value="South Sudan">South Sudan</option>
<option value="Sudan">Sudan</option>
<option value="Tanzania">Tanzania</option>
<option value="Togo">Togo</option>
<option value="Tunisia">Tunisia</option>
<option value="Uganda">Uganda</option>
<option value="Zambia">Zambia</option>
<option value="Zimbabwe">Zimbabwe</option>
<option value="Western Sahara">Western Sahara</option>
            </select>

            <div style="height:10px"></div>

            <select id="aflCity" class="afl-mt-field">
              <option value="">Select city</option>
              <option value="Nasr City">Nasr City</option>
<option value="Heliopolis">Heliopolis</option>
<option value="Maadi">Maadi</option>
<option value="Zamalek">Zamalek</option>
<option value="New Cairo">New Cairo</option>

<option value="Stanley">Stanley</option>
<option value="Smouha">Smouha</option>
<option value="Gleem">Gleem</option>
<option value="Miami">Miami</option>
<option value="Sidi Gaber">Sidi Gaber</option>

<option value="Ikeja">Ikeja</option>
<option value="Lekki">Lekki</option>
<option value="Victoria Island">Victoria Island</option>
<option value="Surulere">Surulere</option>
<option value="Yaba">Yaba</option>

<option value="Wuse">Wuse</option>
<option value="Garki">Garki</option>
<option value="Maitama">Maitama</option>
<option value="Asokoro">Asokoro</option>
<option value="Gwarinpa">Gwarinpa</option>

<option value="Westlands">Westlands</option>
<option value="Kilimani">Kilimani</option>
<option value="Karen">Karen</option>
<option value="Lavington">Lavington</option>
<option value="Embakasi">Embakasi</option>

<option value="Sandton">Sandton</option>
<option value="Rosebank">Rosebank</option>
<option value="Soweto">Soweto</option>
<option value="Midrand">Midrand</option>
<option value="Randburg">Randburg</option>

<option value="Sea Point">Sea Point</option>
<option value="CBD">CBD</option>
<option value="Woodstock">Woodstock</option>
<option value="Claremont">Claremont</option>
<option value="Camps Bay">Camps Bay</option>

<option value="Osu">Osu</option>
<option value="East Legon">East Legon</option>
<option value="Airport Residential">Airport Residential</option>
<option value="Labadi">Labadi</option>
<option value="Tema (nearby)">Tema (nearby)</option>

<option value="Maarif">Maarif</option>
<option value="Ain Diab">Ain Diab</option>
<option value="Sidi Maârouf">Sidi Maârouf</option>
<option value="Bourgogne">Bourgogne</option>
<option value="Hay Hassani">Hay Hassani</option>

<option value="Agdal">Agdal</option>
<option value="Hay Riad">Hay Riad</option>
<option value="Souissi">Souissi</option>
<option value="Hassan">Hassan</option>
<option value="Yacoub El Mansour">Yacoub El Mansour</option>

<option value="La Marsa">La Marsa</option>
<option value="Carthage">Carthage</option>
<option value="Le Bardo">Le Bardo</option>
<option value="El Menzah">El Menzah</option>
<option value="Centre Ville">Centre Ville</option>

<option value="Kinondoni">Kinondoni</option>
<option value="Ilala">Ilala</option>
<option value="Temeke">Temeke</option>
<option value="Msasani">Msasani</option>
<option value="Mikocheni">Mikocheni</option>

<option value="Nyarugenge">Nyarugenge</option>
<option value="Kacyiru">Kacyiru</option>
<option value="Kimironko">Kimironko</option>
<option value="Remera">Remera</option>
<option value="Gikondo">Gikondo</option>

<option value="Plateau">Plateau</option>
<option value="Almadies">Almadies</option>
<option value="Yoff">Yoff</option>
<option value="Parcelles Assainies">Parcelles Assainies</option>
<option value="Ouakam">Ouakam</option>

<option value="Bole">Bole</option>
<option value="Piazza">Piazza</option>
<option value="Kazanchis">Kazanchis</option>
<option value="Merkato">Merkato</option>
<option value="Sar Bet">Sar Bet</option>

<option value="Hydra">Hydra</option>
<option value="Bab El Oued">Bab El Oued</option>
<option value="El Madania">El Madania</option>
<option value="Kouba">Kouba</option>
<option value="Bir Mourad Raïs">Bir Mourad Raïs</option>

<option value="Gargaresh">Gargaresh</option>
<option value="Fashloum">Fashloum</option>
<option value="Suk Al Juma">Suk Al Juma</option>
<option value="Tajoura">Tajoura</option>
<option value="Ain Zara">Ain Zara</option>
            </select>

            <div style="height:10px"></div>

            <select id="aflArea" class="afl-mt-field">
              <option value="">Select area</option>
              <option value="Nasr City">Nasr City</option>
<option value="Heliopolis">Heliopolis</option>
<option value="Maadi">Maadi</option>
<option value="Zamalek">Zamalek</option>
<option value="New Cairo">New Cairo</option>

<option value="Stanley">Stanley</option>
<option value="Smouha">Smouha</option>
<option value="Gleem">Gleem</option>
<option value="Miami">Miami</option>
<option value="Sidi Gaber">Sidi Gaber</option>

<option value="Ikeja">Ikeja</option>
<option value="Lekki">Lekki</option>
<option value="Victoria Island">Victoria Island</option>
<option value="Surulere">Surulere</option>
<option value="Yaba">Yaba</option>

<option value="Wuse">Wuse</option>
<option value="Garki">Garki</option>
<option value="Maitama">Maitama</option>
<option value="Asokoro">Asokoro</option>
<option value="Gwarinpa">Gwarinpa</option>

<option value="Westlands">Westlands</option>
<option value="Kilimani">Kilimani</option>
<option value="Karen">Karen</option>
<option value="Lavington">Lavington</option>
<option value="Embakasi">Embakasi</option>

<option value="Sandton">Sandton</option>
<option value="Rosebank">Rosebank</option>
<option value="Soweto">Soweto</option>
<option value="Midrand">Midrand</option>
<option value="Randburg">Randburg</option>

<option value="Sea Point">Sea Point</option>
<option value="CBD">CBD</option>
<option value="Woodstock">Woodstock</option>
<option value="Claremont">Claremont</option>
<option value="Camps Bay">Camps Bay</option>

<option value="Osu">Osu</option>
<option value="East Legon">East Legon</option>
<option value="Airport Residential">Airport Residential</option>
<option value="Labadi">Labadi</option>
<option value="Tema (nearby)">Tema (nearby)</option>

<option value="Maarif">Maarif</option>
<option value="Ain Diab">Ain Diab</option>
<option value="Sidi Maârouf">Sidi Maârouf</option>
<option value="Bourgogne">Bourgogne</option>
<option value="Hay Hassani">Hay Hassani</option>

<option value="Agdal">Agdal</option>
<option value="Hay Riad">Hay Riad</option>
<option value="Souissi">Souissi</option>
<option value="Hassan">Hassan</option>
<option value="Yacoub El Mansour">Yacoub El Mansour</option>

<option value="La Marsa">La Marsa</option>
<option value="Carthage">Carthage</option>
<option value="Le Bardo">Le Bardo</option>
<option value="El Menzah">El Menzah</option>
<option value="Centre Ville">Centre Ville</option>

<option value="Kinondoni">Kinondoni</option>
<option value="Ilala">Ilala</option>
<option value="Temeke">Temeke</option>
<option value="Msasani">Msasani</option>
<option value="Mikocheni">Mikocheni</option>

<option value="Nyarugenge">Nyarugenge</option>
<option value="Kacyiru">Kacyiru</option>
<option value="Kimironko">Kimironko</option>
<option value="Remera">Remera</option>
<option value="Gikondo">Gikondo</option>

<option value="Plateau">Plateau</option>
<option value="Almadies">Almadies</option>
<option value="Yoff">Yoff</option>
<option value="Parcelles Assainies">Parcelles Assainies</option>
<option value="Ouakam">Ouakam</option>

<option value="Bole">Bole</option>
<option value="Piazza">Piazza</option>
<option value="Kazanchis">Kazanchis</option>
<option value="Merkato">Merkato</option>
<option value="Sar Bet">Sar Bet</option>

<option value="Hydra">Hydra</option>
<option value="Bab El Oued">Bab El Oued</option>
<option value="El Madania">El Madania</option>
<option value="Kouba">Kouba</option>
<option value="Bir Mourad Raïs">Bir Mourad Raïs</option>

<option value="Gargaresh">Gargaresh</option>
<option value="Fashloum">Fashloum</option>
<option value="Suk Al Juma">Suk Al Juma</option>
<option value="Tajoura">Tajoura</option>
<option value="Ain Zara">Ain Zara</option>
            </select>
          </div>

          <div class="afl-mt-section">
            <div class="afl-mt-label">Within</div>
            <select id="aflWithin" class="afl-mt-field">
              <option value="">Any distance</option>
              <option value="10">10 kms</option>
              <option value="25">25 kms</option>
              <option value="50">50 kms</option>
              <option value="100">100 kms</option>
              <option value="250">250 kms</option>
              <option value="500">500 kms</option>
            </select>
          </div>

        </div>

        <!-- Advanced -->
        <div data-afl-tabpanel="advanced" style="display:none">
          <div class="afl-mt-section">
            <div class="afl-mt-grid2">
              <label style="display:flex;gap:10px;align-items:center">
                <input id="aflVerified" type="checkbox" />
                <span style="font-weight:900">Verified only</span>
              </label>

              <label style="display:flex;gap:10px;align-items:center">
                <input id="aflWithPhoto" type="checkbox" />
                <span style="font-weight:900">With photo</span>
              </label>
            </div>
          </div>
          <p style="padding:0 2px 10px;font-size:12px;opacity:.7;margin:0">
            UI is ready. Your listing query just needs to read the URL params (seek, ageMin, ageMax, country, city, area, within, verified, with_photo).
          </p>
        </div>

      </div>

      <div class="afl-mt-modal-foot">
        <button class="afl-mt-ghost afl-mt-cancel" type="button">Cancel</button>
        <button class="afl-mt-primary afl-mt-apply-criteria" type="button">Save</button>
      </div>
    </div>

    <?php
    return ob_get_clean();
  }
}

AFL_Meet_Singles_Header_UI::init();