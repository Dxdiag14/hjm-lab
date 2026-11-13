<?php
/**
 * Headless content for the Astro site (lab.hjmiranda.com)
 *
 * 1. "Projects" content type + "Platforms" taxonomy, readable in the REST API
 * 2. Extra fields: live URL, tags, kind, image (ready-made for Astro)
 * 3. Keep projects out of Yoast sitemaps and search (the WordPress site has its own Work page)
 * 4. Rebuild the Astro site when content changes (deploy hook in Settings › General)
 * 5. [hjm_case_studies] shortcode: case study cards for the Case Studies page
 */

// 1. Content type + taxonomy
add_action( 'init', function () {
    register_post_type( 'hjm_project', array(
        'labels'              => array(
            'name'          => 'Projects',
            'singular_name' => 'Project',
            'add_new_item'  => 'Add New Project',
            'edit_item'     => 'Edit Project',
            'all_items'     => 'All Projects',
        ),
        'public'              => true,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'show_in_rest'        => true,
        'rest_base'           => 'projects',
        'menu_icon'           => 'dashicons-portfolio',
        'menu_position'       => 21,
        'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'page-attributes' ),
    ) );

    register_taxonomy( 'hjm_platform', 'hjm_project', array(
        'labels'            => array( 'name' => 'Platforms', 'singular_name' => 'Platform' ),
        'public'            => true,
        'rewrite'           => false,
        'hierarchical'      => false,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rest_base'         => 'platforms',
    ) );

    $meta = array(
        'hjm_url'  => 'Live site URL',
        'hjm_tags' => 'Tags, comma separated (e.g. Elementor, Custom PHP)',
        'hjm_kind'     => 'public, nda or case-study',
        'hjm_case_url' => 'Case study URL (fills the Case Studies page)',
        'hjm_metric'   => 'Headline result for the case study card (e.g. 62 → 98 PageSpeed)',
    );

    foreach ( $meta as $key => $description ) {
        register_post_meta( 'hjm_project', $key, array(
            'type'          => 'string',
            'description'   => $description,
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ) );
    }
} );

// 2. Ready-made image + platform names in the REST response
add_action( 'rest_api_init', function () {
    register_rest_field( 'hjm_project', 'hjm_image', array(
        'get_callback' => function ( $post ) {
            $id = get_post_thumbnail_id( $post['id'] );
            if ( ! $id ) {
                return null;
            }
            $full  = wp_get_attachment_image_src( $id, 'full' );
            $large = wp_get_attachment_image_src( $id, 'large' );
            return array(
                'url'    => $full ? $full[0] : '',
                'large'  => $large ? $large[0] : '',
                'width'  => $full ? (int) $full[1] : 0,
                'height' => $full ? (int) $full[2] : 0,
                'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            );
        },
        'schema' => null,
    ) );

    register_rest_field( 'hjm_project', 'hjm_platforms', array(
        'get_callback' => function ( $post ) {
            $terms = get_the_terms( $post['id'], 'hjm_platform' );
            return is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : array();
        },
        'schema' => null,
    ) );
} );

// 3. Not in Yoast sitemaps, and noindex if ever opened on WordPress
add_filter( 'wpseo_sitemap_exclude_post_type', function ( $excluded, $post_type ) {
    return 'hjm_project' === $post_type ? true : $excluded;
}, 10, 2 );

add_filter( 'wpseo_sitemap_exclude_taxonomy', function ( $excluded, $taxonomy ) {
    return 'hjm_platform' === $taxonomy ? true : $excluded;
}, 10, 2 );

add_filter( 'wpseo_robots', function ( $robots ) {
    return is_singular( 'hjm_project' ) ? 'noindex, follow' : $robots;
} );

// 4. Deploy hook: Settings › General › "Astro deploy hook URL"
add_action( 'admin_init', function () {
    register_setting( 'general', 'hjm_deploy_hook', array(
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ) );

    add_settings_field( 'hjm_deploy_hook', 'Astro deploy hook URL', function () {
        printf(
            '<input type="url" name="hjm_deploy_hook" value="%s" class="regular-text code" placeholder="https://api.cloudflare.com/client/v4/pages/webhooks/deploy_hooks/…"><p class="description">When a project or post is published, updated or deleted, the Astro site (lab.hjmiranda.com) rebuilds.</p>',
            esc_attr( get_option( 'hjm_deploy_hook', '' ) )
        );
    }, 'general' );
} );

function hjm_trigger_deploy( $post_id ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }

    if ( ! in_array( get_post_type( $post_id ), array( 'hjm_project', 'post' ), true ) ) {
        return;
    }

    $hook = get_option( 'hjm_deploy_hook' );

    // One rebuild per minute at most, even if several things are saved.
    if ( ! $hook || get_transient( 'hjm_deploy_lock' ) ) {
        return;
    }

    set_transient( 'hjm_deploy_lock', 1, MINUTE_IN_SECONDS );
    wp_remote_post( $hook, array( 'timeout' => 5, 'blocking' => false ) );
}

add_action( 'save_post', function ( $post_id, $post ) {
    if ( 'publish' === $post->post_status ) {
        hjm_trigger_deploy( $post_id );
    }
}, 20, 2 );

add_action( 'transition_post_status', function ( $new, $old, $post ) {
    if ( 'publish' === $old && 'publish' !== $new ) {
        delete_transient( 'hjm_deploy_lock' );
        hjm_trigger_deploy( $post->ID );
    }
}, 10, 3 );

// 5. Case study cards: [hjm_case_studies] (optionally [hjm_case_studies limit="3"])
add_shortcode( 'hjm_case_studies', function ( $atts ) {
    $atts = shortcode_atts( array( 'limit' => -1 ), $atts, 'hjm_case_studies' );

    $query = new WP_Query( array(
        'post_type'      => 'hjm_project',
        'post_status'    => 'publish',
        'posts_per_page' => (int) $atts['limit'],
        'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
        'meta_query'     => array(
            array( 'key' => 'hjm_case_url', 'value' => '', 'compare' => '!=' ),
        ),
        'no_found_rows'  => true,
    ) );

    if ( ! $query->have_posts() ) {
        return '<p class="hjm-cs-empty">New case studies are on the way.</p>';
    }

    $html  = '<div class="hjm-cs-grid">';
    $index = 0;

    foreach ( $query->posts as $post ) {
        $url      = get_post_meta( $post->ID, 'hjm_case_url', true );
        $metric   = get_post_meta( $post->ID, 'hjm_metric', true );
        $tags     = array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $post->ID, 'hjm_tags', true ) ) ) );
        $terms    = get_the_terms( $post->ID, 'hjm_platform' );
        $platform = is_array( $terms ) ? implode( ' · ', wp_list_pluck( $terms, 'name' ) ) : '';
        $external = 0 !== strpos( $url, home_url() ) && 0 !== strpos( $url, '/' );
        $target   = $external ? ' target="_blank" rel="noopener"' : '';
        $summary  = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
        $image    = get_the_post_thumbnail(
            $post,
            'large',
            array(
                'class'   => 'hjm-cs-img',
                'loading' => 0 === $index ? 'eager' : 'lazy',
                'sizes'   => 0 === $index ? '(max-width: 1024px) 100vw, 640px' : '(max-width: 767px) 100vw, 560px',
            )
        );

        $html .= '<article class="hjm-cs-card' . ( 0 === $index ? ' is-featured' : '' ) . '">';

        if ( $image ) {
            $html .= '<a class="hjm-cs-media" href="' . esc_url( $url ) . '"' . $target . ' tabindex="-1" aria-hidden="true">' . $image . '</a>';
        }

        $html .= '<div class="hjm-cs-body">';
        $html .= '<span class="hjm-cs-eyebrow">Case study' . ( $platform ? ' · ' . esc_html( $platform ) : '' ) . '</span>';
        $html .= '<h3 class="hjm-cs-title"><a href="' . esc_url( $url ) . '"' . $target . '>' . esc_html( get_the_title( $post ) ) . '</a></h3>';

        if ( $metric ) {
            $html .= '<p class="hjm-cs-metric">' . esc_html( $metric ) . '</p>';
        }

        $html .= '<p class="hjm-cs-summary">' . esc_html( $summary ) . '</p>';

        if ( $tags ) {
            $html .= '<ul class="hjm-cs-tags">';
            foreach ( array_slice( $tags, 0, 4 ) as $tag ) {
                $html .= '<li>' . esc_html( $tag ) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<span class="hjm-cs-more" aria-hidden="true">Read the case study ' . ( $external ? '↗' : '→' ) . '</span>';
        $html .= '</div></article>';
        $index++;
    }

    return $html . '</div>';
} );
