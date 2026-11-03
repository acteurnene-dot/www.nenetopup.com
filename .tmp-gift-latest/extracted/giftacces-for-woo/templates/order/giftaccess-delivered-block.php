<?php
/**
 * Delivered Gift Access products block on customer order page.
 *
 * @var array $rows
 * @var array $pending_rows
 * @var int   $order_id
 * @var int   $pending_refresh_secs
 * @var int   $pending_refresh_max
 * @var string $customer_brand_name
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ( empty( $rows ) || ! is_array( $rows ) ) && ( empty( $pending_rows ) || ! is_array( $pending_rows ) ) ) {
	return;
}
?>
<?php if ( ! empty( $pending_rows ) ) : ?>
<section
	class="giftaccess-woo-pending-block"
	data-giftaccess-woo-pending="1"
	data-order-id="<?php echo esc_attr( (string) $order_id ); ?>"
	data-auto-refresh-seconds="<?php echo esc_attr( (string) $pending_refresh_secs ); ?>"
	data-max-refresh-attempts="<?php echo esc_attr( (string) $pending_refresh_max ); ?>"
>
	<h3 class="giftaccess-woo-pending-title"><?php echo esc_html__( 'Livraison en cours', 'giftaccess-for-woo' ); ?></h3>
	<p class="giftaccess-woo-pending-lead">
		<strong><?php echo esc_html__( 'Traitement en cours, patientez', 'giftaccess-for-woo' ); ?></strong><span class="giftaccess-woo-pending-dots" data-giftaccess-woo-dots="1">...</span>
	</p>
	<p class="giftaccess-woo-pending-text">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: site title. */
				__( 'Votre code sera affiché ici automatiquement dès que %s finalise la livraison.', 'giftaccess-for-woo' ),
				$customer_brand_name
			)
		);
		?>
	</p>
	<div class="giftaccess-woo-pending-list">
		<?php foreach ( $pending_rows as $row ) : ?>
			<article class="giftaccess-woo-pending-item">
				<h4 class="giftaccess-woo-pending-item-name"><?php echo esc_html( $row['product_name'] ); ?></h4>
				<p class="giftaccess-woo-pending-item-meta">
					<strong><?php echo esc_html__( 'Montant demandé', 'giftaccess-for-woo' ); ?>:</strong> <?php echo esc_html( $row['amount'] ); ?>
				</p>
				<p class="giftaccess-woo-pending-item-message"><?php echo esc_html( $row['message'] ); ?></p>
			</article>
		<?php endforeach; ?>
	</div>
	<p class="giftaccess-woo-pending-refresh" data-giftaccess-woo-countdown="1"></p>
</section>
<?php endif; ?>

<?php if ( ! empty( $rows ) ) : ?>
<section class="giftaccess-woo-delivered-block">
	<h3 class="giftaccess-woo-delivered-title"><?php echo esc_html__( 'Produits livrés', 'giftaccess-for-woo' ); ?></h3>

	<div class="giftaccess-woo-delivered-list">
		<?php foreach ( $rows as $row ) : ?>
			<article class="giftaccess-woo-delivered-item">
				<div class="giftaccess-woo-delivered-item-main">
					<h4 class="giftaccess-woo-delivered-item-name"><?php echo esc_html( $row['product_name'] ); ?></h4>
					<p class="giftaccess-woo-delivered-item-meta">
						<strong><?php echo esc_html__( 'Montant demandé', 'giftaccess-for-woo' ); ?>:</strong> <?php echo esc_html( $row['amount'] ); ?>
					</p>
					<p class="giftaccess-woo-delivered-code">
						<span class="giftaccess-woo-delivered-code-label"><strong><?php echo esc_html__( 'Code', 'giftaccess-for-woo' ); ?>:</strong></span>
						<code><?php echo esc_html( $row['display_code'] ); ?></code>
					</p>
					<div class="giftaccess-woo-delivered-actions">
						<button type="button" class="giftaccess-woo-btn giftaccess-woo-copy-btn" data-copy="<?php echo esc_attr( $row['display_code'] ); ?>">
							<?php echo esc_html__( 'COPIER', 'giftaccess-for-woo' ); ?>
						</button>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>
