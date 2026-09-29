<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if (!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_customers'))) {
    header('Location: /login');
    exit;
}

$customersWithPins = [];
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($db) {
    try {
        $stmt = $db->query('SELECT id, account_number, full_name, phone_number, address, location_label, latitude, longitude, status FROM users WHERE latitude IS NOT NULL AND longitude IS NOT NULL');
        $customersWithPins = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        $customersWithPins = [];
    }
}

$page_title = 'Admin - Customer Locations';
require_once __DIR__ . '/../../templates/header.php';
?>

<div class="container mt-4 admin-shell">
    <div class="row">
        <div class="col-12">
            <div class="pb-banner pb-banner--cobalt mb-4">
                <div class="pb-bg" aria-hidden="true">
                    <div class="pb-grid"></div>
                    <div class="pb-blob pb-blob--a"></div>
                    <div class="pb-blob pb-blob--b"></div>
                    <i class="bi bi-geo-alt-fill pb-watermark"></i>
                </div>
                <div class="pb-inner">
                    <div class="pb-left">
                        <div class="pb-eyebrow-row">
                            <span class="pb-eyebrow-chip"><i class="bi bi-pin-map-fill"></i> Mapping</span>
                        </div>
                        <h2 class="pb-title">Customer Locations</h2>
                        <p class="pb-subtitle">View all customers with GPS pins on a single map.</p>
                    </div>
                    <div class="pb-right">
                        <div class="pb-btn-row">
                            <a href="/admin/users" class="pb-btn pb-btn--accent">
                                <i class="bi bi-people"></i> Back to Users
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0">Pinned Customer Locations</h5>
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted">Total pins: <?php echo count($customersWithPins); ?></small>
                        <?php if (!empty($customersWithPins)): ?>
                            <div class="input-group input-group-sm" style="width: 260px;">
                                <input type="text" class="form-control" id="customerLocationSearch" placeholder="Find by account, name or phone">
                                <button class="btn btn-outline-secondary" type="button" id="customerLocationSearchBtn">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($customersWithPins)): ?>
                        <p class="p-3 mb-0 text-muted">No customers with GPS pins yet. Add pins from the Users page.</p>
                    <?php else: ?>
                        <div id="allCustomersMap" style="height: 520px; width: 100%; border-radius: 0.5rem; overflow: hidden;"></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$customersJson = json_encode($customersWithPins ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$selectedUserIdJs = (int)$selectedUserId;
$googleMapsApiKey = Database::env('GOOGLE_MAPS_API_KEY', '');

if ($googleMapsApiKey) {
    $custom_scripts = <<<JS
<script>
function initCustomerLocationsMap() {
    var customers = $customersJson || [];
    var selectedUserId = $selectedUserIdJs;
    var mapEl = document.getElementById('allCustomersMap');
    if (!mapEl || typeof google === 'undefined' || !google.maps) {
        return;
    }

    var defaultLat = -1.292066; // Nairobi fallback
    var defaultLng = 36.821945;
    var map = new google.maps.Map(mapEl, {
        center: { lat: defaultLat, lng: defaultLng },
        zoom: 9,
        mapTypeId: google.maps.MapTypeId.ROADMAP,
        mapTypeControl: true,
        streetViewControl: false
    });

    if (!Array.isArray(customers) || customers.length === 0) {
        return;
    }

    var bounds = new google.maps.LatLngBounds();
    var markersById = {};
    var infoWindow = new google.maps.InfoWindow();

    customers.forEach(function(cust) {
        if (!cust || cust.latitude === null || cust.longitude === null) {
            return;
        }
        var lat = parseFloat(cust.latitude);
        var lng = parseFloat(cust.longitude);
        if (isNaN(lat) || isNaN(lng)) {
            return;
        }
        var pos = new google.maps.LatLng(lat, lng);
        bounds.extend(pos);

        var status = (cust.status || '').toString();
        var statusLabel = status ? status.charAt(0).toUpperCase() + status.slice(1) : '';
        var account = cust.account_number || '';
        var name = cust.full_name || '';
        var phone = cust.phone_number || '';
        var address = cust.address || '';
        var locLabel = cust.location_label || '';

        var popupHtml = '<div style="min-width: 220px;">' +
            '<strong>' + (account ? account + ' - ' : '') + name + '</strong><br>' +
            (phone ? '<span><i class="bi bi-phone"></i> ' + phone + '</span><br>' : '') +
            (address ? '<span><i class="bi bi-geo-alt"></i> ' + address + '</span><br>' : '') +
            (locLabel ? '<span><i class="bi bi-map"></i> ' + locLabel + '</span><br>' : '') +
            (statusLabel ? '<span class="badge bg-' + (status === 'active' ? 'success' : (status === 'suspended' ? 'warning' : 'secondary')) + '">' + statusLabel + '</span><br>' : '') +
            '<a href="https://www.google.com/maps/search/?api=1&query=' + lat + ',' + lng + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary mt-1">' +
                '<i class="bi bi-geo-alt-fill"></i> Open in Google Maps' +
            '</a>' +
            '</div>';

        var marker = new google.maps.Marker({
            position: pos,
            map: map,
            title: (account ? account + ' - ' : '') + name + (phone ? ' (' + phone + ')' : '')
        });

        marker._popupHtml = popupHtml;
        if (typeof cust.id !== 'undefined') {
            markersById[cust.id] = marker;
        }

        marker.addListener('click', function() {
            infoWindow.setContent(marker._popupHtml || '');
            infoWindow.open(map, marker);
        });
    });

    function zoomToCustomer(cust) {
        if (!cust || cust.latitude === null || cust.longitude === null) {
            return false;
        }
        var marker = markersById[cust.id];
        if (!marker) {
            return false;
        }
        var pos = marker.getPosition();
        map.setCenter(pos);
        map.setZoom(17);
        infoWindow.setContent(marker._popupHtml || '');
        infoWindow.open(map, marker);
        return true;
    }

    // If coming from "View on map" link for a specific user, try to focus that pin
    if (selectedUserId) {
        var selected = null;
        for (var i = 0; i < customers.length; i++) {
            if (customers[i] && customers[i].id === selectedUserId) {
                selected = customers[i];
                break;
            }
        }
        if (!selected || !zoomToCustomer(selected)) {
            if (!bounds.isEmpty()) {
                map.fitBounds(bounds);
            }
        }
    } else if (!bounds.isEmpty()) {
        map.fitBounds(bounds);
    }

    // Simple search: find a pinned customer by account, name or phone
    var searchInput = document.getElementById('customerLocationSearch');
    var searchBtn = document.getElementById('customerLocationSearchBtn');

    function findAndZoom(query) {
        if (!query) return;
        query = query.toString().trim().toLowerCase();
        if (query.length < 2) return;

        var match = null;
        for (var i = 0; i < customers.length; i++) {
            var c = customers[i];
            if (!c) continue;
            var account = (c.account_number || '').toString().toLowerCase();
            var name = (c.full_name || '').toString().toLowerCase();
            var phone = (c.phone_number || '').toString().toLowerCase();
            if (account.indexOf(query) !== -1 || name.indexOf(query) !== -1 || phone.indexOf(query) !== -1) {
                match = c;
                break;
            }
        }

        if (!match) {
            if (window.showToast) {
                showToast('No pinned customer found for search term.', 'warning');
            }
            return;
        }

        if (!zoomToCustomer(match) && window.showToast) {
            showToast('Selected customer does not have a valid pin.', 'warning');
        }
    }

    if (searchInput && searchBtn) {
        searchBtn.addEventListener('click', function() {
            findAndZoom(searchInput.value || '');
        });
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                findAndZoom(searchInput.value || '');
            }
        });
    }
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=$googleMapsApiKey&callback=initCustomerLocationsMap" async defer></script>
JS;
} else {
    // Fallback to Leaflet map if Google Maps API key is not configured
    $custom_scripts = <<<JS
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof L === 'undefined') {
        return;
    }

    var customers = $customersJson || [];
    var selectedUserId = $selectedUserIdJs;
    var mapEl = document.getElementById('allCustomersMap');
    if (!mapEl) {
        return;
    }

    var defaultLat = -1.292066; // Nairobi fallback
    var defaultLng = 36.821945;
    var map = L.map('allCustomersMap').setView([defaultLat, defaultLng], 9);

    L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, Tiles style by Humanitarian OpenStreetMap Team hosted by OpenStreetMap France'
    }).addTo(map);

    if (!Array.isArray(customers) || customers.length === 0) {
        return;
    }

    var bounds = [];
    var markersById = {};

    customers.forEach(function(cust) {
        if (!cust || cust.latitude === null || cust.longitude === null) {
            return;
        }
        var lat = parseFloat(cust.latitude);
        var lng = parseFloat(cust.longitude);
        if (isNaN(lat) || isNaN(lng)) {
            return;
        }
        var pos = [lat, lng];
        bounds.push(pos);

        var status = (cust.status || '').toString();
        var statusLabel = status ? status.charAt(0).toUpperCase() + status.slice(1) : '';
        var account = cust.account_number || '';
        var name = cust.full_name || '';
        var phone = cust.phone_number || '';
        var address = cust.address || '';
        var locLabel = cust.location_label || '';

        var popupHtml = '<div style="min-width: 220px;">' +
            '<strong>' + (account ? account + ' - ' : '') + name + '</strong><br>' +
            (phone ? '<span><i class="bi bi-phone"></i> ' + phone + '</span><br>' : '') +
            (address ? '<span><i class="bi bi-geo-alt"></i> ' + address + '</span><br>' : '') +
            (locLabel ? '<span><i class="bi bi-map"></i> ' + locLabel + '</span><br>' : '') +
            (statusLabel ? '<span class="badge bg-' + (status === 'active' ? 'success' : (status === 'suspended' ? 'warning' : 'secondary')) + '">' + statusLabel + '</span><br>' : '') +
            '</div>';

        var marker = L.marker(pos).addTo(map).bindPopup(popupHtml);
        if (typeof cust.id !== 'undefined') {
            markersById[cust.id] = marker;
        }
    });

    function zoomToCustomer(cust) {
        if (!cust || cust.latitude === null || cust.longitude === null) {
            return false;
        }
        var marker = markersById[cust.id];
        if (!marker) {
            return false;
        }
        var ll = marker.getLatLng();
        map.setView(ll, 17);
        marker.openPopup();
        return true;
    }

    if (bounds.length > 0) {
        map.fitBounds(bounds, { padding: [24, 24] });
    }
});
</script>
JS;
}

require_once __DIR__ . '/../../templates/footer.php';
?>
