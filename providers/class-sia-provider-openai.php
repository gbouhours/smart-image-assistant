<?php
if (!defined('ABSPATH')) exit;

class SIA_Provider_OpenAI extends SIA_AI_Provider {

    private const MODELS = [
        'gpt-image-1-mini' => [
            'label' => 'GPT Image 1 Mini',
            'default_quality' => 'medium',
            'supports_quality' => true,
            'supports_output_format' => true,
            'supported_output_formats' => ['png', 'jpeg', 'webp'],
        ],
        'gpt-image-1' => [
            'label' => 'GPT Image 1',
            'default_quality' => 'medium',
            'supports_quality' => true,
            'supports_output_format' => true,
            'supported_output_formats' => ['png', 'jpeg', 'webp'],
        ],
        'gpt-image-1.5' => [
            'label' => 'GPT Image 1.5',
            'default_quality' => 'medium',
            'supports_quality' => true,
            'supports_output_format' => true,
            'supported_output_formats' => ['png', 'jpeg', 'webp'],
        ],
    ];

    public function get_key(): string { return 'openai'; }
    public function get_name(): string { return __('OpenAI', 'smart-image-assistant'); }
    public function get_description(): string { return __('Uses OpenAI image generation.', 'smart-image-assistant'); }

    public function get_default_models(): array {
        $models = [];
        foreach (self::MODELS as $slug => $meta) {
            $models[$slug] = $meta['label'];
        }
        return $models;
    }

    public function is_configured(array $s): bool {
        return !empty(trim($s['api_key'] ?? ''));
    }

    public function render_settings_fields(array $s): void {
        $n = Smart_Image_Assistant::OPTION_NAME; $k = $this->get_key(); $m = $s['model'] ?? 'gpt-image-1-mini';
        $q = $s['quality'] ?? 'medium';
        $f = $s['output_format'] ?? 'png';
        echo '<tr><th><label>'.__('API Key','smart-image-assistant').'</label></th><td>';
        echo '<input type="password" name="'.esc_attr($n).'[providers]['.esc_attr($k).'][api_key]" value="'.esc_attr($s['api_key']??'').'" class="regular-text" />';
        echo '</td></tr>';
        echo '<tr><th><label>'.__('Model','smart-image-assistant').'</label></th><td>';
        echo '<select name="'.esc_attr($n).'[providers]['.esc_attr($k).'][model]">';
        foreach ($this->get_default_models() as $slug => $label) {
            echo '<option value="'.esc_attr($slug).'" '.selected($m,$slug,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>'.__('Quality','smart-image-assistant').'</label></th><td>';
        echo '<select name="'.esc_attr($n).'[providers]['.esc_attr($k).'][quality]">';
        foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $v => $l) {
            echo '<option value="'.esc_attr($v).'" '.selected($q, $v, false).'>'.esc_html($l).'</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>'.__('Output Format','smart-image-assistant').'</label></th><td>';
        echo '<select name="'.esc_attr($n).'[providers]['.esc_attr($k).'][output_format]">';
        foreach (['png' => 'PNG', 'jpeg' => 'JPEG', 'webp' => 'WEBP'] as $v => $l) {
            echo '<option value="'.esc_attr($v).'" '.selected($f, $v, false).'>'.esc_html($l).'</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>'.__('Aspect Ratio','smart-image-assistant').'</label></th><td>';
        echo '<select name="'.esc_attr($n).'[providers]['.esc_attr($k).'][ratio]">';
        foreach (['16:9'=>'16:9 Landscape','2:3'=>'2:3 Portrait','1:1'=>'1:1 Square'] as $v=>$l) {
            echo '<option value="'.$v.'" '.selected($s['ratio']??'16:9',$v,false).'>'.$l.'</option>';
        }
        echo '</select></td></tr>';
    }

    public function sanitize_settings(array $in): array {
        $model = sanitize_text_field($in['model'] ?? 'gpt-image-1-mini');
        if (!array_key_exists($model, self::MODELS)) {
            $model = 'gpt-image-1-mini';
        }
        return [
            'api_key' => sanitize_text_field($in['api_key'] ?? ''),
            'model'   => $model,
            'quality' => in_array($in['quality'] ?? 'low', ['low', 'medium', 'high'], true) ? $in['quality'] : 'low',
            'output_format' => in_array($in['output_format'] ?? 'png', ['png', 'jpeg', 'webp'], true) ? $in['output_format'] : 'png',
            'ratio'   => in_array($in['ratio']??'', ['16:9','2:3','1:1'], true) ? $in['ratio'] : '16:9',
        ];
    }

    private function get_model_meta(string $model): array {
        return self::MODELS[$model] ?? self::MODELS['gpt-image-1-mini'];
    }

    public function generate_image(string $prompt, int $w, int $h, array $s) {
        $key = trim($s['api_key'] ?? '');
        $model = $s['model'] ?? 'gpt-image-1-mini';
        $meta = $this->get_model_meta($model);
        if (empty($key)) return new WP_Error('sia_oai_key', __('OpenAI key missing.','smart-image-assistant'));

        // Build request body
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $this->get_size_for_ratio($s['ratio'] ?? '16:9'),
        ];

        if (!empty($meta['supports_quality']) && !empty($s['quality'])) {
            $body['quality'] = in_array($s['quality'], ['low', 'medium', 'high'], true) ? $s['quality'] : $meta['default_quality'];
        }

        if (!empty($meta['supports_output_format']) && !empty($s['output_format'])) {
            $format = in_array($s['output_format'], $meta['supported_output_formats'] ?? ['png'], true)
                ? $s['output_format']
                : 'png';
            $body['output_format'] = $format;
        }

        $resp = wp_remote_post('https://api.openai.com/v1/images/generations', [
            'headers'=>['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'],
            'timeout'=>120,
            'body'=>wp_json_encode($body),
        ]);
        if (is_wp_error($resp)) return $resp;

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            if (defined('WP_DEBUG') && WP_DEBUG) error_log('SIA OpenAI HTTP '.$code);
            $message = $this->extract_error_message($data ?? null, $raw, $code);
            return new WP_Error('sia_oai_http', sprintf(__('OpenAI HTTP %1$d: %2$s','smart-image-assistant'), $code, $message));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) return new WP_Error('sia_oai_json', __('Invalid JSON.','smart-image-assistant'));
        if (!empty($data['error'])) return new WP_Error('sia_oai_api', $data['error']['message']??'Unknown');

        $items = $data['data'] ?? [];
        foreach ($items as $item) {
            // Check for b64_json first
            if (!empty($item['b64_json'])) {
                $format = !empty($body['output_format']) ? $body['output_format'] : 'png';
                return ['base64' => $item['b64_json'], 'mime' => $this->format_to_mime($format), 'extension' => $this->format_to_extension($format)];
            }
            // Fallback to URL
            $url = $item['url'] ?? null;
        if ($url) {
                $downloaded = $this->download_image($url);
                if ($downloaded) return $downloaded;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) error_log('SIA OpenAI no image: '.json_encode(array_keys($data)));
        return new WP_Error('sia_oai_noimg', __('No image.','smart-image-assistant'));
    }

    private function get_size_for_ratio(string $ratio): string {
        // gpt-image-1 supported sizes: 1024x1024, 1536x1024 (landscape), 1024x1536 (portrait)
        switch ($ratio) {
            case '16:9': return '1536x1024';
            case '2:3':  return '1024x1536';
            case '1:1':
            default:     return '1024x1024';
        }
    }

    private function download_image(string $url): ?array {
        if (!$this->is_safe_image_url($url)) return null;
        $resp = wp_safe_remote_get($url, [
            'timeout' => 20,
            'redirection' => 0,
            'limit_response_size' => 10 * MB_IN_BYTES,
        ]);
        if (is_wp_error($resp)) return null;
        if (wp_remote_retrieve_response_code($resp) >= 300) return null;
        $body = wp_remote_retrieve_body($resp);
        if (!$body) return null;
        $content_type = (string) wp_remote_retrieve_header($resp, 'content-type');
        $format = $this->mime_to_format($content_type);
        if ($format === null) return null;
        return [
            'base64' => base64_encode($body),
            'mime' => $this->format_to_mime($format),
            'extension' => $this->format_to_extension($format),
        ];
    }

    private function format_to_mime(string $format): string {
        return match ($format) {
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function format_to_extension(string $format): string {
        return match ($format) {
            'jpeg' => 'jpg',
            'webp' => 'webp',
            default => 'png',
        };
    }

    private function mime_to_format(string $mime): ?string {
        $mime = strtolower(trim($mime));
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/webp' => 'webp',
            'image/png' => 'png',
            default => null,
        };
    }

    public function get_endpoint_url(array $s): string { return 'https://api.openai.com/v1/images/generations'; }

    private function is_safe_image_url(string $url): bool {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
            return false;
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        return (bool) wp_http_validate_url($url);
    }

    private function extract_error_message($data, string $raw, int $code): string {
        if (is_array($data)) {
            $message = $data['error']['message'] ?? $data['message'] ?? $data['errors'][0]['message'] ?? '';
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }
        $text = wp_strip_all_tags(substr($raw, 0, 500));
        return $text !== '' ? $text : ('HTTP '.$code);
    }
}