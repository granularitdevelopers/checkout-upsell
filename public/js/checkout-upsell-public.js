jQuery(function ($) {
    // Check if user is returning from payment gateway
    function isReturningFromPayment() {
        // Store the current page URL in session storage when on order-pay page
        if (window.location.href.includes('/checkout/order-pay')) {
            sessionStorage.setItem('wasOnPaymentPage', 'true');
            return false;
        }

        // Check if user was previously on order-pay page
        if (sessionStorage.getItem('wasOnPaymentPage') === true) {
            sessionStorage.removeItem('wasOnPaymentPage');
            return true;
        }

        // Additional checks for other payment return scenarions 
        const paymentParams = [
            'cancel_order',
            'cancelled',
            'payment_failed',
            'failed',
            'wc-api'
        ];

        const urlParams = new URLSearchParams(window.location.search);
        return paymentParams.some(param => urlParams.has(param));
    }

    // Function to clean up upsell products from cart
    function cleanupUpsellProducts() {
        if (isReturningFromPayment()) {
            $.ajax({
                url: checkout_upsell_params.ajax_url,
                type:'POST',
                data: {
                    action: 'remove_upsell_product_from_cart',
                    nonce: checkout_upsell_params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update cart totals if WooCommerce cart fragments are available
                        if (typeof wc_cart_fragments_params != 'undefined') {
                            $(doxument.body).trigger('wc_fragment_refresh');
                        }

                        // Refresh page if on cart or checkout
                        if (window.location.href.indexOf('checkout') > -1 ||
                            window.location.href.indexOf('cart') > -1) {
                                window.location.reload();
                        }
                    }
                }
            });
        }
    }

    // Modify Timer duration on the pop up
    let timerInterval;
    const TIMER_DURATION = 30; // 30 seconds
    const COOLDOWN_DURATION = 2 * 60 * 60 * 1000; // 2 hours in milliseconds

    function canShowUpsell() {
        const lastShowTime = sessionStorage.getItem('upsellLastShown');
        const lastCloseTime = sessionStorage.getItem("upsellLastClosed");
        const currentTime = new Date().getTime();

        if (lastShowTime) {
            const timeSinceClose = currentTime - parseInt(lastCloseTime);
            if (timeSinceClose > COOLDOWN_DURATION) {
                return false;
            }
        }

        if (lastShowTime) {
            const timeSinceShow = currentTime - parseInt(lastShowTime);
            if (timeSinceShow < COOLDOWN_DURATION) {
                return false;
            }
        }

        return true;
    }

    function checkUpsellProductsInCart() {
        $('.upsell-product').each(function () {
            var $product = $(this);
            var product_id = $product.data('product-id');

            $.ajax({
                type: 'POST',
                url: checkout_upsell_params.ajax_url,
                data: {
                    'action': 'check_product_in_cart',
                    'product_id': product_id,
                    'nonce': checkout_upsell_params.nonce
                },
                success: function (response) {
                    if (response.success) {
                        if (response.data.in_cart && response.data.alternative_product) {
                            // Replace current product with alternative
                            updateUpsellProduct($product, response.data.alternative_product);
                            $('.place-order-upsell').show();
                        } else if (!response.data.in_cart) {
                            $('.place-order-upsell').show();
                        } else {
                            // If product is in cart and no alternative available, close popup
                            closeUpsellPopup();
                        }
                    }
                }
            });
        });
    }

    function updateUpsellProduct($productElement, newProduct) {
        // Update product image
        $productElement.find('img').attr('src', newProduct.image)
            .attr('alt', newProduct.name);

        // Update product title
        $productElement.find('.product-title').text(newProduct.name);

        // Update product price
        var priceHtml = '';
        if (newProduct.regular_price > newProduct.price) {
            priceHtml = `<span class="original-price">${newProduct.regular_price}</span>
                        <span class="sale-price">${newProduct.price}</span>`;
        } else {
            priceHtml = `<span class="regular-price">${newProduct.price}</span>`;
        }
        $productElement.find('.product-price').html(priceHtml);

        // Update data attributes
        $productElement.data('product-id', newProduct.id);
        $('.place-order-upsell').data('product-id', newProduct.id)
            .data('quantity', newProduct.min_quantity);
    }

    function checkForUpsellProducts() {
        // Only proceed with the check if we can show the upsell
        if (!canShowUpsell()) {
            $('#place_order').show();
            $('#upsell-place-order').remove();
            return;
        }

        $.ajax({
            url: checkout_upsell_params.ajax_url,
            type: 'POST',
            data: {
                action: 'check_for_upsell_products',
                nonce: checkout_upsell_params.nonce
            },
            success: function (response) {
                // Remove any existing upsell buttons first
                $('#upsell-place-order').remove();

                if (response.has_upsell_products) {
                    $('#place_order').hide();

                    // Safely append the new button HTML
                    $('.form-row.place-order').append(response.button_html);
                } else {
                    $('#place_order').show();
                }
            },
            error: function (xhr, status, error) {
                console.error('Upsell check failed:', error);
                // Ensure the default button is visible in case of error
                $('#place_order').show();
            }
        });
    }

    // Debounce the function to prevent multiple rapid calls
    const debouncedCheck = _.debounce(checkForUpsellProducts, 250);

    // Run on jQuery ready
    debouncedCheck();

    // Run when checkout updates
    $(document.body).on('updated_checkout', debouncedCheck);

    // Run when payment method changes
    $(document.body).on('payment_method_selected', debouncedCheck);

    // Additional fallback for dynamic page loads
    $(window).on('load', debouncedCheck);

    // Optional: Run when fragments are updated
    $(document.body).on('wc_fragments_updated', debouncedCheck);

    function closeUpsellPopup(timerExpired = false) {
        if (timerInterval) {
            clearInterval(timerInterval);
        }

        // Store the close time if timer expired
        if (timerExpired) {
            sessionStorage.setItem('upsellLastClosed', new Date().getTime().toString());
        }

        $('.upsell-popup-wrapper').fadeOut(400, function () {
            $('#upsell-place-order').hide();
            $('#place_order').show();
            $(this).remove();
        });
    }

    function startTimer() {
        let timeLeft = TIMER_DURATION;

        // Clear any existing timer
        if (timerInterval) {
            clearInterval(timerInterval);
        }

        // Set the initial shown time
        sessionStorage.setItem('upsellLastShown', new Date().getTime().toString());

        timerInterval = setInterval(function () {
            // Update the display
            $('.seconds').text(timeLeft.toString().padStart(2, '0'));

            // Add warning class when less than 1 minute remains
            if (timeLeft <= 10) {
                $('.countdown-timer').addClass('warning');
            }

            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                closeUpsellPopup();
                $('form.checkout').submit();
                return;
            }

            timeLeft--;
        }, 1000);
    }

    // Show upsell popup when the checkout button is clicked
    $(document.body).on('click', '#upsell-place-order', function (e) {
        e.preventDefault();

        if (!canShowUpsell) {
            const lastShown = parseInt(sessionStorage.getItem('upsellLastShown'));
            const timeLeft = Math.ceil((COOLDOWN_DURATION - (new Date().getTime() - lastShown)) / 1000 / 60);

            showNotification(`Please wait ${timeLeft} minutes before seeing new offers.`, 'info');
            $('form.checkout').submit();
            return;
        }

        $.ajax({
            url: checkout_upsell_params.ajax_url,
            method: 'POST',
            data: {
                'action': 'get_upsell_products',
                'nonce': checkout_upsell_params.nonce
            },
            success: function (response) {
                if (response.success) {
                    $('#upsell-popup-container').html(response.data).show();
                    checkUpsellProductsInCart();
                    startTimer(); // Start the timer when popup is shown
                } else {
                    console.error('Failed to load upsell products');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error details:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText,
                    readyState: xhr.readyState,
                    statusCode: xhr.status,
                    statusText: xhr.statusText
                });
            }
        });
    });

    // Handle adding upsell product to cart
    $(document).on('click', '.upsell-add-to-cart:not(.added)', function (e) {
        e.preventDefault();
        var $thisbutton = $(this);
        var product_id = $thisbutton.attr('href').match(/\d+/)[0];
        var quantity = $thisbutton.data('quantity') || 1;
        // var $buttonWrapper = $thisbutton.closest('.upsell-add-to-cart-button');
        // var $removeButton = $buttonWrapper.find('.upsell-remove-from-cart');

        $.ajax({
            type: 'POST',
            url: wc_add_to_cart_params.ajax_url,
            data: {
                'action': 'add_upsell_to_cart',
                'product_id': product_id,
                'quantity': quantity,
                'nonce': checkout_upsell_params.nonce
            },
            beforeSend: function (response) {
                // Show loader and disable button
                $thisbutton.addClass('loading');
                $thisbutton.prop('disabled', true);
            },
            success: function (response) {
                if (response.success) {
                    setTimeout(function () {
                        // Remove loading state
                        $thisbutton.removeClass('loading');
                        $thisbutton.prop('disabled', false);

                        // Switch buttons
                        $thisbutton.hide();
                        // $removeButton.show();

                        // Update cart total if available
                        if (response.data && response.data.cart_total) {
                            updateCartTotal(response.data.cart_total);
                        }

                        if (response.data.fragments) {
                            $.each(response.data.fragments, function (key, value) {
                                $(key).replaceWith(value);
                            });
                        }

                        // Show the Place Order with Offers button
                        $('.place-order-upsell').fadeIn();

                        // Optional: Show success message
                        showNotification('Product added to cart successfully', 'success');
                    }, 300); // Small delay for smoother transition
                } else {
                    // Handle error
                    $thisbutton.removeClass('loading');
                    $thisbutton.prop('disabled', false);
                    showNotification(response.data?.message || 'Failed to add product to cart', 'error');
                    console.error('Failed to add product to cart:', response);
                }
            },
            error: function (xhr, status, error) {
                // Handle AJAX error
                $thisbutton.removeClass('loading');
                $thisbutton.prop('disabled', false);
                showNotification('Error occurred. Please try again.', 'error');
                console.error('AJAX error:', status, error);
            }
        });
    });

    // Handle removing upsell product from cart
    $(document).on('click', '.upsell-remove-from-cart', function (e) {
        e.preventDefault();
        var $thisbutton = $(this);
        var product_id = $thisbutton.data('product_id');

        $.ajax({
            type: 'POST',
            url: checkout_upsell_params.ajax_url,
            data: {
                'action': 'remove_upsell_from_cart',
                'product_id': product_id,
                'nonce': checkout_upsell_params.nonce
            },
            beforeSend: function (response) {
                $thisbutton.addClass('loading...');
            },
            success: function (response) {
                if (response.success) {
                    $thisbutton.hide();
                    $thisbutton.siblings('.upsell-add-to-cart').show().removeClass('added');
                    updateCartTotal(response.data.cart_total);

                    if (response.data.fragments) {
                        $.each(response.data.fragments, function (key, value) {
                            $(key).replaceWith(value);
                        });
                    }
                } else {
                    console.error('Failed to remove product from cart');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', status, error);
            }
        });
    });

    // Handle declining upsell
    $(document).on('click', '.decline-upsell', function () {
        const $button = $(this);
        // First check if there are items in the cart
        $.ajax({
            type: 'POST',
            url: checkout_upsell_params.ajax_url,
            data: {
                'action': 'check_cart_contents_handler',
                'nonce': checkout_upsell_params.nonce
            },
            beforeSend: function () {
                $button.prop('disabled', true).addClass('loading');
            },
            success: function (response) {
                if (response && response.item_count !== undefined) {
                    const hasUpsellProducts = response.has_upsell_products;

                    // If there are no upsell products, proceed directly to checkout
                    if (!hasUpsellProducts) {
                        closeUpsellPopup();
                        $('form.checkout').submit();
                        return;
                    }

                    // If there are items, proceed with removing upsell products
                    $.ajax({
                        type: 'POST',
                        url: checkout_upsell_params.ajax_url,
                        data: {
                            'action': 'remove_upsell_product_from_cart',
                            'nonce': checkout_upsell_params.nonce
                        },
                        beforeSend: function () {
                            $button.prop('disabled', true).addClass('loading');
                        },
                        success: function (response) {
                            if (response.success) {
                                updateCartTotal(response.data.cart_total);
                                closeUpsellPopup();

                                // Double check cart contents after removal
                                if (response.data.cart_total <= 0 || response.data.item_count === 0) {
                                    // Show message if cart is empty after removal
                                    showNotification('Cart is empty. Please add items before checkout.', 'error');
                                    return;
                                }

                                $('form.checkout').submit();
                            } else {
                                showNotification(response.data.message || 'Failed to remove upsell products', 'error');
                                console.error('Failed to remove upsell products:', response.data.message);
                            }
                        },
                        error: function (xhr, status, error) {
                            showNotification('Error processing request. Please try again.', 'error');
                            console.error('AJAX error:', status, error);
                        }
                    });
                } else {
                    showNotification('Error checking cart contents. Please try again.', 'error');
                    console.error('Failed to check cart contents:', response.data?.message);
                }
            },
            error: function (xhr, status, error) {
                showNotification('Error checking cart contents. Please try again.', 'error');
                console.error('AJAX error checking cart:', status, error);
            }
        });
    });

    // Handle placing order with upsell
    // Replace the existing place-order-upsell click handler with:
    $(document).on('click', '.place-order-upsell', function () {
        const $button = $(this);
        const product_id = $button.data('product-id');
        const quantity = $button.data('quantity') || 1;

        // Add the upsell product to cart first
        $.ajax({
            type: 'POST',
            url: wc_add_to_cart_params.ajax_url,
            data: {
                'action': 'add_upsell_to_cart',
                'product_id': product_id,
                'quantity': quantity,
                'nonce': checkout_upsell_params.nonce
            },
            beforeSend: function () {
                $button.prop('disabled', true).addClass('loading');
            },
            success: function (response) {
                if (response.success) {
                    clearInterval(timerInterval); // Clear timer
                    $('.upsell-popup-wrapper').remove();
                    $('form.checkout').append('<input type="hidden" name="upsell_accepted" value="1">');
                    $('form.checkout').submit();
                } else {
                    showNotification(response.data?.message || 'Failed to add product', 'error');
                    $button.prop('disabled', false).removeClass('loading');
                }
            },
            error: function () {
                showNotification('Error occurred. Please try again.', 'error');
                $button.prop('disabled', false).removeClass('loading');
            }
        });
    });

    // Remove or comment out the checkUpsellProductsInCart function since it's no longer needed

    // Handle closing the popup
    $(document).on('click', '.close-popup', function () {
        closeUpsellPopup();
    });

    // Function to update the cart total
    function updateCartTotal(newTotal) {
        $('.cart-totals .total-amount').html(newTotal);
    }

    // Function to check which upsell products are already in the cart
    function checkUpsellProductsInCart() {
        $('.upsell-product').each(function () {
            var $product = $(this);
            var product_id = $product.data('product-id');
            $.ajax({
                type: 'POST',
                url: checkout_upsell_params.ajax_url,
                data: {
                    'action': 'check_product_in_cart',
                    'product_id': product_id,
                    'nonce': checkout_upsell_params.nonce
                },
                success: function (response) {
                    if (response.success && response.data.in_cart) {
                        $('.place-order-upsell').show();
                    } else {
                        $('.place-order-upsell').hide();
                    }
                }
            });
        });
    }

    // Helper function to show notifications
    function showNotification(message, type = 'info') {
        // Check if WooCommerce notices container exists
        let $noticesContainer = $('.woocommerce-notices-wrapper');
        if (!$noticesContainer.length) {
            $noticesContainer = $('<div class="woocommerce-notices-wrapper"></div>');
            $('form.checkout').before($noticesContainer);
        }

        // Create notice HTML
        const noticeHtml = `
                <div class="woocommerce-${type} woocommerce-message" role="alert">
                    ${message}
                    <button type="button" class="woocommerce-notice-dismiss">&times;</button>
                </div>
            `;

        // Add notice to container
        $noticesContainer.html(noticeHtml);

        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            $noticesContainer.find('.woocommerce-' + type).fadeOut(400, function () {
                $(this).remove();
            });
        }, 5000);
    }

    // Handle notice dismissal
    $(document).on('click', '.woocommerce-notice-dismiss', function () {
        $(this).closest('.woocommerce-message, .woocommerce-error, .woocommerce-info')
            .fadeOut(400, function () {
                $(this).remove();
            });
    });

    // Initialize on page load
    storeInitialQuantities();
    cleanupUpsellProducts();

    // Add event listener for page visibility changes
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            cleanupUpsellProducts();
        }
    })
});