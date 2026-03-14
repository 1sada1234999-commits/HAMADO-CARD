<?php
/**
 * Plugin Name: sho8rday - WP
 * Description: Legal automation toolkit for WordPress publishing with OpenRouter model fallback, Telegram posting, ad slots, and SEO-friendly app pages.
 * Version: 0.1.0
 * Author: sho8rday
 */

if (!defined('ABSPATH')) {
  exit;
}

class Sho8rday_WP_Plugin {
  const SETTINGS_OPTION = 'sho8rday_wp_settings';
  const LOG_OPTION = 'sho8rday_wp_debug_log';
  const CRON_HOOK = 'sho8rday_wp_automation_cron';
  const NS = 'sho8rday/v1';

  public function __construct() {
    add_action('init', [$this, 'register_post_type']);
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('rest_api_init', [$this, 'register_routes']);
    add_action(self::CRON_HOOK, [$this, 'run_automation_cycle']);
    add_shortcode('sho8rday_latest_apps', [$this, 'latest_apps_shortcode']);

    register_activation_hook(__FILE__, [$this, 'activate']);
    register_deactivation_hook(__FILE__, [$this, 'deactivate']);
  }

  public function activate() {
    $this->register_post_type();
    flush_rewrite_rules();

    if (!wp_next_scheduled(self::CRON_HOOK)) {
      wp_schedule_event(time() + 120, 'hourly', self::CRON_HOOK);
    }

    if (!get_option(self::SETTINGS_OPTION)) {
      add_option(self::SETTINGS_OPTION, $this->default_settings());
    }
  }

  public function deactivate() {
    wp_clear_scheduled_hook(self::CRON_HOOK);
    flush_rewrite_rules();
  }

  private function default_settings() {
    return [
      'site_domain' => 'xdownmod.com',
      'openrouter_keys' => [],
      'openrouter_models' => [
        'openrouter/auto',
        'meta-llama/llama-3.1-8b-instruct:free',
        'mistralai/mistral-7b-instruct:free',
      ],
      'auto_select_model' => 1,
      'preferred_model' => 'openrouter/auto',
      'telegram_enabled' => 0,
      'telegram_bot_token' => '',
      'telegram_chat_id' => '',
      'automation_enabled' => 0,
      'allow_auto_publish' => 0,
      'email_promotions_enabled' => 0,
      'seo_helpers_enabled' => 1,
      'ads_enabled' => 0,
      'ads_slot_top' => '',
      'ads_slot_bottom' => '',
      'system_prompt' => $this->default_system_prompt(),
      'task_queue' => [],
    ];
  }

  private function default_system_prompt() {
    return 'أنت مساعد نشر قانوني لموقع تطبيقات. عند طلب "اجلب لي تطبيق X آخر إصدار" قم بإنشاء محتوى عربي احترافي متوافق SEO يتضمن: عنوان واضح، وصف المزايا، التغييرات، متطلبات التشغيل، أسئلة شائعة، وتنبيه قانوني بعدم دعم أي انتهاك حقوق. لا تذكر أو تروج للمحتوى المقرصن.';
  }

  public function register_post_type() {
    register_post_type('sho8_app', [
      'label' => 'Applications',
      'public' => true,
      'has_archive' => true,
      'rewrite' => ['slug' => 'apps'],
      'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
      'show_in_rest' => true,
      'menu_icon' => 'dashicons-smartphone',
    ]);
  }

  public function admin_menu() {
    add_menu_page(
      'sho8rday - WP',
      'sho8rday - WP',
      'manage_options',
      'sho8rday-wp',
      [$this, 'render_settings_page'],
      'dashicons-admin-generic',
      58
    );
  }

  public function register_settings() {
    register_setting('sho8rday_wp_group', self::SETTINGS_OPTION, [$this, 'sanitize_settings']);
  }

  public function sanitize_settings($input) {
    $settings = $this->get_settings();

    $settings['site_domain'] = sanitize_text_field($input['site_domain'] ?? $settings['site_domain']);
    $settings['auto_select_model'] = !empty($input['auto_select_model']) ? 1 : 0;
    $settings['preferred_model'] = sanitize_text_field($input['preferred_model'] ?? 'openrouter/auto');
    $settings['telegram_enabled'] = !empty($input['telegram_enabled']) ? 1 : 0;
    $settings['telegram_bot_token'] = sanitize_text_field($input['telegram_bot_token'] ?? '');
    $settings['telegram_chat_id'] = sanitize_text_field($input['telegram_chat_id'] ?? '');
    $settings['automation_enabled'] = !empty($input['automation_enabled']) ? 1 : 0;
    $settings['allow_auto_publish'] = !empty($input['allow_auto_publish']) ? 1 : 0;
    $settings['email_promotions_enabled'] = !empty($input['email_promotions_enabled']) ? 1 : 0;
    $settings['seo_helpers_enabled'] = !empty($input['seo_helpers_enabled']) ? 1 : 0;
    $settings['ads_enabled'] = !empty($input['ads_enabled']) ? 1 : 0;
    $settings['ads_slot_top'] = wp_kses_post($input['ads_slot_top'] ?? '');
    $settings['ads_slot_bottom'] = wp_kses_post($input['ads_slot_bottom'] ?? '');
    $settings['system_prompt'] = sanitize_textarea_field($input['system_prompt'] ?? $this->default_system_prompt());

    $keysRaw = trim((string) ($input['openrouter_keys_raw'] ?? ''));
    $keys = [];
    if ($keysRaw !== '') {
      $lines = preg_split('/\r\n|\r|\n/', $keysRaw);
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
          continue;
        }
        $parts = array_map('trim', explode('|', $line));
        $keys[] = [
          'type' => sanitize_text_field($parts[0] ?? 'main'),
          'label' => sanitize_text_field($parts[1] ?? 'key'),
          'key' => sanitize_text_field($parts[2] ?? ''),
        ];
        if (count($keys) >= 10) {
          break;
        }
      }
    }

    $modelsRaw = trim((string) ($input['openrouter_models_raw'] ?? ''));
    $models = [];
    if ($modelsRaw !== '') {
      foreach (preg_split('/\r\n|\r|\n/', $modelsRaw) as $model) {
        $model = sanitize_text_field(trim($model));
        if ($model !== '') {
          $models[] = $model;
        }
      }
    }

    $settings['openrouter_keys'] = $keys;
    $settings['openrouter_models'] = !empty($models) ? array_values(array_unique($models)) : $settings['openrouter_models'];

    return $settings;
  }

  private function get_settings() {
    $settings = get_option(self::SETTINGS_OPTION, []);
    return wp_parse_args($settings, $this->default_settings());
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Unauthorized.', 'sho8rday-wp'));
    }

    $settings = $this->get_settings();
    $keysRaw = '';
    foreach ($settings['openrouter_keys'] as $entry) {
      $keysRaw .= ($entry['type'] ?? 'main') . '|' . ($entry['label'] ?? 'key') . '|' . ($entry['key'] ?? '') . "\n";
    }

    $logs = get_option(self::LOG_OPTION, []);
    ?>
    <div class="wrap">
      <h1>sho8rday - WP</h1>
      <p>Automation toolkit with OpenRouter failover, Telegram posting, and SEO-safe publishing workflows.</p>

      <form method="post" action="options.php">
        <?php settings_fields('sho8rday_wp_group'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">Site Domain</th>
            <td><input name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[site_domain]" type="text" value="<?php echo esc_attr($settings['site_domain']); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row">OpenRouter Keys (max 10)</th>
            <td>
              <textarea name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[openrouter_keys_raw]" rows="8" class="large-text code"><?php echo esc_textarea(trim($keysRaw)); ?></textarea>
              <p class="description">Each line: type|label|api_key</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Model Candidates</th>
            <td>
              <textarea name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[openrouter_models_raw]" rows="6" class="large-text code"><?php echo esc_textarea(implode("\n", $settings['openrouter_models'])); ?></textarea>
              <p class="description">One model per line. Plugin will rotate models/keys until one succeeds.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Preferred Model</th>
            <td><input name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[preferred_model]" type="text" value="<?php echo esc_attr($settings['preferred_model']); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row">Options</th>
            <td>
              <label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[auto_select_model]" value="1" <?php checked($settings['auto_select_model'], 1); ?> /> Auto-select working model</label><br />
              <label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[automation_enabled]" value="1" <?php checked($settings['automation_enabled'], 1); ?> /> Enable automation cycle</label><br />
              <label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[allow_auto_publish]" value="1" <?php checked($settings['allow_auto_publish'], 1); ?> /> Direct publish (off = draft only)</label><br />
              <label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[email_promotions_enabled]" value="1" <?php checked($settings['email_promotions_enabled'], 1); ?> /> Enable compliant email promotions</label><br />
              <label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[seo_helpers_enabled]" value="1" <?php checked($settings['seo_helpers_enabled'], 1); ?> /> Enable SEO helpers</label><br />
              <label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[ads_enabled]" value="1" <?php checked($settings['ads_enabled'], 1); ?> /> Enable ad slots</label><br />
            </td>
          </tr>
          <tr>
            <th scope="row">Telegram</th>
            <td>
              <label><input type="checkbox" name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[telegram_enabled]" value="1" <?php checked($settings['telegram_enabled'], 1); ?> /> Enable Telegram posting</label><br /><br />
              <input name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[telegram_bot_token]" type="text" value="<?php echo esc_attr($settings['telegram_bot_token']); ?>" class="regular-text" placeholder="Bot token" /><br /><br />
              <input name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[telegram_chat_id]" type="text" value="<?php echo esc_attr($settings['telegram_chat_id']); ?>" class="regular-text" placeholder="Channel chat_id (e.g. @channel)" />
            </td>
          </tr>
          <tr>
            <th scope="row">Ad Slot (Top)</th>
            <td><textarea name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[ads_slot_top]" rows="4" class="large-text code"><?php echo esc_textarea($settings['ads_slot_top']); ?></textarea></td>
          </tr>
          <tr>
            <th scope="row">Ad Slot (Bottom)</th>
            <td><textarea name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[ads_slot_bottom]" rows="4" class="large-text code"><?php echo esc_textarea($settings['ads_slot_bottom']); ?></textarea></td>
          </tr>
          <tr>
            <th scope="row">AI System Prompt</th>
            <td><textarea name="<?php echo esc_attr(self::SETTINGS_OPTION); ?>[system_prompt]" rows="5" class="large-text"><?php echo esc_textarea($settings['system_prompt']); ?></textarea></td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>

      <hr />
      <h2>Live Debug (latest 30)</h2>
      <pre style="background:#111;color:#0f0;padding:12px;max-height:380px;overflow:auto;"><?php
      $recent = array_slice(array_reverse($logs), 0, 30);
      foreach ($recent as $log) {
        echo esc_html(sprintf('[%s] %s %s', $log['time'], $log['event'], wp_json_encode($log['context']))) . "\n";
      }
      ?></pre>
    </div>
    <?php
  }

  public function register_routes() {
    register_rest_route(self::NS, '/queue', [
      'methods' => 'POST',
      'permission_callback' => function () {
        return current_user_can('manage_options');
      },
      'callback' => [$this, 'api_add_queue_task'],
    ]);

    register_rest_route(self::NS, '/run', [
      'methods' => 'POST',
      'permission_callback' => function () {
        return current_user_can('manage_options');
      },
      'callback' => [$this, 'api_run_now'],
    ]);
  }

  public function api_add_queue_task(WP_REST_Request $request) {
    $topic = sanitize_text_field($request->get_param('topic'));
    if (!$topic) {
      return new WP_Error('invalid_topic', 'Missing topic.', ['status' => 400]);
    }

    $settings = $this->get_settings();
    $settings['task_queue'][] = [
      'topic' => $topic,
      'created_at' => current_time('mysql'),
    ];
    update_option(self::SETTINGS_OPTION, $settings);

    $this->log('queue_added', ['topic' => $topic]);
    return ['ok' => true, 'queued' => count($settings['task_queue'])];
  }

  public function api_run_now() {
    $result = $this->run_automation_cycle();
    return ['ok' => true, 'result' => $result];
  }

  public function run_automation_cycle() {
    $settings = $this->get_settings();
    if (empty($settings['automation_enabled'])) {
      $this->log('automation_skipped', ['reason' => 'disabled']);
      return ['status' => 'disabled'];
    }

    if (empty($settings['task_queue'])) {
      $this->log('automation_skipped', ['reason' => 'empty_queue']);
      return ['status' => 'empty_queue'];
    }

    $task = array_shift($settings['task_queue']);
    update_option(self::SETTINGS_OPTION, $settings);

    $topic = sanitize_text_field($task['topic'] ?? '');
    if ($topic === '') {
      $this->log('task_invalid', $task);
      return ['status' => 'invalid_task'];
    }

    $prompt = "اكتب صفحة تطبيق احترافية عن: {$topic}. اجعلها منظمة بعناوين H2/H3 وفق SEO وبأسلوب قانوني.";
    $response = $this->generate_with_fallback($prompt, $settings);

    if (is_wp_error($response)) {
      $this->log('automation_failed', ['topic' => $topic, 'error' => $response->get_error_message()]);
      return ['status' => 'failed', 'error' => $response->get_error_message()];
    }

    $postId = $this->create_app_post($topic, $response['content'], $settings);
    $this->log('post_created', ['post_id' => $postId, 'topic' => $topic, 'model' => $response['model']]);

    if (!empty($settings['telegram_enabled'])) {
      $this->send_to_telegram(get_the_title($postId), get_permalink($postId), $settings);
    }

    return ['status' => 'ok', 'post_id' => $postId, 'model' => $response['model']];
  }

  private function generate_with_fallback($prompt, $settings) {
    $keys = $settings['openrouter_keys'];
    if (empty($keys)) {
      return new WP_Error('missing_keys', 'No OpenRouter key configured.');
    }

    $models = $settings['openrouter_models'];
    if (!$settings['auto_select_model'] && !empty($settings['preferred_model'])) {
      $models = [$settings['preferred_model']];
    }

    foreach ($keys as $entry) {
      $apiKey = sanitize_text_field($entry['key'] ?? '');
      if ($apiKey === '') {
        continue;
      }

      foreach ($models as $model) {
        $result = $this->call_openrouter($apiKey, $model, $settings['system_prompt'], $prompt, $settings['site_domain']);
        if (!is_wp_error($result)) {
          return [
            'content' => $result,
            'model' => $model,
            'key_label' => $entry['label'] ?? 'key',
          ];
        }
        $this->log('model_failed', ['model' => $model, 'error' => $result->get_error_message()]);
      }
    }

    return new WP_Error('all_failed', 'All configured key/model combinations failed.');
  }

  private function call_openrouter($apiKey, $model, $systemPrompt, $userPrompt, $domain) {
    $url = 'https://openrouter.ai/api/v1/chat/completions';
    $body = [
      'model' => $model,
      'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
      ],
      'temperature' => 0.6,
    ];

    $response = wp_remote_post($url, [
      'timeout' => 45,
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
        'HTTP-Referer' => home_url('/'),
        'X-Title' => $domain,
      ],
      'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $raw = wp_remote_retrieve_body($response);
    $json = json_decode($raw, true);

    if ($code < 200 || $code >= 300) {
      return new WP_Error('api_error', 'OpenRouter error: ' . $code);
    }

    $text = $json['choices'][0]['message']['content'] ?? '';
    if (!$text) {
      return new WP_Error('empty_response', 'Empty content from model.');
    }

    return wp_kses_post($text);
  }

  private function create_app_post($topic, $content, $settings) {
    $title = wp_strip_all_tags($topic . ' - Latest Version');

    if (!empty($settings['ads_enabled'])) {
      $content = $settings['ads_slot_top'] . "\n" . $content . "\n" . $settings['ads_slot_bottom'];
    }

    $postId = wp_insert_post([
      'post_type' => 'sho8_app',
      'post_title' => $title,
      'post_content' => $content,
      'post_status' => !empty($settings['allow_auto_publish']) ? 'publish' : 'draft',
      'post_excerpt' => wp_trim_words(wp_strip_all_tags($content), 28),
    ]);

    if (!is_wp_error($postId) && !empty($settings['seo_helpers_enabled'])) {
      update_post_meta($postId, '_yoast_wpseo_metadesc', wp_trim_words(wp_strip_all_tags($content), 24));
      update_post_meta($postId, '_rank_math_description', wp_trim_words(wp_strip_all_tags($content), 24));
    }

    return $postId;
  }

  private function send_to_telegram($title, $url, $settings) {
    $token = $settings['telegram_bot_token'];
    $chat = $settings['telegram_chat_id'];
    if ($token === '' || $chat === '') {
      $this->log('telegram_skipped', ['reason' => 'missing_config']);
      return;
    }

    $endpoint = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
    $message = "📢 {$title}\n\n🔗 {$url}";

    $response = wp_remote_post($endpoint, [
      'timeout' => 20,
      'body' => [
        'chat_id' => $chat,
        'text' => $message,
      ],
    ]);

    if (is_wp_error($response)) {
      $this->log('telegram_failed', ['error' => $response->get_error_message()]);
      return;
    }

    $this->log('telegram_sent', ['chat' => $chat, 'status' => wp_remote_retrieve_response_code($response)]);
  }

  public function latest_apps_shortcode($atts) {
    $atts = shortcode_atts(['limit' => 8], $atts);
    $query = new WP_Query([
      'post_type' => 'sho8_app',
      'posts_per_page' => (int) $atts['limit'],
      'post_status' => 'publish',
      'orderby' => 'date',
      'order' => 'DESC',
    ]);

    if (!$query->have_posts()) {
      return '<p>No apps yet.</p>';
    }

    ob_start();
    echo '<ul class="sho8rday-app-list">';
    while ($query->have_posts()) {
      $query->the_post();
      echo '<li><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></li>';
    }
    echo '</ul>';
    wp_reset_postdata();

    return ob_get_clean();
  }

  private function log($event, $context = []) {
    $logs = get_option(self::LOG_OPTION, []);
    $logs[] = [
      'time' => current_time('mysql'),
      'event' => sanitize_key($event),
      'context' => $context,
    ];
    if (count($logs) > 500) {
      $logs = array_slice($logs, -500);
    }
    update_option(self::LOG_OPTION, $logs, false);
  }
}

new Sho8rday_WP_Plugin();
