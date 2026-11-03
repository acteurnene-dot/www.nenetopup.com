<?php
/**
 * Gift Access item details template.
 *
 * @var array $data
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $data ) || ! is_array( $data ) ) {
	return;
}
?>
<div class="giftaccess-woo-customer-item-meta">
	<strong><?php echo esc_html__( 'Livraison digitale', 'giftaccess-for-woo' ); ?>:</strong>
	<?php echo esc_html( ucfirst( $data['status'] ) ); ?>
	<?php if ( ! empty( $data['reference'] ) ) : ?>
		| <?php echo esc_html__( 'Réf', 'giftaccess-for-woo' ); ?>: <?php echo esc_html( $data['reference'] ); ?>
	<?php endif; ?>
	<?php if ( 'failed' === strtolower( (string) $data['status'] ) && ! empty( $data['error'] ) ) : ?>
		<br><strong><?php echo esc_html__( 'Erreur', 'giftaccess-for-woo' ); ?>:</strong> <?php echo esc_html( $data['error'] ); ?>
	<?php endif; ?>
</div>
