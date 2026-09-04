<?php
if (!defined('ABSPATH')) exit;

class SIA_Provider_Cloudflare extends SIA_AI_Provider
{

    public function get_key(): string
    {
        return 'cloudflare';
    }
    public function get_name(): string
    {
        return __('Cloudflare Workers AI', 'smart-image-assistant');
    }
    public function get_description(): string
    {
        return __('Cloudflare Workers AI text-to-image models.', 'smart-image-assistant');
    }

    public function get_default_models(): array
    {
        return [
            '@cf/black-forest-labs/flux-1-schnell' => 'Flux 1 Schnell',
            '@cf/stabilityai/stable-diffusion-xl-base-1.0' => 'SDXL',
            '@cf/bytedance/stable-diffusion-xl-lightning' => 'SDXL Lightning',
        ];
    }

    public function is_configured(array $s): bool
    {
        return !empty(trim($s['account_id'] ?? '')) && !empty(trim($s['api_token'] ?? ''));
    }

    public function render_settings_fields(array $s): void
    {
        $n = Smart_Image_Assistant::OPTION_NAME;
        $k = $this->get_key();
        $m = $s['model'] ?? '';
        echo '<tr><th><label>' . __('Account ID', 'smart-image-assistant') . '</label></th><td>';
        echo '<input type="text" name="' . esc_attr($n) . '[providers][' . esc_attr($k) . '][account_id]" value="' . esc_attr($s['account_id'] ?? '') . '" class="regular-text" />';
        echo '</td></tr>';
        echo '<tr><th><label>' . __('API Token', 'smart-image-assistant') . '</label></th><td>';
        echo '<input type="password" name="' . esc_attr($n) . '[providers][' . esc_attr($k) . '][api_token]" value="' . esc_attr($s['api_token'] ?? '') . '" class="regular-text" />';
        echo '</td></tr>';
        echo '<tr><th><label for="sia_cloudflare_model">'
            . esc_html__('Model', 'smart-image-assistant')
            . '</label></th><td>';

        echo '<input
    id="sia_cloudflare_model"
    type="text"
    class="regular-text"
    list="sia_cloudflare_models"
    name="' . esc_attr($n) . '[providers][' . esc_attr($k) . '][model]"
    value="' . esc_attr($m) . '"
    placeholder="@cf/black-forest-labs/flux-1-schnell"
 />';

        echo '<datalist id="sia_cloudflare_models">';

        foreach ($this->get_default_models() as $slug => $label) {
            echo '<option value="' . esc_attr($slug) . '">'
                . esc_html($label)
                . '</option>';
        }

        echo '</datalist>';

        echo '<p class="description">'
            . esc_html__('Choose a listed model or enter a valid Workers AI model slug.', 'smart-image-assistant')
            . '</p>';

        echo '</td></tr>';
        echo '<tr><th><label>' . __('Width (px)', 'smart-image-assistant') . '</label></th><td>';
        echo '<input type="number" min="64" max="2048" step="1" name="' . esc_attr($n) . '[providers][' . esc_attr($k) . '][width]" value="' . esc_attr($s['width'] ?? 1024) . '" style="width:80px" /> × ';
        echo '<input type="number" min="64" max="2048" step="1" name="' . esc_attr($n) . '[providers][' . esc_attr($k) . '][height]" value="' . esc_attr($s['height'] ?? 576) . '" style="width:80px" />';
        echo '<p class="description">'
            . esc_html__(
                'Image dimensions apply to SDXL models. Flux.1 Schnell uses its own default dimensions.',
                'smart-image-assistant'
            )
            . '</p>';
        echo '</td></tr>';
    }

    public function sanitize_settings(array $in): array
    {
        return [
            'account_id' => sanitize_text_field($in['account_id'] ?? ''),
            'api_token'  => sanitize_text_field($in['api_token'] ?? ''),
            'model'      => sanitize_text_field($in['model'] ?? ''),
            'width'      => max(64, min(2048, intval($in['width'] ?? 1024))),
            'height'     => max(64, min(2048, intval($in['height'] ?? 576))),
        ];
    }

    public function generate_image(string $prompt, int $w, int $h, array $s)
    {
        $aid = trim((string) ($s['account_id'] ?? ''));
        $tok = trim((string) ($s['api_token'] ?? ''));
        $mod = trim((string) ($s['model'] ?? ''));

        if ($aid === '' || $tok === '' || $mod === '') {
            return new WP_Error(
                'sia_cf_cfg',
                __('Incomplete Cloudflare settings.', 'smart-image-assistant')
            );
        }

        $prompt = trim($prompt);

        if ($prompt === '') {
            return new WP_Error(
                'sia_cf_prompt',
                __('The image prompt cannot be empty.', 'smart-image-assistant')
            );
        }

        if (mb_strlen($prompt) > 2048) {
            return new WP_Error(
                'sia_cf_prompt_length',
                __('The Cloudflare prompt must not exceed 2,048 characters.', 'smart-image-assistant')
            );
        }

        $is_flux_schnell = $mod === '@cf/black-forest-labs/flux-1-schnell';

        $is_sdxl = in_array($mod, [
            '@cf/stabilityai/stable-diffusion-xl-base-1.0',
            '@cf/bytedance/stable-diffusion-xl-lightning',
        ], true);

        $w = max(256, min(2048, $w));
        $h = max(256, min(2048, $h));

        /*
     * SDXL fonctionne mieux avec des tailles multiples de 128.
     * On évite de dépasser 2048 une fois l'arrondi appliqué.
     */
        if ($is_sdxl) {
            $w = min(2048, (int) ceil($w / 128) * 128);
            $h = min(2048, (int) ceil($h / 128) * 128);
        }

        /*
     * Encode le slug complet tout en préservant les slashs.
     *
     * Exemple :
     * @cf/black-forest-labs/flux-1-schnell
     * devient
     * %40cf/black-forest-labs/flux-1-schnell
     */
        $encoded_model = str_replace(
            '%2F',
            '/',
            rawurlencode($mod)
        );

        $endpoint = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
            rawurlencode($aid),
            $encoded_model
        );

        $body = [
            'prompt' => $prompt,
        ];

        switch ($mod) {
            case '@cf/black-forest-labs/flux-1-schnell':
                /*
                * Paramètres acceptés par Flux.1 Schnell.
                * Ne pas transmettre width, height ni format.
                */
                $body['steps'] = 4;
                break;

            case '@cf/stabilityai/stable-diffusion-xl-base-1.0':
                $body['width'] = $w;
                $body['height'] = $h;
                $body['num_steps'] = 20;
                break;

            case '@cf/bytedance/stable-diffusion-xl-lightning':
                $body['width'] = $w;
                $body['height'] = $h;
                $body['num_steps'] = 4;
                break;

            default:
                /*
                * À ne faire que pour un modèle dont tu as vérifié
                * qu'il accepte width / height.
                */
                $body['width'] = $w;
                $body['height'] = $h;
                break;
        }
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $tok,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => 120,
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'sia_cf_request_failed',
                sprintf(
                    __('Cloudflare request failed: %s', 'smart-image-assistant'),
                    $response->get_error_message()
                )
            );
        }

        $code         = (int) wp_remote_retrieve_response_code($response);
        $raw          = wp_remote_retrieve_body($response);
        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');

        if ($code < 200 || $code >= 300) {
            $error_data = json_decode($raw, true);

            $message = is_array($error_data)
                ? ($error_data['errors'][0]['message']
                    ?? $error_data['messages'][0]['message']
                    ?? '')
                : '';

            if ($message === '') {
                $message = wp_strip_all_tags(substr($raw, 0, 500));
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    sprintf(
                        'SIA Cloudflare HTTP %d | model=%s | response=%s',
                        $code,
                        $mod,
                        substr($raw, 0, 1000)
                    )
                );
            }

            return new WP_Error(
                'sia_cf_http',
                sprintf(
                    __('Cloudflare HTTP %1$d: %2$s', 'smart-image-assistant'),
                    $code,
                    $message ?: __('Unknown API error.', 'smart-image-assistant')
                ),
                [
                    'status' => $code,
                    'body'   => $error_data ?: $raw,
                ]
            );
        }

        /*
     * Cas 1 : Cloudflare renvoie une image binaire directement.
     */
        if (
            str_starts_with(strtolower($content_type), 'image/')
            || str_starts_with($raw, "\x89PNG")
            || str_starts_with($raw, "\xFF\xD8\xFF")
        ) {
            return ['base64' => base64_encode($raw)];
        }

        /*
     * Cas 2 : Cloudflare renvoie le wrapper JSON standard.
     */
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    'SIA Cloudflare invalid JSON: ' . substr($raw, 0, 1000)
                );
            }

            return new WP_Error(
                'sia_cf_json',
                __('Cloudflare returned neither valid JSON nor an image.', 'smart-image-assistant')
            );
        }

        if (!empty($data['errors'])) {
            $message = $data['errors'][0]['message']
                ?? __('Cloudflare API error.', 'smart-image-assistant');

            return new WP_Error('sia_cf_api', $message, $data);
        }

        $result = $data['result'] ?? null;
        $base64 = null;

        if (is_array($result)) {
            $base64 = $result['image']
                ?? ($result[0]['image'] ?? null)
                ?? ($result['data'] ?? null);
        }

        if (!is_string($base64) || $base64 === '') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    'SIA Cloudflare: missing image in JSON. Response: ' .
                        substr($raw, 0, 1000)
                );
            }

            return new WP_Error(
                'sia_cf_noimg',
                __('Cloudflare returned no image data.', 'smart-image-assistant')
            );
        }

        /*
     * Certains services retournent data:image/...;base64,... ;
     * on normalise avant de transmettre la valeur au reste du plugin.
     */
        if (str_starts_with($base64, 'data:image/')) {
            $base64 = preg_replace('#^data:image/[^;]+;base64,#', '', $base64);
        }

        return ['base64' => $base64];
    }

    public function get_endpoint_url(array $s): string
    {
        return 'https://api.cloudflare.com/client/v4/accounts/{id}/ai/run/{model}';
    }
}
