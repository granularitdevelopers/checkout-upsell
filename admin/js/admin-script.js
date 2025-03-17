jQuery(document).ready(function($) {
    // Initialize Select2 for the product selection dropdown
    $('#add-upsell-product-select').select2({
        placeholder: checkoutUpsellData.selectPlaceholder,
        allowClear: true,
        width: '300px'
    });

    // Initialize DataTable only if it hasn't been initialized yet
    var table;
    if (!$.fn.DataTable.isDataTable('#upsell-products-table')) {
        table = $('#upsell-products-table').DataTable({
            "order": [[0, "desc"]],
            "pageLength": 10,
            "responsive": true,
            "dom": 'lrtip'
        });
    } else {
        // Retrieve the existing instance if already initialized
        table = $('#upsell-products-table').DataTable();
    }
    // Update form fields dynamically when a product is selected
    $('#add-upsell-product-select').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        const price = selectedOption.data('price');
        const category = selectedOption.data('category');
        $('#upsell-price').val(price || '');
        $('#product-category').text(category || 'N/A');
    });

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
        var upsellPrice = $('#upsell-price').val();
        var submitButton = $('#add-upsell-submit');
        var table = $('#upsell-products-table').DataTable();

        if (!productId) {
            alert('Please select a product');
            return;
        }

        submitButton.prop('disabled', true).text('Adding...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'add_upsell_product',
                nonce: $('#add_upsell_nonce').val(),
                product_id: productId,
                price: upsellPrice
            },
            success: function(response) {
                if (response.success) {
                    var product = response.data.product;
                    var newRow = [
                        product.id,
                        product.name,
                        product.regular_price,
                        `<input type="number" step="0.01" name="upsell_price[${product.id}]" 
                            value="${product.upsell_price}" class="small-text upsell-price-input"
                            data-product-id="${product.id}">
                        <button type="button" class="button update-upsell-price" data-product-id="${product.id}">
                            Update
                        </button>`,
                        product.min_quantity,
                        `<span class="dashicons ${product.category === 'Dog' ? 'dashicons-pets' : 'dashicons-heart'}"></span> ${product.category}`,
                        `<button type="button" class="button remove-upsell" data-product-id="${product.id}">
                            Remove
                        </button>`
                    ];
                    table.row.add(newRow).draw(false); // Add row without resetting pagination
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
                submitButton.prop('disabled', false).text('Add Upsell Product');
            }
        });
    });

    // Handle removing upsell products
    $(document).on('click', '.remove-upsell', function() {
        var productId = $(this).data('product-id');
        var $row = $(this).closest('tr');
        var table = $('#upsell-products-table').DataTable();
        
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
                        table.row($row).remove().draw(false); // Remove row without resetting pagination
                        alert(response.data.message);
                    } else {
                        alert('Failed to remove product. Please try again.');
                    }
                }
            });
        }
    });

    // Bar Chart: Times Shown vs Times Purchased
if (document.getElementById('conversionBarChart')) {
    // Ensure upsellMetrics exists and has data
    if (typeof upsellMetrics === 'undefined' || Object.keys(upsellMetrics).length === 0) {
        const chartWrapper = document.querySelector('#conversionBarChart').parentElement;
        chartWrapper.innerHTML = '<p style="text-align: center; color: #666;">No metrics data available for bar chart.</p>';
    } else {
        const truncateLabel = (label, maxLength = 20) => {
            return label.length > maxLength ? label.substring(0, maxLength) + '...' : label;
        };

        // Extract data from metrics object
        const fullLabels = Object.values(upsellMetrics).map(metric => metric.name || 'Unknown');
        const truncatedLabels = fullLabels.map(label => truncateLabel(label));
        const timesShown = Object.values(upsellMetrics).map(metric => parseInt(metric.total_shown) || 0);
        const timesPurchased = Object.values(upsellMetrics).map(metric => parseInt(metric.total_purchased) || 0);

        const barChartCtx = document.getElementById('conversionBarChart').getContext('2d');
        new Chart(barChartCtx, {
            type: 'bar',
            data: {
                labels: truncatedLabels,
                datasets: [
                    {
                        label: 'Times Shown',
                        data: timesShown,
                        backgroundColor: 'rgba(54, 162, 235, 0.6)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Times Purchased',
                        data: timesPurchased,
                        backgroundColor: 'rgba(255, 99, 132, 0.6)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Count'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Product'
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45,
                            font: {
                                size: 10
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            title: function(tooltipItems) {
                                const index = tooltipItems[0].dataIndex;
                                return fullLabels[index];
                            }
                        }
                    }
                }
            }
        });
    }
}

// Pie Chart: Purchases by Category
if (document.getElementById('categoryPieChart')) {
    // Ensure upsellMetrics exists and has data
    if (typeof upsellMetrics === 'undefined' || Object.keys(upsellMetrics).length === 0) {
        const chartWrapper = document.querySelector('#categoryPieChart').parentElement;
        chartWrapper.innerHTML = '<p style="text-align: center; color: #666;">No metrics data available for pie chart.</p>';
    } else {
        // Process category data more safely
        const categoryData = {dog: 0, cat: 0};
        
        Object.values(upsellMetrics).forEach(metric => {
            const category = (metric.category || '').toLowerCase();
            const purchases = parseInt(metric.total_purchased) || 0;
            
            if (category === 'dog') {
                categoryData.dog += purchases;
            } else if (category === 'cat') {
                categoryData.cat += purchases;
            }
        });

        // Check if we have any purchases
        if (categoryData.dog === 0 && categoryData.cat === 0) {
            const chartWrapper = document.querySelector('#categoryPieChart').parentElement;
            chartWrapper.innerHTML = '<p style="text-align: center; color: #666;">No purchase data available for categories.</p>';
        } else {
            const pieChartCtx = document.getElementById('categoryPieChart').getContext('2d');
            new Chart(pieChartCtx, {
                type: 'pie',
                data: {
                    labels: ['Dog Products', 'Cat Products'],
                    datasets: [{
                        data: [categoryData.dog, categoryData.cat],
                        backgroundColor: ['rgba(54, 162, 235, 0.8)', 'rgba(255, 99, 132, 0.8)'],
                        borderColor: ['rgba(54, 162, 235, 1)', 'rgba(255, 99, 132, 1)'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        },
                        title: {
                            display: true,
                            text: 'Purchases by Category'
                        }
                    }
                }
            });
        }
    }
}
});