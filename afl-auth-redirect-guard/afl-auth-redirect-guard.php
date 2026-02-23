<?php
/**
 * Plugin Name: AFL Auth Redirect Guard
 * Description: Redirect logged-out users away from protected pages (Meet Singles / Messages / Activities) back to /home/ when session expires.
 * Version: 1.0.1
 * Author: Felix Cordero Jr.
 */

if ( ! defined('ABSPATH') ) { exit; }

final class AFL_Auth_Redirect_Guard {

  /* ============================================================
   * ✅ SETTINGS: Edit these only
   * ============================================================ */

  // ✅ Where to send users if they're not logged in
  // Requirement: If they close the browser while on /meet-singles/, reopening should redirect to /home/
  const REDIRECT_TO = '/home/';

  /**
   * ✅ Protected page slugs (URLs) that REQUIRE login.
   * Add/remove your slugs here.
   */
  private static $protected_paths = [
    '/meet-singles',
    '/message',
    '/messages',
    '/activities',
    '/activity',
    '/matches',
    '/my-account',
    '/profile',
    '/members',
  ];

  /* ============================================================
   * ✅ BOOT
   * ============================================================ */
  public static function init(){
    add_action('template_redirect', [__CLASS__, 'redirect_logged_out_users'], 1);
  }

  /* ============================================================
   * ✅ MAIN REDIRECT LOGIC
   * ============================================================ */
  public static function redirect_logged_out_users(){

    // 1) Ignore admin + login page + ajax + REST
    if ( is_admin() ) return;

    if ( (defined('DOING_AJAX') && DOING_AJAX) ) return;
    if ( defined('REST_REQUEST') && REST_REQUEST ) return;

    $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( strpos($req_uri, 'wp-login.php') !== false ) return;

    // 2) If user IS logged in, do nothing
    if ( is_user_logged_in() ) return;

    // 3) If user is NOT logged in and visiting protected URL => redirect to /home/
    $path = self::normalize_path($req_uri);

    if ( self::is_protected_path($path) ) {

      // ✅ Build target URL safely
      $target = home_url(self::REDIRECT_TO);

      // ✅ Safety fallback (in case /home/ doesn't exist for some reason)
      if ( empty($target) ) {
        $target = home_url('/');
      }

      wp_safe_redirect($target);
      exit;
    }
  }

  /* ============================================================
   * ✅ HELPERS
   * ============================================================ */

  // Normalize /path/?x=1 to just "/path"
  private static function normalize_path($uri){
    $uri = strtok($uri, '?'); // remove query string
    $uri = trim($uri);

    if ($uri === '') $uri = '/';
    if ($uri[0] !== '/') $uri = '/' . $uri;

    if ($uri !== '/') $uri = rtrim($uri, '/');

    return strtolower($uri);
  }

  // Match exact or prefix paths
  private static function is_protected_path($path){
    $path = strtolower($path);

    foreach ( self::$protected_paths as $p ){
      $p = strtolower(rtrim($p, '/'));

      // exact match: /meet-singles
      if ( $path === $p ) return true;

      // prefix match: /meet-singles/anything
      if ( strpos($path, $p . '/') === 0 ) return true;
    }

    return false;
  }
}

AFL_Auth_Redirect_Guard::init();
