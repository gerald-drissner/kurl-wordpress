<?php

defined('ABSPATH') || exit;

/**
 * Front-end output: the [kurl_shortlink] shortcode and the template tags
 * kurl_get_shortlink() and kurl_the_shortlink().
 */
final class Kurl_Frontend {

    private const ALLOWED_TARGETS = ['_blank', '_self', '_parent', '_top'];

    public static function init(): void {
        add_shortcode('kurl_shortlink', [__CLASS__, 'shortcode']);
    }

    /**
     * Resolve the post a template tag or shortcode refers to.
     *
     * Inside a loop this is the current loop post. Outside a loop, for example
     * in a footer, header, or page-builder template, it is the queried post on
     * singular views. Archives and other non-singular views resolve to 0, so
     * a footer never shows the short URL of whichever post the archive loop
     * happened to end with.
     *
     * @param int|string|WP_Post|null $post Explicit post, or null for the current one.
     */
    public static function resolve_post_id($post = null): int {
        if ($post instanceof WP_Post) {
            return (int) $post->ID;
        }
        if (is_numeric($post) && (int) $post > 0) {
            $object = get_post((int) $post);
            return $object instanceof WP_Post ? (int) $object->ID : 0;
        }
        if (in_the_loop()) {
            return (int) get_the_ID();
        }
        if (is_singular()) {
            return (int) get_queried_object_id();
        }
        return 0;
    }

    /**
     * Short URL of a post, or an empty string.
     *
     * @param int|string|WP_Post|null $post     Explicit post, or null for the current one.
     * @param bool                    $fallback Use the regular WordPress shortlink when no kURL link exists.
     */
    public static function get_shortlink($post = null, bool $fallback = false): string {
        $post_id = self::resolve_post_id($post);
        if ($post_id <= 0 || !self::can_show($post_id)) {
            return '';
        }

        $shorturl = Kurl_Shortlinks::get_shorturl($post_id);
        if ($shorturl === '' && $fallback) {
            // kURL's own pre_get_shortlink filter returns no value here, so this
            // yields the WordPress (or another plugin's) shortlink.
            $core = wp_get_shortlink($post_id);
            $shorturl = is_string($core) ? Kurl_Helpers::sanitize_http_url($core) : '';
        }

        /**
         * Filter the short URL returned by kurl_get_shortlink() and the shortcode.
         *
         * @param string $shorturl Short URL or an empty string.
         * @param int    $post_id  Post ID.
         * @param bool   $fallback Whether the WordPress shortlink fallback was requested.
         */
        $shorturl = apply_filters('kurl_get_shortlink', $shorturl, $post_id, $fallback);
        return is_string($shorturl) ? $shorturl : '';
    }

    /**
     * Shortcode handler for [kurl_shortlink].
     *
     * @param array|string $atts Shortcode attributes.
     */
    public static function shortcode($atts): string {
        $atts = shortcode_atts(self::defaults(), is_array($atts) ? $atts : [], 'kurl_shortlink');
        return self::render($atts);
    }

    /**
     * Build the output for the shortcode and kurl_the_shortlink().
     *
     * @param array $args See self::defaults().
     */
    public static function render(array $args = []): string {
        $args = wp_parse_args($args, self::defaults());

        $post = $args['post_id'] !== '' && $args['post_id'] !== null ? $args['post_id'] : null;
        $url  = self::get_shortlink($post, self::truthy($args['fallback']));
        if ($url === '') {
            return '';
        }

        if (self::truthy($args['link'])) {
            $text  = Kurl_Helpers::scalar_string($args['text']);
            $attrs = ' href="' . esc_url($url) . '"';

            $classes = self::sanitize_tokens(Kurl_Helpers::scalar_string($args['class']), 'sanitize_html_class');
            if ($classes !== '') {
                $attrs .= ' class="' . esc_attr($classes) . '"';
            }

            $target = Kurl_Helpers::scalar_string($args['target']);
            $rel    = self::sanitize_tokens(Kurl_Helpers::scalar_string($args['rel']), 'sanitize_key');
            if (in_array($target, self::ALLOWED_TARGETS, true)) {
                $attrs .= ' target="' . esc_attr($target) . '"';
                if ($target === '_blank' && strpos(' ' . $rel . ' ', ' noopener ') === false) {
                    $rel = trim($rel . ' noopener');
                }
            }
            if ($rel !== '') {
                $attrs .= ' rel="' . esc_attr($rel) . '"';
            }

            $output = '<a' . $attrs . '>' . esc_html($text !== '' ? $text : $url) . '</a>';
        } else {
            $output = esc_html($url);
        }

        $html = wp_kses_post(Kurl_Helpers::scalar_string($args['before'])) . $output . wp_kses_post(Kurl_Helpers::scalar_string($args['after']));

        /**
         * Filter the HTML of the [kurl_shortlink] shortcode and kurl_the_shortlink().
         *
         * @param string $html Output HTML.
         * @param string $url  Short URL.
         * @param array  $args Parsed arguments.
         */
        $html = apply_filters('kurl_shortlink_html', $html, $url, $args);
        return is_string($html) ? $html : '';
    }

    /**
     * Supported shortcode attributes and template-tag arguments.
     */
    public static function defaults(): array {
        return [
            'post_id'  => '',
            'link'     => '0',
            'text'     => '',
            'class'    => 'kurl-shortlink',
            'target'   => '',
            'rel'      => '',
            'before'   => '',
            'after'    => '',
            'fallback' => '0',
        ];
    }

    /**
     * Do not reveal the short URL of a draft, private, or otherwise
     * non-public post to visitors who could not read the post itself.
     */
    private static function can_show(int $post_id): bool {
        return is_post_publicly_viewable($post_id) || current_user_can('read_post', $post_id);
    }

    /**
     * @param mixed $value Attribute value.
     */
    private static function truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim(Kurl_Helpers::scalar_string($value))), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Sanitize a space-separated attribute value token by token.
     *
     * @param string   $value     Raw value.
     * @param callable $sanitizer Per-token sanitizer.
     */
    private static function sanitize_tokens(string $value, callable $sanitizer): string {
        $tokens = preg_split('/\s+/', trim($value));
        $clean  = [];
        foreach (is_array($tokens) ? $tokens : [] as $token) {
            $token = (string) call_user_func($sanitizer, $token);
            if ($token !== '') {
                $clean[] = $token;
            }
        }
        return implode(' ', array_values(array_unique($clean)));
    }
}
