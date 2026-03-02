<?php
/**
 * Plugin Name: AFL Match Toolbar UI
 * Description: Top filter toolbar UI (Match Criteria, Sort, Matches, Mutual Matches...) with modal + querystring apply.
 * Version: 1.0.0
 * Author: Felix Cordero Jr.
 * Shortcode: [afl_match_toolbar]
 */

if ( ! defined('ABSPATH') ) exit;

final class AFL_Match_Toolbar_UI {

  const VER = '1.0.0';

  public static function init(){
    add_shortcode('afl_match_toolbar', [__CLASS__, 'shortcode']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
  }

  public static function assets(){
    if ( is_admin() ) return;

    wp_register_style('afl-match-toolbar', false, [], self::VER);
    wp_register_script('afl-match-toolbar', false, ['jquery'], self::VER, true);

    $css = '
/* Wrap */
.afl-mt-wrap{width:100%;max-width:auto;margin:12px auto 0;padding:0 14px;box-sizing:border-box;}
.afl-mt-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

/* Left controls */
.afl-mt-btn{
  display:inline-flex;align-items:center;gap:8px;
  height:40px;padding:0 12px;border-radius:10px;
  border:1px solid #e5e7eb;background:#fff;color:#111827;
  cursor:pointer;user-select:none;font-weight:700;font-size:13px;
  box-shadow:0 6px 16px rgba(0,0,0,.06);
}
.afl-mt-btn:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(0,0,0,.10)}
.afl-mt-icon{font-size:16px;line-height:1}
.afl-mt-btn small{font-weight:600;opacity:.75}

/* Pills */
.afl-mt-pill{
  display:inline-flex;align-items:center;gap:8px;
  height:40px;padding:0 14px;border-radius:999px;
  border:1px solid #e5e7eb;background:#fff;color:#111827;
  cursor:pointer;user-select:none;font-weight:800;font-size:13px;
}
.afl-mt-pill.is-active{
  background:#7b001a;color:#fff;border-color:#7b001a;
}
.afl-mt-pill:hover{filter:brightness(.98)}
.afl-mt-pill .afl-mt-dot{width:10px;height:10px;border-radius:99px;background:#11182722;display:inline-block}
.afl-mt-pill.is-active .afl-mt-dot{background:#ffffff55}

/* Panels */
.afl-mt-panel{
  position:absolute;z-index:999999;
  background:#fff;border:1px solid #e5e7eb;border-radius:14px;
  box-shadow:0 18px 50px rgba(0,0,0,.18);
  min-width:260px;padding:10px;display:none;
}
.afl-mt-panel h4{margin:6px 8px 10px;font-size:14px}
.afl-mt-panel .afl-mt-row{display:flex;gap:8px;align-items:center;margin:8px}
.afl-mt-panel select,.afl-mt-panel input{
  width:100%;height:40px;border-radius:10px;border:1px solid #e5e7eb;padding:0 10px;font-size:13px;outline:none;
}
.afl-mt-panel .afl-mt-actions{display:flex;gap:10px;padding:10px 8px 4px}
.afl-mt-panel .afl-mt-ghost,
.afl-mt-panel .afl-mt-primary{
  height:40px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;
  padding:0 14px;cursor:pointer;font-weight:800;
}
.afl-mt-panel .afl-mt-primary{background:#7b001a;color:#fff;border-color:#7b001a;flex:1}
.afl-mt-panel .afl-mt-ghost{flex:1}

/* Modal overlay */
.afl-mt-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.35);
  z-index:999998;display:none;
}
.afl-mt-modal{
  position:fixed;left:50%;top:54%;transform:translate(-50%,-50%);
  width:min(560px,calc(100vw - 26px));
  background:#fff;border:1px solid #e5e7eb;border-radius:18px;
  z-index:999999;display:none;
  box-shadow:0 26px 70px rgba(0,0,0,.28);
}
.afl-mt-modal-head{
  padding:16px 16px 10px;display:flex;align-items:center;justify-content:space-between;
}
.afl-mt-modal-title{font-size:20px;font-weight:900;margin:0}
.afl-mt-x{
  width:38px;height:38px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;
  font-size:18px;display:flex;align-items:center;justify-content:center;
}
.afl-mt-tabs{display:flex;gap:10px;padding:0 16px 12px}
.afl-mt-tab{
  height:36px;padding:0 14px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-weight:900;
}
.afl-mt-tab.is-active{background:#7b001a;color:#fff;border-color:#7b001a}
.afl-mt-modal-body{padding:0 16px 6px}
.afl-mt-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:560px){.afl-mt-grid{grid-template-columns:1fr}}

.afl-mt-label{font-size:12px;font-weight:800;opacity:.7;margin:6px 2px}
.afl-mt-modal-foot{padding:12px 16px 16px;display:flex;gap:10px}
.afl-mt-modal-foot .afl-mt-ghost,
.afl-mt-modal-foot .afl-mt-primary{height:44px;border-radius:12px;font-weight:900;cursor:pointer}
.afl-mt-modal-foot .afl-mt-primary{flex:1;border-color:#7b001a;background:#7b001a;color:#fff}
.afl-mt-modal-foot .afl-mt-ghost{flex:1;border:1px solid #e5e7eb;background:#fff}

/* Make it sit “below nav” nicely */
.afl-mt-spacer{height:6px}
';

    $js = '
(function($){
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

  function openOverlay(){ $(".afl-mt-overlay").show(); }
  function closeOverlay(){
    $(".afl-mt-overlay").hide();
    $(".afl-mt-modal").hide();
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
    const isOpen=$(panel).is(":visible");
    $(".afl-mt-panel").hide();
    if(isOpen){ $(panel).hide(); return; }
    $(panel).show();
    positionPanel(this, panel);
  });

  // Open Match Criteria modal
  $(document).on("click",".afl-mt-open-criteria",function(e){
    e.preventDefault(); e.stopPropagation();
    openOverlay();
    $(".afl-mt-modal").show();
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

  // Apply Sort
  $(document).on("click",".afl-mt-apply-sort",function(){
    const q=qsGet();
    q.sort = $("#aflMtSortSelect").val() || "";
    qsSet(q);
  });

  // Apply Criteria
  $(document).on("click",".afl-mt-apply-criteria",function(){
    const q=qsGet();
    q.seek   = $("#aflSeek").val() || "";
    q.ageMin = $("#aflAgeMin").val() || "";
    q.ageMax = $("#aflAgeMax").val() || "";
    q.country= $("#aflCountry").val() || "";
    q.city   = $("#aflCity").val() || "";
    q.area   = $("#aflArea").val() || "";
    // advanced
    q.verified = $("#aflVerified").is(":checked") ? "1" : "";
    q.with_photo = $("#aflWithPhoto").is(":checked") ? "1" : "";
    closeOverlay();
    qsSet(q);
  });

  // Cancel buttons
  $(document).on("click",".afl-mt-cancel",function(){
    closeOverlay();
  });

  // Close panels on outside click
  $(document).on("click",function(){ $(".afl-mt-panel").hide(); });

  // Stop click inside panel/modal from closing
  $(document).on("click",".afl-mt-panel, .afl-mt-modal",function(e){ e.stopPropagation(); });

  // Init active pills
  $(function(){
    setActivePills();
    // prefill from query
    const q=qsGet();
    $("#aflMtSortSelect").val(q.sort || "");
    $("#aflSeek").val(q.seek || "any");
    $("#aflAgeMin").val(q.ageMin || "20");
    $("#aflAgeMax").val(q.ageMax || "25");
    $("#aflCountry").val(q.country || "");
    $("#aflCity").val(q.city || "");
    $("#aflArea").val(q.area || "");
    $("#aflVerified").prop("checked", q.verified==="1");
    $("#aflWithPhoto").prop("checked", q.with_photo==="1");
  });

})(jQuery);
';

    wp_add_inline_style('afl-match-toolbar', $css);
    wp_add_inline_script('afl-match-toolbar', $js);
    wp_enqueue_style('afl-match-toolbar');
    wp_enqueue_script('afl-match-toolbar');
  }

  public static function shortcode($atts){
    if ( ! is_user_logged_in() ) return '';

    ob_start(); ?>
    <div class="afl-mt-wrap">
      <div class="afl-mt-spacer"></div>

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

        <!-- Pills (like your red checks) -->
        <a href="#" class="afl-mt-pill" data-afl-pill="tab" data-afl-val="matches"><span class="afl-mt-dot"></span>Matches</a>
        <a href="#" class="afl-mt-pill" data-afl-pill="tab" data-afl-val="mutual"><span class="afl-mt-dot"></span>Mutual Matches</a>
    <!--<a href="#" class="afl-mt-pill" data-afl-pill="tab" data-afl-val="reverse"><span class="afl-mt-dot"></span>Reverse Matches</a>

        <a href="#" class="afl-mt-pill" data-afl-pill="filter" data-afl-val="online"><span class="afl-mt-dot"></span>Online</a>
        <a href="#" class="afl-mt-pill" data-afl-pill="filter" data-afl-val="popular"><span class="afl-mt-dot"></span>Popular</a>
        <a href="#" class="afl-mt-pill" data-afl-pill="filter" data-afl-val="newest"><span class="afl-mt-dot"></span>Newest members</a>
        <a href="#" class="afl-mt-pill" data-afl-pill="filter" data-afl-val="photos"><span class="afl-mt-dot"></span>Latest Photos</a>
        <a href="#" class="afl-mt-pill" data-afl-pill="filter" data-afl-val="area"><span class="afl-mt-dot"></span>In My Area</a> 
    -->
        
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

        <!-- Advanced -->
        <div data-afl-tabpanel="advanced" style="display:none">
          <div class="afl-mt-row" style="justify-content:space-between">
            <div>
              <div class="afl-mt-label">Verified only</div>
              <input id="aflVerified" type="checkbox">
            </div>
            <div>
              <div class="afl-mt-label">With photo</div>
              <input id="aflWithPhoto" type="checkbox">
            </div>
          </div>

          <div class="afl-mt-label">Extra</div>
          <div class="afl-mt-row">
            <input id="aflArea2" type="text" placeholder="Optional: Interests, keywords, etc. (wire to your logic later)" disabled>
          </div>
          <p style="padding:0 8px 10px;font-size:12px;opacity:.7;margin:0">
            This UI is ready; you just need your listing query to read the URL params.
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
