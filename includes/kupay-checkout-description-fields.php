<?php

add_filter( 'woocommerce_gateway_description', 'kupay_description_fields', 20, 2 );

function kupay_description_fields( $description, $payment_id ) {

    if ( 'kupay' !== $payment_id ) {
        return $description;
    }

    $description = '<div style="display:block; width:300px; height:auto;">';
    $description .= '<img src="' . plugins_url('../assets/icon.png', __FILE__ ) . '">';
    $description .= '</div>';

    return $description;
}
