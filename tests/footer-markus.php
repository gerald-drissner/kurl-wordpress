<?php
// Markus' footer code, exactly as described in his e-mail, plus the new shortcode.
add_action('wp_footer', function () {
    echo "\n<!--MARKUS:" . wp_get_shortlink() . "-->\n";
    echo "<!--SHORTCODE:" . do_shortcode('[kurl_shortlink link="1"]') . "-->\n";
});
