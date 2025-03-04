jQuery(document).ready(function($) {
    // Handle updating upsell prices
    $('.update-upsell-price').on('click', function() {
        var $button = $(this);
        var productId = $button.data('product-id');
        var $priceInput = $('input[name="upsell_price[' + productId + ']"]');
        var newPrice = $priceInput.val();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'update_upsell_price',
                nonce: $('#upsell_nonce').val(),
                product_id: productId,
                price: newPrice
            },
            beforeSend: function() {
                $button.prop('disabled', true).text('Updating...');
            },
            success: function(response) {
                if (response.success) {
                    $button.text('Updated!');
                    setTimeout(function() {
                        $button.text('Update');
                    }, 2000);
                } else {
                    alert('Failed to update price. Please try again.');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Handle adding upsell products
    $('#add-upsell-product-form').on('submit', function(e) {
        e.preventDefault();

        var productId = $('#add-upsell-product-select').val();
        var submitButton = $('#add-upsell-submit');

        if (!productId) {
            alert('Please select a product');
            return;
        }

        // Show loading state
        submitButton.prop('disabled', true).text('Adding...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'add_upsell_product',
                nonce: $('#add_upsell_nonce').val(),
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    // Add the new product to the table
                    var product = response.data.product;
                    var newRow = `
                        <tr>
                            <td>${product.id}</td>
                            <td>${product.name}</td>
                            <td>${product.regular_price}</td>
                            <td>
                                <input type="number" step="0.01" name="upsell_price[${product.id}]" 
                                    value="${product.upsell_price}" class="small-text upsell-price-input"
                                    data-product-id="${product.id}">
                                <button type="button" class="button update-upsell-price" data-product-id="${product.id}">
                                    Update
                                </button>
                            </td>
                            <td>${product.min_quantity}</td>
                            <td>${product.category}</td>
                            <td>
                                <button type="button" class="button remove-upsell" data-product-id="${product.id}">
                                    Remove
                                </button>
                            </td>
                        </tr>
                    `;
                    $('#upsell-products-table tbody').append(newRow);
                    $('#add-upsell-product-select').val('').trigger('change');
                    alert(response.data.message);
                } else {
                    alert(response.data.message || 'Failed to add product. Please try again.');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX Error:', error);
                alert('An error occurred while adding the product. Please check the console for details.');
            },
            complete: function () {
                // Reset button state
                submitButton.prop('disabled', false).text('Add Upsell Product');
            }
        });
    });

    // Handle removing upsell products
    $(document).on('click', '.remove-upsell', function() {
        var productId = $(this).data('product-id');
        var $row = $(this).closest('tr');
        
        if (confirm('Are you sure you want to remove this product from the upsell list?')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'remove_upsell_product',
                    nonce: $('#remove_upsell_nonce').val(),
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        $row.remove();
                    } else {
                        alert('Failed to remove product. Please try again.');
                    }
                }
            });
        }
    });

    // Add autocomplete to the product selection dropdown
    $('#add-upsell-product-select').select2({
        placeholder: 'Select a product',
        allowClear: true,
        width: '300px'
    });
});