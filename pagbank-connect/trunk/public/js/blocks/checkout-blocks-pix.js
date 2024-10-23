import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { sprintf, __ } from '@wordpress/i18n';

import PaymentInstructions from './components/PaymentInstructions';
import PaymentUnavailable from './components/PaymentUnavailable';
import CustomerDocumentField from './components/CustomerDocumentField';

const settings = getSetting('rm-pagbank-pix_data', {});
const label = decodeEntities( settings.title ) || window.wp.i18n.__( 'PagBank Connect Pix', 'rm-pagbank' );

/**
 * Icon component
 * @returns {JSX.Element|string}
 * @constructor
 */
const Icon = () => {
    return (
        <div dangerouslySetInnerHTML={{ __html: settings.icon }}  style={{ marginLeft: '12px', lineHeight: '0.5rem' }} />
    )
}

/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = ( props ) => {
    const { PaymentMethodLabel } = props.components;
    return (
        <>
            <PaymentMethodLabel text={ label } />
            <Icon />
        </>
    );
};

/**
 * Content component
 */
const Content = ( props ) => {
    const { eventRegistration, emitResponse } = props;
    const { onPaymentSetup, onCheckoutSuccess, onCheckoutFail } = eventRegistration;

    let instructions = settings.instructions;
    let expiry = settings.expirationTime;
    let expiryText = sprintf( __( 'Você terá %s para pagar com seu código PIX.', 'rm-pagbank' ), expiry );
    let discountText = settings.hasDiscount ? settings.discountText : '';

    instructions = `${instructions} <br> ${expiryText} <br> ${discountText}`;

    if (settings.paymentUnavailable) {
        useEffect( () => {
            const unsubscribe = onPaymentSetup(() => {
                console.error('PagBank indisponível para pedidos inferiores a R$1,00.');
                return {
                    type: emitResponse.responseTypes.ERROR,
                    messageContext: emitResponse.noticeContexts.PAYMENTS,
                    message: __('PagBank indisponível para pedidos inferiores a R$1,00.', 'rm-pagbank'),
                };
            });

            return () => {
                unsubscribe();
            };
        }, [onPaymentSetup] );

        return (
            <div className="rm-pagbank-pix">
                <PaymentUnavailable />
            </div>
        );
    }

    useEffect( () => {
        const unsubscribe = onPaymentSetup(() => {
            const customerDocumentValue = document.getElementById('rm-pagbank-customer-document').value;
            return {
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: {
                        'rm-pagbank-customer-document': customerDocumentValue.replace(/\D/g, ''),
                    },
                },
            };
        } );

        return () => {
            unsubscribe();
        };
    }, [onPaymentSetup] );

    return (
        <div className="rm-pagbank-pix">
            <PaymentInstructions
                checkoutClass={'pix'}
                instructions={instructions}
            />
            <CustomerDocumentField />
            <input type="hidden" name="ps_connect_method" value="pix"/>
        </div>
    );
};

const Rm_Pagbank_Pix_Block_Gateway = {
    name: 'rm-pagbank-pix',
    label: <Label />,
    content: <Content />,
    edit: <Content />,
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings?.supports ?? [],
    },
};

registerPaymentMethod( Rm_Pagbank_Pix_Block_Gateway );