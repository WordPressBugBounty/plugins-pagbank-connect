<?php
/**
 * PagBank Subscription was Canceled (E-mail de cancelamento de assinatura) 
 *
 * Este e-mail pode ser sobrescrito pelo seu tema. Copie este arquivo para
 * seutema/woocommerce/emails/canceled-subscription.php.
 *
 * 
 * NO ENTANTO, ocasionalmente você precisará atualizar os arquivos de modelo e você
 * (o desenvolvedor do tema) precisará copiar os novos arquivos para o seu tema para
 * manter a compatibilidade. Tentamos fazer isso o menos possível, mas pode
 * acontecer. Quando isso ocorrer, a versão do arquivo de modelo será alterada e
 * as notas de versão listará todas as alterações importantes. Considere comparar o
 *
 * @version abaixo com a versão copiada para seu tema. Mudanças na versão do modelo
 * que impactem variáveis somente mudarão o primeiro e segundo número da versão. Se
 * um dos dois primeiros números for diferente no seu tema, é possível que haja
 * incompatibilidade e você precisará tomar alguma providência.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @version 4.0.0
 */

/** @var stdClass $subscription */
/** @var string $email_heading */
/** @var string $account_link */
/** @var SuspendedSubscription $email */
/** @var WC_Order $order */

use RM_PagBank\Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: Customer first name */ ?>
    <p><?php printf(esc_html__('Olá %s,', 'pagbank-connect'), esc_html($order->get_billing_first_name())); ?></p>
    <p><?php echo sprintf(
            esc_html(
                'Sua assinatura #%d foi suspensa.',
                'pagbank-connect'
            ),
            $subscription->id
        ); ?></p>

    <?php if($subscription->suspended_reason):?>
    <p>
        <?php
        echo sprintf(
            esc_html(
                'Razão: %s.',
                'pagbank-connect'
            ),
            $subscription->suspended_reason
        ); ?></p>
    <?php endif;?>

    <p><?php echo sprintf(
            esc_html(
                'Uma nova tentativa de cobrança será feita no dia %s.',
                'pagbank-connect'
            ),
            mysql2date('d/m/Y', $subscription->next_bill_at));
    ?></p>
    <p>
        <?php echo __('Recomendamos que atualize o cartão utilizado no pagamento da assinatura.','pagbank-connect'); ?>
    <br>
        <a href="<?php echo esc_url( $account_link ); ?>" class="button button-primary" target="_blank">
            <?php esc_html_e( 'Atualizar cartão', 'pagbank-connect' ); ?>
        </a>
    </p>
<?php

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
