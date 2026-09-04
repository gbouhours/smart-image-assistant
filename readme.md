=== Smart Image Assistant ===
Contributors: Gregory Bouhours
Tags: featured image, ai, image generation, gutenberg, openai, cloudflare, openrouter
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: smart-image-assistant
Domain Path: /languages

Smart Image Assistant adds AI image generation to the WordPress block editor so you can create a featured image from a post title and excerpt.

== Description ==

Smart Image Assistant provides a Gutenberg sidebar panel for generating images through supported AI providers, previewing the result, saving it to the Media Library, and setting it as the post featured image.

The plugin includes three providers in the current codebase: Cloudflare Workers AI, OpenAI, and OpenRouter.

== Features ==

- Gutenberg sidebar panel for posts enabled in the plugin settings.
- Image generation from the current post title and excerpt, with an editable prompt template.
- Provider selection from the configured AI providers.
- Image preview inside the block editor after generation.
- Media Library attachment creation from the generated image.
- Optional assignment of the generated image as the featured image.
- Optional automatic generation on post save when enabled in settings.
- Per-provider configuration in the WordPress admin settings page.

== AI Providers ==

| Provider | Available models / purpose | Required settings | Known limitations |
| --- | --- | --- | --- |
| OpenAI | `gpt-image-1-mini`, `gpt-image-1`, `gpt-image-1.5` | API key, model, quality, output format, aspect ratio | Quality is limited to the implemented values `low`, `medium`, and `high`. Output format is implemented as `png`, `jpeg`, or `webp`. Supported image sizes are mapped from the selected aspect ratio. |
| Cloudflare Workers AI | `@cf/black-forest-labs/flux-1-schnell`, `@cf/stabilityai/stable-diffusion-xl-base-1.0`, `@cf/bytedance/stable-diffusion-xl-lightning` | Account ID, API token, model, width, height | Flux.1 Schnell ignores width and height and uses its own default dimensions. SDXL models use width and height and are rounded to multiples of 128. The provider accepts binary image responses or JSON responses containing base64 image data. |
| OpenRouter | `google/gemini-2.5-flash-image`, `openai/gpt-image-2` | API key, model or custom model slug, aspect ratio | The plugin sends image generation requests to the OpenRouter Images API and expects an image-bearing response. If the selected model does not return image data, generation fails. |

== Installation ==

1. Upload or copy the plugin folder to `wp-content/plugins/smart-image-assistant/`.
2. Activate the plugin from the WordPress Plugins screen.

== Configuration ==

The plugin adds a Smart Image Assistant settings page under the WordPress admin Settings menu.

General settings include:

- Default AI provider.
- Prompt template used to build generation prompts.
- Enabled post types.
- Optional auto-generation on save.

Provider settings require the following credentials:

- OpenAI: API key.
- Cloudflare Workers AI: Account ID and API token.
- OpenRouter: API key.

Example placeholder values only:

- `sk-...`
- `cf-api-token-...`
- `account-id-...`

== Gutenberg Usage ==

1. Open a post that uses an enabled post type.
2. Make sure the post has a title. The generator requires a title.
3. Open the Smart Image Assistant panel in the editor sidebar.
4. Choose a configured provider.
5. Adjust the size settings or aspect ratio if needed.
6. Edit the prompt template if you want a custom result.
7. Click Generate Featured Image.
8. Review the preview.
9. Click Set as Featured Image to create the media attachment and assign it to the post.

The editor also supports a manual preview-only generation flow before the image is attached.

== Image Formats and Media Library ==

The plugin stores generated images as attachments in the WordPress Media Library.

- OpenAI can return PNG, JPEG, or WebP, and the plugin preserves the returned format when creating the attachment.
- Cloudflare Workers AI and OpenRouter currently feed the attachment workflow with the returned image bytes and detected MIME type when available.
- Attachment files are created through WordPress upload and attachment functions, then attachment metadata is generated with core image functions.

== Limitations and Troubleshooting ==

- A provider must be configured before it can be used in the editor.
- Generation fails if the post has no title.
- Cloudflare Workers AI requires a valid Account ID, API token, and model slug.
- Cloudflare Workers AI returns an error if the prompt is empty or longer than 2,048 characters.
- Cloudflare Workers AI returns an error when the provider response does not contain image data.
- Flux.1 Schnell does not use custom width and height values.
- OpenAI requires a valid API key and a supported image model.
- OpenRouter requires a valid API key and a model that actually returns image data through the Images API.
- If the remote provider returns an HTTP error, the plugin surfaces that error in the editor.
- If WordPress cannot process the returned image format on the server, attachment creation may fail.

== Security ==

API credentials must never be committed to Git, exposed in public JavaScript, shared in screenshots, or pasted into support tickets.

Credentials are entered through the plugin settings page in the WordPress administration area.

== Development ==

The plugin follows standard WordPress coding conventions and uses the `smart-image-assistant` text domain.

Minimal current file structure:

- `smart-image-assistant.php`
- `includes/`
- `providers/`
- `editor.js`
- `readme.md`
- `languages/`

Compatibility declared in the plugin header:

- WordPress 6.0 or later
- PHP 7.4 or later

== Changelog ==

= 2.0.0 =
* Current release.

= 1.0.0 =
* Initial release.