<?php
/**
 * Plugin Name: Hamado Card API
 * Description: REST API for Hamado Card (users, wallet, orders, deposits, transactions, KYC, admin) using WordPress DB.
 * Version: 1.1.0
 * Author: Hamado Card
 */

if (!defined('ABSPATH')) {
  exit;
}

class Hamado_Card_API {
  const NS = 'hamado/v1';

  public function __construct() {
    register_activation_hook(__FILE__, [$this, 'activate']);
    add_action('rest_api_init', [$this, 'routes']);
  }

  public function activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $sql = [];
    $sql[] = "CREATE TABLE {$wpdb->prefix}hc_sessions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      token VARCHAR(128) NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      type VARCHAR(20) NOT NULL DEFAULT 'user',
      ip VARCHAR(64) NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY token (token),
      KEY user_id (user_id),
      KEY type (type)
    ) {$charset};";

    $sql[] = "CREATE TABLE {$wpdb->prefix}hc_orders (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      order_number VARCHAR(64) NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL,
      product_id VARCHAR(64) NOT NULL,
      product_name VARCHAR(191) NOT NULL,
      product_icon VARCHAR(32) NULL,
      player_id VARCHAR(191) NULL,
      quantity INT NOT NULL DEFAULT 1,
      total_price DECIMAL(10,3) NOT NULL DEFAULT 0,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      admin_note TEXT NULL,
      ip VARCHAR(64) NULL,
      transaction_type VARCHAR(32) NOT NULL DEFAULT 'purchase',
      payment_type VARCHAR(32) NOT NULL DEFAULT 'wallet',
      created_at DATETIME NOT NULL,
      completed_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY order_number (order_number),
      KEY user_id (user_id),
      KEY status (status)
    ) {$charset};";

    $sql[] = "CREATE TABLE {$wpdb->prefix}hc_deposits (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      amount DECIMAL(10,3) NOT NULL,
      method VARCHAR(64) NOT NULL,
      ref_code VARCHAR(191) NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      ip VARCHAR(64) NULL,
      payment_type VARCHAR(32) NOT NULL DEFAULT 'manual',
      created_at DATETIME NOT NULL,
      approved_at DATETIME NULL,
      rejected_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY user_id (user_id),
      KEY status (status)
    ) {$charset};";

    $sql[] = "CREATE TABLE {$wpdb->prefix}hc_transactions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      type VARCHAR(32) NOT NULL,
      amount DECIMAL(10,3) NOT NULL,
      description VARCHAR(191) NULL,
      payment_type VARCHAR(32) NULL,
      transaction_type VARCHAR(32) NULL,
      ip VARCHAR(64) NULL,
      balance_before DECIMAL(10,3) NULL,
      balance_after DECIMAL(10,3) NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY user_id (user_id),
      KEY type (type)
    ) {$charset};";

    $sql[] = "CREATE TABLE {$wpdb->prefix}hc_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NULL,
      action_name VARCHAR(64) NOT NULL,
      ip VARCHAR(64) NULL,
      details LONGTEXT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY user_id (user_id),
      KEY action_name (action_name)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach ($sql as $q) {
      dbDelta($q);
    }

    add_option('hc_usd_rate', 14000);
    add_option('hc_admin_user', 'admin');
    if (!get_option('hc_admin_pass_hash')) {
      add_option('hc_admin_pass_hash', wp_hash_password('Hamado@2025!'));
    }
  }

  public function routes() {
    // Public endpoints.
    $this->route('/settings/public', 'GET', 'settings_public', '__return_true');
    $this->route('/products', 'GET', 'products', '__return_true');
    $this->route('/auth/register', 'POST', 'register', '__return_true');
    $this->route('/auth/login', 'POST', 'login', '__return_true');
    $this->route('/auth/google', 'POST', 'google_login', '__return_true');
    $this->route('/auth/admin-login', 'POST', 'admin_login', '__return_true');

    // User-auth endpoints.
    $userPerm = [$this, 'permission_user'];
    $this->route('/auth/me', 'GET', 'me', $userPerm);
    $this->route('/auth/logout', 'POST', 'logout', $userPerm);
    $this->route('/wallet', 'GET', 'wallet', $userPerm);
    $this->route('/wallet/transactions', 'GET', 'wallet_transactions', $userPerm);
    $this->route('/wallet/deposit', 'POST', 'wallet_deposit', $userPerm);
    $this->route('/orders', 'GET', 'orders', $userPerm);
    $this->route('/orders/create', 'POST', 'create_order', $userPerm);
    $this->route('/kyc/submit', 'POST', 'kyc_submit', $userPerm);
    $this->route('/request-product', 'POST', 'request_product', $userPerm);

    // Admin-auth endpoints.
    $adminPerm = [$this, 'permission_admin'];
    $this->route('/admin/stats', 'GET', 'admin_stats', $adminPerm);
    $this->route('/admin/orders', 'GET', 'admin_orders', $adminPerm);
    $this->route('/admin/orders/(?P<id>[\w\-]+)/complete', 'POST', 'admin_order_complete', $adminPerm);
    $this->route('/admin/orders/(?P<id>[\w\-]+)/reject', 'POST', 'admin_order_reject', $adminPerm);
    $this->route('/admin/deposits', 'GET', 'admin_deposits', $adminPerm);
    $this->route('/admin/deposits/(?P<id>\d+)/approve', 'POST', 'admin_deposit_approve', $adminPerm);
    $this->route('/admin/deposits/(?P<id>\d+)/reject', 'POST', 'admin_deposit_reject', $adminPerm);
    $this->route('/admin/users', 'GET', 'admin_users', $adminPerm);
    $this->route('/admin/users/(?P<id>\d+)/balance', 'POST', 'admin_set_balance', $adminPerm);
    $this->route('/admin/kyc', 'GET', 'admin_kyc', $adminPerm);
    $this->route('/admin/kyc/(?P<uid>\d+)/approve', 'POST', 'admin_kyc_approve', $adminPerm);
    $this->route('/admin/kyc/(?P<uid>\d+)/reject', 'POST', 'admin_kyc_reject', $adminPerm);
    $this->route('/admin/settings', 'POST', 'admin_settings', $adminPerm);
    $this->route('/admin/activity', 'GET', 'admin_activity', $adminPerm);
    $this->route('/admin/transactions', 'GET', 'admin_transactions', $adminPerm);
    $this->route('/admin/sessions', 'GET', 'admin_sessions', $adminPerm);
  }

  private function route($path, $methods, $callback, $permission) {
    register_rest_route(self::NS, $path, [
      'methods' => $methods,
      'callback' => [$this, $callback],
      'permission_callback' => $permission,
    ]);
  }

  private function now() {
    return current_time('mysql');
  }

  private function ip() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $parts = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
      return trim($parts[0]);
    }
    return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
  }

  private function token($prefix = 'hc') {
    return $prefix . '_' . wp_generate_password(32, false, false);
  }

  private function user_balance($userId) {
    return (float) get_user_meta($userId, 'hc_balance', true);
  }

  private function set_user_balance($userId, $amount) {
    update_user_meta($userId, 'hc_balance', round((float) $amount, 3));
  }

  private function log_action($userId, $action, $details = []) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'hc_logs', [
      'user_id' => $userId ?: null,
      'action_name' => $action,
      'ip' => $this->ip(),
      'details' => wp_json_encode($details),
      'created_at' => $this->now(),
    ]);
  }

  private function parse_bearer_token() {
    $header = sanitize_text_field($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $m)) {
      return trim($m[1]);
    }
    return '';
  }

  private function session_token() {
    $xhc = sanitize_text_field($_SERVER['HTTP_X_HC_TOKEN'] ?? '');
    return $xhc ?: $this->parse_bearer_token();
  }

  private function admin_token() {
    $xa = sanitize_text_field($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
    return $xa ?: $this->session_token();
  }

  private function get_user_from_token() {
    global $wpdb;
    $token = $this->session_token();
    if (!$token) {
      return new WP_Error('unauthorized', 'Missing token', ['status' => 401]);
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hc_sessions WHERE token=%s AND type='user'", $token));
    if (!$row) {
      return new WP_Error('unauthorized', 'Invalid token', ['status' => 401]);
    }
    $user = get_userdata((int) $row->user_id);
    if (!$user) {
      return new WP_Error('unauthorized', 'User not found', ['status' => 401]);
    }
    return $user;
  }

  public function permission_user() {
    $u = $this->get_user_from_token();
    return is_wp_error($u) ? $u : true;
  }

  public function permission_admin() {
    global $wpdb;
    $token = $this->admin_token();
    if (!$token) {
      return new WP_Error('unauthorized', 'Missing admin token', ['status' => 401]);
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hc_sessions WHERE token=%s AND type='admin'", $token));
    if (!$row) {
      return new WP_Error('unauthorized', 'Invalid admin token', ['status' => 401]);
    }
    return true;
  }

  private function user_payload($u) {
    $roles = (array) $u->roles;
    $isAdmin = in_array('administrator', $roles, true);

    return [
      'id' => (int) $u->ID,
      'name' => $u->display_name,
      'email' => $u->user_email,
      'avatar' => '👤',
      'balance' => $this->user_balance($u->ID),
      'kyc_status' => get_user_meta($u->ID, 'hc_kyc_status', true) ?: 'none',
      'total_spent' => (float) get_user_meta($u->ID, 'hc_total_spent', true),
      'role' => $isAdmin ? 'admin' : 'user',
      'is_admin' => $isAdmin,
      'last_login_at' => get_user_meta($u->ID, 'hc_last_login_at', true) ?: null,
      'last_login_ip' => get_user_meta($u->ID, 'hc_last_login_ip', true) ?: null,
    ];
  }

  private function products_list() {
    return [
      ['id' => 1, 'name' => 'Free Fire 600', 'icon' => '💎', 'price' => 2.5],
      ['id' => 2, 'name' => 'PUBG 600 UC', 'icon' => '🎮', 'price' => 8.99],
      ['id' => 3, 'name' => 'Netflix Monthly', 'icon' => '🎬', 'price' => 6.5],
    ];
  }

  public function settings_public() {
    return ['success' => true, 'usd_rate' => (int) get_option('hc_usd_rate', 14000)];
  }

  public function products() {
    return ['success' => true, 'products' => $this->products_list()];
  }

  public function register(WP_REST_Request $r) {
    $name = sanitize_text_field((string) $r->get_param('name'));
    $email = sanitize_email((string) $r->get_param('email'));
    $password = (string) $r->get_param('password');

    if (!$name || !$email || !$password) {
      return new WP_Error('bad_request', 'Missing fields', ['status' => 400]);
    }
    if (email_exists($email)) {
      return new WP_Error('bad_request', 'Email already exists', ['status' => 400]);
    }

    $uid = wp_create_user($email, $password, $email);
    if (is_wp_error($uid)) {
      return $uid;
    }

    wp_update_user(['ID' => $uid, 'display_name' => $name]);
    update_user_meta($uid, 'hc_balance', 0);
    update_user_meta($uid, 'hc_kyc_status', 'none');
    update_user_meta($uid, 'hc_total_spent', 0);
    $this->log_action($uid, 'register', ['email' => $email]);

    return ['success' => true, 'message' => 'Registered successfully'];
  }

  public function login(WP_REST_Request $r) {
    global $wpdb;
    $email = sanitize_email((string) $r->get_param('email'));
    $password = (string) $r->get_param('password');

    $user = get_user_by('email', $email);
    if (!$user || !wp_check_password($password, $user->user_pass, $user->ID)) {
      return new WP_Error('unauthorized', 'Invalid credentials', ['status' => 401]);
    }

    $token = $this->token('user');
    $wpdb->insert($wpdb->prefix . 'hc_sessions', [
      'token' => $token,
      'user_id' => $user->ID,
      'type' => 'user',
      'ip' => $this->ip(),
      'created_at' => $this->now(),
    ]);

    update_user_meta($user->ID, 'hc_last_login_at', $this->now());
    update_user_meta($user->ID, 'hc_last_login_ip', $this->ip());
    $this->log_action($user->ID, 'login', ['email' => $email]);

    return ['success' => true, 'token' => $token, 'user' => $this->user_payload($user)];
  }

  public function google_login(WP_REST_Request $r) {
    global $wpdb;
    $email = sanitize_email((string) $r->get_param('email'));
    $name = sanitize_text_field((string) $r->get_param('name'));
    $googleId = sanitize_text_field((string) $r->get_param('google_id'));

    if (!$email || !$googleId) {
      return new WP_Error('bad_request', 'Missing Google data', ['status' => 400]);
    }

    $user = get_user_by('email', $email);
    if (!$user) {
      $uid = wp_create_user($email, wp_generate_password(20), $email);
      if (is_wp_error($uid)) {
        return $uid;
      }
      wp_update_user(['ID' => $uid, 'display_name' => ($name ?: $email)]);
      update_user_meta($uid, 'hc_balance', 0);
      update_user_meta($uid, 'hc_kyc_status', 'none');
      update_user_meta($uid, 'hc_total_spent', 0);
      $user = get_userdata($uid);
    }

    $token = $this->token('user');
    $wpdb->insert($wpdb->prefix . 'hc_sessions', [
      'token' => $token,
      'user_id' => $user->ID,
      'type' => 'user',
      'ip' => $this->ip(),
      'created_at' => $this->now(),
    ]);

    update_user_meta($user->ID, 'hc_last_login_at', $this->now());
    update_user_meta($user->ID, 'hc_last_login_ip', $this->ip());
    $this->log_action($user->ID, 'google_login', ['email' => $email]);

    return ['success' => true, 'token' => $token, 'user' => $this->user_payload($user)];
  }

  public function me() {
    $user = $this->get_user_from_token();
    if (is_wp_error($user)) {
      return $user;
    }
    return ['success' => true, 'user' => $this->user_payload($user)];
  }

  public function logout() {
    global $wpdb;
    $token = $this->session_token();
    if ($token) {
      $wpdb->delete($wpdb->prefix . 'hc_sessions', ['token' => $token], ['%s']);
    }
    return ['success' => true];
  }

  public function wallet() {
    $user = $this->get_user_from_token();
    if (is_wp_error($user)) {
      return $user;
    }
    return ['success' => true, 'balance' => $this->user_balance($user->ID)];
  }

  public function wallet_transactions() {
    global $wpdb;
    $user = $this->get_user_from_token();
    if (is_wp_error($user)) {
      return $user;
    }

    $rows = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$wpdb->prefix}hc_transactions WHERE user_id=%d ORDER BY id DESC", $user->ID),
      ARRAY_A
    );
    return ['success' => true, 'transactions' => $rows];
  }

  public function wallet_deposit(WP_REST_Request $r) {
    global $wpdb;
    $user = $this->get_user_from_token();
    if (is_wp_error($user)) {
      return $user;
    }

    $amount = (float) $r->get_param('amount');
    $method = sanitize_text_field((string) $r->get_param('method'));
    $ref = sanitize_text_field((string) $r->get_param('ref'));

    if ($amount <= 0 || !$method) {
      return new WP_Error('bad_request', 'Missing deposit data', ['status' => 400]);
    }

    $wpdb->insert($wpdb->prefix . 'hc_deposits', [
      'user_id' => $user->ID,
      'amount' => $amount,
      'method' => $method,
      'ref_code' => $ref,
      'status' => 'pending',
      'ip' => $this->ip(),
      'payment_type' => $method,
      'created_at' => $this->now(),
    ]);

    $this->log_action($user->ID, 'deposit_request', ['amount' => $amount, 'method' => $method, 'ref' => $ref]);
    return ['success' => true];
  }

  public function orders() {
    global $wpdb;
    $user = $this->get_user_from_token();
    if (is_wp_error($user)) {
      return $user;
    }

    $orders = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$wpdb->prefix}hc_orders WHERE user_id=%d ORDER BY id DESC", $user->ID),
      ARRAY_A
    );

    return ['success' => true, 'orders' => $orders];
  }

  public function create_order(WP_REST_Request $r) {
    global $wpdb;
    $user = $this->get_user_from_token();
    if (is_wp_error($user)) {
      return $user;
    }

    $productId = (string) $r->get_param('product_id');
    $qty = max(1, (int) $r->get_param('quantity'));
    $playerId = sanitize_text_field((string) $r->get_param('player_id'));

    $product = null;
    foreach ($this->products_list() as $p) {
      if ((string) $p['id'] === $productId) {
        $product = $p;
        break;
      }
    }
    if (!$product) {
      return new WP_Error('not_found', 'Product not found', ['status' => 404]);
    }

    $before = $this->user_balance($user->ID);
    $total = round(((float) $product['price']) * $qty, 3);
    if ($before < $total) {
      return new WP_Error('bad_request', 'Insufficient balance', ['status' => 400]);
    }

    $after = round($before - $total, 3);
    $this->set_user_balance($user->ID, $after);
    update_user_meta($user->ID, 'hc_total_spent', (float) get_user_meta($user->ID, 'hc_total_spent', true) + $total);

    $orderNo = 'ORD-' . time() . wp_rand(100, 999);
    $wpdb->insert($wpdb->prefix . 'hc_orders', [
      'order_number' => $orderNo,
      'user_id' => $user->ID,
      'product_id' => $productId,
      'product_name' => $product['name'],
      'product_icon' => $product['icon'],
      'player_id' => $playerId,
      'quantity' => $qty,
      'total_price' => $total,
      'status' => 'pending',
      'ip' => $this->ip(),
      'transaction_type' => 'purchase',
      'payment_type' => 'wallet',
      'created_at' => $this->now(),
    ]);

    $wpdb->insert($wpdb->prefix . 'hc_transactions', [
      'user_id' => $user->ID,
      'type' => 'purchase',
      'amount' => -$total,
      'description' => 'شراء: ' . $product['name'],
      'payment_type' => 'wallet',
      'transaction_type' => 'purchase',
      'ip' => $this->ip(),
      'balance_before' => $before,
      'balance_after' => $after,
      'created_at' => $this->now(),
    ]);

    $this->log_action($user->ID, 'order_create', ['order_number' => $orderNo, 'total_price' => $total]);
    return ['success' => true, 'order_number' => $orderNo, 'balance' => $after];
  }

  public function kyc_submit(WP_REST_Request $r) {
    $user = $this->get_user_from_token();
    if (is_wp_error($user)) {
      return $user;
    }

    update_user_meta($user->ID, 'hc_kyc_status', 'pending');
    update_user_meta($user->ID, 'hc_kyc_data', $r->get_json_params());
    $this->log_action($user->ID, 'kyc_submit', []);
    return ['success' => true, 'kyc_status' => 'pending'];
  }

  public function request_product(WP_REST_Request $r) {
    $user = $this->get_user_from_token();
    if (is_wp_error($user)) {
      return $user;
    }

    $this->log_action($user->ID, 'request_product', (array) $r->get_json_params());
    return ['success' => true];
  }

  public function admin_login(WP_REST_Request $r) {
    global $wpdb;
    $username = sanitize_text_field((string) $r->get_param('username'));
    $password = (string) $r->get_param('password');

    $savedUser = (string) get_option('hc_admin_user', 'admin');
    $savedPassHash = (string) get_option('hc_admin_pass_hash', '');

    if ($username !== $savedUser || !$savedPassHash || !wp_check_password($password, $savedPassHash)) {
      return new WP_Error('unauthorized', 'Invalid admin credentials', ['status' => 401]);
    }

    $token = $this->token('admin');
    $wpdb->insert($wpdb->prefix . 'hc_sessions', [
      'token' => $token,
      'user_id' => 0,
      'type' => 'admin',
      'ip' => $this->ip(),
      'created_at' => $this->now(),
    ]);

    $this->log_action(0, 'admin_login', ['username' => $username]);
    return ['success' => true, 'is_admin' => true, 'token' => $token];
  }

  public function admin_stats() {
    global $wpdb;
    return [
      'success' => true,
      'users' => (int) $wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->users}"),
      'orders' => (int) $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}hc_orders"),
      'pending_orders' => (int) $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}hc_orders WHERE status='pending'"),
      'pending_deposits' => (int) $wpdb->get_var("SELECT COUNT(id) FROM {$wpdb->prefix}hc_deposits WHERE status='pending'"),
    ];
  }

  public function admin_orders() {
    global $wpdb;
    return ['success' => true, 'orders' => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hc_orders ORDER BY id DESC", ARRAY_A)];
  }

  private function find_order_by_param($idParam) {
    global $wpdb;
    if (is_numeric($idParam)) {
      $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hc_orders WHERE id=%d", (int) $idParam));
      if ($order) {
        return $order;
      }
    }
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hc_orders WHERE order_number=%s", sanitize_text_field((string) $idParam)));
  }

  public function admin_order_complete(WP_REST_Request $r) {
    global $wpdb;
    $order = $this->find_order_by_param($r['id']);
    if (!$order) {
      return new WP_Error('not_found', 'Order not found', ['status' => 404]);
    }

    $wpdb->update($wpdb->prefix . 'hc_orders', [
      'status' => 'completed',
      'admin_note' => sanitize_text_field((string) $r->get_param('note')),
      'completed_at' => $this->now(),
    ], ['id' => (int) $order->id]);

    $this->log_action((int) $order->user_id, 'admin_order_complete', ['order_id' => (int) $order->id]);
    return ['success' => true];
  }

  public function admin_order_reject(WP_REST_Request $r) {
    global $wpdb;
    $order = $this->find_order_by_param($r['id']);
    if (!$order) {
      return new WP_Error('not_found', 'Order not found', ['status' => 404]);
    }

    // Refund only once when first rejected.
    if ($order->status !== 'rejected') {
      $before = $this->user_balance((int) $order->user_id);
      $refund = (float) $order->total_price;
      $after = round($before + $refund, 3);
      $this->set_user_balance((int) $order->user_id, $after);

      $wpdb->insert($wpdb->prefix . 'hc_transactions', [
        'user_id' => (int) $order->user_id,
        'type' => 'refund',
        'amount' => $refund,
        'description' => 'استرجاع طلب مرفوض: ' . $order->order_number,
        'payment_type' => 'wallet',
        'transaction_type' => 'refund',
        'ip' => $this->ip(),
        'balance_before' => $before,
        'balance_after' => $after,
        'created_at' => $this->now(),
      ]);
    }

    $wpdb->update($wpdb->prefix . 'hc_orders', [
      'status' => 'rejected',
      'admin_note' => sanitize_text_field((string) $r->get_param('note')),
    ], ['id' => (int) $order->id]);

    $this->log_action((int) $order->user_id, 'admin_order_reject', ['order_id' => (int) $order->id]);
    return ['success' => true];
  }

  public function admin_deposits() {
    global $wpdb;
    return ['success' => true, 'deposits' => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hc_deposits ORDER BY id DESC", ARRAY_A)];
  }

  public function admin_deposit_approve(WP_REST_Request $r) {
    global $wpdb;
    $id = (int) $r['id'];
    $dep = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hc_deposits WHERE id=%d", $id));
    if (!$dep) {
      return new WP_Error('not_found', 'Deposit not found', ['status' => 404]);
    }

    if ($dep->status !== 'approved') {
      $before = $this->user_balance((int) $dep->user_id);
      $after = round($before + (float) $dep->amount, 3);
      $this->set_user_balance((int) $dep->user_id, $after);

      $wpdb->insert($wpdb->prefix . 'hc_transactions', [
        'user_id' => (int) $dep->user_id,
        'type' => 'deposit',
        'amount' => (float) $dep->amount,
        'description' => 'إيداع مقبول (' . $dep->method . ')',
        'payment_type' => $dep->method,
        'transaction_type' => 'deposit',
        'ip' => $dep->ip,
        'balance_before' => $before,
        'balance_after' => $after,
        'created_at' => $this->now(),
      ]);
    }

    $wpdb->update($wpdb->prefix . 'hc_deposits', ['status' => 'approved', 'approved_at' => $this->now()], ['id' => $id]);
    $this->log_action((int) $dep->user_id, 'admin_deposit_approve', ['deposit_id' => $id]);
    return ['success' => true];
  }

  public function admin_deposit_reject(WP_REST_Request $r) {
    global $wpdb;
    $id = (int) $r['id'];
    $dep = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hc_deposits WHERE id=%d", $id));
    if (!$dep) {
      return new WP_Error('not_found', 'Deposit not found', ['status' => 404]);
    }
    $wpdb->update($wpdb->prefix . 'hc_deposits', ['status' => 'rejected', 'rejected_at' => $this->now()], ['id' => $id]);
    $this->log_action((int) $dep->user_id, 'admin_deposit_reject', ['deposit_id' => $id]);
    return ['success' => true];
  }

  public function admin_users() {
    $users = get_users(['number' => 1000]);
    $list = [];
    foreach ($users as $u) {
      $item = $this->user_payload($u);
      $item['created_at'] = $u->user_registered;
      $list[] = $item;
    }
    return ['success' => true, 'users' => $list];
  }

  public function admin_set_balance(WP_REST_Request $r) {
    $uid = (int) $r['id'];
    $newBalance = (float) $r->get_param('balance');
    $this->set_user_balance($uid, $newBalance);
    $this->log_action($uid, 'admin_set_balance', ['balance' => $newBalance]);
    return ['success' => true];
  }

  public function admin_kyc() {
    $users = get_users(['meta_key' => 'hc_kyc_status', 'meta_compare' => 'EXISTS', 'number' => 1000]);
    $rows = [];
    foreach ($users as $u) {
      $rows[] = [
        'id' => (int) $u->ID,
        'name' => $u->display_name,
        'email' => $u->user_email,
        'kyc_status' => get_user_meta($u->ID, 'hc_kyc_status', true) ?: 'none',
        'kyc_data' => get_user_meta($u->ID, 'hc_kyc_data', true),
      ];
    }
    return ['success' => true, 'users' => $rows];
  }

  public function admin_kyc_approve(WP_REST_Request $r) {
    $uid = (int) $r['uid'];
    update_user_meta($uid, 'hc_kyc_status', 'approved');
    $this->log_action($uid, 'admin_kyc_approve', []);
    return ['success' => true];
  }

  public function admin_kyc_reject(WP_REST_Request $r) {
    $uid = (int) $r['uid'];
    update_user_meta($uid, 'hc_kyc_status', 'rejected');
    $this->log_action($uid, 'admin_kyc_reject', []);
    return ['success' => true];
  }

  public function admin_settings(WP_REST_Request $r) {
    $usdRate = $r->get_param('usd_rate');
    if ($usdRate !== null) {
      update_option('hc_usd_rate', (int) $usdRate);
    }

    $adminUser = sanitize_text_field((string) $r->get_param('admin_user'));
    if ($adminUser) {
      update_option('hc_admin_user', $adminUser);
    }

    $adminPassword = (string) $r->get_param('admin_password');
    if ($adminPassword) {
      update_option('hc_admin_pass_hash', wp_hash_password($adminPassword));
    }

    $this->log_action(0, 'admin_settings_update', ['usd_rate' => $usdRate]);
    return ['success' => true];
  }

  public function admin_activity() {
    global $wpdb;
    $logs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hc_logs ORDER BY id DESC LIMIT 200", ARRAY_A);
    return ['success' => true, 'logs' => $logs];
  }

  public function admin_transactions() {
    global $wpdb;
    $tx = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hc_transactions ORDER BY id DESC", ARRAY_A);
    return ['success' => true, 'transactions' => $tx];
  }

  public function admin_sessions() {
    global $wpdb;
    $sessions = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hc_sessions WHERE type='user' ORDER BY id DESC", ARRAY_A);
    return ['success' => true, 'sessions' => $sessions];
  }
}

new Hamado_Card_API();
