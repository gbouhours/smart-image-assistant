<?php
if (!defined('ABSPATH')) exit;

class Smart_Image_Assistant {
    const OPTION_NAME  = 'smart_image_assistant_options';
    const OPTION_GROUP = 'smart_image_assistant_options_group';
    const TEXT_DOMAIN  = 'smart-image-assistant';

    private array $providers = [];

    public function __construct() {
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('save_post', [$this, 'maybe_auto_generate_on_save'], 20, 3);
        $this->register_providers();
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/../languages');
    }

    private function register_providers(): void {
        $this->providers['cloudflare'] = new SIA_Provider_Cloudflare($this);
        $this->providers['openrouter'] = new SIA_Provider_OpenRouter($this);
        $this->providers['openai']     = new SIA_Provider_OpenAI($this);
    }

    public function get_providers(): array { return $this->providers; }
    public function get_provider(string $key): ?SIA_AI_Provider { return $this->providers[$key] ?? null; }
    public function get_active_provider(): ?SIA_AI_Provider {
        $opts = $this->get_options();
        return $this->get_provider($opts['default_provider'] ?? 'cloudflare');
    }

    public function default_options(): array {
        return [
            'default_provider'   => 'cloudflare',
            'providers'          => [
                'cloudflare' => ['account_id'=>'','api_token'=>'','model'=>'@cf/black-forest-labs/flux-1-schnell','width'=>1024,'height'=>576],
                'openrouter' => ['api_key'=>'','model'=>'','ratio'=>'16:9'],
                'openai'     => ['api_key'=>'','model'=>'gpt-image-1-mini','quality'=>'medium','output_format'=>'png','ratio'=>'16:9'],
            ],
            'default_format'     => 'png',
            'enabled_post_types' => ['post'],
            'prompt_template'    => 'Create a clean, modern, high-contrast illustration suitable as a blog featured image. Title: "{{title}}". Summary: {{excerpt}}. Avoid text, focus on symbolic, eye-catching visuals.',
            'auto_generate'      => false,
        ];
    }

    public function get_options(): array {
        $opts = get_option(self::OPTION_NAME, []);
        $opts = wp_parse_args(is_array($opts) ? $opts : [], $this->default_options());

        if (
            isset($opts['providers']['openai']['quality'])
            && $opts['providers']['openai']['quality'] === 'low'
        ) {
            $opts['providers']['openai']['quality'] = 'medium';
        }

        return $opts;
    }

    // Settings
    public function register_settings(): void {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, ['sanitize_callback' => [$this, 'sanitize_options']]);
        add_settings_section('sia_general', __('General Settings','smart-image-assistant'), '__return_empty_string', 'smart_image_assistant_settings');
        add_settings_field('default_provider', __('Default AI Provider','smart-image-assistant'), [$this,'render_field_default_provider'], 'smart_image_assistant_settings', 'sia_general');
        add_settings_field('prompt_template', __('Prompt Template','smart-image-assistant'), [$this,'render_field_prompt_template'], 'smart_image_assistant_settings', 'sia_general');
        add_settings_field('enabled_post_types', __('Enabled Post Types','smart-image-assistant'), [$this,'render_field_enabled_post_types'], 'smart_image_assistant_settings', 'sia_general');
        add_settings_field('auto_generate', __('Auto-Generation','smart-image-assistant'), [$this,'render_field_auto_generate'], 'smart_image_assistant_settings', 'sia_general');
    }

    public function sanitize_options($in): array {
        $d = $this->default_options();
        $c = [];
        $c['default_provider'] = in_array($in['default_provider']??'', array_keys($this->providers), true) ? $in['default_provider'] : $d['default_provider'];
        $c['prompt_template'] = sanitize_textarea_field($in['prompt_template'] ?? $d['prompt_template']);
        $all_pts = array_keys(get_post_types(['show_ui'=>true], 'names'));
        $sel = array_map('sanitize_key', $in['enabled_post_types'] ?? []);
        $c['enabled_post_types'] = array_values(array_intersect($sel, $all_pts));
        $c['auto_generate'] = !empty($in['auto_generate']);
        $c['providers'] = [];
        foreach ($this->providers as $k => $p) {
            $c['providers'][$k] = $p->sanitize_settings($in['providers'][$k] ?? []);
        }
        return $c;
    }

    public function register_settings_page(): void {
        add_options_page(__('Smart Image Assistant','smart-image-assistant'), __('Smart Image Assistant','smart-image-assistant'), 'manage_options', 'smart-image-assistant', [$this, 'render_settings_page']);
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) return;
        $o = $this->get_options();
        echo '<div class="wrap"><h1>'.esc_html__('Smart Image Assistant','smart-image-assistant').'</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections('smart_image_assistant_settings');
        echo '<h2>'.esc_html__('AI Provider Configuration','smart-image-assistant').'</h2>';
        echo '<table class="form-table">';
        foreach ($this->providers as $p) {
            $ps = $o['providers'][$p->get_key()] ?? [];
            $is_cfg = $p->is_configured($ps);
            echo '<tr><th colspan="2" style="border-bottom:1px solid #ccd0d4;"><strong>'.esc_html($p->get_name()).'</strong> ';
            echo '<span style="color:'.($is_cfg?'green':'orange').'">'.($is_cfg ? '✓ '.esc_html__('Configured','smart-image-assistant') : '⚠ '.esc_html__('Not configured','smart-image-assistant')).'</span></th></tr>';
            $p->render_settings_fields($ps);
        }
        echo '</table>';
        submit_button(__('Save Settings','smart-image-assistant'));
        echo '</form></div>';
    }

    public function render_field_default_provider(): void {
        $o = $this->get_options(); $cur = $o['default_provider'] ?? 'cloudflare';
        foreach ($this->providers as $p) {
            echo '<label style="display:block"><input type="radio" name="'.esc_attr(self::OPTION_NAME).'[default_provider]" value="'.esc_attr($p->get_key()).'" '.checked($cur,$p->get_key(),false).' /> '.esc_html($p->get_name()).'</label>';
        }
    }

    public function render_field_prompt_template(): void {
        $o = $this->get_options();
        echo '<textarea name="'.esc_attr(self::OPTION_NAME).'[prompt_template]" rows="4" class="large-text">'.esc_textarea($o['prompt_template']).'</textarea>';
        echo '<p class="description">Use {{title}} and {{excerpt}} placeholders.</p>';
    }

    public function render_field_enabled_post_types(): void {
        $o = $this->get_options(); $cur = $o['enabled_post_types'] ?? [];
        $pts = get_post_types(['show_ui'=>true], 'objects');
        foreach ($pts as $s=>$obj) {
            if (post_type_supports($s, 'thumbnail')) {
                echo '<label style="display:block"><input type="checkbox" name="'.esc_attr(self::OPTION_NAME).'[enabled_post_types][]" value="'.esc_attr($s).'" '.checked(in_array($s,$cur,true),true,false).' /> '.esc_html($obj->labels->singular_name??$s).'</label>';
            }
        }
    }

    public function render_field_auto_generate(): void {
        $o = $this->get_options();
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPTION_NAME).'[auto_generate]" value="1" '.checked(!empty($o['auto_generate']),true,false).' /> '.__('Auto-generate on save.','smart-image-assistant').'</label>';
    }

    // Helpers
    private function resolve_dimensions(int $rw, int $rh, string $pk): array {
        $o = $this->get_options(); $ps = $o['providers'][$pk] ?? [];
        if ($pk === 'cloudflare') {
            return ['width' => $rw ?: intval($ps['width']??1024), 'height' => $rh ?: intval($ps['height']??576)];
        }
        $r = $ps['ratio'] ?? '16:9'; $b = 1024;
        if ($r === '2:3') return ['width'=>(int)($b*2/3), 'height'=>$b];
        if ($r === '1:1') return ['width'=>$b, 'height'=>$b];
        return ['width'=>$b, 'height'=>(int)($b*9/16)];
    }

    private function build_prompt(string $title, string $excerpt, string $pk='', ?string $custom=null): string {
        $o = $this->get_options();
        $tpl = !empty(trim((string)$custom)) ? $custom : $o['prompt_template'];
        $p = strtr($tpl, ['{{title}}'=>$title, '{{excerpt}}'=>$excerpt]);
        if ($pk && $pk !== 'cloudflare') {
            $r = $o['providers'][$pk]['ratio'] ?? '16:9';
            $labels = ['16:9'=>'landscape 16:9','2:3'=>'portrait 2:3','1:1'=>'square 1:1'];
            $p .= ' Use a '.($labels[$r]??'landscape 16:9').' aspect ratio.';
        }
        return $p;
    }

    private function request_preview_image(string $title, string $excerpt, int $w, int $h, ?string $pk=null, ?string $cp=null) {
        $o = $this->get_options();
        $prov = $pk ? $this->get_provider($pk) : $this->get_active_provider();
        if (!$prov) return new WP_Error('sia_noprov', __('No provider.','smart-image-assistant'));
        $pk = $prov->get_key();
        $ps = $o['providers'][$pk] ?? [];
        if (!$prov->is_configured($ps)) {
            return new WP_Error('sia_notcfg', sprintf(__('%s not configured.','smart-image-assistant'), $prov->get_name()));
        }
        $dim = $this->resolve_dimensions($w, $h, $pk);
        $prompt = $this->build_prompt($title, $excerpt, $pk, $cp);
        return $prov->generate_image($prompt, $dim['width'], $dim['height'], $ps);
    }

    private function create_attachment(int $pid, string $b64, string $mime = 'image/png', string $ext = 'png') {
        $bin = base64_decode($b64, true);
        if ($bin === false) return new WP_Error('sia_decode', __('Decode error.','smart-image-assistant'));
        if (strlen($bin) > 10 * MB_IN_BYTES) return new WP_Error('sia_image_too_large', __('Image too large.','smart-image-assistant'));
        if (!function_exists('getimagesizefromstring') || @getimagesizefromstring($bin) === false) {
            return new WP_Error('sia_invalid_image', __('Invalid image data.','smart-image-assistant'));
        }
        $ext = in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) ? $ext : 'png';
        if ($ext === 'jpeg') { $ext = 'jpg'; }
        $mime = in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) ? $mime : 'image/png';
        $up = wp_upload_bits('sia-'.$pid.'-'.time().'.'.$ext, null, $bin);
        if (!empty($up['error'])) return new WP_Error('sia_upload', $up['error']);
        $ft = wp_check_filetype($up['file'], null);
        $aid = wp_insert_attachment(['post_mime_type'=>$ft['type']?:$mime,'post_title'=>wp_strip_all_tags(get_the_title($pid)),'post_status'=>'inherit'], $up['file'], $pid);
        if (!$aid || is_wp_error($aid)) return new WP_Error('sia_attach', __('Insert error.','smart-image-assistant'));
        require_once ABSPATH.'wp-admin/includes/image.php';
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $up['file']));
        return $aid;
    }

    // Auto-generate
    public function maybe_auto_generate_on_save(int $pid, WP_Post $post, bool $update): void {
        $o = $this->get_options();
        if (empty($o['auto_generate'])) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($pid) || wp_is_post_autosave($pid)) return;
        if (!in_array($post->post_type, $o['enabled_post_types']??[], true)) return;
        if (!current_user_can('edit_post', $pid)) return;
        if (has_post_thumbnail($pid)) return;
        $title = get_the_title($pid);
        if (empty(trim($title))) return;
        $excerpt = has_excerpt($pid) ? get_the_excerpt($pid) : wp_trim_words(wp_strip_all_tags($post->post_content??''), 40);
        $prev = $this->request_preview_image($title, $excerpt, 0, 0);
        if (is_wp_error($prev)) return;
        $aid = $this->create_attachment($pid, $prev['base64'], $prev['mime'] ?? 'image/png', $prev['extension'] ?? 'png');
        if (!is_wp_error($aid)) set_post_thumbnail($pid, $aid);
    }

    // REST
    public function register_rest_routes(): void {
        register_rest_route('smart-image-assistant/v1', '/generate', [
            'methods'=>'POST',
            'permission_callback'=>fn($r)=>current_user_can('edit_post', intval($r['postId'])),
            'args' => [
                'postId' => [
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => fn($value) => absint($value) > 0,
                ],
                'width' => [
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ],
                'height' => [
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ],
                'provider' => [
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'customPrompt' => [
                    'required' => false,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
            'callback'=>function($r) {
                $pid = intval($r->get_param('postId'));
                $w = intval($r->get_param('width')); $h = intval($r->get_param('height'));
                $pk = sanitize_text_field($r->get_param('provider')) ?: '';
                $cp = sanitize_textarea_field($r->get_param('customPrompt')) ?: null;
                $title = get_the_title($pid);
                if (!is_string($title) || trim($title)==='') return new WP_REST_Response(['error'=>__('No title.','smart-image-assistant')], 400);
                $post = get_post($pid);
                $excerpt = has_excerpt($pid) ? get_the_excerpt($pid) : wp_trim_words(wp_strip_all_tags($post?$post->post_content:''), 40);
                $prev = $this->request_preview_image($title, $excerpt, $w?:0, $h?:0, $pk, $cp);
                if (is_wp_error($prev)) {
                    $status = intval($prev->get_error_data('status') ?: 0);
                    if ($status <= 0) {
                        $data = $prev->get_error_data();
                        if (is_array($data) && !empty($data['status'])) {
                            $status = intval($data['status']);
                        }
                    }
                    return new WP_REST_Response(['error'=>$prev->get_error_message()], $status >= 400 ? $status : 400);
                }
                return new WP_REST_Response([
                    'imageBase64'=>$prev['base64'],
                    'mime'=>$prev['mime'] ?? 'image/png',
                    'extension'=>$prev['extension'] ?? 'png',
                ], 200);
            },
        ]);

        register_rest_route('smart-image-assistant/v1', '/set-featured', [
            'methods'=>'POST',
            'permission_callback'=>fn($r)=>current_user_can('edit_post', intval($r['postId'])),
            'args' => [
                'postId' => [
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => fn($value) => absint($value) > 0,
                ],
                'attachmentId' => [
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ],
                'imageBase64' => [
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'mime' => [
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'extension' => [
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
            'callback'=>function($r) {
                $pid = intval($r->get_param('postId'));
                $aid = intval($r->get_param('attachmentId'));
                $b64 = $r->get_param('imageBase64');
                if (!$pid) return new WP_REST_Response(['error'=>__('No post ID.','smart-image-assistant')], 400);
                if (!current_user_can('edit_post', $pid)) return new WP_REST_Response(['error'=>__('Permission denied.','smart-image-assistant')], 403);
                if (!$aid && $b64) { $aid = $this->create_attachment($pid, $b64, (string) $r->get_param('mime'), (string) $r->get_param('extension')); if (is_wp_error($aid)) return new WP_REST_Response(['error'=>$aid->get_error_message()], 400); }
                if (!$aid) return new WP_REST_Response(['error'=>__('No image.','smart-image-assistant')], 400);
                $ok = set_post_thumbnail($pid, $aid);
                if ($ok===false) return new WP_REST_Response(['error'=>__('Failed.','smart-image-assistant')], 400);
                return new WP_REST_Response(['success'=>true,'attachmentId'=>$aid], 200);
            },
        ]);
    }

    // Editor assets
    public function enqueue_editor_assets(): void {
        wp_enqueue_script('sia-editor', plugin_dir_url(__FILE__).'../editor.js', ['wp-plugins','wp-edit-post','wp-element','wp-components','wp-data','wp-api-fetch','wp-i18n'], '2.0.0', true);
        $o = $this->get_options();
        $enabled = $o['enabled_post_types'] ?? [];
        $providers = []; $defaults = [];
        foreach ($this->providers as $p) {
            $k = $p->get_key(); $ps = $o['providers'][$k] ?? [];
            if ($p->is_configured($ps)) $providers[] = ['key'=>$k, 'name'=>$p->get_name()];
            $defaults[$k] = ['ratio'=>$ps['ratio']??'16:9', 'width'=>intval($ps['width']??1024), 'height'=>intval($ps['height']??576)];
        }
        wp_localize_script('sia-editor', 'SIASettings', [
            'namespace'=>'smart-image-assistant/v1', 'route'=>'generate',
            'defaultProvider' => $o['default_provider'] ?? 'cloudflare',
            'enabledPostTypes'=>$enabled, 'providers'=>$providers, 'providerDefaults'=>$defaults,
            'promptTemplate'=>$o['prompt_template']??'',
        ]);
    }
}