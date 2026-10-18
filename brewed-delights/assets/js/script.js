(function () {
    'use strict';

    /* ---------------- Toast ---------------- */
    function showToast(message) {
        var toast = document.getElementById('toast');
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('show');
        clearTimeout(showToast._t);
        showToast._t = setTimeout(function () {
            toast.classList.remove('show');
        }, 2200);
    }

    function setMenuView(groupName) {
        var buttons = document.querySelectorAll('[data-menu-view-group]');
        buttons.forEach(function (button) {
            var matches = button.getAttribute('data-menu-view-group') === groupName;
            button.classList.toggle('is-active', matches);
            button.setAttribute('aria-pressed', matches ? 'true' : 'false');
        });

        var groups = document.querySelectorAll('.menu-group');
        groups.forEach(function (group) {
            var matchesGroup = group.getAttribute('data-menu-group-name') === groupName;
            group.hidden = !matchesGroup;
            if (matchesGroup) {
                group.setAttribute('open', 'open');
            } else {
                group.removeAttribute('open');
            }
        });

        var sidebar = document.querySelector('.sidebar-cats');
        if (sidebar) {
            var sidebarLinks = Array.from(sidebar.querySelectorAll('[data-sidebar-group]'));
            sidebarLinks
                .filter(function (link) { return link.getAttribute('data-sidebar-group') === groupName; })
                .concat(sidebarLinks.filter(function (link) {
                    return link.getAttribute('data-sidebar-group') !== groupName;
                }))
                .forEach(function (link) { sidebar.appendChild(link); });
        }
    }

    document.addEventListener('click', function (evt) {
        var menuToggle = evt.target.closest('[data-menu-view-group]');
        if (menuToggle) {
            setMenuView(menuToggle.getAttribute('data-menu-view-group'));
            return;
        }
        var groupLink = evt.target.closest('a[href^="#menu-group-"]');
        var linkedGroup = groupLink && document.querySelector(groupLink.getAttribute('href'));
        if (linkedGroup) {
            setMenuView(linkedGroup.getAttribute('data-menu-group-name'));
        }
    });

    document.addEventListener('click', function (evt) {
        var categoryLink = evt.target.closest('a[href^="#cat-"]');
        if (!categoryLink) return;
        var section = document.querySelector(categoryLink.getAttribute('href'));
        var group = section && section.closest('[data-menu-group]');
        if (!group) return;

        evt.preventDefault();
        setMenuView(group.getAttribute('data-menu-group-name'));
        group.open = true;
        requestAnimationFrame(function () {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        setMenuView('Drinks');
    });

    /* ---------------- Quantity stepper (menu cards / item rows) ---------------- */
    document.addEventListener('click', function (evt) {
        var decBtn = evt.target.closest('.qty-decrease');
        var incBtn = evt.target.closest('.qty-increase');
        if (!decBtn && !incBtn) return;

        var stepper = (decBtn || incBtn).closest('[data-qty-stepper]');
        if (!stepper) return;
        var valueEl = stepper.querySelector('.qty-value');
        var current = parseInt(valueEl.textContent, 10) || 1;
        if (decBtn) current = Math.max(1, current - 1);
        if (incBtn) current = Math.min(20, current + 1);
        valueEl.textContent = current;
    });

    document.addEventListener('change', function (evt) {
        var changedControl = evt.target.closest('[data-menu-option], [data-menu-addon]');
        if (!changedControl) return;
        var container = changedControl.closest('[data-item-id]');
        var priceEl = container && container.querySelector('[data-item-price]');
        if (priceEl) {
            var optionSelect = container.querySelector('[data-menu-option]');
            var selectedOption = optionSelect && optionSelect.options[optionSelect.selectedIndex];
            var price = selectedOption
                ? Number(selectedOption.getAttribute('data-price'))
                : Number(priceEl.getAttribute('data-base-price'));
            container.querySelectorAll('[data-menu-addon]:checked').forEach(function (addon) {
                price += Number(addon.getAttribute('data-price'));
            });
            priceEl.textContent = new Intl.NumberFormat('en-PH', {
                style: 'currency', currency: 'PHP', minimumFractionDigits: 2
            }).format(price);
        }
    });

    document.addEventListener('change', function (evt) {
        var quantityInput = evt.target.closest('[data-cart-qty]');
        if (!quantityInput) return;

        var row = quantityInput.closest('[data-cart-row]');
        if (!row) return;
        var qty = Math.max(1, Math.min(20, parseInt(quantityInput.value, 10) || 1));
        quantityInput.value = qty;

        var formData = new FormData(row);
        formData.set('qty', qty);
        var status = row.querySelector('[data-cart-update-status]');
        if (status) status.textContent = 'Saving...';
        quantityInput.disabled = true;

        fetch('cart.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
            .then(function (res) {
                if (!res.ok) throw new Error('Cart update failed');
                return res.json();
            })
            .then(function (data) {
                if (!data.ok) throw new Error(data.error || 'Cart update failed');
                var lineTotal = row.querySelector('[data-cart-line-total]');
                var amount = Number(lineTotal.getAttribute('data-unit-price')) * qty;
                lineTotal.setAttribute('data-line-total', amount.toFixed(2));
                lineTotal.textContent = new Intl.NumberFormat('en-PH', {
                    style: 'currency', currency: 'PHP', minimumFractionDigits: 2
                }).format(amount);

                var grandTotal = Array.from(document.querySelectorAll('[data-cart-line-total]'))
                    .reduce(function (sum, line) { return sum + Number(line.getAttribute('data-line-total')); }, 0);
                var grandTotalEl = document.querySelector('[data-cart-grand-total]');
                if (grandTotalEl) {
                    grandTotalEl.textContent = new Intl.NumberFormat('en-PH', {
                        style: 'currency', currency: 'PHP', minimumFractionDigits: 2
                    }).format(grandTotal);
                }

                var cartLink = document.querySelector('.nav-cart');
                if (cartLink) {
                    var badge = cartLink.querySelector('.cart-badge');
                    if (data.cart_count > 0 && !badge) {
                        badge = document.createElement('span');
                        badge.className = 'cart-badge';
                        cartLink.appendChild(badge);
                    }
                    if (badge) {
                        if (data.cart_count > 0) badge.textContent = data.cart_count;
                        else badge.remove();
                    }
                }
                if (status) status.textContent = 'Saved';
                setTimeout(function () { if (status) status.textContent = ''; }, 1200);
            })
            .catch(function () {
                if (status) status.textContent = 'Could not save';
                quantityInput.disabled = false;
            })
            .finally(function () {
                if (quantityInput.isConnected) quantityInput.disabled = false;
            });
    });

    /* ---------------- Add to cart (menu page) ---------------- */
    document.addEventListener('click', function (evt) {
        var btn = evt.target.closest('[data-add-to-cart]');
        if (!btn) return;

        var itemId = btn.getAttribute('data-add-to-cart');

        // If this item has a quantity stepper (a sibling within the same
        // item container), use its current value; otherwise default to 1
        // (e.g. the cart page's cross-sell cards have no stepper).
        var qty = 1;
        var container = btn.closest('[data-item-id]') || btn.closest('.crosssell-item') || btn.parentElement;
        if (container) {
            var qtyValueEl = container.querySelector('.qty-value');
            if (qtyValueEl) qty = parseInt(qtyValueEl.textContent, 10) || 1;
        }

        btn.disabled = true;
        var originalText = btn.textContent;
        btn.textContent = 'Adding...';

        var formData = new URLSearchParams();
        formData.append('item_id', itemId);
        formData.append('qty', qty);
        var optionSelect = container && container.querySelector('[data-menu-option]');
        if (optionSelect) formData.append('variant_id', optionSelect.value);
        if (container) {
            container.querySelectorAll('[data-menu-addon]:checked').forEach(function (addon) {
                formData.append('addon_ids[]', addon.value);
            });
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? csrfMeta.content : '';

        fetch('add_to_cart.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: formData.toString()
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.ok) {
                    showToast(data.item_name + ' added to cart');
                    var cartBadgeParent = document.querySelector('.nav-cart');
                    if (cartBadgeParent) {
                        var badge = cartBadgeParent.querySelector('.cart-badge');
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'cart-badge';
                            cartBadgeParent.appendChild(badge);
                        }
                        badge.textContent = data.cart_count;
                    }
                    // Reset the stepper back to 1 for next time.
                    if (container) {
                        var resetEl = container.querySelector('.qty-value');
                        if (resetEl) resetEl.textContent = '1';
                        container.querySelectorAll('[data-menu-addon]:checked').forEach(function (addon) {
                            addon.checked = false;
                        });
                        var priceControl = container.querySelector('[data-menu-option], [data-menu-addon]');
                        if (priceControl) priceControl.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    if (container && container.classList.contains('crosssell-item') && document.querySelector('[data-cart-row]')) {
                        window.location.reload();
                    }
                } else {
                    showToast(data.error || 'Could not add item.');
                }
            })
            .catch(function () { showToast('Network error — please try again.'); })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = originalText;
            });
    });

    /* ---------------- Live order status polling (orders.php) ---------------- */
    if (document.querySelector('[data-orders-page]')) {
        var poll = function () {
            if (document.hidden) return;
            fetch('order_status.php')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.ok) return;
                    var completedDeliveryChanged = false;
                    data.orders.forEach(function (order) {
                        var card = document.querySelector('[data-order-id="' + order.order_id + '"]');
                        if (!card) {
                            card = createOrderCard(order);
                            var list = document.querySelector('[data-orders-list]');
                            if (!card || !list) return;
                            var emptyState = list.querySelector('[data-orders-empty]');
                            if (emptyState) emptyState.remove();
                            list.prepend(card);
                            showToast('A new order was added to My orders');
                        }

                        var label = card.querySelector('[data-status-label]');
                        if (label && label.textContent.trim() !== order.status) {
                            label.textContent = order.status;
                            label.className = 'badge ' + statusBadgeClass(order.status);
                            updateTrack(card, order.order_type, order.status);
                            showToast('Order #' + order.order_id + ' is now ' + order.status);
                            if (order.order_type === 'Delivery' && order.status === 'Completed') {
                                completedDeliveryChanged = true;
                            }
                        }

                        updateRiderContact(card, order);
                        updateRiderMap(card, order);
                    });
                    if (completedDeliveryChanged) window.location.reload();
                })
                .catch(function () { /* silent — will retry on next interval */ });
        };

        function statusBadgeClass(status) {
            return {
                Pending: 'badge-pending',
                Preparing: 'badge-preparing',
                Ready: 'badge-ready',
                'Out for Delivery': 'badge-delivery',
                Completed: 'badge-completed',
                Cancelled: 'badge-cancelled'
            }[status] || 'badge-pending';
        }

        function createOrderCard(order) {
            var card = document.createElement('article');
            card.className = 'order-card';
            card.setAttribute('data-order-id', order.order_id);

            var head = document.createElement('div');
            head.className = 'order-card-head';
            var identity = document.createElement('div');
            var orderNumber = document.createElement('strong');
            orderNumber.textContent = 'Order #' + order.order_id;
            identity.appendChild(orderNumber);
            if (order.reference_code) {
                var reference = document.createElement('span');
                reference.className = 'order-date';
                reference.textContent = 'Ref: ' + order.reference_code;
                identity.appendChild(reference);
            }
            var date = document.createElement('span');
            date.className = 'order-date';
            date.textContent = order.created_at_display || order.created_at;
            identity.appendChild(date);
            var status = document.createElement('span');
            status.className = 'badge ' + statusBadgeClass(order.status);
            status.setAttribute('data-status-label', '');
            status.textContent = order.status;
            head.append(identity, status);
            card.appendChild(head);

            var itemList = document.createElement('ul');
            itemList.className = 'order-items';
            (order.items || []).forEach(function (item) {
                var row = document.createElement('li');
                row.textContent = item.quantity + ' × ' + item.item_name;
                itemList.appendChild(row);
            });
            if (Number(order.loyalty_discount) > 0) {
                var discount = document.createElement('li');
                discount.textContent = 'Loyalty points applied: -₱' + Number(order.loyalty_discount).toFixed(2) +
                    ' (' + order.points_redeemed + ' pts)';
                itemList.appendChild(discount);
            }
            card.appendChild(itemList);

            if (order.order_type === 'Delivery') {
                var delivery = document.createElement('div');
                delivery.className = 'delivery-info';
                delivery.setAttribute('data-rider-contact', '');
                var address = document.createElement('span');
                address.textContent = order.delivery_address || 'Delivery';
                var rider = document.createElement('span');
                rider.setAttribute('data-rider-details', '');
                rider.textContent = 'Rider: ' + (order.rider_name || 'Assigning soon...');
                var riderPhone = document.createElement('a');
                riderPhone.setAttribute('data-rider-phone', '');
                delivery.append(address, rider, riderPhone);
                card.appendChild(delivery);
            }

            var foot = document.createElement('div');
            foot.className = 'order-card-foot';
            var total = document.createElement('span');
            total.textContent = 'Total: ₱' + Number(order.total_amount).toFixed(2);
            var payment = document.createElement('span');
            payment.append(document.createTextNode((order.payment_method || 'Payment') + ': '));
            var paymentBadge = document.createElement('span');
            paymentBadge.className = 'badge ' + ({
                Unpaid: 'badge-pending',
                'Pending Verification': 'badge-preparing',
                Paid: 'badge-ready',
                Failed: 'badge-cancelled',
                Refunded: 'badge-cancelled'
            }[order.payment_status] || 'badge-pending');
            paymentBadge.textContent = order.payment_status;
            payment.appendChild(paymentBadge);
            foot.append(total, payment);
            card.appendChild(foot);

            var steps = order.order_type === 'Delivery'
                ? ['Pending', 'Preparing', 'Ready', 'Out for Delivery', 'Completed']
                : ['Pending', 'Preparing', 'Ready', 'Completed'];
            var progress = document.createElement('div');
            progress.className = 'status-track';
            progress.setAttribute('data-status-track', '');
            progress.setAttribute('data-order-type', order.order_type);
            var currentStep = steps.indexOf(order.status);
            steps.forEach(function (stepName, index) {
                var step = document.createElement('div');
                step.className = 'track-step' + (order.status === 'Cancelled'
                    ? ' step-cancelled'
                    : (currentStep >= 0 && index <= currentStep ? ' step-done' : ''));
                var dot = document.createElement('span');
                dot.className = 'dot';
                var label = document.createElement('span');
                label.textContent = stepName;
                step.append(dot, label);
                progress.appendChild(step);
            });
            card.appendChild(progress);
            return card;
        }

        function updateRiderContact(card, order) {
            var contact = card.querySelector('[data-rider-contact]');
            if (!contact || order.order_type !== 'Delivery') return;
            var riderDetails = contact.querySelector('[data-rider-details]');
            if (riderDetails) riderDetails.textContent = 'Rider: ' + (order.rider_name || 'Assigning soon...');

            var phoneLink = contact.querySelector('[data-rider-phone]');
            if (!phoneLink) {
                phoneLink = document.createElement('a');
                phoneLink.setAttribute('data-rider-phone', '');
                contact.appendChild(phoneLink);
            }
            var phone = order.rider_phone || '';
            var canCall = order.status === 'Out for Delivery' && phone !== '';
            phoneLink.hidden = !canCall;
            if (canCall) {
                phoneLink.href = 'tel:' + phone.replace(/[^0-9+]/g, '');
                phoneLink.textContent = 'Call rider: ' + phone;
            } else {
                phoneLink.removeAttribute('href');
                phoneLink.textContent = '';
            }
        }

        function updateTrack(card, orderType, status) {
            var steps = orderType === 'Delivery'
                ? ['Pending', 'Preparing', 'Ready', 'Out for Delivery', 'Completed']
                : ['Pending', 'Preparing', 'Ready', 'Completed'];
            var idx = steps.indexOf(status);
            var trackSteps = card.querySelectorAll('.track-step');
            trackSteps.forEach(function (stepEl, i) {
                stepEl.classList.remove('step-done', 'step-cancelled');
                if (status === 'Cancelled') {
                    stepEl.classList.add('step-cancelled');
                } else if (idx !== -1 && i <= idx) {
                    stepEl.classList.add('step-done');
                }
            });
            updateBrewCup(card, steps, status, idx);
        }

        function updateBrewCup(card, steps, status, idx) {
            var fillEl = card.querySelector('.brew-fill');
            var wrapEl = card.querySelector('.brew-cup-wrap');
            if (!fillEl || !wrapEl) return;

            var cancelled = status === 'Cancelled';
            var percent = cancelled ? 0 : (idx !== -1 ? Math.round(((idx + 1) / steps.length) * 100) : 0);
            percent = cancelled ? 0 : Math.max(4, Math.min(100, percent));
            var fillHeight = Math.round((percent / 100) * 92);
            var fillY = 134 - fillHeight;

            fillEl.setAttribute('y', fillY);
            fillEl.setAttribute('height', fillHeight);
            wrapEl.classList.toggle('brew-cup-cancelled', cancelled);

            var steamEls = card.querySelectorAll('.brew-steam');
            var showSteam = !cancelled && percent >= 45;
            steamEls.forEach(function (s) { s.style.display = showSteam ? '' : 'none'; });
        }

        /* ---- Self-drawn live map: no map tiles, no API key ----
           Plots the delivery pin and the rider's last known position
           as two dots on a small SVG canvas, using an equirectangular
           approximation to convert lat/lng offsets to meters, scaled
           to fit the box. Distance uses the Haversine formula. */
        function haversineKm(lat1, lng1, lat2, lng2) {
            var R = 6371;
            var dLat = (lat2 - lat1) * Math.PI / 180;
            var dLng = (lng2 - lng1) * Math.PI / 180;
            var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        function renderMiniMap(container, destLat, destLng, riderLat, riderLng) {
            var size = 220;
            var pad = 30;

            // Meters per degree at this latitude (equirectangular approx).
            var metersPerDegLat = 111320;
            var metersPerDegLng = 111320 * Math.cos(destLat * Math.PI / 180);

            var dxM = (riderLng - destLng) * metersPerDegLng;
            var dyM = (riderLat - destLat) * metersPerDegLat;

            // Scale so the farther point sits near the edge, min 40m radius.
            var maxM = Math.max(Math.abs(dxM), Math.abs(dyM), 40);
            var scale = (size / 2 - pad) / maxM;

            var cx = size / 2, cy = size / 2;
            var rx = cx + dxM * scale;
            var ry = cy - dyM * scale; // screen y grows downward

            var distanceKm = haversineKm(destLat, destLng, riderLat, riderLng);
            var distanceLabel = distanceKm < 1
                ? Math.round(distanceKm * 1000) + ' m away'
                : distanceKm.toFixed(1) + ' km away';

            container.innerHTML =
                '<svg viewBox="0 0 ' + size + ' ' + size + '" class="mini-map-svg" role="img" aria-label="Rider location relative to your delivery address">' +
                '<rect x="0" y="0" width="' + size + '" height="' + size + '" rx="12" class="mini-map-bg"/>' +
                '<line x1="' + cx + '" y1="' + cy + '" x2="' + rx + '" y2="' + ry + '" class="mini-map-line"/>' +
                '<circle cx="' + cx + '" cy="' + cy + '" r="7" class="mini-map-dest"/>' +
                '<circle cx="' + rx + '" cy="' + ry + '" r="7" class="mini-map-rider"/>' +
                '</svg>' +
                '<p class="map-caption"><span class="mini-map-dot mini-map-dot-dest"></span> Your address &nbsp; ' +
                '<span class="mini-map-dot mini-map-dot-rider"></span> Rider — ' + distanceLabel + '</p>';
        }

        var leafletPromise = null;
        function loadLeaflet() {
            if (window.L) return Promise.resolve();
            if (leafletPromise) return leafletPromise;

            leafletPromise = new Promise(function (resolve, reject) {
                var stylesheet = document.createElement('link');
                stylesheet.rel = 'stylesheet';
                stylesheet.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.appendChild(stylesheet);

                var script = document.createElement('script');
                script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
            return leafletPromise;
        }

        function renderRiderMap(container, destLat, destLng, riderLat, riderLng) {
            var distanceKm = haversineKm(destLat, destLng, riderLat, riderLng);
            var distanceLabel = distanceKm < 1
                ? Math.round(distanceKm * 1000) + ' m away'
                : distanceKm.toFixed(1) + ' km away';

            if (!window.L) {
                renderMiniMap(container, destLat, destLng, riderLat, riderLng);
                if (container.dataset.leafletFailed === 'true') return;
                if (container.dataset.leafletLoading === 'true') return;
                container.dataset.leafletLoading = 'true';
                loadLeaflet().then(function () {
                    delete container.dataset.leafletLoading;
                    container.innerHTML = '';
                    renderRiderMap(container, destLat, destLng, riderLat, riderLng);
                }).catch(function () {
                    delete container.dataset.leafletLoading;
                    container.dataset.leafletFailed = 'true';
                });
                return;
            }

            var mapElement = container.querySelector('[data-live-map]');
            if (!mapElement) {
                container.innerHTML = '<div class="live-rider-map" data-live-map></div>' +
                    '<p class="map-caption" data-map-caption></p>';
                mapElement = container.querySelector('[data-live-map]');
            }

            if (!container.liveRiderMap) {
                var map = window.L.map(mapElement, { scrollWheelZoom: false, attributionControl: true });
                window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>'
                }).addTo(map);
                container.liveRiderMap = {
                    map: map,
                    destination: window.L.circleMarker([destLat, destLng], {
                        radius: 8, color: '#fff', weight: 2, fillColor: '#30271f', fillOpacity: 1
                    }).addTo(map).bindPopup('Delivery address'),
                    rider: window.L.circleMarker([riderLat, riderLng], {
                        radius: 8, color: '#fff', weight: 2, fillColor: '#743b62', fillOpacity: 1
                    }).addTo(map).bindPopup('Rider'),
                    line: window.L.polyline([[destLat, destLng], [riderLat, riderLng]], {
                        color: '#743b62', dashArray: '6 6', weight: 2
                    }).addTo(map)
                };
            } else {
                container.liveRiderMap.destination.setLatLng([destLat, destLng]);
                container.liveRiderMap.rider.setLatLng([riderLat, riderLng]);
                container.liveRiderMap.line.setLatLngs([[destLat, destLng], [riderLat, riderLng]]);
            }

            var bounds = window.L.latLngBounds([[destLat, destLng], [riderLat, riderLng]]);
            if (bounds.getNorthEast().equals(bounds.getSouthWest())) bounds.pad(0.005);
            container.liveRiderMap.map.fitBounds(bounds, { padding: [24, 24], maxZoom: 16 });

            var caption = container.querySelector('[data-map-caption]');
            caption.textContent = 'Delivery address · Rider — ' + distanceLabel;
        }

        function updateRiderMap(card, order) {
            var wrap = card.querySelector('[data-rider-map]');
            var shouldShow = order.order_type === 'Delivery'
                && order.status === 'Out for Delivery'
                && order.delivery_lat !== null && order.delivery_lat !== ''
                && order.delivery_lng !== null && order.delivery_lng !== '';

            if (!shouldShow) {
                if (wrap) wrap.style.display = 'none';
                return;
            }

            if (!wrap) {
                wrap = document.createElement('div');
                wrap.className = 'rider-map-wrap';
                wrap.setAttribute('data-rider-map', '');
                var track = card.querySelector('[data-status-track]');
                if (track) track.insertAdjacentElement('afterend', wrap);
                else card.appendChild(wrap);
            }
            wrap.style.display = '';

            if (order.rider_lat !== null && order.rider_lat !== ''
                && order.rider_lng !== null && order.rider_lng !== '') {
                renderRiderMap(wrap, parseFloat(order.delivery_lat), parseFloat(order.delivery_lng),
                    parseFloat(order.rider_lat), parseFloat(order.rider_lng));
            } else {
                if (wrap.liveRiderMap) {
                    wrap.liveRiderMap.map.remove();
                    delete wrap.liveRiderMap;
                }
                wrap.innerHTML = '<p class="map-caption">Rider location — waiting for rider to share location…</p>';
            }
        }

        poll();
        setInterval(poll, 8000);
        // Render immediately on load for any card whose data-rider-map already
        // has coordinates from the initial page render (PHP-rendered stub).
        document.querySelectorAll('[data-rider-map]').forEach(function (wrap) {
            var destLat = parseFloat(wrap.getAttribute('data-delivery-lat'));
            var destLng = parseFloat(wrap.getAttribute('data-delivery-lng'));
            var riderLat = wrap.getAttribute('data-rider-lat');
            var riderLng = wrap.getAttribute('data-rider-lng');
            if (riderLat !== '' && riderLng !== '') {
                renderRiderMap(wrap, destLat, destLng, parseFloat(riderLat), parseFloat(riderLng));
            }
        });
    }
})();

/* ---------------- Live delivery status (rider dashboard) ---------------- */
(function () {
    'use strict';
    if (!document.querySelector('[data-rider-dashboard]')) return;

    function pollRiderDeliveries() {
        if (document.hidden) return;
        fetch('order_status.php')
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) return;
                var needsRefresh = Array.from(document.querySelectorAll('[data-rider-order-id]')).some(function (card) {
                    var order = data.orders.find(function (item) {
                        return Number(item.order_id) === Number(card.getAttribute('data-rider-order-id'));
                    });
                    var badge = card.querySelector('.badge');
                    return !order || !badge || badge.textContent.trim() !== order.status;
                });
                if (needsRefresh) window.location.reload();
            })
            .catch(function () { /* retry on the next interval */ });
    }

    pollRiderDeliveries();
    setInterval(pollRiderDeliveries, 8000);
})();

/* ---------------- Live rider pickup status (admin orders) ---------------- */
(function () {
    'use strict';
    if (!document.querySelector('.admin-order-card')) return;

    var badgeClasses = {
        Pending: 'badge-pending',
        Preparing: 'badge-preparing',
        Ready: 'badge-ready',
        'Out for Delivery': 'badge-delivery',
        Completed: 'badge-completed',
        Cancelled: 'badge-cancelled'
    };

    function pollAdminOrders() {
        if (document.hidden) return;
        fetch('order_status.php')
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) return;
                var activeFilter = new URLSearchParams(window.location.search).get('filter') || 'active';
                var shouldReload = false;
                data.orders.forEach(function (order) {
                    var card = document.querySelector('.admin-order-card[data-order-id="' + Number(order.order_id) + '"]');
                    var matchesFilter = activeFilter === 'active'
                        ? !['Completed', 'Cancelled'].includes(order.status)
                        : activeFilter === order.status;
                    if (!card) {
                        if (matchesFilter) shouldReload = true;
                        return;
                    }

                    var statusBadge = card.querySelector('[data-status-label]');
                    if (statusBadge && statusBadge.textContent.trim() !== order.status) {
                        if (!matchesFilter) {
                            shouldReload = true;
                            return;
                        }
                        statusBadge.textContent = order.status;
                        statusBadge.className = 'badge ' + (badgeClasses[order.status] || 'badge-pending');
                        statusBadge.setAttribute('aria-live', 'polite');
                    }

                    var statusForm = Array.from(card.querySelectorAll('form.status-form')).find(function (form) {
                        return form.querySelector('input[name="action"][value="update_status"]');
                    });
                    var statusSelect = statusForm && statusForm.querySelector('select[name="status"]');
                    if (statusSelect && statusSelect.value !== order.status) statusSelect.value = order.status;
                });
                if (shouldReload) window.location.reload();
            })
            .catch(function () { /* retry on the next interval */ });
    }

    pollAdminOrders();
    setInterval(pollAdminOrders, 8000);
})();

/* ---------------- Sidebar category scroll-spy (index.php menu) ---------------- */
(function () {
    'use strict';
    var sections = document.querySelectorAll('[data-menu-group]');
    var links = document.querySelectorAll('.sidebar-cat-link');
    var search = document.querySelector('[data-menu-search]');
    var groups = document.querySelectorAll('[data-menu-group]');

    document.addEventListener('click', function (evt) {
        var link = evt.target.closest('a[href^="#menu-group-"]');
        if (!link) return;
        var target = document.querySelector(link.getAttribute('href'));
        if (target) target.open = true;
    });

    if (search) {
        search.addEventListener('input', function () {
            var query = search.value.trim().toLowerCase();
            var anyVisible = false;
            groups.forEach(function (group) {
                var groupHasMatch = false;
                group.querySelectorAll('.item-section').forEach(function (section) {
                    var sectionHasMatch = false;
                    section.querySelectorAll('.item-row').forEach(function (row) {
                        var title = row.querySelector('h3');
                        var description = row.querySelector('.desc');
                        var searchable = (title ? title.textContent : '') + ' ' + (description ? description.textContent : '');
                        var optionSelect = row.querySelector('[data-menu-option]');
                        if (optionSelect) searchable += ' ' + Array.from(optionSelect.options).map(function (option) {
                            return option.textContent;
                        }).join(' ');
                        var matches = !query || searchable.toLowerCase().includes(query);
                        row.hidden = !matches;
                        sectionHasMatch = sectionHasMatch || matches;
                    });
                    section.hidden = !sectionHasMatch;
                    groupHasMatch = groupHasMatch || sectionHasMatch;
                });
                group.hidden = !groupHasMatch;
                if (query && groupHasMatch) group.open = true;
                anyVisible = anyVisible || groupHasMatch;
            });
            var emptyMessage = document.querySelector('[data-menu-no-results]');
            if (emptyMessage) emptyMessage.hidden = anyVisible;
        });
    }
    if (!sections.length || !links.length) return;

    var linkFor = {};
    links.forEach(function (l) { linkFor[l.getAttribute('href').replace('#', '')] = l; });

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    links.forEach(function (l) { l.classList.remove('active'); });
                    var link = linkFor[entry.target.id];
                    if (link) link.classList.add('active');
                }
            });
        }, { rootMargin: '-20% 0px -70% 0px' });

        sections.forEach(function (s) { observer.observe(s); });
    }
})();

/* ---------------- Mobile navigation (hamburger toggle) ---------------- */
(function () {
    'use strict';
    var toggle = document.querySelector('[data-nav-toggle]');
    var nav = document.querySelector('[data-main-nav]');
    if (!toggle || !nav) return;

    toggle.addEventListener('click', function () {
        var isOpen = nav.classList.toggle('nav-open');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    // Close the menu automatically after tapping a link, so it doesn't
    // stay open once the page navigates.
    nav.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            nav.classList.remove('nav-open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });
})();

/* ---------------- Scroll-reveal for homepage sections ---------------- */
(function () {
    'use strict';
    var reveals = document.querySelectorAll('.reveal');
    if (!reveals.length) return;

    var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion || !('IntersectionObserver' in window)) {
        reveals.forEach(function (el) { el.classList.add('reveal-visible'); });
        return;
    }

    var observer = new IntersectionObserver(function (entries, obs) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('reveal-visible');
                obs.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });

    reveals.forEach(function (el) { observer.observe(el); });
})();

/* ---------------- Floating "Order now" button ---------------- */
(function () {
    'use strict';
    var btn = document.querySelector('[data-floating-order]');
    var hero = document.querySelector('.hero');
    if (!btn || !hero) return;

    var toggle = function () {
        var heroBottom = hero.getBoundingClientRect().bottom;
        btn.classList.toggle('visible', heroBottom < 0);
    };
    window.addEventListener('scroll', toggle, { passive: true });
    toggle();
})();

/* ---------------- Back-to-top button while browsing ---------------- */
(function () {
    'use strict';
    var btn = document.querySelector('[data-back-to-top]');
    if (!btn) return;

    var toggle = function () {
        btn.classList.toggle('visible', window.scrollY > 320);
    };

    btn.addEventListener('click', function () {
        var prefersReducedMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
    });

    window.addEventListener('scroll', toggle, { passive: true });
    window.addEventListener('resize', toggle);
    toggle();
})();
