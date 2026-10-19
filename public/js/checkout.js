/**
 * 2Checkout 2Pay.js Client-Side Integration Script
 * Manages secure PCI-DSS SAQ-A card tokenization, client validation, and 3DS flows.
 */

document.addEventListener('DOMContentLoaded', async () => {
    const config = window.TCO_CONFIG || {};
    const form = document.getElementById('payment-form');
    const submitBtn = document.getElementById('submit-btn');
    const spinner = document.getElementById('btn-spinner');
    const btnText = document.getElementById('btn-text');
    const alertBox = document.getElementById('checkout-alert');

    function showAlert(message, type = 'danger') {
        alertBox.className = `alert alert-${type}`;
        alertBox.textContent = message;
        alertBox.style.display = 'block';
        alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideAlert() {
        alertBox.style.display = 'none';
        alertBox.textContent = '';
    }

    function setLoading(isLoading) {
        if (isLoading) {
            submitBtn.disabled = true;
            spinner.style.display = 'inline-block';
            btnText.textContent = 'Securing Payment...';
        } else {
            submitBtn.disabled = false;
            spinner.style.display = 'none';
            btnText.textContent = 'Pay Now';
        }
    }

    // Handle product selection switching
    const planOptions = document.querySelectorAll('.plan-option');
    planOptions.forEach(option => {
        option.addEventListener('click', () => {
            planOptions.forEach(p => p.classList.remove('active'));
            option.classList.add('active');
            const radio = option.querySelector('input[type="radio"]');
            radio.checked = true;

            // Update summary
            const price = option.getAttribute('data-price');
            const name = option.getAttribute('data-name');
            document.getElementById('summary-product-name').textContent = name;
            document.getElementById('summary-subtotal').textContent = `$${price} USD`;
            document.getElementById('summary-total').textContent = `$${price} USD`;
        });
    });

    if (!config.merchantCode) {
        showAlert('Payment configuration error: Merchant Code is missing. Please configure .env');
        return;
    }

    let jsPaymentClient = null;
    let cardComponent = null;

    try {
        if (typeof TwoPayClient === 'undefined') {
            throw new Error('2Pay.js library failed to load. Please check network connection.');
        }

        // Initialize 2Checkout Client
        jsPaymentClient = new TwoPayClient(config.merchantCode);

        // Mount the secure 2Pay.js card component with dark-mode styling
        cardComponent = jsPaymentClient.components.create('card');
        await cardComponent.mount('#card-element');
        console.log('2Pay.js secure card component mounted.');
    } catch (err) {
        console.error('Failed to initialize 2Pay.js:', err);
        showAlert('Could not initialize secure payment interface: ' + err.message);
        return;
    }

    // Form submission interceptor
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideAlert();

        // Basic client validation
        const firstName = form.querySelector('[name="first_name"]').value.trim();
        const lastName = form.querySelector('[name="last_name"]').value.trim();
        const email = form.querySelector('[name="email"]').value.trim();
        const country = form.querySelector('[name="country"]').value;
        const csrfToken = form.querySelector('[name="csrf_token"]').value;
        const productCode = form.querySelector('input[name="product_code"]:checked').value;

        if (!firstName || !lastName || !email || !country) {
            showAlert('Please complete all required billing fields.');
            return;
        }

        setLoading(true);

        try {
            // 1. Generate secure single-use token via 2Pay.js
            const billingDetails = {
                name: `${firstName} ${lastName}`
            };

            const tokenResult = await jsPaymentClient.tokens.generate(cardComponent, billingDetails);

            if (!tokenResult || !tokenResult.token) {
                throw new Error('Failed to generate payment authorization token. Please check your card details.');
            }

            const eesToken = tokenResult.token;

            // 2. Submit payload to backend process-order.php
            const payload = {
                csrf_token: csrfToken,
                product_code: productCode,
                ees_token: eesToken,
                first_name: firstName,
                last_name: lastName,
                email: email,
                country: country,
                address: form.querySelector('[name="address"]').value.trim(),
                city: form.querySelector('[name="city"]').value.trim(),
                state: form.querySelector('[name="state"]').value.trim(),
                zip: form.querySelector('[name="zip"]').value.trim()
            };

            const response = await fetch('process-order.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Payment was declined or could not be processed.');
            }

            // 3. Handle 3D Secure Challenge Redirection if required
            if (data.redirect_url) {
                showAlert('Redirecting for 3D Secure Bank Verification...', 'success');
                window.location.href = data.redirect_url;
                return;
            }

            // 4. Payment succeeded directly
            showAlert('Payment authorized successfully! Redirecting...', 'success');
            setTimeout(() => {
                window.location.href = `return.php?order_ref=${encodeURIComponent(data.order_ref)}`;
            }, 1000);

        } catch (error) {
            console.error('Payment error:', error);
            showAlert(error.message || 'An unexpected payment error occurred.');
            setLoading(false);
        }
    });
});
