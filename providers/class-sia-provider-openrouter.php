<?php
if (!defined('ABSPATH')) exit;

class SIA_Provider_OpenRouter extends SIA_AI_Provider {

    private const DEFAULT_MODEL = 'google/gemini-2.5-flash-image';

    public function get_key(): string { return 'openrouter'; }
    public function get_name(): string { return __('OpenRouter', 'smart-image-assistant'); }
    public function get_description(): string { return __('OpenRouter API for AI image generation models.', 'smart-image-assistant'); }

    public function get_default_models(): array {
        return [
            'google/gemini-2.5-flash-image' => 'Google Gemini 2.5 Flash Image',
            'openai/gpt-image-2' => 'OpenAI GPT Image 2',
        ];
    }

    public function is_configured(array $s): bool {
        return !empty(trim($s['api_key'] ?? ''));
    }

    public function render_settings_fields(array $s): void {
        $n = Smart_Image_Assistant::OPTION_NAME;
        $k = $this->get_key();
        $m = $s['model'] ?? self::DEFAULT_MODEL;
        $custom = $s['custom_model'] ?? '';

        echo '<tr><th><label>'.__('API Key','smart-image-assistant').'</label></th><td>';
        echo '<input type="password" name="'.esc_attr($n).'[providers]['.esc_attr($k).'][api_key]" value="'.esc_attr($s['api_key']??'').'" class="regular-text" />';
        echo '<p class="description">'.__('Get your API key from','smart-image-assistant').' <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a></p>';
        echo '</td></tr>';

        echo '<tr><th><label>'.__('Model','smart-image-assistant').'</label></th><td>';
        echo '<select name="'.esc_attr($n).'[providers]['.esc_attr($k).'][model]" id="sia_or_model">';
        foreach ($this->get_default_models() as $slug => $label) {
            $sel = ($m === $slug && empty($custom)) ? ' selected' : '';
            echo '<option value="'.esc_attr($slug).'"'.$sel.'>'.esc_html($label).'</option>';
        }
        echo '</select>';
        echo '<p class="description">'.__('Select a preset model or use custom slug below.','smart-image-assistant').'</p>';
        echo '</td></tr>';

        echo '<tr><th><label>'.__('Custom Model Slug','smart-image-assistant').'</label></th><td>';
        echo '<input type="text" name="'.esc_attr($n).'[providers]['.esc_attr($k).'][custom_model]" value="'.esc_attr($custom).'" class="regular-text" placeholder="e.g. openai/gpt-image-2" />';
        echo '<p class="description">'.__('Override the model selection with a custom OpenRouter model slug. Leave empty to use the selected model above.','smart-image-assistant').'</p>';
        echo '<p class="description">'.__('Find image-capable models at','smart-image-assistant').' <a href="https://openrouter.ai/models?output_modalities=image" target="_blank">openrouter.ai/models</a></p>';
        echo '</td></tr>';

        echo '<tr><th><label>'.__('Aspect Ratio','smart-image-assistant').'</label></th><td>';
        echo '<select name="'.esc_attr($n).'[providers]['.esc_attr($k).'][ratio]">';
        foreach (['16:9'=>'16:9 Landscape','2:3'=>'2:3 Portrait','1:1'=>'1:1 Square'] as $v=>$l) {
            echo '<option value="'.$v.'" '.selected($s['ratio']??'16:9',$v,false).'>'.$l.'</option>';
        }
        echo '</select></td></tr>';
    }

    public function sanitize_settings(array $in): array {
        return [
            'api_key'      => sanitize_text_field($in['api_key'] ?? ''),
            'model'        => sanitize_text_field($in['model'] ?? self::DEFAULT_MODEL),
            'custom_model' => sanitize_text_field($in['custom_model'] ?? ''),
            'ratio'        => in_array($in['ratio']??'', ['16:9','2:3','1:1'], true) ? $in['ratio'] : '16:9',
        ];
    }

    public function generate_image(string $prompt, int $w, int $h, array $s) {
        $key = trim((string) ($s['api_key'] ?? ''));
        $custom = trim((string) ($s['custom_model'] ?? ''));
        $model = $custom !== '' ? $custom : trim((string) ($s['model'] ?? self::DEFAULT_MODEL));

        if ($key === '') return new WP_Error('sia_or_key', __('OpenRouter API key missing.','smart-image-assistant'));
        if ($model === '') return new WP_Error('sia_or_model', __('Model not specified.','smart-image-assistant'));

        $headers = [
            'Authorization'      => 'Bearer '.$key,
            'Content-Type'       => 'application/json',
            'HTTP-Referer'       => home_url(),
            'X-OpenRouter-Title' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
        ];
        $body = [
            'model' => $model,
            'prompt' => trim($prompt),
            'n' => 1,
            'aspect_ratio' => $this->get_aspect_ratio($s['ratio'] ?? '16:9', $w, $h),
        ];

        $resp = wp_remote_post($this->get_endpoint_url($s), [
            'headers' => $headers,
            'timeout' => 180,
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($resp)) return new WP_Error('sia_or_transport', $resp->get_error_message(), $resp->get_error_data());

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('SIA OpenRouter HTTP '.$code.': '.substr($raw, 0, 1000));
            }
            $msg = $this->extract_error_message($data, $raw, $code);
            return new WP_Error(
                'sia_or_http',
                sprintf(__('OpenRouter HTTP %1$d: %2$s', 'smart-image-assistant'), $code, $msg),
                [
                    'status' => $code,
                    'body'   => is_array($data) ? $data : $raw,
                ]
            );
        }

        if (!is_array($data)) {
            return new WP_Error('sia_or_json', __('Invalid JSON response from OpenRouter.','smart-image-assistant'));
        }
        if (!empty($data['error'])) {
            return new WP_Error('sia_or_api', $this->extract_error_message($data, $raw, $code ?: 400), [
                'status' => $code ?: 400,
                'body'   => $data,
            ]);
        }

        // Try to extract image from response
        $result = $this->extract_image_from_response($data);
        if ($result) {
            return $result;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('SIA OpenRouter no image found in response');
        }
        return new WP_Error('sia_or_noimg', __('No image in OpenRouter response. The selected model may not support image generation on the Images API.','smart-image-assistant'), [
            'status' => 422,
            'body'   => $data,
        ]);
    }

    private function get_aspect_ratio(string $ratio, int $w, int $h): string {
        $ratio = trim($ratio);
        if (in_array($ratio, ['16:9', '2:3', '1:1'], true)) {
            return $ratio;
        }
        if ($w > 0 && $h > 0) {
            $gcd = $this->gcd($w, $h);
            if ($gcd > 0) {
                return (int) ($w / $gcd) . ':' . (int) ($h / $gcd);
            }
        }
        return '16:9';
    }

    private function get_size_for_ratio(string $ratio): string {
        switch ($ratio) {
            case '16:9': return '1536x1024';
            case '2:3':  return '1024x1536';
            case '1:1':
            default:     return '1024x1024';
        }
    }

    private function gcd(int $a, int $b): int {
        $a = abs($a);
        $b = abs($b);
        while ($b !== 0) {
            $tmp = $b;
            $b = $a % $b;
            $a = $tmp;
        }
        return $a;
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

    private function extract_image_from_response(array $data): ?array {
        $items = $data['data'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                if (!empty($item['b64_json']) && is_string($item['b64_json'])) {
                    $format = $this->normalize_format($item['media_type'] ?? $item['output_format'] ?? $item['format'] ?? 'png');
                    return ['base64' => $item['b64_json'], 'mime' => $this->format_to_mime($format), 'extension' => $this->format_to_extension($format)];
                }
                if (!empty($item['url']) && is_string($item['url'])) {
                    $downloaded = $this->download_image($item['url']);
                    if ($downloaded) return $downloaded;
                }
                if (!empty($item['image']) && is_string($item['image'])) {
                    $normalized = $this->extract_or_download($item['image']);
                    if ($normalized) return $normalized;
                }
            }
        }

        $choices = $data['choices'] ?? [];
        if (is_array($choices)) {
            foreach ($choices as $choice) {
                $message = is_array($choice) ? ($choice['message'] ?? []) : [];
                $content = is_array($message) ? ($message['content'] ?? null) : null;

                if (is_string($content)) {
                    $parsed = $this->parse_image_from_content($content);
                    if ($parsed) return $parsed;
                }

                if (is_array($content)) {
                    foreach ($content as $part) {
                        if (!is_array($part)) continue;
                        if (($part['type'] ?? null) === 'image_url' && !empty($part['image_url']['url'])) {
                            $downloaded = $this->download_image((string) $part['image_url']['url']);
                            if ($downloaded) return $downloaded;
                        }
                        if (($part['type'] ?? null) === 'output_text' && !empty($part['text'])) {
                            $parsed = $this->parse_image_from_content((string) $part['text']);
                            if ($parsed) return $parsed;
                        }
                        if (!empty($part['inline_data']['data']) && is_string($part['inline_data']['data'])) {
                            $mime = $this->normalize_mime((string) ($part['inline_data']['mime_type'] ?? 'image/png'));
                            return ['base64' => $part['inline_data']['data'], 'mime' => $mime, 'extension' => $this->mime_to_extension($mime)];
                        }
                        if (!empty($part['image']) && is_string($part['image'])) {
                            $normalized = $this->extract_or_download($part['image']);
                            if ($normalized) return $normalized;
                        }
                        if (($part['type'] ?? null) === 'image' && !empty($part['data']) && is_string($part['data'])) {
                            return ['base64' => $part['data'], 'mime' => 'image/png', 'extension' => 'png'];
                        }
                    }
                }
            }
        }

        return null;
    }

    private function parse_image_from_content(string $content): ?array {
        if (preg_match('/data:(image\/[^;]+);base64,([A-Za-z0-9+\/=]+)/', $content, $m)) {
            $mime = $this->normalize_mime($m[1]);
            return ['base64' => $m[2], 'mime' => $mime, 'extension' => $this->mime_to_extension($mime)];
        }

        $trimmed = trim($content);
        if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return $this->download_image($trimmed);
        }

        $json = json_decode($content, true);
        if (is_array($json)) {
            if (!empty($json['b64_json']) && is_string($json['b64_json'])) {
                $format = $this->normalize_format($json['media_type'] ?? $json['output_format'] ?? $json['format'] ?? 'png');
                return ['base64' => $json['b64_json'], 'mime' => $this->format_to_mime($format), 'extension' => $this->format_to_extension($format)];
            }
            if (!empty($json['image']) && is_string($json['image'])) {
                return $this->extract_or_download($json['image']);
            }
            if (!empty($json['url']) && is_string($json['url'])) {
                return $this->download_image($json['url']);
            }
        }

        if (strlen($trimmed) > 100 && preg_match('/^[A-Za-z0-9+\/=]+$/', $trimmed)) {
            return ['base64' => $trimmed, 'mime' => 'image/png', 'extension' => 'png'];
        }

        return null;
    }

    private function extract_or_download(string $data): ?array {
        if (preg_match('/^data:(image\/[^;]+);base64,(.+)$/', $data, $m)) {
            $mime = $this->normalize_mime($m[1]);
            return ['base64' => $m[2], 'mime' => $mime, 'extension' => $this->mime_to_extension($mime)];
        }
        if (filter_var($data, FILTER_VALIDATE_URL)) {
            return $this->download_image($data);
        }
        if (strlen($data) > 100 && preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            return ['base64' => $data, 'mime' => 'image/png', 'extension' => 'png'];
        }
        return null;
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
        $mime = $this->normalize_mime($content_type ?: '');
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) return null;
        return ['base64' => base64_encode($body), 'mime' => $mime, 'extension' => $this->mime_to_extension($mime)];
    }

    private function normalize_format(string $format): string {
        $format = strtolower(trim($format));
        if (str_starts_with($format, 'image/')) {
            $format = substr($format, 6);
        }
        return in_array($format, ['png', 'jpeg', 'jpg', 'webp', 'svg', 'image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'], true) ? $format : 'png';
    }

    private function normalize_mime(string $mime): string {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        return match ($mime) {
            'image/jpg' => 'image/jpeg',
            'image/webp' => 'image/webp',
            'image/jpeg', 'image/png' => $mime,
            default => 'application/octet-stream',
        };
    }

    private function format_to_mime(string $format): string {
        return match ($format) {
            'jpeg', 'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function format_to_extension(string $format): string {
        return match ($format) {
            'jpeg', 'jpg' => 'jpg',
            'webp' => 'webp',
            default => 'png',
        };
    }

    private function mime_to_extension(string $mime): string {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    public function get_endpoint_url(array $s): string {
        return 'https://openrouter.ai/api/v1/images';
    }

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
}
