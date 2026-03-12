<?php
/**
 * Plugin Name: EPO Fabric Listing
 * Description: Shortcode que muestra productos con sus swatches de Extra Product Options
 *              (ThemeComplete) en un layout tipo catálogo de telas. Filtra por categoría
 *              de producto y taxonomy personalizada "collection".
 * Version: 2.0.2
 * Author: Alejandro Gonzalez
 * Requires Plugins: woocommerce, woocommerce-tm-extra-product-options
 *
 * ============================================================================
 * USO DEL SHORTCODE:
 *
 *   [epo_fabric_listing]
 *       → Muestra TODOS los productos que tengan opciones EPO con imágenes.
 *
 *   [epo_fabric_listing category="upholstery"]
 *       → Filtra por categoría de producto (slug).
 *
 *   [epo_fabric_listing collection="spring-2026"]
 *       → Filtra por la taxonomy personalizada "collection" (slug).
 *
 *   [epo_fabric_listing category="outdoor" collection="resort"]
 *       → Combina ambos filtros.
 *
 *   [epo_fabric_listing columns="4" limit="20" orderby="title" order="ASC"]
 *       → Personaliza columnas, cantidad y orden.
 *
 *   [epo_fabric_listing show_product_image="yes"]
 *       → Muestra la imagen destacada del producto junto al nombre.
 *
 *   [epo_fabric_listing link_to_product="yes"]
 *       → Hace que el nombre del producto sea un enlace a su página.
 *
 * PARÁMETROS COMPLETOS:
 *   category          → Slug de product_cat (separar múltiples con coma)
 *   collection        → Slug de la taxonomy "collection" (separar con coma)
 *   columns           → Columnas del grid (default: 3)
 *   limit             → Máximo de productos (default: -1 = todos)
 *   orderby           → Ordenar por: title, date, menu_order, rand (default: title)
 *   order             → ASC o DESC (default: ASC)
 *   show_product_image→ yes/no - mostrar imagen destacada (default: no)
 *   link_to_product   → yes/no - enlazar nombre al producto (default: yes)
 *   swatch_size       → Tamaño de swatch en px (default: 150)
 *
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/* ==========================================================================
   1. REGISTRAR SHORTCODE
   ========================================================================== */

add_shortcode( 'epo_fabric_listing', 'epo_fabric_listing_shortcode' );

function epo_fabric_listing_shortcode( $atts ) {

    $atts = shortcode_atts( array(
        'category'           => '',
        'collection'         => '',
        'columns'            => 3,
        'limit'              => -1,
        'orderby'            => 'title',
        'order'              => 'ASC',
        'show_product_image' => 'no',
        'link_to_product'    => 'yes',
        'swatch_size'        => 150,
    ), $atts, 'epo_fabric_listing' );

    // Construir la query de productos.
    $query_args = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => intval( $atts['limit'] ),
        'orderby'        => sanitize_text_field( $atts['orderby'] ),
        'order'          => sanitize_text_field( $atts['order'] ),
        'tax_query'      => array(
            'relation' => 'AND',
        ),
    );

    // Filtro por categoría de producto.
    if ( ! empty( $atts['category'] ) ) {
        $query_args['tax_query'][] = array(
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => array_map( 'trim', explode( ',', $atts['category'] ) ),
        );
    }

    // Filtro por taxonomy "collection".
    if ( ! empty( $atts['collection'] ) ) {
        $query_args['tax_query'][] = array(
            'taxonomy' => 'collection',
            'field'    => 'slug',
            'terms'    => array_map( 'trim', explode( ',', $atts['collection'] ) ),
        );
    }

    $products = new WP_Query( $query_args );

    if ( ! $products->have_posts() ) {
        return '<p class="epo-fl-empty">No se encontraron productos con las opciones especificadas.</p>';
    }

    // Encolar assets.
    epo_fabric_listing_enqueue( $atts );

    $columns     = max( 1, min( 6, intval( $atts['columns'] ) ) );
    $swatch_size = max( 60, min( 300, intval( $atts['swatch_size'] ) ) );
    $link        = $atts['link_to_product'] === 'yes';
    $show_img    = $atts['show_product_image'] === 'yes';

    ob_start();

    echo '<div class="epo-fl" style="--epo-fl-columns:' . $columns . ';--epo-fl-swatch:' . $swatch_size . 'px;">';

    while ( $products->have_posts() ) {
        $products->the_post();

        global $product;
        $product_id = $product->get_id();
        $images     = epo_fl_get_radio_images( $product_id );

        // Solo mostrar productos que tengan swatches.
        if ( empty( $images ) ) {
            continue;
        }

        $count     = count( $images );
        $permalink = get_permalink( $product_id );
        $title     = get_the_title( $product_id );

        echo '<div class="epo-fl__product">';

        // --- Header: nombre + count ---
        echo '<div class="epo-fl__header">';
        echo '<h3 class="epo-fl__title">';
        if ( $link ) {
            echo '<a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a>';
        } else {
            echo esc_html( $title );
        }
        echo '</h3>';
        echo '<span class="epo-fl__count">(' . $count . ' color' . ( $count !== 1 ? 's' : '' ) . ')</span>';
        echo '</div>';

        $main_image = array_shift( $images );

        if ( $show_img && has_post_thumbnail( $product_id ) ) {
            $featured_url = get_the_post_thumbnail_url( $product_id, 'medium' );
            if ( ! empty( $featured_url ) ) {
                $main_image = array(
                    'url'   => esc_url( $featured_url ),
                    'label' => '',
                );

                $images = array_values( array_filter( $images, function( $img ) use ( $featured_url ) {
                    return empty( $img['url'] ) || $img['url'] !== $featured_url;
                } ) );
            }
        }

        // --- Galería: imagen principal + variaciones ---
        echo '<div class="epo-fl__gallery">';

        if ( ! empty( $main_image['url'] ) ) {
            echo '<div class="epo-fl__main-swatch">';

            $main_link = $link ? epo_fl_get_swatch_link( $product, $main_image, $permalink ) : '';

            if ( $link ) {
                echo '<a href="' . esc_url( $main_link ) . '" class="epo-fl__swatch-link">';
            }

            echo '<div class="epo-fl__swatch-img-wrap epo-fl__swatch-img-wrap--main">';
            echo '<img src="' . esc_url( $main_image['url'] ) . '" ';
            echo 'alt="' . esc_attr( $main_image['label'] ? $main_image['label'] : $title ) . '" ';
            echo 'loading="lazy" decoding="async" class="epo-fl__swatch-img" />';
            echo '</div>';

            if ( ! empty( $main_image['label'] ) ) {
                echo '<span class="epo-fl__swatch-label">' . esc_html( $main_image['label'] ) . '</span>';
            }

            if ( $link ) {
                echo '</a>';
            }

            echo '</div>'; // .epo-fl__main-swatch
        }

        $main_image = array_shift( $images );

        // --- Galería: imagen principal + variaciones ---
        echo '<div class="epo-fl__gallery">';

        if ( ! empty( $main_image['url'] ) ) {
            echo '<div class="epo-fl__main-swatch">';

            $main_link = $link ? epo_fl_get_swatch_link( $product, $main_image, $permalink ) : '';

            if ( $link ) {
                echo '<a href="' . esc_url( $main_link ) . '" class="epo-fl__swatch-link">';
            }

            echo '<div class="epo-fl__swatch-img-wrap epo-fl__swatch-img-wrap--main">';
            echo '<img src="' . esc_url( $main_image['url'] ) . '" ';
            echo 'alt="' . esc_attr( $main_image['label'] ? $main_image['label'] : $title ) . '" ';
            echo 'loading="lazy" decoding="async" class="epo-fl__swatch-img" />';
            echo '</div>';

            if ( ! empty( $main_image['label'] ) ) {
                echo '<span class="epo-fl__swatch-label">' . esc_html( $main_image['label'] ) . '</span>';
            }

            if ( $link ) {
                echo '</a>';
            }

            echo '</div>'; // .epo-fl__main-swatch
        }

        echo '<div class="epo-fl__swatches">';

        foreach ( $images as $img ) {
            if ( empty( $img['url'] ) ) {
                continue;
            }

            echo '<div class="epo-fl__swatch">';

            $swatch_link = $link ? epo_fl_get_swatch_link( $product, $img, $permalink ) : '';

            if ( $link ) {
                echo '<a href="' . esc_url( $swatch_link ) . '" class="epo-fl__swatch-link">';
            }

            echo '<div class="epo-fl__swatch-img-wrap">';
            echo '<img src="' . esc_url( $img['url'] ) . '" ';
            echo 'alt="' . esc_attr( $img['label'] ? $img['label'] : $title ) . '" ';
            echo 'loading="lazy" decoding="async" class="epo-fl__swatch-img" />';
            echo '</div>';

            if ( ! empty( $img['label'] ) ) {
                echo '<span class="epo-fl__swatch-label">' . esc_html( $img['label'] ) . '</span>';
            }

            if ( $link ) {
                echo '</a>';
            }

            echo '</div>'; // .epo-fl__swatch
        }

        echo '</div>'; // .epo-fl__swatches
        echo '</div>'; // .epo-fl__gallery
        echo '</div>'; // .epo-fl__product
    }

    echo '</div>'; // .epo-fl

    wp_reset_postdata();

    return ob_get_clean();
}


/* ==========================================================================
   2. EXTRAER IMÁGENES EPO DE UN PRODUCTO
   ========================================================================== */

function epo_fl_get_radio_images( $product_id ) {
    $images = array();

    // ----- A) OPCIONES GLOBALES (tm_global_cp) -----
    // Cache de EPOs globales para no repetir la query en cada producto.
    static $global_epos_cache = null;

    if ( $global_epos_cache === null ) {
        $global_epos_cache = get_posts( array(
            'post_type'      => 'tm_global_cp',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ) );
    }

    foreach ( $global_epos_cache as $epo_id ) {
        // Verificar si este EPO global aplica al producto.
        if ( ! epo_fl_global_epo_applies( $epo_id, $product_id ) ) {
            continue;
        }

        // Estructura 1: _tm_epo_element_data (array serializado de elementos).
        $elements = get_post_meta( $epo_id, '_tm_epo_element_data', true );

        if ( is_array( $elements ) && ! empty( $elements ) ) {
            $images = array_merge( $images, epo_fl_extract_from_elements( $elements ) );
        }

        // Estructura 2: tm_meta → tmfbuilder (formato builder).
        $tm_meta = get_post_meta( $epo_id, 'tm_meta', true );
        if ( ! empty( $tm_meta ) && isset( $tm_meta['tmfbuilder'] ) ) {
            $images = array_merge( $images, epo_fl_extract_from_builder( $tm_meta['tmfbuilder'] ) );
        }
    }

    // ----- B) OPCIONES LOCALES DEL PRODUCTO -----
    foreach ( array( 'tm_meta_cpf', 'tm_meta' ) as $meta_key ) {
        $local_meta = get_post_meta( $product_id, $meta_key, true );
        if ( ! empty( $local_meta ) && is_array( $local_meta ) && isset( $local_meta['tmfbuilder'] ) ) {
            $images = array_merge( $images, epo_fl_extract_from_builder( $local_meta['tmfbuilder'] ) );
        }
    }

    // Deduplicar por URL.
    $unique = array();
    $seen   = array();
    foreach ( $images as $img ) {
        if ( ! empty( $img['url'] ) && ! isset( $seen[ $img['url'] ] ) ) {
            $seen[ $img['url'] ] = true;
            $unique[] = $img;
        }
    }

    return $unique;
}


/* ==========================================================================
   3. VERIFICAR SI UN EPO GLOBAL APLICA AL PRODUCTO
   ========================================================================== */

function epo_fl_global_epo_applies( $epo_id, $product_id ) {
    $apply_to = get_post_meta( $epo_id, '_tm_epo_apply_to', true );

    // Si está vacío o es "all", aplica a todos.
    if ( empty( $apply_to ) || $apply_to === '' || $apply_to === 'all' ) {
        return true;
    }

    // Aplica a productos específicos.
    if ( $apply_to === 'specific' ) {
        $ids_raw = get_post_meta( $epo_id, '_tm_epo_product_ids', true );
        if ( ! empty( $ids_raw ) ) {
            $ids = array_map( 'intval', explode( ',', $ids_raw ) );
            return in_array( $product_id, $ids, true );
        }
        return false;
    }

    // Aplica a categorías específicas.
    if ( $apply_to === 'category' ) {
        $cat_ids_raw = get_post_meta( $epo_id, '_tm_epo_category_ids', true );
        if ( ! empty( $cat_ids_raw ) ) {
            $epo_cats  = array_map( 'intval', explode( ',', $cat_ids_raw ) );
            $prod_cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
            return ! empty( array_intersect( $epo_cats, $prod_cats ) );
        }
        return false;
    }

    // Para cualquier otro caso, asumir que aplica.
    return true;
}


/* ==========================================================================
   4. EXTRAER IMÁGENES DESDE _tm_epo_element_data
   ========================================================================== */

function epo_fl_extract_from_elements( $elements ) {
    $images = array();

    foreach ( $elements as $element ) {
        if ( ! is_array( $element ) || ! isset( $element['type'] ) ) {
            continue;
        }

        $type = $element['type'];

        // Solo campos radio / radiobuttons.
        if ( ! in_array( $type, array( 'radio', 'radiobuttons', 'multiple_radiobuttons' ), true ) ) {
            continue;
        }

        // --- Estructura: options[] con image/imagep/imagesp ---
        if ( isset( $element['options'] ) && is_array( $element['options'] ) ) {
            foreach ( $element['options'] as $option ) {
                if ( ! is_array( $option ) ) {
                    continue;
                }

                $img_url = '';
                foreach ( array( 'image', 'imagep', 'imagesp', 'imagec' ) as $key ) {
                    if ( ! empty( $option[ $key ] ) ) {
                        $img_url = $option[ $key ];
                        break;
                    }
                }

                $label = '';
                foreach ( array( 'title', 'text', 'label', 'value' ) as $key ) {
                    if ( ! empty( $option[ $key ] ) ) {
                        $label = $option[ $key ];
                        break;
                    }
                }

                if ( ! empty( $img_url ) ) {
                    $images[] = epo_fl_normalize_image( $img_url, $label );
                }
            }
        }

        // --- Estructura: arrays paralelos (images[], titles[]) ---
        if ( isset( $element['images'] ) && is_array( $element['images'] ) ) {
            $titles = isset( $element['titles'] ) ? $element['titles'] : array();
            if ( empty( $titles ) && isset( $element['labels'] ) ) {
                $titles = $element['labels'];
            }
            // Intentar con 'text' también.
            if ( empty( $titles ) && isset( $element['text'] ) && is_array( $element['text'] ) ) {
                $titles = $element['text'];
            }

            foreach ( $element['images'] as $i => $img ) {
                if ( ! empty( $img ) ) {
                    $label    = isset( $titles[ $i ] ) ? $titles[ $i ] : '';
                    $images[] = epo_fl_normalize_image( $img, $label );
                }
            }
        }

        // --- Estructura: imagesp[] / imagesc[] ---
        foreach ( array( 'imagesp', 'imagesc' ) as $img_key ) {
            if ( isset( $element[ $img_key ] ) && is_array( $element[ $img_key ] ) ) {
                foreach ( $element[ $img_key ] as $i => $img ) {
                    if ( ! empty( $img ) ) {
                        $images[] = epo_fl_normalize_image( $img, '' );
                    }
                }
            }
        }
    }

    return $images;
}


/* ==========================================================================
   5. EXTRAER IMÁGENES DESDE tmfbuilder
   ========================================================================== */

/**
 * Estructura real de ThemeComplete EPO (verificada con debug):
 *
 *   element_type              => Array( [0] => 'radiobuttons' )
 *
 *   multiple_radiobuttons_options_image  => Array(
 *       [0] => Array( url1, url2, url3, ... )   ← elemento index 0
 *       [1] => Array( ... )                      ← elemento index 1 (si hay otro radio)
 *   )
 *   multiple_radiobuttons_options_imagep => Array(
 *       [0] => Array( url1, url2, url3, ... )
 *   )
 *   multiple_radiobuttons_options_title  => Array(
 *       [0] => Array( 'Aqueduct', 'Croissant', 'Earl Grey', ... )
 *   )
 *   multiple_radiobuttons_options_value  => Array(
 *       [0] => Array( 'Aqueduct', 'Croissant', 'Earl Grey', ... )
 *   )
 *
 * Las claves son SINGULARES (_image, no _images) y el primer índice del
 * array es el índice del elemento radio dentro del builder.
 */
function epo_fl_extract_from_builder( $builder ) {
    $images = array();

    if ( ! is_array( $builder ) ) {
        return $images;
    }

    // Determinar cuántos elementos hay y cuáles son radiobuttons.
    $element_types = isset( $builder['element_type'] ) ? $builder['element_type'] : array();

    // Claves donde ThemeComplete guarda las imágenes (en orden de prioridad).
    $image_keys = array(
        'multiple_radiobuttons_options_image',
        'multiple_radiobuttons_options_imagep',
        'multiple_radiobuttons_options_imagec',
        'multiple_radiobuttons_options_imagel',
    );

    // Claves donde ThemeComplete guarda los labels/títulos (en orden de prioridad).
    $title_keys = array(
        'multiple_radiobuttons_options_title',
        'multiple_radiobuttons_options_value',
    );

    // Iterar sobre cada índice de elemento.
    $max_index = 0;
    foreach ( $image_keys as $ik ) {
        if ( isset( $builder[ $ik ] ) && is_array( $builder[ $ik ] ) ) {
            $max_index = max( $max_index, count( $builder[ $ik ] ) );
        }
    }

    for ( $el_idx = 0; $el_idx < $max_index; $el_idx++ ) {

        // Verificar que este elemento sea de tipo radiobuttons (si tenemos esa info).
        if ( ! empty( $element_types ) && isset( $element_types[ $el_idx ] ) ) {
            $type = $element_types[ $el_idx ];
            if ( ! in_array( $type, array( 'radio', 'radiobuttons', 'multiple_radiobuttons' ), true ) ) {
                continue;
            }
        }

        // Buscar las imágenes en las claves posibles.
        $img_urls = array();
        foreach ( $image_keys as $ik ) {
            if ( isset( $builder[ $ik ][ $el_idx ] ) && is_array( $builder[ $ik ][ $el_idx ] ) ) {
                $candidate = array_filter( $builder[ $ik ][ $el_idx ] );
                if ( ! empty( $candidate ) ) {
                    $img_urls = $builder[ $ik ][ $el_idx ];
                    break;
                }
            }
        }

        if ( empty( $img_urls ) ) {
            continue;
        }

        // Buscar los títulos/labels.
        $titles = array();
        foreach ( $title_keys as $tk ) {
            if ( isset( $builder[ $tk ][ $el_idx ] ) && is_array( $builder[ $tk ][ $el_idx ] ) ) {
                $titles = $builder[ $tk ][ $el_idx ];
                break;
            }
        }

        // Construir el array de imágenes.
        foreach ( $img_urls as $i => $url ) {
            if ( empty( $url ) ) {
                continue;
            }
            $label    = isset( $titles[ $i ] ) ? $titles[ $i ] : '';
            $images[] = epo_fl_normalize_image( $url, $label );
        }
    }

    return $images;
}


/* ==========================================================================
   6. NORMALIZAR IMAGEN
   ========================================================================== */

function epo_fl_normalize_image( $img, $label = '' ) {
    $url = '';

    if ( is_array( $img ) ) {
        $url = isset( $img['url'] ) ? $img['url'] : ( isset( $img['src'] ) ? $img['src'] : '' );
    } elseif ( is_numeric( $img ) ) {
        $url = wp_get_attachment_image_url( intval( $img ), 'medium' );
    } else {
        $url = $img;
    }

    // Rutas relativas → absolutas.
    if ( ! empty( $url ) && strpos( $url, 'http' ) !== 0 && strpos( $url, '//' ) !== 0 ) {
        $upload_dir = wp_get_upload_dir();
        $url = $upload_dir['baseurl'] . '/' . ltrim( $url, '/' );
    }

    return array(
        'url'   => esc_url( $url ),
        'label' => sanitize_text_field( $label ),
    );
}

/**
 * Intenta enlazar el swatch a una variación de WooCommerce por label.
 * Si no hay match, vuelve al permalink del producto.
 */
function epo_fl_get_swatch_link( $product, $img, $default_url ) {
    if ( ! $product instanceof WC_Product ) {
        return $default_url;
    }

    if ( ! $product->is_type( 'variable' ) || empty( $img['label'] ) ) {
        return $default_url;
    }

    static $cache = array();

    $product_id = $product->get_id();
    $label      = sanitize_title( wp_strip_all_tags( $img['label'] ) );

    if ( isset( $cache[ $product_id ][ $label ] ) ) {
        return $cache[ $product_id ][ $label ];
    }

    $variations = $product->get_available_variations();

    foreach ( $variations as $variation ) {
        if ( empty( $variation['attributes'] ) || ! is_array( $variation['attributes'] ) ) {
            continue;
        }

        foreach ( $variation['attributes'] as $attribute_key => $attribute_value ) {
            if ( '' === $attribute_value ) {
                continue;
            }

            $candidates   = array( sanitize_title( $attribute_value ) );
            $taxonomy_key = str_replace( 'attribute_', '', $attribute_key );

            if ( taxonomy_exists( $taxonomy_key ) ) {
                $term = get_term_by( 'slug', $attribute_value, $taxonomy_key );
                if ( $term && ! is_wp_error( $term ) ) {
                    $candidates[] = sanitize_title( $term->name );
                }
            }

            if ( in_array( $label, $candidates, true ) ) {
                $query = array_filter( $variation['attributes'] );
                $url   = add_query_arg( $query, $default_url );

                $cache[ $product_id ][ $label ] = $url;
                return $url;
            }
        }
    }

    $cache[ $product_id ][ $label ] = $default_url;
    return $default_url;
}


/* ==========================================================================
   7. CSS
   ========================================================================== */

function epo_fabric_listing_enqueue( $atts ) {
    static $enqueued = false;
    if ( $enqueued ) {
        return;
    }
    $enqueued = true;

    $css = '
    /* ===================================================================
       EPO Fabric Listing — Editorial Catalogue Style
       =================================================================== */

    .epo-fl {
        display: grid;
        grid-template-columns: repeat(var(--epo-fl-columns, 3), 1fr);
        gap: 48px 36px;
        padding: 20px 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
    }

    /* --- Producto --- */
    .epo-fl__product {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    /* --- Header: título + count --- */
    .epo-fl__header {
        display: flex;
        align-items: baseline;
        gap: 8px;
        flex-wrap: wrap;
        border-bottom: 1px solid #e0e0e0;
        padding-bottom: 10px;
    }

    .epo-fl__title {
        font-size: 18px;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        margin: 0;
        line-height: 1.3;
        color: #1a1a1a;
    }

    .epo-fl__title a {
        color: inherit;
        text-decoration: none;
        transition: color 0.25s ease;
    }

    .epo-fl__title a:hover {
        color: #555;
    }

    .epo-fl__count {
        font-size: 13px;
        font-weight: 400;
        color: #888;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }

    /* --- Grid de swatches --- */
    .epo-fl__gallery {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 12px;
        align-items: start;
    }

    .epo-fl__main-swatch {
        grid-column: span 1;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    /* --- Grid de swatches --- */
    .epo-fl__gallery {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 12px;
        align-items: start;
    }

    .epo-fl__main-swatch {
        grid-column: span 1;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .epo-fl__swatches {
        grid-column: span 4;
        display: flex;
        flex-wrap: wrap;
        gap: 16px 14px;
    }

    .epo-fl__swatch {
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 0;
    }

    .epo-fl__swatch-link {
        text-decoration: none;
        color: inherit;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .epo-fl__swatch-img-wrap {
        width: 100%;
        aspect-ratio: 1 / 1;
        overflow: hidden;
        border-radius: 2px;
        background: #f7f7f7;
        position: relative;
    }

    .epo-fl__swatch-img-wrap--main {
        width: 100%;
    }

    .epo-fl__swatch-img-wrap::after {
        content: "";
        position: absolute;
        inset: 0;
        border: 1px solid rgba(0,0,0,0.06);
        border-radius: 2px;
        pointer-events: none;
    }

    .epo-fl__swatch-img {
        width: 100%;
        height: 100% !important;
        object-fit: cover !important;
        display: block;
        transition: transform 0.35s ease, opacity 0.3s ease;
    }

    .epo-fl__swatch-link:hover .epo-fl__swatch-img,
    .epo-fl__swatch:hover .epo-fl__swatch-img {
        transform: scale(1.06);
    }

    .epo-fl__swatch-label {
        font-size: 13px;
        font-weight: 400;
        color: #444;
        line-height: 1.3;
        letter-spacing: 0.01em;
        display: block;
    }

    .epo-fl__swatch-link:hover .epo-fl__swatch-label {
        color: #111;
    }

    /* --- Empty --- */
    .epo-fl-empty {
        grid-column: 1 / -1;
        text-align: center;
        color: #888;
        font-size: 15px;
        padding: 40px 0;
    }

    /* --- Responsive --- */
    @media (max-width: 1024px) {
        .epo-fl {
            grid-template-columns: repeat(2, 1fr);
            gap: 36px 28px;
        }

        .epo-fl__gallery {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .epo-fl__main-swatch,
        .epo-fl__swatches {
            grid-column: span 2;
        }
    }

    @media (max-width: 640px) {
        .epo-fl {
            grid-template-columns: 1fr;
            gap: 32px 0;
        }

        .epo-fl__swatches {
            gap: 12px 10px;
        }

        .epo-fl__swatch {
            width: calc((100% - 10px) / 2);
        }
    }
    ';

    wp_register_style( 'epo-fabric-listing-css', false );
    wp_enqueue_style( 'epo-fabric-listing-css' );
    wp_add_inline_style( 'epo-fabric-listing-css', $css );
}


/* ==========================================================================
   8. HOOK EN ARCHIVE (opcional, además del shortcode)
   ========================================================================== */

/**
 * Si además del shortcode quieres que se muestren los swatches en el loop
 * normal del archive de WooCommerce (shop, categorías), descomenta la
 * siguiente línea:
 */
// add_action( 'woocommerce_after_shop_loop_item_title', 'epo_fl_archive_hook', 5 );

function epo_fl_archive_hook() {
    global $product;
    if ( ! $product ) {
        return;
    }

    $product_id = $product->get_id();
    $images     = epo_fl_get_radio_images( $product_id );

    if ( empty( $images ) ) {
        return;
    }

    $max = apply_filters( 'epo_fl_archive_max_swatches', 5 );

    echo '<div class="epo-fl__swatches epo-fl__swatches--inline" style="margin:8px 0;gap:6px;">';

    foreach ( array_slice( $images, 0, $max ) as $img ) {
        if ( empty( $img['url'] ) ) {
            continue;
        }
        echo '<div class="epo-fl__swatch" style="width:36px;">';
        echo '<div class="epo-fl__swatch-img-wrap" style="width:36px;height:36px;">';
        echo '<img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $img['label'] ) . '" loading="lazy" class="epo-fl__swatch-img" />';
        echo '</div></div>';
    }

    if ( count( $images ) > $max ) {
        echo '<span style="font-size:12px;color:#888;align-self:center;">+' . ( count( $images ) - $max ) . '</span>';
    }

    echo '</div>';
}


/* ==========================================================================
   9. DEBUG HELPER
   ========================================================================== */

/**
 * Shortcode de debug: [epo_debug product_id="123"]
 * Muestra toda la estructura de datos EPO de un producto. Solo para admins.
 */
add_shortcode( 'epo_debug', 'epo_fl_debug_shortcode' );

function epo_fl_debug_shortcode( $atts ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return '<p>Solo disponible para administradores.</p>';
    }

    $atts = shortcode_atts( array( 'product_id' => 0 ), $atts );
    $pid  = intval( $atts['product_id'] );

    if ( ! $pid ) {
        return '<p>Especifica product_id. Ej: [epo_debug product_id="123"]</p>';
    }

    ob_start();
    echo '<div style="font-family:monospace;font-size:12px;background:#f9f9f9;padding:16px;border:1px solid #ddd;border-radius:4px;max-height:600px;overflow:auto;">';

    echo '<h4 style="margin:0 0 12px;">EPO Debug — Producto #' . $pid . '</h4>';

    // Globals.
    $globals = get_posts( array(
        'post_type'      => 'tm_global_cp',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ) );

    echo '<strong>Global EPOs (' . count( $globals ) . '):</strong><br>';

    foreach ( $globals as $epo_id ) {
        $applies  = epo_fl_global_epo_applies( $epo_id, $pid ) ? '✅ APLICA' : '❌ No aplica';
        $elements = get_post_meta( $epo_id, '_tm_epo_element_data', true );
        $tm_meta  = get_post_meta( $epo_id, 'tm_meta', true );

        echo '<details style="margin:8px 0;"><summary>EPO #' . $epo_id . ' — ' . $applies . '</summary>';
        echo '<pre style="white-space:pre-wrap;word-break:break-all;max-height:300px;overflow:auto;">';
        echo htmlspecialchars( print_r( $elements, true ) );
        echo "\n---\n";
        echo htmlspecialchars( print_r( $tm_meta, true ) );
        echo '</pre></details>';
    }

    // Locales.
    echo '<br><strong>Local Meta:</strong>';
    foreach ( array( 'tm_meta_cpf', 'tm_meta' ) as $key ) {
        $val = get_post_meta( $pid, $key, true );
        echo '<details style="margin:8px 0;"><summary>' . $key . '</summary>';
        echo '<pre style="white-space:pre-wrap;word-break:break-all;max-height:300px;overflow:auto;">';
        echo htmlspecialchars( print_r( $val, true ) );
        echo '</pre></details>';
    }

    // Resultado.
    $final = epo_fl_get_radio_images( $pid );
    echo '<br><strong>Imágenes extraídas (' . count( $final ) . '):</strong>';
    echo '<pre style="white-space:pre-wrap;">' . htmlspecialchars( print_r( $final, true ) ) . '</pre>';

    echo '</div>';
    return ob_get_clean();
}
