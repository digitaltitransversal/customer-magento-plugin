define(
    [
        'ko',
        'femsaCheckout',
        'Magento_Payment/js/view/payment/cc-form',
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/checkout-data',
        'mage/storage',
        'uiRegistry',
        'domReady!',
        'Magento_Checkout/js/model/shipping-save-processor',
        'Magento_Checkout/js/action/set-billing-address',
        'Magento_Checkout/js/model/cart/totals-processor/default',
        'Magento_Checkout/js/model/cart/cache'
    ],
    function (ko, femsaCheckout, Component, $, quote, customer, validator, storage, uiRegistry, domRe, shSP, sBA, totalsProcessor, cartCache) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'DigitalFemsa_Payments/payment/base-form',
                transactionResult: '',
                renderProperties: {
                    shippingMethodCode: '',
                    quoteBaseGrandTotal: '',
                    shippingAddress: '',
                    billingAddress: '',
                    guestEmail: '',
                    isLoggedIn: '',
                }
            },

            getFormTemplate: function () {
                return 'DigitalFemsa_Payments/payment/embedform/form'
            },

            initObservable: function () {

                this._super()
                    .observe([
                        'checkoutId',
                        'isIframeLoaded',
                        'isVisiblePaymentButton',
                        'iframOrderData',
                        'femsaError',
                        'isFormLoading'
                    ]);
                this.iframOrderData('');
                this.checkoutId('');
                this.femsaError(null);
                this.isFormLoading(false);

                var baseGrandTotal = quote.totals._latestValue.base_grand_total;

                var shippingAddress = '';
                if (quote.shippingAddress())
                    shippingAddress = JSON.stringify(quote.shippingAddress());

                var billingAddress = '';
                if (quote.billingAddress())
                    billingAddress = JSON.stringify(quote.billingAddress());

                var shippingMethodCode = '';
                if (quote.shippingMethod._latestValue) {
                    shippingMethodCode = quote.shippingMethod._latestValue.method_code;
                }

                this.renderProperties.quoteBaseGrandTotal = baseGrandTotal;
                this.renderProperties.shippingMethod = shippingMethodCode;
                this.renderProperties.shippingAddress = shippingAddress;
                this.renderProperties.billingAddress = billingAddress;
                this.renderProperties.guestEmail = quote.guestEmail;
                this.renderProperties.isLoggedIn = customer.isLoggedIn();

                //Suscriptions to re-render
                quote.totals.subscribe(this.reRender, this);
                quote.billingAddress.subscribe(this.billingAddressChanges, this);
                customer.isLoggedIn.subscribe(this.reRender, this);
                uiRegistry
                    .get('checkout.steps.billing-step.payment.customer-email')
                    .email
                    .subscribe(this.reRender, this);

                return this;
            },

            initialize: function () {
                var self = this;
                this._super();
                if (customer.isLoggedIn() &&
                    quote.isVirtual() &&
                    quote.billingAddress()
                ) {
                    $.when(sBA()).then(this.initializeForm());
                } else {
                    this.initializeForm();
                }

            },

            initializeForm: function () {

                //if doesn't rendered yet, then tries to render
                if (!this.reRender()) {

                    this.isFormLoading(true);
                    this.loadCheckoutId();
                }
            },

            billingAddressChanges: function () {
                var self = this;

                //if no billing info, then form is editing
                if (!quote.billingAddress()) {
                    self.reRender();

                } else if (!quote.isVirtual()) {
                    self.isFormLoading(false);
                    try {
                        shSP.saveShippingInformation()
                            .done(function () {
                                self.reRender();
                            });
                    } catch (error) {
                        console.log(error)
                        self.reRender();
                    }
                }

            },

            reRender: function (total) {

                if (this.isFormLoading())
                    return;

                this.isFormLoading(true);

                var hasToReRender = false;

                if (quote.shippingMethod._latestValue && !this.isEmpty(quote.shippingMethod._latestValue)
                    && quote.shippingMethod._latestValue.method_code !== undefined
                    && quote.shippingMethod._latestValue.method_code !== this.renderProperties.shippingMethod) {
                    //check for shipping methods changes
                    this.renderProperties.shippingMethod = quote.shippingMethod._latestValue.method_code;
                    hasToReRender = true;
                }

                //check for total changes
                if (quote.totals._latestValue.base_grand_total !== this.renderProperties.quoteBaseGrandTotal) {
                    this.renderProperties.quoteBaseGrandTotal = quote.totals._latestValue.base_grand_total;
                    hasToReRender = true;
                }

                //check for shipping changes
                if (quote.shippingAddress()) {
                    const shippingAddress = JSON.stringify(quote.shippingAddress());
                    if (shippingAddress !== this.renderProperties.shippingAddress) {
                        this.renderProperties.shippingAddress = shippingAddress;
                        hasToReRender = true;
                    }
                }


                //check for billing changes
                if(quote.billingAddress()) {
                    const quoteBilling = JSON.stringify(quote.billingAddress());
                    if (quoteBilling !== this.renderProperties.billingAddress) {
                        this.renderProperties.billingAddress = quoteBilling;
                        hasToReRender = true;
                    }
                }


                if (!customer.isLoggedIn() && quote.isVirtual()) {
                    let currentGuestEmail = quote.guestEmail;

                    //If is virtual, guest mail gets from uiregistry
                    currentGuestEmail = uiRegistry.get('checkout.steps.billing-step.payment.customer-email').email();

                    //check for guest email changes on virtual cart
                    if (currentGuestEmail !== this.renderProperties.guestEmail) {
                        this.renderProperties.guestEmail = currentGuestEmail;
                        hasToReRender = true;
                    }
                }

                //Check if customer is logged in changes
                if (customer.isLoggedIn() !== this.renderProperties.isLoggedIn) {
                    this.renderProperties.isLoggedIn = customer.isLoggedIn();
                    hasToReRender = true;
                }

                if (hasToReRender) {
                    this.loadCheckoutId()

                } else {
                    this.isFormLoading(false);
                }

                return hasToReRender;
            },

            validateRenderEmbedForm: function () {
                var isValid = true;

                if (!this.renderProperties.billingAddress) {
                    this.femsaError('Información de Facturación: Complete todos los campos requeridos para continuar');
                    return false;
                }

                if (!customer.isLoggedIn() &&
                    quote.isVirtual() &&
                    (!quote.guestEmail || (
                            this.renderProperties.guestEmail &&
                            this.renderProperties.guestEmail !== quote.guestEmail
                        )
                    )
                ) {
                    this.femsaError('Ingrese un email válido para continuar');
                    return false;
                }

                if (!customer.isLoggedIn() &&
                    !quote.isVirtual() &&
                    !quote.guestEmail
                ) {
                    this.femsaError('Ingrese un email válido para continuar');
                    return false;
                }

                return true;
            },

            loadCheckoutId: function () {
                var self = this;
                var guest_email = '';
                if (this.isLoggedIn() === false) {
                    guest_email = quote.guestEmail;
                }
                var params = {
                    'guestEmail': guest_email
                };

                if (this.validateRenderEmbedForm()) {
                    console.log('before render iframe')
                    this.validateCheckoutSession()
                    $.ajax({
                        type: 'POST',
                        url: self.getcreateOrderUrl(),
                        data: params,
                        async: true,
                        showLoader: true,
                        success: function (response) {
                            self.femsaError(null);
                            self.checkoutId(response.checkout_id);

                            if (self.checkoutId()) {
                                self.renderizeEmbedForm();
                            } else {
                                self.isFormLoading(false);
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error(status);
                            self.femsaError(xhr.responseJSON.error_message);
                            self.isFormLoading(false);
                        }
                    })
                } else {
                    this.isFormLoading(false);
                }

            },

            renderizeEmbedForm: function () {
                var self = this;
                document.getElementById("femsaIframeContainer").innerHTML = "";
                window.DigitalFemsaCheckoutComponents.Integration({
                    targetIFrame: '#femsaIframeContainer',
                    checkoutRequestId: this.checkoutId(),
                    publicKey: this.getPublicKey(),
                    paymentMethods: this.getPaymenMethods(),
                    options: {
                        theme: 'default'
                    },
                    onCreateTokenSucceeded: function (token) {

                    },
                    onCreateTokenError: function (error) {
                        console.error(error);
                    },
                    onFinalizePayment: function (event) {
                        self.iframOrderData(event);
                        self.beforePlaceOrder();
                    },
                    onErrorPayment: function(a) {
                        self.femsaError("Ocurrió un error al procesar el pago. Por favor, inténtalo de nuevo.");
                    },
                });

                $('#femsaIframeContainer').find('iframe').attr('data-cy', 'the-frame');
                self.isFormLoading(false);
            },

            getData: function () {
                if (this.iframOrderData() !== '') {
                    var params = this.iframOrderData();
                    var data = {
                        'method': this.getCode(),
                        'additional_data': {
                            'payment_method': params.charge.payment_method.type,
                            'reference': params.reference,
                            'order_id': params.charge.order_id,
                            'txn_id': params.charge.id,
                            'iframe_payment': true
                        }
                    };
                    return data;
                }
                var data = {
                    'method': this.getCode(),
                    'additional_data': {
                        'payment_method': '',
                        'reference': '',
                        'iframe_payment': false,
                        'order_id': '',
                        'txn_id': ''
                    }
                };
                return data;
            },

            beforePlaceOrder: function () {
                var self = this;
                if (this.iframOrderData() !== '') {
                    self.placeOrder();
                    return;
                }
            },

            validate: function () {
                if (this.iframOrderData() !== '') {
                    return true;
                }

                var $form = $('#' + this.getCode() + '-form');
                return $form.validation() && $form.validation('isValid');
            },

            getCode: function () {
                return 'digitalfemsa_ef';
            },

            isActive: function () {
                return true;
            },

            getGlobalConfig: function () {
                return window.checkoutConfig.payment.digitalfemsa_global
            },

            getMethodConfig: function () {
                return window.checkoutConfig.payment.digitalfemsa_ef
            },

            getPublicKey: function () {
                return this.getGlobalConfig().publicKey;
            },

            getPaymenMethods: function () {
                return this.getMethodConfig().paymentMethods;
            },

            getDigitalFemsaLogo: function () {
                return this.getGlobalConfig().digitalfemsa_logo;
            },

            getcreateOrderUrl: function () {
                return this.getMethodConfig().createOrderUrl;
            },

            isLoggedIn: function () {
                return customer.isLoggedIn();
            },

            validateCheckoutSession: function () {
                const lifeTime = parseInt(this.getMethodConfig().sessionExpirationTime)
                const timeToExpire = (lifeTime - 5) * 1000
                setTimeout(()=> {
                    document.getElementById("femsaIframeContainer").innerHTML = `<div style="width: 100%; text-align: center;"><p>La sesión a finalizado por
                    favor actualice la pagina</p> <button onclick="window.location.reload()" class="button action continue primary">Actualizar</button></body></div>`;
                }, timeToExpire)
            },

            isEmpty: function (obj) {
                return obj === undefined || obj === null || obj === ''
            }
        });
    }
);
