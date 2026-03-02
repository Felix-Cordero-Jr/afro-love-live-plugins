<?php
/**
 * Plugin Name: AFL Match Toolbar UI
 * Description: Top filter toolbar UI (Match Criteria, Sort, Matches, Mutual Matches...) with modal + querystring apply.
 * Version: 1.0.1
 * Author: Felix Cordero Jr.
 * Shortcode: [afl_match_toolbar]
 */

if ( ! defined('ABSPATH') ) exit;

final class AFL_Match_Toolbar_UI {

  /** Plugin version for cache-busting inline assets */
  const VER = '1.0.1';

  /**
   * Bootstraps the plugin:
   * - Registers shortcode
   * - Enqueues assets (only when shortcode exists on the page)
   */
  public static function init() {
    add_shortcode('afl_match_toolbar', [__CLASS__, 'shortcode']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
  }

  /**
   * Checks if the current page content contains our shortcode.
   * This prevents loading CSS/JS site-wide.
   */
  private static function page_has_shortcode() {
    if ( is_admin() ) return false;

    global $post;
    if ( empty($post) || empty($post->post_content) ) return false;

    return has_shortcode($post->post_content, 'afl_match_toolbar');
  }

  /**
   * Registers and enqueues inline CSS/JS for the toolbar UI.
   * Runs on front-end only and only if shortcode is present.
   */
  public static function assets() {
    if ( ! self::page_has_shortcode() ) return;

    // Register “virtual” handles (inline style/script attached to these).
    wp_register_style('afl-match-toolbar', false, [], self::VER);
    wp_register_script('afl-match-toolbar', false, ['jquery'], self::VER, true);

    /**
     * =========================
     * CSS (UI / Layout / Modal)
     * =========================
     */
    $css = '
/* Wrap container */
.afl-mt-wrap{
  width:100%;
  margin:12px auto 0;
  padding:0 14px;
  box-sizing:border-box;
}
.afl-mt-bar{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}

/* Left controls (Match Criteria / Sort) */
.afl-mt-btn{
  display:inline-flex;
  align-items:center;
  gap:8px;
  height:40px;
  padding:0 12px;
  border-radius:10px;
  border:1px solid #e5e7eb;
  background:#fff;
  color:#111827;
  cursor:pointer;
  user-select:none;
  font-weight:700;
  font-size:13px;
  box-shadow:0 6px 16px rgba(0,0,0,.06);
  transition:transform .12s ease, box-shadow .12s ease, filter .12s ease;
}
.afl-mt-btn:hover{
  transform:translateY(-1px);
  box-shadow:0 10px 22px rgba(0,0,0,.10);
}
.afl-mt-icon{font-size:16px;line-height:1}
.afl-mt-btn small{font-weight:600;opacity:.75}

/* Pills (Matches / Mutual Matches etc.) */
.afl-mt-pill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  height:40px;
  padding:0 14px;
  border-radius:999px;
  border:1px solid #e5e7eb;
  background:#fff;
  color:#111827;
  cursor:pointer;
  user-select:none;
  font-weight:800;
  font-size:13px;
  text-decoration:none;
  transition:filter .12s ease;
}
.afl-mt-pill.is-active{
  background:#7b001a;
  color:#fff;
  border-color:#7b001a;
}
.afl-mt-pill:hover{filter:brightness(.98)}
.afl-mt-pill .afl-mt-dot{
  width:10px;height:10px;border-radius:99px;
  background:#11182722;
  display:inline-block
}
.afl-mt-pill.is-active .afl-mt-dot{background:#ffffff55}

/* Sort dropdown panel */
.afl-mt-panel{
  position:absolute;
  z-index:999999;
  background:#fff;
  border:1px solid #e5e7eb;
  border-radius:14px;
  box-shadow:0 18px 50px rgba(0,0,0,.18);
  min-width:260px;
  padding:10px;
  display:none;
}
.afl-mt-panel h4{margin:6px 8px 10px;font-size:14px}
.afl-mt-panel .afl-mt-row{
  display:flex;
  gap:8px;
  align-items:center;
  margin:8px;
}
.afl-mt-panel select,.afl-mt-panel input{
  width:100%;
  height:40px;
  border-radius:10px;
  border:1px solid #e5e7eb;
  padding:0 10px;
  font-size:13px;
  outline:none;
}
.afl-mt-panel .afl-mt-actions{
  display:flex;
  gap:10px;
  padding:10px 8px 4px;
}
.afl-mt-panel .afl-mt-ghost,
.afl-mt-panel .afl-mt-primary{
  height:40px;
  border-radius:10px;
  border:1px solid #e5e7eb;
  background:#fff;
  padding:0 14px;
  cursor:pointer;
  font-weight:800;
}
.afl-mt-panel .afl-mt-primary{
  background:#7b001a;
  color:#fff;
  border-color:#7b001a;
  flex:1;
}
.afl-mt-panel .afl-mt-ghost{flex:1}

/* Modal overlay + modal */
.afl-mt-overlay{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.35);
  z-index:999998;
  display:none;
}
.afl-mt-modal{
  position:fixed;
  left:50%;
  top:54%;
  transform:translate(-50%,-50%);
  width:min(560px,calc(100vw - 26px));
  background:#fff;
  border:1px solid #e5e7eb;
  border-radius:18px;
  z-index:999999;
  display:none;
  box-shadow:0 26px 70px rgba(0,0,0,.28);
}
.afl-mt-modal-head{
  padding:16px 16px 10px;
  display:flex;
  align-items:center;
  justify-content:space-between;
}
.afl-mt-modal-title{font-size:20px;font-weight:900;margin:0}
.afl-mt-x{
  width:38px;height:38px;
  border-radius:999px;
  border:1px solid #e5e7eb;
  background:#fff;
  cursor:pointer;
  font-size:18px;
  display:flex;
  align-items:center;
  justify-content:center;
}
.afl-mt-tabs{display:flex;gap:10px;padding:0 16px 12px}
.afl-mt-tab{
  height:36px;
  padding:0 14px;
  border-radius:999px;
  border:1px solid #e5e7eb;
  background:#fff;
  cursor:pointer;
  font-weight:900;
}
.afl-mt-tab.is-active{
  background:#7b001a;
  color:#fff;
  border-color:#7b001a;
}
.afl-mt-modal-body{padding:0 16px 6px}
.afl-mt-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:560px){.afl-mt-grid{grid-template-columns:1fr}}

.afl-mt-label{
  font-size:12px;
  font-weight:800;
  opacity:.7;
  margin:6px 2px;
}
.afl-mt-modal-foot{
  padding:12px 16px 16px;
  display:flex;
  gap:10px;
}
.afl-mt-modal-foot .afl-mt-ghost,
.afl-mt-modal-foot .afl-mt-primary{
  height:44px;
  border-radius:12px;
  font-weight:900;
  cursor:pointer;
}
.afl-mt-modal-foot .afl-mt-primary{
  flex:1;
  border-color:#7b001a;
  background:#7b001a;
  color:#fff;
}
.afl-mt-modal-foot .afl-mt-ghost{
  flex:1;
  border:1px solid #e5e7eb;
  background:#fff;
}

/* Tiny spacer so it breathes under nav */
.afl-mt-spacer{height:6px}
';

    /**
     * =========================
     * JS (Querystring + UI)
     * =========================
     */
    $js = '
(function($){

  /**
   * Reads current URL querystring into a plain object
   * Example: ?tab=matches&sort=newest -> {tab:"matches", sort:"newest"}
   */
  function qsGet(){
    const out = {};
    const p = new URLSearchParams(window.location.search);
    p.forEach((v,k)=>out[k]=v);
    return out;
  }

  /**
   * Writes the provided object back to URL querystring.
   * Empty values are removed to keep URLs clean.
   * Triggers navigation (page reload).
   */
  function qsSet(obj){
    const p = new URLSearchParams();
    Object.keys(obj).forEach(k=>{
      const v = obj[k];
      if(v !== null && v !== undefined && String(v).trim() !== "") p.set(k, String(v));
    });
    const url = window.location.pathname + "?" + p.toString() + window.location.hash;
    window.location.href = url;
  }

  /** Opens overlay (background dimmer) */
  function openOverlay(){
    $(".afl-mt-overlay").show().attr("aria-hidden","false");
  }

  /**
   * Closes everything:
   * - Overlay
   * - Modal
   * - Dropdown panels
   */
  function closeOverlay(){
    $(".afl-mt-overlay").hide().attr("aria-hidden","true");
    $(".afl-mt-modal").hide().attr("aria-hidden","true");
    $(".afl-mt-panel").hide().attr("aria-hidden","true");
  }

  /**
   * Sets .is-active on pills based on current querystring values.
   * Example: <a data-afl-pill="tab" data-afl-val="matches"> becomes active if ?tab=matches
   */
  function setActivePills(){
    const q = qsGet();
    $("[data-afl-pill]").each(function(){
      const key = $(this).data("afl-pill");
      const val = $(this).data("afl-val");
      const on  = (q[key] === String(val));
      $(this).toggleClass("is-active", on);
    });
  }

  /**
   * Positions dropdown panel under a button with viewport edge protection.
   */
  function positionPanel(btn, panel){
    const r = btn.getBoundingClientRect();
    const top  = r.bottom + 8 + window.scrollY;

    // Keep panel within viewport width
    const maxLeft = window.scrollX + window.innerWidth - panel.offsetWidth - 12;
    const left = Math.min(r.left + window.scrollX, maxLeft);

    panel.style.top  = top + "px";
    panel.style.left = left + "px";
  }

  /* =========================
   * UI EVENTS
   * ========================= */

  // Toggle Sort dropdown panel
  $(document).on("click", ".afl-mt-open-sort", function(e){
    e.preventDefault();
    e.stopPropagation();

    const panel = document.getElementById("aflMtSortPanel");
    const isOpen = $(panel).is(":visible");

    $(".afl-mt-panel").hide().attr("aria-hidden","true");

    if(isOpen){
      $(panel).hide().attr("aria-hidden","true");
      return;
    }

    $(panel).show().attr("aria-hidden","false");
    positionPanel(this, panel);
  });

  // Open Match Criteria modal
  $(document).on("click", ".afl-mt-open-criteria", function(e){
    e.preventDefault();
    e.stopPropagation();
    openOverlay();
    $(".afl-mt-modal").show().attr("aria-hidden","false");
  });

  // Close modal when clicking X or overlay
  $(document).on("click", ".afl-mt-x, .afl-mt-overlay", function(){
    closeOverlay();
  });

  // Close everything on ESC
  $(document).on("keydown", function(e){
    if(e.key === "Escape") closeOverlay();
  });

  // Tabs inside criteria modal (Basic / Advanced)
  $(document).on("click", ".afl-mt-tab", function(){
    const t = $(this).data("tab");
    $(".afl-mt-tab").removeClass("is-active");
    $(this).addClass("is-active");
    $("[data-afl-tabpanel]").hide().attr("aria-hidden","true");
    $(`[data-afl-tabpanel="${t}"]`).show().attr("aria-hidden","false");
  });

  // Pill click -> set querystring (used for Matches / Mutual Matches etc.)
  $(document).on("click", "[data-afl-pill]", function(e){
    e.preventDefault();
    const key = $(this).data("afl-pill");
    const val = $(this).data("afl-val");

    const q = qsGet();
    q[key] = String(val);

    qsSet(q); // reload with new params
  });

  // Apply Sort -> writes ?sort=value
  $(document).on("click", ".afl-mt-apply-sort", function(){
    const q = qsGet();
    q.sort = $("#aflMtSortSelect").val() || "";
    qsSet(q);
  });

  // Apply Criteria -> writes all fields to querystring
  $(document).on("click", ".afl-mt-apply-criteria", function(){
    const q = qsGet();

    // Basic filters
    q.seek    = $("#aflSeek").val() || "";
    q.ageMin  = $("#aflAgeMin").val() || "";
    q.ageMax  = $("#aflAgeMax").val() || "";
    q.country = $("#aflCountry").val() || "";
    q.city    = $("#aflCity").val() || "";
    q.area    = $("#aflArea").val() || "";

    // Advanced filters
    q.verified   = $("#aflVerified").is(":checked") ? "1" : "";
    q.with_photo = $("#aflWithPhoto").is(":checked") ? "1" : "";

    closeOverlay();
    qsSet(q);
  });

  // Cancel buttons just close the modal/panel without changing URL
  $(document).on("click", ".afl-mt-cancel", function(){
    closeOverlay();
  });

  // Click outside closes dropdown panels
  $(document).on("click", function(){
    $(".afl-mt-panel").hide().attr("aria-hidden","true");
  });

  // Prevent inside-click from bubbling and closing things
  $(document).on("click", ".afl-mt-panel, .afl-mt-modal", function(e){
    e.stopPropagation();
  });

  /* =========================
   * INIT (On page load)
   * ========================= */
  $(function(){
    setActivePills();

    // Prefill inputs from querystring so UI reflects current state
    const q = qsGet();

    $("#aflMtSortSelect").val(q.sort || "");

    $("#aflSeek").val(q.seek || "any");
    $("#aflAgeMin").val(q.ageMin || "20");
    $("#aflAgeMax").val(q.ageMax || "25");

    $("#aflCountry").val(q.country || "");
    $("#aflCity").val(q.city || "");
    $("#aflArea").val(q.area || "");

    $("#aflVerified").prop("checked", q.verified === "1");
    $("#aflWithPhoto").prop("checked", q.with_photo === "1");
  });

})(jQuery);
';

    // Attach inline assets
    wp_add_inline_style('afl-match-toolbar', $css);
    wp_add_inline_script('afl-match-toolbar', $js);

    // Enqueue
    wp_enqueue_style('afl-match-toolbar');
    wp_enqueue_script('afl-match-toolbar');
  }

  /**
   * Shortcode output:
   * - Toolbar UI
   * - Sort dropdown panel
   * - Match Criteria modal
   *
   * IMPORTANT:
   * This plugin only writes filters to the URL (querystring).
   * Your member directory query must read $_GET and apply filtering.
   */
  public static function shortcode($atts) {
    if ( ! is_user_logged_in() ) return '';

    ob_start(); ?>
    <div class="afl-mt-wrap">
      <div class="afl-mt-spacer"></div>

      <div class="afl-mt-bar">

        <!-- Match Criteria button opens modal -->
        <button class="afl-mt-btn afl-mt-open-criteria" type="button" aria-haspopup="dialog">
          <span class="afl-mt-icon">⚙️</span>
          <span>Match Criteria</span>
        </button>

        <!-- Sort button opens dropdown panel -->
        <button class="afl-mt-btn afl-mt-open-sort" type="button" aria-haspopup="menu" aria-controls="aflMtSortPanel">
          <span class="afl-mt-icon">⇅</span>
          <span>Sort</span>
          <small>▼</small>
        </button>

        <!-- Pills (these set ?tab=matches or ?tab=mutual) -->
        <a href="#" class="afl-mt-pill" data-afl-pill="tab" data-afl-val="matches">
          <span class="afl-mt-dot"></span>Matches
        </a>
        <a href="#" class="afl-mt-pill" data-afl-pill="tab" data-afl-val="mutual">
          <span class="afl-mt-dot"></span>Mutual Matches
        </a>

        <?php /* Add more pills later as needed:
        <a href="#" class="afl-mt-pill" data-afl-pill="tab" data-afl-val="reverse"><span class="afl-mt-dot"></span>Reverse Matches</a>
        <a href="#" class="afl-mt-pill" data-afl-pill="filter" data-afl-val="online"><span class="afl-mt-dot"></span>Online</a>
        */ ?>

      </div>
    </div>

    <!-- Sort Panel (dropdown) -->
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

    <div class="afl-mt-modal" role="dialog" aria-modal="true" aria-label="Match Criteria" aria-hidden="true">
      <div class="afl-mt-modal-head">
        <h3 class="afl-mt-modal-title">Match Criteria</h3>
        <button class="afl-mt-x" type="button" aria-label="Close">✕</button>
      </div>

      <div class="afl-mt-tabs">
        <button class="afl-mt-tab is-active" data-tab="basic" type="button">Basic Filters</button>
        <button class="afl-mt-tab" data-tab="advanced" type="button">Advanced Filters</button>
      </div>

      <div class="afl-mt-modal-body">

        <!-- Basic Tab -->
        <div data-afl-tabpanel="basic" aria-hidden="false">

          <div class="afl-mt-label">I’m seeking</div>
          <div class="afl-mt-row">
            <select id="aflSeek">
              <option value="any">Any</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </div>

          <div class="afl-mt-grid">
            <div>
              <div class="afl-mt-label">Aged between</div>
              <select id="aflAgeMin">
                <?php for($i=18;$i<=80;$i++): ?>
                  <option value="<?php echo esc_attr($i); ?>"><?php echo esc_html($i); ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div>
              <div class="afl-mt-label">&nbsp;</div>
              <select id="aflAgeMax">
                <?php for($i=18;$i<=80;$i++): ?>
                  <option value="<?php echo esc_attr($i); ?>"><?php echo esc_html($i); ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>

          <div class="afl-mt-label">Living in</div>
          <div class="afl-mt-row"><input id="aflCountry" type="text" placeholder="Country (e.g., Philippines)"></div>
          <div class="afl-mt-row"><input id="aflCity" type="text" placeholder="City (e.g., Manila)"></div>
          <div class="afl-mt-row"><input id="aflArea" type="text" placeholder="Area (e.g., Taguig)"></div>
        </div>

        <!-- Advanced Tab -->
        <div data-afl-tabpanel="advanced" aria-hidden="true" style="display:none">

          <div class="afl-mt-row" style="justify-content:space-between">
            <label style="display:flex;flex-direction:column;gap:6px">
              <span class="afl-mt-label">Verified only</span>
              <input id="aflVerified" type="checkbox">
            </label>

            <label style="display:flex;flex-direction:column;gap:6px">
              <span class="afl-mt-label">With photo</span>
              <input id="aflWithPhoto" type="checkbox">
            </label>
          </div>

          <div class="afl-mt-label">Extra</div>
          <div class="afl-mt-row">
            <input type="text" placeholder="Optional: Interests, keywords, etc. (wire to your logic later)" disabled>
          </div>

          <p style="padding:0 8px 10px;font-size:12px;opacity:.7;margin:0">
            UI is ready. Next step is making your member grid read these URL params and filter results.
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

AFL_Match_Toolbar_UI::init();